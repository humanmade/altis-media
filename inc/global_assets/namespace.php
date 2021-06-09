<?php
/**
 * Altis Global Media.
 *
 * @package altis-media
 */

namespace Altis\Media\Global_Assets;

use Altis;
use Altis\Global_Content;
use Altis\Media;

/**
 * Setup global media hooks.
 *
 * @return void
 */
function bootstrap() {
	$config = Altis\get_config()['modules']['media'];

	if ( empty( $config['global-media-library'] ) ) {
		return;
	}

	// Load WP AMF and configure global site.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_load_amf_wp', 5 );

	// Bail if a site URL has been provided, assume it's external.
	if ( is_string( $config['global-media-library'] ) ) {
		define( 'AMF_WORDPRESS_URL', $config['global-media-library'] );
		return;
	}

	// Configure the global media site.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\configure_site', 9 );
	add_filter( 'altis.core.global_content_site_menu_pages', __NAMESPACE__ . '\\allow_media_pages' );

	// Configure the WP provider name.
	add_filter( 'amf/wordpress/provider_name', __NAMESPACE__ . '\\set_global_site_provider_name' );
}

/**
 * Load Asset Manager Framework for WordPress.
 *
 * Will not load if WP is being installed or if the current site is the global content site.
 *
 * @return void
 */
function maybe_load_amf_wp() {
	// Wait until post installation.
	if ( defined( 'WP_INITIAL_INSTALL' ) && WP_INITIAL_INSTALL ) {
		return;
	}

	// Don't load AMF on the global site itself.
	if ( Global_Content\is_global_site() ) {
		return;
	}

	// Load Asset Manager Framework.
	Media\load_amf();

	// Load AMF WP.
	require_once Altis\ROOT_DIR . '/vendor/humanmade/amf-wordpress/plugin.php';
}

/**
 * Create the Global Media Library site.
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
	remove_action( 'amf/register_providers', 'AMFWordPress\\register_provider' );

	// Suggest running the migrate command.
	trigger_error( 'The Global Content Repository site does not exist yet! To use the Global Media Library feature you will need to run the `wp altis migrate` command.', E_USER_WARNING );
}

/**
 * Filter the WordPress media provider name.
 *
 * @param string $name The default provider name.
 * @return string
 */
function set_global_site_provider_name( string $name ) : string {
	static $provider_name;

	// Cache the value so switch_to_blog() only happens once.
	if ( ! empty( $provider_name ) ) {
		return $provider_name;
	}

	$provider_name = $name;

	$site_name = get_blog_option( Global_Content\get_site_id(), 'blogname' );
	if ( ! empty( $site_name ) ) {
		$provider_name = sprintf( '%s %s', $site_name, __( 'Media' ) );
	}

	return $provider_name;
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
