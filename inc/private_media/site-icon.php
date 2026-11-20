<?php
/**
 * Private Media — Site Icon Handling.
 *
 * Ensures the site icon attachment is always treated as public.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Site_Icon;

use Altis\Media\Private_Media\Visibility;

/**
 * Bootstrap site icon hooks.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'update_option_site_icon', __NAMESPACE__ . '\\on_site_icon_update', 10, 2 );
}

/**
 * Handle site icon option updates.
 *
 * Marks the new icon attachment with site_icon metadata and clears it from the old icon.
 *
 * @param mixed $old_value The old site icon attachment ID.
 * @param mixed $new_value The new site icon attachment ID.
 * @return void
 */
function on_site_icon_update( $old_value, $new_value ) : void {
	$old_id = (int) $old_value;
	$new_id = (int) $new_value;

	// Clear site_icon flag from old attachment.
	if ( $old_id > 0 ) {
		$metadata = wp_get_attachment_metadata( $old_id );
		if ( is_array( $metadata ) ) {
			unset( $metadata['site_icon'] );
			wp_update_attachment_metadata( $old_id, $metadata );
			Visibility\set_attachment_visibility( $old_id );
		}
	}

	// Set site_icon flag on new attachment.
	if ( $new_id > 0 ) {
		$metadata = wp_get_attachment_metadata( $new_id );
		if ( ! is_array( $metadata ) ) {
			$metadata = [];
		}
		$metadata['site_icon'] = true;
		wp_update_attachment_metadata( $new_id, $metadata );
		Visibility\set_attachment_visibility( $new_id );
	}
}
