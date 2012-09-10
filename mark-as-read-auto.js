jQuery(document).ready(function($) {
	var time = parseInt(mark_as_read_auto_js.time) * 1000;
	if( time == '' || time == null ) {
		time = 10000; // default to 10 seconds
	}
	ran = false;
	setTimeout( function() {
		if( ! ran ) {
			var data = {
				action: 'bbp_mark_as_read',
				topic_id: mark_as_read_auto_js.topic_id
			};
			$.post( mark_as_read_auto_js.ajaxurl, data, function(response) {
				ran = true;
			});
		}
	}, time );
});