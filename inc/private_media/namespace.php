<?php
/**
 * Private Media — Bootstrap and Configuration.
 *
 * Main entry point for the Private Media feature. Handles feature detection,
 * configuration, and hook orchestration.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media;

use Altis;

/**
 * Check if the private media feature is active for the current site.
 *
 * Returns false for the global media library site and when the feature is
 * disabled via configuration.
 *
 * @return bool True if private media is active.
 */
function is_private_media_active() : bool {
	$config = Altis\get_config()['modules']['media'] ?? [];

	// Check if explicitly disabled.
	if ( isset( $config['private-media'] ) && $config['private-media'] === false ) {
		return false;
	}

	// Disable on the global media library site.
	// Only check when WP is fully loaded (get_site_meta is available).
	if ( did_action( 'muplugins_loaded' ) ) {
		if ( function_exists( '\\Altis\\Global_Content\\is_global_site' ) && \Altis\Global_Content\is_global_site() ) {
			return false;
		}
	}

	return true;
}

/**
 * Bootstrap the private media feature.
 *
 * Query compatibility always runs (even when inactive) to prevent data loss.
 * All other hooks are gated behind is_private_media_active().
 *
 * @return void
 */
function bootstrap() {
	// Query compat always runs — prevents data loss when feature is toggled off.
	Query_Compat\bootstrap();

	// Check config-level disable (safe to check early — no WP functions needed).
	$config = Altis\get_config()['modules']['media'] ?? [];
	if ( isset( $config['private-media'] ) && $config['private-media'] === false ) {
		return;
	}

	// Defer remaining bootstrap until WP is loaded enough for is_global_site() etc.
	// If muplugins_loaded has already fired (e.g. in test environments), call directly.
	if ( did_action( 'muplugins_loaded' ) ) {
		bootstrap_feature();
	} else {
		add_action( 'muplugins_loaded', __NAMESPACE__ . '\\bootstrap_feature' );
	}
}

/**
 * Bootstrap the private media feature after WordPress is loaded.
 *
 * Called on muplugins_loaded so that functions like get_site_meta() are available
 * for the global site check.
 *
 * @return void
 */
function bootstrap_feature() {
	if ( ! is_private_media_active() ) {
		return;
	}

	// Core visibility logic (new attachment → private, S3 filters).
	Visibility\bootstrap();

	// Post lifecycle (publish/unpublish transitions).
	Post_Lifecycle\bootstrap();

	// Content sanitisation (strip AWS params).
	Sanitisation\bootstrap();

	// Signed URL previews.
	Signed_Urls\bootstrap();

	// Site icon handling.
	Site_Icon\bootstrap();

	// UI hooks (media library, row actions, bulk actions).
	UI\bootstrap();

	// CLI commands.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		CLI\bootstrap();
	}
}
