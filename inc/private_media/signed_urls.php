<?php
/**
 * Private Media — Signed URL Previews.
 *
 * Replaces private media URLs with signed S3 URLs in preview contexts
 * and disables srcset generation for previews.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Signed_Urls;

use Altis\Media\Private_Media\Content_Parser;
use Altis\Media\Private_Media\Visibility;

/**
 * Bootstrap signed URL hooks.
 *
 * @return void
 */
function bootstrap() {
	add_filter( 'the_content', __NAMESPACE__ . '\\replace_private_urls_in_preview' );

	// Register REST filters for all post types with editor support.
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_filters' );

	add_filter( 'wp_calculate_image_srcset', __NAMESPACE__ . '\\disable_srcset_in_preview', 10, 1 );

	// Rewrite presigned URLs to use the canonical S3 endpoint so the
	// signature matches the host the request will be sent to.
	add_filter( 's3_uploads_presigned_url', __NAMESPACE__ . '\\rewrite_presigned_url_to_canonical_s3', 999, 2 );
}

/**
 * Replace private media URLs with signed S3 URLs in preview mode.
 *
 * Only runs on is_preview() context.
 *
 * @param string $content The post content.
 * @return string Content with signed URLs.
 */
function replace_private_urls_in_preview( string $content ) : string {
	// DEBUG: temporary logging.
	error_log( sprintf(
		'[Private Media] replace_private_urls_in_preview: is_preview=%s',
		is_preview() ? 'true' : 'false'
	) );

	if ( ! is_preview() ) {
		return $content;
	}

	return replace_private_urls( $content );
}

/**
 * Register REST API filters for signed URLs in draft/future posts.
 *
 * @return void
 */
function register_rest_filters() {
	$post_types = get_post_types_by_support( 'editor' );

	foreach ( $post_types as $post_type ) {
		add_filter( "rest_prepare_{$post_type}", __NAMESPACE__ . '\\sign_rest_content', 10, 2 );
	}
}

/**
 * Add signed URLs to REST API response content for drafts/future posts.
 *
 * @param \WP_REST_Response $response The REST response.
 * @param \WP_Post          $post     The post object.
 * @return \WP_REST_Response
 */
function sign_rest_content( \WP_REST_Response $response, \WP_Post $post ) : \WP_REST_Response {
	// Only sign URLs for non-published posts.
	if ( $post->post_status === 'publish' ) {
		return $response;
	}

	$data = $response->get_data();

	// Sign URLs in raw content so the block editor can display private
	// images. The sanitisation module strips AWS params on save, so
	// signed URLs are never persisted to the database.
	if ( ! empty( $data['content']['raw'] ) ) {
		$data['content']['raw'] = replace_private_urls( $data['content']['raw'] );
		$response->set_data( $data );
	}

	return $response;
}

/**
 * Replace private media URLs in content with signed S3 URLs.
 *
 * Images are signed then passed through tachyon_url() so Tachyon receives
 * the AWS params directly. Non-image files (PDFs, videos, etc.) use the
 * signed URL directly.
 *
 * @param string $content The content to process.
 * @return string Content with private URLs replaced by signed ones.
 */
