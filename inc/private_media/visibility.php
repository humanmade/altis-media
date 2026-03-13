<?php
/**
 * Private Media — Visibility Logic.
 *
 * Core business logic for determining and setting attachment visibility.
 * Implements the priority check from spec Section 3 and manages ACL updates.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Visibility;

/**
 * Bootstrap visibility hooks.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'add_attachment', __NAMESPACE__ . '\\set_new_attachment_private' );
	add_filter( 's3_uploads_is_attachment_private', __NAMESPACE__ . '\\filter_is_attachment_private', 10, 2 );
	add_filter( 's3_uploads_private_attachment_url_expiry', __NAMESPACE__ . '\\filter_private_url_expiry', 10, 2 );
	add_filter( 'map_meta_cap', __NAMESPACE__ . '\\grant_private_attachment_read', 10, 4 );
}

/**
 * Check if an attachment should be public.
 *
 * Evaluated in priority order; first match wins (spec Section 3):
 * 1. Force-private override → private
 * 2. Force-public override → public
 * 3. Used in a published post → public
 * 4. Legacy attachment → public
 * 5. Site icon → public
 * 6. Default → private
 *
 * @param int $attachment_id The attachment ID.
 * @return bool True if the attachment should be public.
 */
function check_attachment_is_public( int $attachment_id ) : bool {
	$override = get_override( $attachment_id );

	// 1. Force-private override — takes absolute precedence.
	if ( $override === 'private' ) {
		return false;
	}

	// 2. Force-public override.
	if ( $override === 'public' ) {
		return true;
	}

	// 3. Used in a published post.
	$used_in = get_used_in_posts( $attachment_id );
	if ( ! empty( $used_in ) ) {
		return true;
	}

	// 4. Legacy attachment (pre-migration).
	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( is_array( $metadata ) && ! empty( $metadata['legacy_attachment'] ) ) {
		return true;
	}

	// 5. Site icon — check metadata flag.
	if ( is_array( $metadata ) && ! empty( $metadata['site_icon'] ) ) {
		return true;
	}

	// 6. Fallback site icon check via option.
	if ( (int) get_option( 'site_icon' ) === $attachment_id ) {
		return true;
	}

	// 7. Default — private.
	return false;
}

/**
 * Set the visibility of an attachment based on the public check logic.
 *
 * Updates both post_status and S3 ACL.
 *
 * @param int $attachment_id The attachment ID.
 * @return void
 */
function set_attachment_visibility( int $attachment_id ) : void {
	// Invalidate the per-request cache so the fresh check below is accurate.
	is_attachment_private_cached( $attachment_id, true );

	$is_public = check_attachment_is_public( $attachment_id );
	$new_status = $is_public ? 'publish' : 'private';
	$new_acl = $is_public ? 'public-read' : 'private';

	$current_status = get_post_status( $attachment_id );

	if ( $current_status !== $new_status ) {
		wp_update_post( [
			'ID'          => $attachment_id,
			'post_status' => $new_status,
		] );
	}

	update_s3_acl( $attachment_id, $new_acl );
	purge_cdn_cache( $attachment_id );
}

/**
 * Update the S3 ACL for an attachment.
 *
 * Filterable via 'private_media/update_s3_acl' for testing — if the filter
 * returns non-null, the real S3 call is skipped.
 *
 * @param int    $attachment_id The attachment ID.
 * @param string $acl           The ACL to set ('public-read' or 'private').
 * @return void
 */
function update_s3_acl( int $attachment_id, string $acl ) : void {
	/**
	 * Filter to intercept S3 ACL updates (test seam).
	 *
	 * Return a non-null value to short-circuit the real S3 call.
	 *
	 * @param null|mixed $result        Return non-null to short-circuit.
	 * @param int        $attachment_id The attachment ID.
	 * @param string     $acl           The ACL being set.
	 */
	$result = apply_filters( 'private_media/update_s3_acl', null, $attachment_id, $acl );
	if ( $result !== null ) {
		return;
	}

	if ( ! class_exists( '\\S3_Uploads\\Plugin' ) ) {
		return;
	}

	\S3_Uploads\Plugin::get_instance()->set_attachment_files_acl( $attachment_id, $acl );
}

/**
 * Purge CDN cache for an attachment.
 *
 * Filterable via 'private_media/purge_cdn_cache' for testing.
 *
 * @param int $attachment_id The attachment ID.
 * @return void
 */
function purge_cdn_cache( int $attachment_id ) : void {
	/**
	 * Filter to intercept CDN cache purge (test seam).
	 *
	 * Return a non-null value to short-circuit.
	 *
	 * @param null|mixed $result        Return non-null to short-circuit.
	 * @param int        $attachment_id The attachment ID.
	 */
	$result = apply_filters( 'private_media/purge_cdn_cache', null, $attachment_id );
	if ( $result !== null ) {
		return;
	}

	/**
	 * Action fired when CDN cache should be purged for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	do_action( 'private_media/do_purge_cdn_cache', $attachment_id );
}

/**
 * Set newly uploaded attachments to private status.
 *
 * Hooked to 'add_attachment'.
 *
 * @param int $attachment_id The new attachment ID.
 * @return void
 */
function set_new_attachment_private( int $attachment_id ) : void {
	wp_update_post( [
		'ID'          => $attachment_id,
		'post_status' => 'private',
	] );

	update_s3_acl( $attachment_id, 'private' );
}

/**
 * Get the manual override visibility for an attachment.
 *
 * @param int $attachment_id The attachment ID.
 * @return string 'auto', 'public', or 'private'.
 */
function get_override( int $attachment_id ) : string {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) || empty( $metadata['altis_override_visibility'] ) ) {
		return 'auto';
	}

	$override = $metadata['altis_override_visibility'];
	if ( in_array( $override, [ 'public', 'private' ], true ) ) {
		return $override;
	}

	return 'auto';
}

