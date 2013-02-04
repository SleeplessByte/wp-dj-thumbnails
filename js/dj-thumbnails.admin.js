jQuery(document).ready(function($) {
	
	var thumb_uploader_id = null;	
	var thumb_width = null;
	var thumb_height = null;
	
	// Open media uploader
	$( '.thumb-upload' ).click(
		function( clickevent ) {
			
			thumb_uploader_id = $( this ).attr( 'name' ) ;
			thumb_width = parseFloat( $('#thumb_display_width').val() );
			thumb_height = parseFloat( $('#thumb_display_height').val() );
			
			console.info(  $('#thumb_display_width') );
			
			tb_show('', 'media-upload.php?type=image&amp;TB_iframe=true');

			var imageFrame = $('#TB_iframeContent');
			imageFrame.load(
				function() {
					imageFrame.contents().find("tr.submit > td.savesend > input").val( dj_thumbnails_i18n.use_image ); //'Use as ... Image'
				}
			);
			
			return false;
		}
	);
	
	// Close media uploader
	window.send_to_editor = function( html ) {
		var selected_img = $('img', html );
		var selected_url = selected_img.attr('src');
		var selected_classes = selected_img.attr('class');

		if (selected_classes != null) {
			var selected_id = selected_classes.replace(/(.*?)wp-image-/, '');
			var width = parseFloat( selected_img.attr( 'width' ) );
			var height = parseFloat( selected_img.attr( 'height' ) );
			
			// Make a thumbnail
			var ratio = width / height ;
			if ( ratio > thumb_width / thumb_height ) {
			 	// wider
				ratio = height / thumb_height;			
				width = Math.round( width / ratio );
				height = Math.floor( thumb_height );
				
			} else {
				ratio = width / thumb_width;
				height = Math.round( height / ratio );
				width = Math.floor( thumb_width );
			}
			
			selected_img.attr( 'width', width );
			selected_img.attr( 'height', height );
			
			$( '#' + thumb_uploader_id + '_image_id' ).val( selected_id );
			$( '#' + thumb_uploader_id + '_icon' ).html( selected_img );
			
			$( '#thumbnail_submit' ).removeAttr( 'disabled' );
		}
		 
		 tb_remove();
	}
});