function replace_private_urls( string $content ) : string {
	if ( ! class_exists( '\\S3_Uploads\\Plugin' ) ) {
		return $content;
	}

	$attachments = Content_Parser\extract_attachments_from_content( $content );

	// DEBUG: temporary logging.
	error_log( sprintf(
		'[Private Media] replace_private_urls: found %d attachments, caller=%s',
		count( $attachments ),
		wp_debug_backtrace_summary( null, 0, false )[3] ?? 'unknown'
	) );

	foreach ( $attachments as $attachment ) {
		$attachment_id = $attachment['attachment_id'];

		// Only sign URLs for private attachments.
		if ( Visibility\check_attachment_is_public( $attachment_id ) ) {
			error_log( sprintf( '[Private Media]   attachment %d: skipped (public)', $attachment_id ) );
			continue;
		}

		// wp_get_attachment_url() already returns a signed URL for private
		// attachments — S3 Uploads hooks into the filter and signs via
		// get_s3_location_for_url(). For non-images, the
		// rewrite_presigned_url_to_canonical_s3 filter then rewrites the
		// host to the canonical S3 endpoint, which can't be re-resolved
		// by get_s3_location_for_url(). So we use the already-signed URL
		// directly instead of stripping params and re-signing.
		$signed_url = (string) wp_get_attachment_url( $attachment_id );

		// DEBUG: temporary logging.
		error_log( sprintf(
			'[Private Media]   attachment %d: modified_url=%s, signed_url=%s',
			$attachment_id,
			substr( $attachment['modified_url'], 0, 100 ),
			substr( $signed_url, 0, 100 )
		) );

		if ( $signed_url !== $attachment['modified_url'] ) {
			// Route images through Tachyon so it can handle S3 auth.
			// Pass the signed S3 URL directly to tachyon_url() so that
			// Tachyon receives the AWS params as top-level query params.
			if ( function_exists( 'tachyon_url' ) && wp_attachment_is_image( $attachment_id ) ) {
				$signed_url = tachyon_url( $signed_url );
			}

			$replaced = str_replace( $attachment['modified_url'], $signed_url, $content );
			$did_replace = $replaced !== $content;
			$content = $replaced;

			error_log( sprintf(
				'[Private Media]   attachment %d: str_replace matched=%s, signed_url=%s',
				$attachment_id,
				$did_replace ? 'YES' : 'NO',
				substr( $signed_url, 0, 150 )
			) );
		}
	}

	return $content;
}

/**
 * Disable srcset generation in preview mode.
 *
 * Signed URLs are unique per size and cannot work with responsive image switching.
 *
 * @param array $sources Array of image source data.
 * @return array Empty array in preview mode, original sources otherwise.
 */
function disable_srcset_in_preview( array $sources ) : array {
	if ( is_preview() ) {
		return [];
	}

	// Also disable in REST context for non-published posts.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return [];
	}

	return $sources;
}

/**
 * Rewrite a presigned URL to use the canonical S3 endpoint.
 *
 * The AWS SDK signs against the canonical S3 host
 * (bucket.s3.region.amazonaws.com) but the URL passed through
 * `s3_uploads_presigned_url` may use a CDN or custom hostname.
 * Replacing the host ensures the signature matches the request.
 *
 * @param string $url     The presigned URL.
 * @param int    $post_id The attachment post ID.
 * @return string URL rewritten to the canonical S3 endpoint, or unchanged for images.
 */
function rewrite_presigned_url_to_canonical_s3( string $url, int $post_id ) : string {
	// Images are served through Tachyon, which handles S3 auth itself.
	if ( wp_attachment_is_image( $post_id ) ) {
		return $url;
	}

	if ( ! class_exists( '\\S3_Uploads\\Plugin' ) ) {
		return $url;
	}

	$instance = \S3_Uploads\Plugin::get_instance();
	$bucket   = $instance->get_s3_bucket();
	$region   = $instance->get_s3_bucket_region();
	$s3_host  = sprintf( '%s.s3.%s.amazonaws.com', $bucket, $region ?: 'us-east-1' );

	// S3_UPLOADS_BUCKET may include a path prefix after the bucket name
	// (e.g. "hmn-uploads-eu/platform-test"). The AWS SDK signs against the
	// full S3 key including this prefix, so we must include it in the URL
	// path for the signature to match.
	$bucket_path_prefix = defined( 'S3_UPLOADS_BUCKET' )
		? substr( S3_UPLOADS_BUCKET, strlen( $bucket ) )
		: '';

	$parts = wp_parse_url( $url );

	return sprintf(
		'https://%s%s%s%s',
		$s3_host,
		$bucket_path_prefix,
		$parts['path'] ?? '',
		isset( $parts['query'] ) ? '?' . $parts['query'] : ''
	);
}

