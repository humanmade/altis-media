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
use AMFWordPress\Factory;
use AssetManagerFramework\Provider;
use WP_Application_Passwords;
use WP_Post;

/**
 * Setup global media hooks.
 *
 * @return void
 */
function bootstrap() {
	$config = Altis\get_config()['modules']['media'];

	// Configure local media provider for AMF.
	if ( empty( $config['local-media-library'] ) && ! defined( 'AMF_ALLOW_LOCAL_MEDIA' ) ) {
		define( 'AMF_ALLOW_LOCAL_MEDIA', false );
	}

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

	// Use Tachyon Provider.
	if ( $config['tachyon'] ) {
		add_filter( 'amf/provider', __NAMESPACE__ . '\\use_tachyon_provider' );
	}

	// Configure the global media site.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\configure_site', 9 );
	add_filter( 'altis.core.global_content_site_menu_pages', __NAMESPACE__ . '\\allow_media_pages' );

	// Configure the WP provider name.
	add_filter( 'amf/wordpress/provider_name', __NAMESPACE__ . '\\set_global_site_provider_name' );

	// Add link to global content site.
	add_action( 'pre-plupload-upload-ui', __NAMESPACE__ . '\\pre_upload_global_site_link' );

	// Global site additions.
	add_filter( 'media_meta', __NAMESPACE__ . '\\add_global_site_link', 20, 2 );
	add_filter( 'media_view_strings', __NAMESPACE__ . '\\filter_media_view_strings', 10 );

	// Permissions.
	add_filter( 'map_meta_cap', __NAMESPACE__ . '\\set_permissions', 10, 4 );

	add_filter( 'http_request_args', __NAMESPACE__ . '\\filter_http_request_args', 10, 2 );
}

/**
 * Filters the arguments used in an HTTP request.
 *
 * @param array  $parsed_args An array of HTTP request arguments.
 * @param string $url         The request URL.
 * @return array An array of HTTP request arguments.
 */
function filter_http_request_args( array $parsed_args, string $url ) : array {
	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
		return $parsed_args;
	}

	$user = wp_get_current_user();

	if ( ! wp_is_application_passwords_available_for_user( $user->ID ) ) {
		return $parsed_args;
	}

	if ( strpos( $url, rtrim( Global_Content\get_site_url(), '/' ) . '/wp-json/wp/v2/media' ) !== 0 ) {
		return $parsed_args;
	}

	// Ensure user has credentials on the Global Repo Site.
	if ( ! is_user_member_of_blog( $user->ID, Global_Content\get_site_id() ) ) {
		add_user_to_blog( Global_Content\get_site_id(), $user->ID, 'subscriber' );
	}

	// Add WP Nonce authentication.
	if ( ! isset( $parsed_args['headers']['authorization'] ) ) {
		// Create or get application password.
		if ( WP_Application_Passwords::application_name_exists_for_user( $user->ID, 'Global Content Repository' ) ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $password ) {
				if ( $password['name'] === 'Global Content Repository' ) {
					WP_Application_Passwords::delete_application_password( $user->ID, $password['uuid'] );
				}
			}
		}

		[ $app_password, $item ] = WP_Application_Passwords::create_new_application_password( $user->ID, [
			'name' => 'Global Content Repository',
		] );

		$parsed_args['headers']['authorization'] = sprintf(
			'Basic %s',
			base64_encode( $user->user_login . ':' . WP_Application_Passwords::chunk_password( $app_password ) )
		);
	}

	return $parsed_args;
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
 * Overrride the WordPress AMF Provider.
 *
 * @param Provider $provider The registered provider.
 * @return Provider
 */
function use_tachyon_provider( Provider $provider ) : Provider {
	// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
	if ( $provider->get_id() !== 'wordpress' ) {
		return $provider;
	}

	return new Tachyon_Provider( new Factory() );
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
 * @return string
 */
function set_global_site_provider_name() : string {
	return __( 'Global Media Library', 'altis' );
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

/**
 * Add a link to Global Media in the upload UI.
 */
function pre_upload_global_site_link() : void {
	if ( Global_Content\is_global_site() ) {
		return;
	}

	printf(
		'<p><a class="components-button is-link" href="%s">%s</a></p>',
		get_admin_url( Global_Content\get_site_id(), '/upload.php' ),
		esc_html__( 'Switch to Global Media Library', 'altis' )
	);
}

/**
 * Check if an attachment is from the global media library.
 *
 * @param integer $attachment_id The attachment ID to check.
 * @return boolean
 */
function is_global_asset( int $attachment_id ) : bool {
	// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
	return get_post_meta( $attachment_id, '_amf_provider', true ) === 'wordpress';
}

/**
 * Add a link to the media on the global site.
 *
 * @param string $media_meta_html The HTML markup containing the media dimensions.
 * @param WP_Post $attachment The WP_Post attachment object.
 * @return string The HTML markup containing the media dimensions.
 */
function add_global_site_link( string $media_meta_html, WP_Post $attachment ) : string {
	if ( Global_Content\is_global_site() ) {
		return $media_meta_html;
	}

	if ( ! is_global_asset( $attachment->ID ) ) {
		return $media_meta_html;
	}

	// Get global site media URL.
	$media_id = intval( str_replace( 'amf-', '', $attachment->post_name ) );
	$media_url = add_query_arg(
		[ 'item' => $media_id ],
		get_site_url( Global_Content\get_site_id(), '/wp-admin/upload.php', 'admin' )
	);

	$media_meta_html .= sprintf(
		'<div class="global-library-link"><a href="%s">%s</a></div>',
		$media_url,
		esc_html__( 'View in Global Media Library' )
	);

	return $media_meta_html;
}

/**
 * Filters the media view strings.
 *
 * @param string[] $strings Array of media view strings keyed by the name they'll be referenced by in JavaScript.
 * @return string[] Array of media view strings keyed by the name they'll be referenced by in JavaScript.
 */
function filter_media_view_strings( array $strings ) : array {
	if ( ! Global_Content\is_global_site() ) {
		return $strings;
	}

	$strings['warnDelete'] = __( "You are about to permanently delete this item, but it could be in use on other sites.\nThis action cannot be undone.\n 'Cancel' to stop, 'OK' to delete.", 'altis' );
	$strings['warnBulkDelete'] = __( "You are about to permanently delete these items, but they could be in use on other sites.\nThis action cannot be undone.\n 'Cancel' to stop, 'OK' to delete.", 'altis' );
	$strings['warnBulkTrash'] = __( "You are about to trash these items but they could be in use on other sites.\n  'Cancel' to stop, 'OK' to delete.", 'altis' );

	return $strings;
}

/**
 * Handle permissions for Global Media assets.
 *
 * @param string[] $caps Primitive capabilities required of the user.
 * @param string $cap Capability being checked.
 * @param int $user_id The user ID.
 * @param array $args Adds context to the capability check, typically
 *                    starting with an object ID.
 * @return string[] Primitive capabilities required of the user.
 */
function set_permissions( array $caps, string $cap, int $user_id, array $args ) : array {
	if ( Global_Content\is_global_site() ) {
		return $caps;
	}

	$caps_to_check = [ 'delete_post' ];

	if ( ! in_array( $cap, $caps_to_check, true ) ) {
		return $caps;
	}

	if ( empty( $args ) || ! is_int( $args[0] ) ) {
		return $caps;
	}

	$post_id = $args[0];
	if ( get_post_type( $post_id ) !== 'attachment' ) {
		return $caps;
	}

	if ( ! is_global_asset( $post_id ) ) {
		return $caps;
	}

	return [ 'do_not_allow' ];
}
