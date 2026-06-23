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

use Altis\Cloud\Cloudfront_Media_Purge;
use S3_Uploads\Plugin;

/**
 * Post meta key storing the S3 ACL state for an attachment.
 *
 * Values: `'private'` or `'public-read'` — the literal S3 ACL strings.
 * Absent meta means the attachment predates the feature.
 */
const META_KEY = '_altis_media_acl';

/**
 * Bootstrap visibility hooks.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'add_attachment', __NAMESPACE__ . '\\set_new_attachment_private', 0 );
	add_filter( 's3_uploads_is_attachment_private', __NAMESPACE__ . '\\filter_is_attachment_private', 10, 2 );
	add_filter( 's3_uploads_private_attachment_url_expiry', __NAMESPACE__ . '\\filter_private_url_expiry', 10, 2 );

	// Default consumers of attachment_visibility_changed: write the S3 ACL,
	// then invalidate any CDN-cached copies. Either can be removed by an
	// integration that wants to do something different.
	add_action( 'altis.media.private_media.attachment_visibility_changed', __NAMESPACE__ . '\\update_s3_acl', 10, 2 );
	add_action( 'altis.media.private_media.attachment_visibility_changed', __NAMESPACE__ . '\\purge_cdn_for_attachment', 10, 1 );
}

/**
 * Check if an attachment should be public.
 *
 * Evaluated in priority order; first match wins (spec Section 3):
 * 1. Force-private override → private
 * 2. Force-public override → public
 * 3. Used in a published post → public
 * 4. Legacy attachment → public
 * 5. Site icon (metadata flag or option) → public
 * 6. No `_altis_media_acl` meta (predates the feature) → public
 * 7. Default → private
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

	return compute_automatic_visibility( $attachment_id );
}

/**
 * Compute what the automatic visibility would be for an attachment.
 *
 * Same priority rules as check_attachment_is_public(), but skips the override
 * checks. Used to show the "currently Public/Private" hint on the Automatic
 * dropdown option in the media modal.
 *
 * @param int $attachment_id The attachment ID.
 * @return bool True if the attachment would be public under automatic rules.
 */
function compute_automatic_visibility( int $attachment_id ) : bool {
	// 3. Used in a published post.
	$used_in = get_used_in_posts( $attachment_id );
	if ( ! empty( $used_in ) ) {
		return true;
	}

	// 4. Legacy attachment (pre-migration).
	// Use unfiltered read to avoid stale static caches in third-party filters.
	$metadata = wp_get_attachment_metadata( $attachment_id, true );
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

	// 7. No _altis_media_acl meta — pre-feature upload.
	// New uploads get the meta set to 'private' immediately by set_new_attachment_private(),
	// and set_attachment_visibility() writes the meta on every transition. An attachment
	// with no meta has never been touched by the feature, so treat as public to avoid
	// breaking content that predates the rollout.
	if ( get_post_meta( $attachment_id, META_KEY, true ) === '' ) {
		return true;
	}

	// 8. Default — private.
	return false;
}

/**
 * Set the visibility of an attachment based on the public check logic.
 *
 * Writes the ACL meta and updates the S3 ACL. Does not touch post_status —
 * attachments stay at WP's default `inherit` so the cap system, admin queries,
 * and third-party plugins behave normally.
 *
 * @param int $attachment_id The attachment ID.
 * @return void
 */
function set_attachment_visibility( int $attachment_id ) : void {
	// Invalidate the per-request cache so the fresh check below is accurate.
	is_attachment_private_cached( $attachment_id, true );

	$is_public = check_attachment_is_public( $attachment_id );
	$new_acl = $is_public ? 'public-read' : 'private';

	update_post_meta( $attachment_id, META_KEY, $new_acl );

	/**
	 * Fires after an attachment's visibility has been re-evaluated and the
	 * `_altis_media_acl` meta has been written. Default consumers update
	 * the S3 ACL and invalidate the CDN cache; either can be replaced by
	 * unhooking and registering a different callback.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $new_acl       The new ACL ('public-read' or 'private').
	 */
	do_action( 'altis.media.private_media.attachment_visibility_changed', $attachment_id, $new_acl );
}

/**
 * Update the S3 ACL for an attachment.
 *
 * The ACL string is run through the `altis.media.private_media.s3_acl`
 * filter before being applied — return an empty string to skip the S3
 * call entirely (used by the test mock to record-and-skip), or return a
 * different ACL to override the value being set.
 *
 * @param int    $attachment_id The attachment ID.
 * @param string $acl           The ACL to set ('public-read' or 'private').
 * @return void
 */
function update_s3_acl( int $attachment_id, string $acl ) : void {
	/**
	 * Filter the S3 ACL string before it is applied.
	 *
	 * Return an empty string to skip the real S3 call. Return a different
	 * non-empty value to override the ACL being written.
	 *
	 * @param string $acl           The ACL ('public-read' or 'private').
	 * @param int    $attachment_id The attachment ID.
	 */
	$acl = apply_filters( 'altis.media.private_media.s3_acl', $acl, $attachment_id );
	if ( $acl === '' ) {
		return;
	}

	Plugin::get_instance()->set_attachment_files_acl( $attachment_id, $acl );
}

/**
 * Invalidate the CDN cache for an attachment.
 *
 * Default consumer of `altis.media.private_media.attachment_visibility_changed`.
 * Delegates to the Altis Cloud media-purge function when CloudFront is
 * actually configured for this environment — local dev, CI, and non-Cloud
 * installs have nowhere to invalidate, so this is a no-op there.
 *
 * @param int $attachment_id The attachment ID.
 * @return void
 */
function purge_cdn_for_attachment( int $attachment_id ) : void {
	if ( ! defined( 'CLOUDFRONT_DISTRIBUTION_ID' ) ) {
		return;
	}

	if ( ! function_exists( '\\Altis\\Cloud\\Cloudfront_Media_Purge\\purge_media_file_cache' ) ) {
		return;
	}

	Cloudfront_Media_Purge\purge_media_file_cache( $attachment_id );
}

/**
 * Set newly uploaded attachments to private.
 *
 * Hooked to 'add_attachment'.
 *
 * @param int $attachment_id The new attachment ID.
 * @return void
 */
function set_new_attachment_private( int $attachment_id ) : void {
	update_post_meta( $attachment_id, META_KEY, 'private' );
	update_s3_acl( $attachment_id, 'private' );
}

/**
 * Get the manual override visibility for an attachment.
 *
 * @param int $attachment_id The attachment ID.
 * @return string 'auto', 'public', or 'private'.
 */
function get_override( int $attachment_id ) : string {
	// Use unfiltered read to avoid stale static caches in third-party filters.
	$metadata = wp_get_attachment_metadata( $attachment_id, true );
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

	// Use unfiltered read to avoid stale static caches in third-party filters.
	$metadata = wp_get_attachment_metadata( $attachment_id, true );
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
	// Use unfiltered read to avoid stale static caches in third-party filters.
	$metadata = wp_get_attachment_metadata( $attachment_id, true );
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
	// Use unfiltered read to avoid stale static caches in third-party filters.
	$metadata = wp_get_attachment_metadata( $attachment_id, true );
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
