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
	add_filter( 'the_content', __NAMESPACE__ . '\\replace_private_urls_in_preview', 999 );

	// Register REST filters for all post types with editor support.
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_filters' );

	add_filter( 'wp_calculate_image_srcset', __NAMESPACE__ . '\\disable_srcset_in_preview', 10, 1 );
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

	if ( ! empty( $data['content']['raw'] ) ) {
		$data['content']['raw'] = replace_private_urls( $data['content']['raw'] );
		$response->set_data( $data );
	}

	return $response;
}

/**
 * Replace private media URLs in content with signed S3 URLs.
 *
 * @param string $content The content to process.
 * @return string Content with private URLs replaced by signed ones.
 */
function replace_private_urls( string $content ) : string {
	if ( ! class_exists( '\\S3_Uploads\\Plugin' ) ) {
		return $content;
	}

	$attachments = Content_Parser\extract_attachments_from_content( $content );

	foreach ( $attachments as $attachment ) {
		$attachment_id = $attachment['attachment_id'];

		// Only sign URLs for private attachments.
		if ( Visibility\check_attachment_is_public( $attachment_id ) ) {
			continue;
		}

		$modified_url = $attachment['modified_url'];
		$signed_url = \S3_Uploads\Plugin::get_instance()->add_s3_signed_params_to_attachment_url(
			$modified_url,
			$attachment_id
		);

		if ( $signed_url !== $modified_url ) {
			$content = str_replace( $modified_url, $signed_url, $content );
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
