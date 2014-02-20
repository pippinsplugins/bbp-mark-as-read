<?php
/*
Plugin Name: bbPress - Mark as Read
Plugin URL: http://pippinsplugins.com/bbpress-mark-as-read
Description: Allows you to mark bbPress topics as read/unread and see all unread topics
Version: 1.0
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/

class BBP_Mark_As_Read {

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	/**
	 * Initializes the plugin by setting localization, filters, and administration functions.
	 */
	function __construct() {

		// load the plugin translation files
		load_plugin_textdomain( 'bbp-mar', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// add the Mark as Read / Unread links
		add_filter( 'bbp_get_user_subscribe_link', array( $this, 'add_links_to_topics' ), 999, 4 );

		// process marked as read requests
		add_action( 'init', array( $this, 'process_marked_as_read' ) );

		// process marked as unread requests
		add_action( 'init', array( $this, 'process_marked_as_unread' ) );

		// process "mark all as read"
		add_action( 'init', array( $this, 'process_mark_all_as_read' ) );

		// process automatic mark as read requests via ajax
		add_action( 'wp_ajax_bbp_mark_as_read', array( $this, 'process_ajax_marked_as_read' ) );
		add_action( 'wp_ajax_nopriv_bbp_mark_as_read', array( $this, 'process_ajax_marked_as_read' ) );

		// add the unread topics section to the bbPress profile page
		add_action( 'bbp_template_after_user_subscriptions', array( $this, 'show_unread_topics' ) );

		// load the JS for auto-marking as read
		add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );

		// add a class name indicating the read status
		add_filter( 'post_class', array( $this, 'topic_post_class' ) );

	} // end constructor


	public function add_links_to_topics( $html, $args, $user_id, $topic_id ) {

		if ( empty( $user_id ) || empty( $topic_id ) ) {
			return $html;
		}

		// Prevent the links from being adding when clicking Subscribe / Unsubscribe
		if( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return $html;
		}

		// No link if you can't edit yourself
		if ( ! current_user_can( 'edit_user', (int) $user_id ) ) {
			return $html;
		}

		// Decine which link to show
		$is_read = $this->is_read( $user_id, $topic_id );
		if ( !empty( $is_read ) ) {
			$text       = __('Mark as Unread', 'bbp-mar');
			$query_args = array( 'action' => 'bbp_mark_as_unread', 'topic_id' => $topic_id );
		} else {
			$text       = __('Mark as Read', 'bbp-mar');
			$query_args = array( 'action' => 'bbp_mark_as_read', 'topic_id' => $topic_id );
		}

		// Create the link based where the user is and if the user is
		if ( is_singular( bbp_get_topic_post_type() ) ) {
			$permalink = bbp_get_topic_permalink( $topic_id );
		} elseif ( is_singular( bbp_get_reply_post_type() ) ) {
			$permalink = bbp_get_topic_permalink( $topic_id );
		} elseif ( bbp_is_query_name( 'bbp_single_topic' ) ) {
			$permalink = get_permalink();
		} else {
			$permalink = null;
		}

		$url = esc_url( wp_nonce_url( add_query_arg( $query_args, $permalink ), 'toggle-read_state_' . $topic_id ) );

		$link = '<span class="mark-as-read-toggle" style="float:right;">';
			$link .= $args['before'];
			$link .= '<a href="' . $url . '">';
				$link .= $text;
			$link .= '</a>';
			$link .= $args['after'];
		$link .= '</span>';
		return $html . $link;
	}

	// checks if a topic is read for the specified user
	public function is_read( $user_id = 0, $topic_id = 0 ) {

		$user_id = bbp_get_user_id( $user_id, true, true );
		if ( empty( $user_id ) )
			return false;

		$retval 	= false;
		$read_ids 	= $this->get_read_ids( $user_id );

		if ( !empty( $read_ids ) ) {

			// Checking a specific topic id
			if ( !empty( $topic_id ) ) {
				$topic     = bbp_get_topic( $topic_id );
				$topic_id = !empty( $topic ) ? $topic->ID : 0;

			// Using the global topic id
			} elseif ( bbp_get_topic_id() ) {
				$topic_id = bbp_get_topic_id();

			// Use the current post id
			} elseif ( !bbp_get_topic_id() ) {
				$topic_id = get_the_ID();
			}

			// Is topic_id in the user's read list
			if ( !empty( $topic_id ) ) {
				$retval = in_array( $topic_id, $read_ids );
			}
		}

		return (bool) apply_filters( 'bbp_mar_is_read', (bool) $retval, $user_id, $topic_id, $read_ids );
	}

	// marks a topic as read for the specified user
	public function mark_as_read( $user_id = 0, $topic_id = 0 ) {

		if ( empty( $user_id ) || empty( $topic_id ) )
			return false;

		$read_ids = (array) $this->get_read_ids( $user_id );

		if( is_array( $topic_id ) ) {

			$read_ids = array_merge( $topic_id, $read_ids );
			$read_ids = array_unique( $read_ids );
			$read_ids   = array_filter( $read_ids );
			$read_ids   = (string) implode( ',', $read_ids );
			update_user_meta( $user_id, '_bbp_mar_read_ids', $read_ids );

		} else {

			if ( !in_array( $topic_id, $read_ids ) ) {
				$read_ids[] = $topic_id;
				$read_ids   = array_filter( $read_ids );
				$read_ids   = (string) implode( ',', $read_ids );
				update_user_meta( $user_id, '_bbp_mar_read_ids', $read_ids );
			}

		}

		do_action( 'bbp_mar_marked_as_read', $user_id, $topic_id );

		return true;
	}

	// marks a topic as unread for the specified user
	public function mark_as_unread( $user_id = 0, $topic_id = 0 ) {
		
		if ( empty( $user_id ) || empty( $topic_id ) )
			return false;

		$read_ids = (array) $this->get_read_ids( $user_id );

		if ( empty( $read_ids ) )
			return false;

		$pos = array_search( $topic_id, $read_ids );
		if ( is_numeric( $pos ) ) {
			array_splice( $read_ids, $pos, 1 );
			$read_ids = array_filter( $read_ids );

			if ( !empty( $read_ids ) ) {
				$read_ids = implode( ',', $read_ids );
				update_user_meta( $user_id, '_bbp_mar_read_ids', $read_ids );
			} else {
				delete_user_meta( $user_id, '_bbp_mar_read_ids' );
			}
		}

		do_action( 'bbp_mar_marked_as_unread', $user_id, $topic_id );

		return true;

	}

	// retrieves all read topic IDs for the specified user
	public function get_read_ids( $user_id = 0 ) {
		$read_ids = (string) get_user_meta( $user_id, '_bbp_mar_read_ids', true );
		$read_ids = (array) explode( ',', $read_ids );
		$read_ids = array_filter( $read_ids );
		return apply_filters( 'bbp_mar_read_ids', (array)$read_ids );
	}

	// processes the mark as read action
	public function process_marked_as_read() {
		if( !isset( $_GET['action'] ) || $_GET['action'] != 'bbp_mark_as_read' )
			return;

		$topic_id = absint( $_GET['topic_id'] );

		if( ! wp_verify_nonce( $_GET['_wpnonce'], 'toggle-read_state_' . $topic_id ) )
			return;

		global $user_ID;

		$topic_id = bbp_get_topic_id( $topic_id );

		if ( empty( $user_ID ) || empty( $topic_id ) )
			return false;

		// No link if you can't edit yourself
		if ( !current_user_can( 'edit_user', (int) $user_ID ) )
			return false;
		
		$this->mark_as_read( $user_ID, $topic_id );

	}

	// processes the mark as unread action
	public function process_marked_as_unread() {
		if( !isset( $_GET['action'] ) || $_GET['action'] != 'bbp_mark_as_unread' )
			return;

		$topic_id = absint( $_GET['topic_id'] );

		if( ! wp_verify_nonce( $_GET['_wpnonce'], 'toggle-read_state_' . $topic_id ) )
			return;

		global $user_ID;

		$topic_id = bbp_get_topic_id( $topic_id );

		if ( empty( $user_ID ) || empty( $topic_id ) )
			return false;

		// No link if you can't edit yourself
		if ( !current_user_can( 'edit_user', (int) $user_ID ) )
			return false;
		
		$this->mark_as_unread( $user_ID, $topic_id );

	}

	// processes the "mark all as read" action
	public function process_mark_all_as_read() {
		if( !isset( $_GET['action'] ) || $_GET['action'] != 'bbp_mark_all_as_read' )
			return;

		if( ! wp_verify_nonce( $_GET['_wpnonce'], 'mark_all_read' ) )
			return;

		global $user_ID;

		if ( empty( $user_ID ) )
			return false;
		//print_r( $this->get_read_ids( $user_ID ) ); exit;
		$args = array(
			'post_type' => 'topic', // only the topic post type
			'posts_per_page' => -1, // get all topcs
			'post__not_in' => $this->get_read_ids( $user_ID ) // exclude already marked as read topics
		);

		$topics = get_posts( apply_filters( 'bbp_mar_all_topics_query', $args, $user_ID ) );
		if( $topics ) {
			$topic_ids = wp_list_pluck( $topics, 'ID' );
			$this->mark_as_read( $user_ID, $topic_ids );
		}

	}

	// processes the mark as read action via ajax
	public function process_ajax_marked_as_read() {
		global $user_ID;

		if ( empty( $user_ID ) || ! isset( $_POST['topic_id']) )
			return false;

		// make sure the current user has permission to edit the user
		if ( !current_user_can( 'edit_user', (int) $user_ID ) )
			return false;

		$this->mark_as_read( $user_ID, $_POST['topic_id'] );
		die();
	}

	// get all unread topic IDs for the specified user
	public function bbp_get_user_unread( $user_id = 0 ) {

		// Default to the displayed user
		$user_id = bbp_get_user_id( $user_id );
		if ( empty( $user_id ) )
			return false;
		// If user has unread topics, load them
		$read_ids = $this->get_read_ids( $user_id );
		if ( !empty( $read_ids ) ) {
			$query = bbp_has_topics( array( 'post__not_in' => $read_ids ) );
			//echo '<pre>'; print_r( bbpress()->topic_query ); echo '</pre>'; exit;
			return apply_filters( 'bbp_get_user_unread', $query, $user_id );
		}

		return bbp_has_topics(); // default query

	}

	// adds a section showing unread topics to the user's profile
	public function show_unread_topics() {

		global $user_ID;

		if ( bbp_is_user_home() || current_user_can( 'edit_users' ) ) : ?>

			<?php bbp_set_query_name( 'bbp_user_profile_unread_topics' ); ?>

			<div id="bbp-author-unread-topics" class="bbp-author-unread-topics">
				<h2 class="entry-title"><?php _e( 'Unread Forum Topics', 'bbp-mar' ); ?></h2>
				<div class="bbp-user-section">

					<?php if ( $this->bbp_get_user_unread( $user_ID ) ) : ?>

						<?php
						global $wp_query;
						//echo '<pre>'; print_r( $wp_query ); echo '</pre>'; exit; ?>

						<?php bbp_get_template_part( 'pagination', 'topics' ); ?>

						<?php bbp_get_template_part( 'loop',       'topics' ); ?>

						<?php bbp_get_template_part( 'pagination', 'topics' ); ?>

						<?php $url = esc_url( wp_nonce_url( add_query_arg( 'action', 'bbp_mark_all_as_read', bbp_get_user_profile_url() ), 'mark_all_read' ) ); ?>
						<p class="bbp-mark-all-read"><strong><a href="<?php echo $url; ?>"><?php _e('Mark all as topics read', 'bbp-mar'); ?></a></strong></p>

					<?php else : ?>

						<p><?php bbp_is_user_home() ? _e( 'You have read every posted topic.', 'bbp-mar' ) : _e( 'This user has no unread topics.', 'bbp-mar' ); ?></p>

					<?php endif; ?>

				</div>
			</div><!-- #bbp-author-unread-topics -->

			<?php bbp_reset_query_name(); ?>

		<?php endif;
	}

	// enqueues the ajax script
	public function load_scripts() {

		global $post, $user_ID;

		if( !is_object( $post ) )
			return;
		if( 'topic' != get_post_type( $post ) || ! is_singular('topic') )
			return;
		if( $this->is_read( $user_ID, $post->ID ) )
			return;

		wp_enqueue_script( 'bbp-mark-as-read', plugin_dir_url( __FILE__ ) . 'mark-as-read-auto.js', array( 'jquery' ), '0.1' );
		wp_localize_script( 'bbp-mark-as-read', 'mark_as_read_auto_js', 
			array( 
				'ajaxurl' 	=> admin_url( 'admin-ajax.php' ),
				'topic_id' 	=> bbp_get_topic_id(),
				'time' 		=> apply_filters( 'bbp_mar_auto_mark_time', 10 )
			) 
		);
	}

	// adds classes to post_class for read/unread statuses
	public function topic_post_class( $classes ) {
		global $post, $user_ID;
		if( 'topic' != get_post_type( $post ) )
			return $classes;

		if( $this->is_read( $user_ID, $post->ID ) )
			$classes[] = 'bbp-topic-read';
		else
			$classes[] = 'bbp-topic-unread';

		return $classes;
	}
  
} // end class

// instantiate our plugin's class
$GLOBALS['bbp_mark_as_read'] = new BBP_Mark_As_Read();