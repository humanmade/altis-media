<?php
/**
 * Altis Global Media.
 *
 * @package altis-media
 */

namespace Altis\Media\Global_Assets;

use Altis;
use Altis\Global_Content;

/**
 * Setup global media hooks.
 *
 * @return void
 */
function bootstrap() {
	$config = Altis\get_config()['modules']['media'];

	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_amf', 5 );

	// Load WP AMF and configure global site.
	if ( empty( $config['global-media-library'] ) ) {
		return;
	}

	// Bail if a site URL has been provided, assume it's external.
	if ( is_string( $config['global-media-library'] ) ) {
		define( 'AMF_WORDPRESS_URL', $config['global-media-library'] );
		return;
	}

	// Configure the global media site.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\configure_site', 9 );
	add_filter( 'altis.core.global_content_site_menu_pages', __NAMESPACE__ . '\\allow_media_pages' );
}

/**
 * Load Asset Mnnager Framework.
 *
 * @return void
 */
function load_amf() {
	$config = Altis\get_config()['modules']['media'];

	// Load Asset Manager Framework.
	require_once Altis\ROOT_DIR . '/vendor/humanmade/asset-manager-framework/plugin.php';

	// Load WP AMF and configure global site.
	if ( $config['global-media-library'] ) {
		require_once Altis\ROOT_DIR . '/vendor/humanmade/amf-wordpress/plugin.php';
	}
}

/**
 * Create the Global Media Library site.
 *
 * @throws Exception If media site cannot be created.
 */
function configure_site() {
	// Wait until post installation.
	if ( defined( 'WP_INITIAL_INSTALL' ) && WP_INITIAL_INSTALL ) {
		return;
	}

	$media_site = Global_Content\get_site_url();
	if ( ! empty( $media_site ) ) {
		define( 'AMF_WORDPRESS_URL', $media_site );
		return;
	}

	// If we have no site and no URL remove the WP AMF plugin hooks.
	remove_action( 'plugins_loaded', 'AMFWordPress\\register_settings' );
	remove_action( 'admin_init', 'AMFWordPress\\register_settings_ui' );
	remove_filter( 'amf/provider', 'AMFWordPress\\get_provider' );

	// Suggest running the migrate command.
	trigger_error( 'The Global Content Repository site does not exist yet! To use the Global Media Library feature you will need to run the `wp altis migrate` command.', E_USER_WARNING );
}

/**
 * Add media pages to the allowed list in the admin menu.
 *
 * @param array $pages Admin page slugs to allow in the menu.
 * @return array
 */
function allow_media_pages( array $pages ) : array {
	$pages[] = 'upload.php';
	$pages[] = 'media.php';
	return $pages;
}
