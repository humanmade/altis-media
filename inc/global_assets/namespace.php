<?php
/**
 * Altis Global Media.
 *
 * @package altis-media
 */

namespace Altis\Media\Global_Assets;

use Altis;
use Exception;
use WP_Site;

/**
 * Setup global media hooks.
 *
 * @return void
 */
function bootstrap() {
	$config = Altis\get_config()['modules']['media'];

	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_amf', 9 );

	// Load WP AMF and configure global site.
	if ( empty( $config['global-media-library'] ) ) {
		return;
	}

	// Bail if a site URL has been provided, assume it's external.
	if ( is_string( $config['global-media-library'] ) ) {
		define( 'AMF_WORDPRESS_URL', $config['global-media-library'] );
		return;
	}

	// Redirect media site dashboard to upload.php.
	add_action( 'admin_init', __NAMESPACE__ . '\\redirect_dashboard' );

	// Handle clean up operations.
	add_action( 'wp_uninitialize_site', __NAMESPACE__ . '\\uninitialize_media_site' );

	// Handle global media library admin customisations.
	add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu', 1000 );

	// Else create the site or define the URL.
	add_action( 'init', __NAMESPACE__ . '\\maybe_create_site' );
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
function maybe_create_site() {
	// Wait until post installation.
	if ( defined( 'WP_INITIAL_INSTALL' ) && WP_INITIAL_INSTALL ) {
		return;
	}

	$media_site = get_site_option( 'global_media_site' );
	if ( ! empty( $media_site ) ) {
		define( 'AMF_WORDPRESS_URL', $media_site );
		return;
	}

	try {
		$media_site_error = get_site_option( 'global_media_site_error' );
		if ( ! empty( $media_site_error ) ) {
			throw new Exception( $media_site_error );
		}

		$media_site_id = wp_insert_site( [
			'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'path' => '/media/',
			'title' => __( 'Global Media Library', 'altis' ),
			'user_id' => get_user_by( 'login', get_super_admins()[0] )->ID,
			'meta' => [
				'is_media_site' => true,
			],
		] );

		if ( is_wp_error( $media_site_id ) ) {
			/**
			 * The error response.
			 *
			 * @var \WP_Error $media_site_id
			 */
			throw new Exception( sprintf( 'Global media site could not be created. %s', $media_site_id->get_error_message() ) );
		}

		// Store the media site URL and ID.
		$media_site_url = get_site_url( $media_site_id );
		update_site_option( 'global_media_site', rtrim( $media_site_url, '/' ) );
		update_site_option( 'global_media_site_id', $media_site_id );

		// Configure WP AMF.
		define( 'AMF_WORDPRESS_URL', $media_site_url );
		return;
	} catch ( Exception $error ) {
		trigger_error( $error->getMessage(), E_USER_WARNING );

		// Store the error.
		update_site_option( 'global_media_site_error', $error->getMessage() );

		// If we have no site and no URL remove the WP AMF plugin hooks.
		remove_action( 'plugins_loaded', 'AMFWordPress\\register_settings' );
		remove_action( 'admin_init', 'AMFWordPress\\register_settings_ui' );
		remove_filter( 'amf/provider', 'AMFWordPress\\get_provider' );
	}
}

/**
 * Handle deletion of old media site.
 *
 * @param WP_Site $old_site The site being deleted.
 * @return void
 */
function uninitialize_media_site( WP_Site $old_site ) {
	if ( ! get_site_meta( $old_site->id, 'is_media_site' ) ) {
		return;
	}

	delete_site_option( 'global_media_site' );
	delete_site_option( 'global_media_site_id' );
}

/**
 * Trim down the media site's admin menu.
 */
function admin_menu() {
	global $menu;

	if ( ! get_site_meta( get_current_blog_id(), 'is_media_site' ) ) {
		return;
	}

	$allowed_menu_pages = [
		'upload.php',
		'users.php',
	];

	foreach ( $menu as $position => $item ) {
		if ( ! in_array( $item[2], $allowed_menu_pages, true ) ) {
			unset( $menu[ $position ] );
		}
	}
}

/**
 * Redirect to the media library from the Global Media Library dashboard.
 *
 * @return void
 */
function redirect_dashboard() {
	global $pagenow;

	if ( ! get_site_meta( get_current_blog_id(), 'is_media_site' ) ) {
		return;
	}

	if ( $pagenow !== 'index.php' ) {
		return;
	}

	wp_safe_redirect( admin_url( '/upload.php' ) );
	exit;
}
