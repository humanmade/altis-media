<?php
/**
 * Private Media — Front-end Attachment Page Guard.
 *
 * The feature keeps attachments at WP's default `inherit` status, so WordPress
 * core never applies its own privacy to the front-end attachment page. Worse,
 * since WP 6.4 the `wp_attachment_pages_enabled` option defaults to off, which
 * makes core's redirect_canonical() 301 every attachment URL straight to
 * wp_get_attachment_url(). For a private attachment that returns a freshly
 * signed, 15-minute presigned S3 URL — handed out to *anyone*, logged in or
 * not. The result: an anonymous visitor to a private attachment's page gets
 * redirected to a working URL for the full private image.
 *
 * This guard closes that gap by 404ing the attachment page for private
 * attachments the current user isn't allowed to edit — before core's
 * redirect_canonical() runs.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Attachment_Page;

use Altis\Media\Private_Media\Visibility;

/**
 * Bootstrap the attachment page guard.
 *
 * @return void
 */
function bootstrap() : void {
	// Priority 9 so this runs before core's redirect_canonical() (priority 10),
	// which would otherwise 301 to the signed file URL before we can intervene.
	add_action( 'template_redirect', __NAMESPACE__ . '\\guard_private_attachment_page', 9 );
}

/**
 * Block public access to the front-end page of a private attachment.
 *
 * Returns a 404 for private attachments the current user can't edit. This
 * prevents both core's redirect-to-signed-file behaviour and any rendered
 * attachment template (which would embed signed URLs via prepend_attachment)
 * from exposing the image to unauthorized visitors.
 *
 * @return void
 */
function guard_private_attachment_page() : void {
	if ( ! is_attachment() ) {
		return;
	}

	$attachment_id = get_queried_object_id();
	if ( ! $attachment_id ) {
		return;
	}

	// Public attachments are safe — the page (or core's redirect to the public
	// file URL) exposes nothing that isn't already meant to be public.
	if ( Visibility\check_attachment_is_public( $attachment_id ) ) {
		return;
	}

	// Private attachment: only users who can edit it may view it, matching the
	// feature's promise that private files stay available to media-capable
	// users. Everyone else is treated as if the page doesn't exist.
	if ( current_user_can( 'edit_post', $attachment_id ) ) {
		return;
	}

	global $wp_query;

	// Re-route the request to a 404. Once is_404() is true, is_attachment()
	// returns false, so core's redirect_canonical() skips its attachment
	// branch and the template loader serves the 404 template instead.
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
