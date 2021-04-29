<?php
/**
 * Altis Global Media.
 *
 * @package altis-media
 */

namespace Altis\Media\Global_Assets;

use Altis;
use Exception;
use WP_Admin_Bar;
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

	// Create the site or define the URL.
	add_action( 'init', __NAMESPACE__ . '\\maybe_create_site' );

	// Redirect media site URLs.
	add_action( 'admin_init', __NAMESPACE__ . '\\redirect_dashboard' );
	add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_frontend' );

	// Handle clean up operations.
	add_action( 'wp_uninitialize_site', __NAMESPACE__ . '\\uninitialize_media_site' );

	// Handle global media library admin customisations.
	add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu', 1000 );
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\\admin_bar_menu', 1000 );

	// Handle media site URL changes.
	add_action( 'updated_option_siteurl', __NAMESPACE__ . '\\handle_siteurl_update', 10, 2 );
	add_action( 'updated_option_home', __NAMESPACE__ . '\\handle_siteurl_update', 10, 2 );
	add_action( 'wp_update_site', __NAMESPACE__ . '\\handle_site_update' );

	// Do not allow media site deletion.
	add_filter( 'map_meta_cap', __NAMESPACE__ . '\\prevent_site_deletion', 10, 4 );

	// Handle network admin sites list.
	add_filter( 'manage_sites_action_links', __NAMESPACE__ . '\\sites_list_row_actions', 10, 2 );
}

/**
 * Returns true if the current site is the global media site.
 *
 * @param int|null $site_id An optional site ID to check. Defaults to the current site.
 * @return boolean
 */
function is_media_site( ?int $site_id = null ) : bool {
	return ! empty( get_site_meta( $site_id ?? get_current_blog_id(), 'is_media_site' ) );
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

		/**
		 * Filters the args used to create the global media site.
		 *
		 * @param array $media_site_args The arguments array for creating the global media site.
		 */
		$media_site_args = apply_filters( 'altis.media.global_site_args', [
			'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'path' => '/media/',
			'title' => __( 'Global Media Library', 'altis' ),
			'user_id' => get_user_by( 'login', get_super_admins()[0] )->ID,
			'meta' => [
				'is_media_site' => true,
			],
		] );

		$media_site_id = wp_insert_site( $media_site_args );

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
 * Handle deletion of media site.
 *
 * @return void
 */
function uninitialize_media_site() {
	if ( ! is_media_site() ) {
		return;
	}

	delete_site_option( 'global_media_site' );
	delete_site_option( 'global_media_site_id' );
}

/**
 * Trim down the media site's admin menu.
 *
 * @return void
 */
function admin_menu() : void {
	global $menu;

	if ( ! is_media_site() ) {
		return;
	}

	$allowed_menu_pages = [
		'upload.php',
		'users.php',
	];

	/**
	 * Filters the admin menu pages allowed on the Global Media Site admin menu.
	 *
	 * @param array $allowed_menu_pages The page slugs allowed in the global media site admin menu.
	 */
	$allowed_menu_pages = (array) apply_filters( 'altis.media.global_site_menu_pages', [] );

	// Always allow upload.php and users.php.
	$allowed_menu_pages[] = 'upload.php';
	$allowed_menu_pages[] = 'users.php';

	foreach ( $menu as $position => $item ) {
		if ( ! in_array( $item[2], $allowed_menu_pages, true ) ) {
			unset( $menu[ $position ] );
		}
	}
}

/**
 * Modify the admin bar for the media site.
 *
 * @param WP_Admin_Bar $wp_admin_bar The menu bar control.
 * @return void
 */
function admin_bar_menu( WP_Admin_Bar $wp_admin_bar ) : void {

	$wp_admin_bar->remove_node( sprintf( 'blog-%d-n', get_site_option( 'global_media_site_id' ) ) );
	$wp_admin_bar->remove_node( sprintf( 'blog-%d-c', get_site_option( 'global_media_site_id' ) ) );
	$wp_admin_bar->remove_node( sprintf( 'blog-%d-v', get_site_option( 'global_media_site_id' ) ) );

	if ( ! is_media_site() ) {
		return;
	}

	// Remove content and front end related menus.
	$wp_admin_bar->remove_menu( 'new-content' );
	$wp_admin_bar->remove_menu( 'comments' );
	$wp_admin_bar->remove_menu( 'comments' );
	$wp_admin_bar->remove_node( 'view-site' );

	// Add the site title in as static text.
	$wp_admin_bar->add_menu( [
		'id' => 'site-name',
		'title' => get_option( 'blogname' ),
		'href' => false,
	] );
}

/**
 * Filter the site row actions in network admin to prevent deletion.
 *
 * @param array $actions The action links array.
 * @param integer $site_id The site ID.
 * @return array
 */
function sites_list_row_actions( array $actions, int $site_id ) : array {
	if ( ! is_media_site( $site_id ) ) {
		return $actions;
	}

	unset( $actions['deactivate'] );
	unset( $actions['archive'] );
	unset( $actions['delete'] );
	unset( $actions['visit'] );

	return $actions;
}

/**
 * Redirect to the media library from the Global Media Library dashboard.
 *
 * @return void
 */
function redirect_dashboard() : void {
	global $pagenow;

	if ( ! is_media_site() ) {
		return;
	}

	if ( $pagenow !== 'index.php' ) {
		return;
	}

	wp_safe_redirect( admin_url( '/upload.php' ) );
	exit;
}

/**
 * Redirect to the media library from the media site frontend.
 *
 * @return void
 */
function redirect_frontend() : void {
	if ( ! is_media_site() ) {
		return;
	}

	if ( is_admin() ) {
		return;
	}

	wp_safe_redirect( admin_url( '/upload.php' ) );
	exit;
}

/**
 * Update netowrk option on site URL changes.
 *
 * @param string $old_value The old option value.
 * @param string $value The new option value.
 * @return void
 */
function handle_siteurl_update( $old_value, $value ) : void {
	update_site_option( 'global_media_site', untrailingslashit( $value ) );
}

/**
 * Handle updating the global media site URL when edited from the network settings.
 *
 * @param WP_Site $site The site object.
 * @return void
 */
function handle_site_update( WP_Site $site ) : void {
	if ( ! is_media_site( $site->id ) ) {
		return;
	}

	// Build the new site URL as the new value isn't cached yet for get_site_url().
	$new_site_url = set_url_scheme( sprintf( 'https://%s%s', $site->domain, $site->path ) );

	update_site_option( 'global_media_site', untrailingslashit( $new_site_url ) );
}

/**
 * Prevent users deleting the global media site.
 *
 * @param string[] $caps Primitive capabilities required of the user.
 * @param string $cap Capability being checked.
 * @param int $user_id The user ID.
 * @param array $args Adds context to the capability check, typically starting with an object ID.
 * @return string[] Primitive capabilities required of the user.
 */
function prevent_site_deletion( array $caps, string $cap, int $user_id, array $args ) : array {
	if ( $cap !== 'delete_site' ) {
		return $caps;
	}

	if ( ! isset( $args[0] ) || intval( $args[0] ) !== (int) get_site_option( 'global_media_site_id' ) ) {
		return $caps;
	}

	return [ 'do_not_allow' ];
}
