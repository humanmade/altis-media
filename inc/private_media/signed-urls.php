<?php
/**
 * Private Media — Signed URL Previews.
 *
 * Replaces private media URLs with signed S3 URLs in preview contexts
 * and disables srcset generation for previews.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Signed_URLs;

use Altis\Media\Private_Media\Content_Parser;
use Altis\Media\Private_Media\Visibility;
use S3_Uploads\Plugin;
use WP_Post;
use WP_REST_Response;

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

	// Route attachment-link hrefs for private images through Tachyon —
	// Tachyon's the_content filter only rewrites <img src>, not <a href>.
	add_filter( 'wp_get_attachment_link_attributes', __NAMESPACE__ . '\\sign_attachment_link_href', 10, 2 );
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
 * @param WP_REST_Response $response The REST response.
 * @param WP_Post          $post     The post object.
 * @return WP_REST_Response
 */
function sign_rest_content( WP_REST_Response $response, WP_Post $post ) : WP_REST_Response {
	// Only sign URLs for non-published posts.
	if ( $post->post_status === 'publish' ) {
		return $response;
	}

	$data = $response->get_data();

	// Sign URLs in raw content so the block editor can display private
	// images. The sanitization module strips AWS params on save, so
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
 * When Tachyon is available, images are wrapped via tachyon_url() and
 * Tachyon handles S3 auth itself. When Tachyon is not available, the
 * signed URL is rewritten to the canonical S3 host by
 * rewrite_presigned_url_to_canonical_s3() so the AWS signature matches.
 *
 * @param string $content The content to process.
 * @return string Content with private URLs replaced by signed ones.
 */
function replace_private_urls( string $content ) : string {
	$attachments = Content_Parser\extract_attachments_from_content( $content );

	foreach ( $attachments as $attachment ) {
		$attachment_id = $attachment['attachment_id'];

		// Only sign URLs for private attachments.
		if ( Visibility\check_attachment_is_public( $attachment_id ) ) {
			continue;
		}

		// wp_get_attachment_url() returns a signed URL for private attachments
		// via S3 Uploads' filter. rewrite_presigned_url_to_canonical_s3 then
		// rewrites the host to canonical S3 when Tachyon isn't available, so
		// the signature matches. Use it directly rather than re-signing.
		$signed_url = (string) wp_get_attachment_url( $attachment_id );

		if ( $signed_url !== $attachment['modified_url'] ) {
			// When Tachyon is available, route images through it so its
			// image processing (resize, format conversion) still applies.
			if ( function_exists( 'tachyon_url' ) && wp_attachment_is_image( $attachment_id ) ) {
				$signed_url = tachyon_url( $signed_url );
			}

			// Replace unescaped URLs in HTML attributes.
			$content = str_replace( $attachment['modified_url'], $signed_url, $content );

			// Also replace JSON-escaped URLs in block comments.
			// Gutenberg stores URLs with escaped slashes (e.g. \/) in block
			// attributes. The block editor reads from these, so we must sign them too.
			$escaped_original = str_replace( '/', '\\/', $attachment['modified_url'] );
			$escaped_signed = str_replace( '/', '\\/', $signed_url );
			$content = str_replace( $escaped_original, $escaped_signed, $content );
		}
	}

	$content = replace_private_poster_urls( $content );

	return $content;
}

/**
 * Replace private video poster URLs with signed versions.
 *
 * Video blocks store a poster URL in both an HTML attribute and a JSON
 * block comment attribute. This function signs both occurrences.
 *
 * @param string $content The content to process.
 * @return string Content with signed poster URLs.
 */
function replace_private_poster_urls( string $content ) : string {
	if ( ! preg_match_all( '/poster="([^"]+)"/', $content, $matches, PREG_SET_ORDER ) ) {
		return $content;
	}

	foreach ( $matches as $match ) {
		$poster_url = $match[1];
		$clean = Content_Parser\clean_url( $poster_url );
		$poster_id = attachment_url_to_postid( $clean );

		if ( $poster_id <= 0 || Visibility\check_attachment_is_public( $poster_id ) ) {
			continue;
		}

		$signed_url = (string) wp_get_attachment_url( $poster_id );

		if ( function_exists( 'tachyon_url' ) ) {
			$signed_url = tachyon_url( $signed_url );
		}

		// Replace in HTML attributes.
		$content = str_replace( $poster_url, $signed_url, $content );

		// Replace JSON-escaped form in block comments.
		$escaped_original = str_replace( '/', '\\/', $poster_url );
		$escaped_signed = str_replace( '/', '\\/', $signed_url );
		$content = str_replace( $escaped_original, $escaped_signed, $content );
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
 * Route the href of an attachment link for a private image through Tachyon.
 *
 * @param array $attributes Link attributes, including `href`.
 * @param int   $attachment_id Attachment post ID.
 * @return array Attributes with href rewritten when applicable.
 */
function sign_attachment_link_href( array $attributes, int $attachment_id ) : array {
	if ( ! function_exists( 'tachyon_url' ) ) {
		return $attributes;
	}

	if ( empty( $attributes['href'] ) ) {
		return $attributes;
	}

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $attributes;
	}

	if ( Visibility\check_attachment_is_public( $attachment_id ) ) {
		return $attributes;
	}

	$attributes['href'] = tachyon_url( $attributes['href'] );

	return $attributes;
}

/**
 * Rewrite a presigned URL to use the canonical S3 endpoint.
 *
 * The AWS SDK signs against the canonical S3 host
 * (bucket.s3.region.amazonaws.com) but the URL passed through
 * `s3_uploads_presigned_url` may use a CDN or custom hostname.
 * Replacing the host ensures the signature matches the request.
 *
 * Skipped for images when Tachyon is enabled — Tachyon handles S3 auth
 * itself, so the URL is left alone whether the image is a direct
 * attachment or a sub-file of a non-image attachment (PDF preview JPEG,
 * video poster).
 *
 * @param string $url     The presigned URL.
 * @param int    $post_id The attachment post ID.
 * @return string URL rewritten to the canonical S3 endpoint, or unchanged for images when Tachyon is enabled.
 */
function rewrite_presigned_url_to_canonical_s3( string $url, int $post_id ) : string {
	if ( function_exists( 'tachyon_url' ) ) {
		if ( wp_attachment_is_image( $post_id ) ) {
			return $url;
		}

		// Image sub-files of non-image attachments (PDF preview JPEGs,
		// video poster images) — Tachyon serves these too even though
		// wp_attachment_is_image() returns false for the parent.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path && preg_match( '/\.(jpe?g|png|gif|webp)$/i', strtok( $path, '?' ) ) ) {
			return $url;
		}
	}

	$instance = Plugin::get_instance();
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

