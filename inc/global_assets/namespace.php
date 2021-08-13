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
use WP_Post;

use function Altis\Security\Network_Tokens\create_network_token;
use function Altis\Security\Network_Tokens\verify_network_token;

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

	// Allow read access to all network users.
	add_filter( 'amf/wordpress/request_args', __NAMESPACE__ . '\\noncify_global_assets_requests' );
	add_filter( 'user_has_cap', __NAMESPACE__ . '\\allow_global_assets_read_access', 10, 2 );
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

/**
 * Add network-wide tokens/nonces to global assets requests.
 *
 * This helps connecting private global content site from within the same network.
 *
 * @param array $args HTTP Request arguments.
 *
 * @filters amf/wordpress/request_args
 *
 * @return array
 */
function noncify_global_assets_requests( array $args ) : array {
	if ( ! Global_Content\is_global_site() ) {
		$args['headers']['X-ALTIS-NETWORK-TOKEN'] = create_network_token( 'global-media-request' );
	}

	return $args;
}

/**
 * Checks for network-wide tokens/nonces within the global content site, to allow unauthenticated internal
 * requests to the REST API without having to login.
 *
 * @param array $allcaps
 * @param array $caps
 *
 * @filters user_has_cap
 *
 * @return array
 */
function allow_global_assets_read_access( array $allcaps, array $caps ) : array {
	$token = $_SERVER['HTTP_X_ALTIS_NETWORK_TOKEN'] ?? null;

	if (
		! $token
		|| $caps !== [ 'read' ]
		|| is_user_logged_in()
		|| ! Global_Content\is_global_site()
	) {
		return $allcaps;
	}

	if ( verify_network_token( $token, 'global-media-request' ) ) {
		$allcaps['read'] = true;
	}

	return $allcaps;
}