/**
 * Set the manual override visibility for an attachment.
 *
 * @param int    $attachment_id The attachment ID.
 * @param string $override      'auto', 'public', or 'private'.
 * @return void
 */
function set_override( int $attachment_id, string $override ) : void {
	if ( ! in_array( $override, [ 'auto', 'public', 'private' ], true ) ) {
		return;
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) ) {
		$metadata = [];
	}

	if ( $override === 'auto' ) {
		unset( $metadata['altis_override_visibility'] );
	} else {
		$metadata['altis_override_visibility'] = $override;
	}

	wp_update_attachment_metadata( $attachment_id, $metadata );
	set_attachment_visibility( $attachment_id );
}

/**
 * Add a post reference to an attachment's metadata.
 *
 * Records that the given post uses this attachment.
 *
 * @param int $attachment_id The attachment ID.
 * @param int $post_id       The post ID that references this attachment.
 * @return void
 */
function add_post_reference( int $attachment_id, int $post_id ) : void {
	$used_in = get_used_in_posts( $attachment_id );

	if ( ! in_array( $post_id, $used_in, true ) ) {
		$used_in[] = $post_id;
		set_used_in_posts( $attachment_id, $used_in );
	}
}

/**
 * Remove a post reference from an attachment's metadata.
 *
 * @param int $attachment_id The attachment ID.
 * @param int $post_id       The post ID to remove.
 * @return void
 */
function remove_post_reference( int $attachment_id, int $post_id ) : void {
	$used_in = get_used_in_posts( $attachment_id );
	$used_in = array_values( array_diff( $used_in, [ $post_id ] ) );
	set_used_in_posts( $attachment_id, $used_in );
}

/**
 * Get the list of published post IDs that reference this attachment.
 *
 * @param int $attachment_id The attachment ID.
 * @return int[] Array of post IDs.
 */
function get_used_in_posts( int $attachment_id ) : array {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) || empty( $metadata['altis_used_in_published_post'] ) ) {
		return [];
	}

	return array_map( 'intval', (array) $metadata['altis_used_in_published_post'] );
}

/**
 * Set the list of published post IDs that reference this attachment.
 *
 * @param int   $attachment_id The attachment ID.
 * @param int[] $post_ids      Array of post IDs.
 * @return void
 */
function set_used_in_posts( int $attachment_id, array $post_ids ) : void {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) ) {
		$metadata = [];
	}

	if ( empty( $post_ids ) ) {
		unset( $metadata['altis_used_in_published_post'] );
	} else {
		$metadata['altis_used_in_published_post'] = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
	}

	wp_update_attachment_metadata( $attachment_id, $metadata );
}

/**
 * Grant read access to private attachments for users who can upload files.
 *
 * WordPress maps 'read_post' for private posts to 'read_private_posts', which
 * authors and contributors lack. This filter downgrades the capability requirement
 * for private attachments so that any user with 'upload_files' can read them.
 * Without this, wp_get_attachment_image(), wp_get_attachment_metadata(), and
 * set_post_thumbnail() all fail for non-admin users.
 *
 * @param string[] $caps    Required capabilities.
 * @param string   $cap     The capability being checked.
 * @param int      $user_id The user ID.
 * @param array    $args    Additional arguments (first element is the post ID).
 * @return string[] Modified capabilities.
 */
function grant_private_attachment_read( array $caps, string $cap, int $user_id, array $args ) : array {
	if ( $cap !== 'read_post' || empty( $args[0] ) ) {
		return $caps;
	}

	$post = get_post( $args[0] );

	// Only apply to private attachments — never modify caps for posts, pages, etc.
	if ( $post && $post->post_type === 'attachment' && $post->post_status === 'private' ) {
		// Replace 'read_private_posts' with 'upload_files' — any user who can
		// upload media should be able to read private attachments.
		return array_map( function ( $required_cap ) {
			return $required_cap === 'read_private_posts' ? 'upload_files' : $required_cap;
		}, $caps );
	}

	return $caps;
}

/**
 * Per-request cache for attachment privacy checks.
 *
 * Avoids repeated DB lookups when S3 Uploads calls the filter for every
 * URL of every image size (e.g. 40 attachments × 5 sizes = 200 calls
 * per media library page load). Accepts an optional second parameter to
 * clear a specific entry when visibility changes within the same request.
 *
 * @param int       $attachment_id The attachment ID.
 * @param bool|null $invalidate    Pass true to clear the cached entry.
 * @return bool|null True if private, false if public, null on invalidation.
 */
function is_attachment_private_cached( int $attachment_id, ?bool $invalidate = null ) : ?bool {
	static $cache = [];

	if ( $invalidate ) {
		unset( $cache[ $attachment_id ] );
		return null;
	}

	if ( ! isset( $cache[ $attachment_id ] ) ) {
		$cache[ $attachment_id ] = ! check_attachment_is_public( $attachment_id );
	}

	return $cache[ $attachment_id ];
}

/**
 * Filter callback for S3 Uploads private attachment check.
 *
 * @param bool $is_private Whether the attachment is private.
 * @param int  $attachment_id The attachment ID.
 * @return bool
 */
function filter_is_attachment_private( bool $is_private, int $attachment_id ) : bool {
	return is_attachment_private_cached( $attachment_id ) ? true : $is_private;
}

/**
 * Filter callback for signed URL expiry.
 *
 * @param string $expiry      The expiry string.
 * @param int    $attachment_id The attachment ID.
 * @return string
 */
function filter_private_url_expiry( string $expiry, int $attachment_id ) : string {
	return '+15 minutes';
}
