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
 * The feature is opt-in: returns false unless explicitly enabled via Altis
 * configuration. Also returns false on the global media library site, even
 * when enabled.
 *
 * @param array|null $module_config Optional. The `modules.media` config slice.
 *                                  Defaults to the live config. Used for testing.
 *
 * @return bool True if private media is active.
 */
function is_private_media_active( ?array $module_config = null ) : bool {
	if ( $module_config === null ) {
		$module_config = Altis\get_config()['modules']['media'] ?? [];
	}

	// Off unless explicitly enabled.
	if ( empty( $module_config['private-media'] ) ) {
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
 * The feature is opt-in. All hooks are gated behind is_private_media_active().
 * When not explicitly enabled, the feature leaves no runtime footprint —
 * attachments stay at WP's default `inherit` status and the `_altis_media_acl`
 * post meta is simply ignored.
 *
 * @return void
 */
function bootstrap() {
	// Early config-only gate (safe to check before muplugins_loaded — no WP
	// functions are needed for the opt-in check). The global-site portion of
	// is_private_media_active() is skipped until muplugins_loaded has fired.
	if ( ! is_private_media_active() ) {
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
