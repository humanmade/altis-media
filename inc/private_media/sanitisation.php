<?php
/**
 * Private Media — Content Sanitisation.
 *
 * Strips AWS signing parameters from URLs in post content and widgets on save
 * to prevent credentials from being persisted in the database.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Sanitisation;

use Altis\Media\Private_Media\Content_Parser;
use Altis\Media\Private_Media\Visibility;

/**
 * AWS parameters to strip from URLs.
 */
const AWS_PARAMS = [
	'X-Amz-Content-Sha256',
	'X-Amz-Security-Token',
	'X-Amz-Algorithm',
	'X-Amz-Credential',
	'X-Amz-Date',
	'X-Amz-SignedHeaders',
	'X-Amz-Expires',
	'X-Amz-Signature',
	'X-Amz-S3-Host',
	'presign',
];

/**
 * Bootstrap sanitisation hooks.
 *
 * @return void
 */
function bootstrap() {
	add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\\sanitise_post_content', 10, 1 );
	add_filter( 'pre_update_option_widget_block', __NAMESPACE__ . '\\sanitise_widget_content', 10, 1 );
}

/**
 * Strip AWS signing parameters from post content on save.
 *
 * @param array $data The post data array.
 * @return array Modified post data.
 */
function sanitise_post_content( array $data ) : array {
	if ( empty( $data['post_content'] ) ) {
		return $data;
	}

	$data['post_content'] = strip_aws_params_from_content( $data['post_content'] );

	return $data;
}

/**
 * Strip AWS signing parameters from widget block content.
 *
 * Also forces all images found in widgets to public since widgets
 * have no publish/draft lifecycle.
 *
 * @param mixed $value The widget option value.
 * @return mixed Modified widget value.
 */
function sanitise_widget_content( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	$value = strip_aws_params_from_content( $value );

	// Force images in widgets to public.
	$attachment_ids = Content_Parser\extract_attachment_ids_from_content( $value );
	foreach ( $attachment_ids as $attachment_id ) {
		Visibility\set_override( $attachment_id, 'public' );
	}

	return $value;
}

/**
 * Strip AWS signing parameters from all URLs in a content string.
 *
 * @param string $content The content to sanitise.
 * @return string Sanitised content.
 */
function strip_aws_params_from_content( string $content ) : string {
	if ( empty( $content ) ) {
		return $content;
	}

	// Build regex pattern matching any of the AWS parameters.
	$param_pattern = implode( '|', array_map( 'preg_quote', AWS_PARAMS ) );

	// Match URLs containing AWS parameters and strip those params.
	// This handles URLs in src="...", href="...", and JSON strings.
	$content = preg_replace_callback(
		'/((?:src|href|data-full-url|data-src)="[^"]*\?)([^"]*")/s',
		function ( $matches ) use ( $param_pattern ) {
			$prefix = $matches[1];
			$rest = $matches[2];

			// Strip the leading ? and trailing "
			$query_and_quote = $rest;
			$quote = substr( $query_and_quote, -1 );
			$query = substr( $query_and_quote, 0, -1 );

			$query = strip_aws_params_from_query( $query, $param_pattern );

			if ( empty( $query ) ) {
				// Remove the ? too.
				return substr( $prefix, 0, -1 ) . $quote;
			}

			return $prefix . $query . $quote;
		},
		$content
	);

	// Also handle URLs in JSON block attributes (escaped quotes).
	$content = preg_replace_callback(
		'/((?:"src"|"imageUrl"|"href")\s*:\s*"[^"]*\?)([^"]*")/s',
		function ( $matches ) use ( $param_pattern ) {
			$prefix = $matches[1];
			$rest = $matches[2];

			$quote = substr( $rest, -1 );
			$query = substr( $rest, 0, -1 );

			$query = strip_aws_params_from_query( $query, $param_pattern );

			if ( empty( $query ) ) {
				return substr( $prefix, 0, -1 ) . $quote;
			}

			return $prefix . $query . $quote;
		},
		$content
	);

	return $content;
}

/**
 * Strip AWS parameters from a query string.
 *
 * @param string $query         The query string (without leading ?).
 * @param string $param_pattern Regex pattern for parameter names.
 * @return string Filtered query string.
 */
function strip_aws_params_from_query( string $query, string $param_pattern ) : string {
	// Split on & and filter out AWS params.
	$parts = explode( '&', $query );
	$parts = array_filter( $parts, function ( string $part ) use ( $param_pattern ) : bool {
		return ! preg_match( '/^(' . $param_pattern . ')=/i', $part );
	} );

	return implode( '&', $parts );
}
