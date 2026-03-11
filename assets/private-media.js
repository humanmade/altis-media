/**
 * Private Media — Media Library UI.
 *
 * Extends the WordPress media modal to add visibility controls
 * and lock icon overlays for private attachments.
 */
( function( $, wp ) {
	'use strict';

	if ( ! wp || ! wp.media ) {
		return;
	}

	/**
	 * Handle visibility dropdown changes via AJAX.
	 */
	$( document ).on( 'change', '.private-media-override', function() {
		var $select = $( this );
		var attachmentId = $select.data( 'attachment-id' );
		var override = $select.val();

		$.ajax( {
			url: privateMedia.ajaxUrl,
			type: 'POST',
			data: {
				action: 'private_media_set_visibility',
				nonce: privateMedia.nonce,
				attachment_id: attachmentId,
				override: override
			},
			success: function( response ) {
				if ( response.success ) {
					// Update the status display.
					var $status = $select.closest( '.attachment-details, .compat-field-private_media_override' )
						.siblings( '.compat-field-private_media_status' )
						.find( 'strong' );

					if ( response.data.override === 'public' ) {
						$status.text( 'Forced Public' );
					} else if ( response.data.override === 'private' ) {
						$status.text( 'Forced Private' );
					} else {
						$status.text( response.data.status === 'public' ? 'Public' : 'Private' );
					}

					// Refresh the attachment in the library if possible.
					if ( wp.media.frame && wp.media.frame.library ) {
						var attachment = wp.media.frame.library.get( attachmentId );
						if ( attachment ) {
							attachment.fetch();
						}
					}
				}
			}
		} );
	} );

	/**
	 * Add lock icon overlay to private attachments in grid view.
	 */
	if ( wp.media.view && wp.media.view.Attachment ) {
		var OriginalAttachment = wp.media.view.Attachment;

		wp.media.view.Attachment = OriginalAttachment.extend( {
			render: function() {
				OriginalAttachment.prototype.render.apply( this, arguments );

				var status = this.model.get( 'status' );
				this.$el.find( '.private-media-lock' ).remove();

				if ( status === 'private' ) {
					this.$el.find( '.attachment-preview' ).append(
						'<span class="private-media-lock dashicons dashicons-lock" title="Private"></span>'
					);
				}

				return this;
			}
		} );
	}

} )( jQuery, window.wp );
