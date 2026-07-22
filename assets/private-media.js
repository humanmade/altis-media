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
	 * Add visibility badge to an attachment preview element.
	 *
	 * - Private: lock icon (default state and forced-private).
	 * - Forced public: globe icon (manual override).
	 * - Naturally public (used in published content): no icon.
	 *
	 * @param {Object} view The Backbone attachment view instance.
	 */
	function addVisibilityBadge( view ) {
		var override = view.model.get( 'privateMediaOverride' );
		var isPublic = view.model.get( 'privateMediaIsPublic' );
		var $preview = view.$el.find( '.attachment-preview' );

		$preview.find( '.private-media-badge' ).remove();

		if ( override === 'public' ) {
			$preview.append(
				'<span class="private-media-badge private-media-badge--forced-public dashicons dashicons-admin-site-alt3" title="Public (forced)"></span>'
			);
		} else if ( ! isPublic ) {
			$preview.append(
				'<span class="private-media-badge private-media-badge--private dashicons dashicons-lock" title="Private"></span>'
			);
		}
	}

	/**
	 * Patch the render method of an Attachment view subclass to add the
	 * visibility badge after the original render completes.
	 */
	function patchRender( ViewClass ) {
		var originalRender = ViewClass.prototype.render;

		ViewClass.prototype.render = function() {
			originalRender.apply( this, arguments );
			addVisibilityBadge( this );
			return this;
		};
	}

	// Patch the Library subclass (grid view on upload.php) and the base
	// Attachment view (media modal). Library's render calls the original
	// (pre-patch) Attachment render via a closure, so patching both is
	// safe — only patch the base when Library does not exist.
	if ( wp.media.view && wp.media.view.Attachment ) {
		if ( wp.media.view.Attachment.Library ) {
			patchRender( wp.media.view.Attachment.Library );
		} else {
			patchRender( wp.media.view.Attachment );
		}
	}

} )( jQuery, window.wp );
