<?php
/**
 * Private Media — Content Parser.
 *
 * Pure functions that extract attachment IDs and URLs from post content.
 * No side effects — used by post_lifecycle.php and other consumers.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Content_Parser;

/**
 * Extract attachments from post content.
 *
 * Returns an array of arrays, each containing:
 * - attachment_id (int)
 * - attachment_url (string) — cleaned base URL
 * - modified_url (string) — URL as it appears in content
 *
 * @param string $content The post content to parse.
 * @return array[] Array of [ attachment_id, attachment_url, modified_url ] arrays.
 */
function extract_attachments_from_content( string $content ) : array {
	if ( empty( $content ) ) {
		return [];
	}

	$results = [];

	// 1. Standard <img> tags with wp-image-{id} class.
	// Matches class="...wp-image-123..." and extracts the src attribute.
	if ( preg_match_all( '/class="[^"]*wp-image-(\d+)[^"]*"[^>]*src="([^"]+)"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $match[2] ),
				'modified_url'  => $match[2],
			];
		}
	}

	// Also match src before class.
	if ( preg_match_all( '/src="([^"]+)"[^>]*class="[^"]*wp-image-(\d+)[^"]*"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[2],
				'attachment_url' => clean_url( $match[1] ),
				'modified_url'  => $match[1],
			];
		}
	}

	// 2. <img> tags with data-full-url attribute (and wp-image class).
	if ( preg_match_all( '/class="[^"]*wp-image-(\d+)[^"]*"[^>]*data-full-url="([^"]+)"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $match[2] ),
				'modified_url'  => $match[2],
			];
		}
	}

	// 3. Gutenberg block attributes: "imageUrl":"...","imageId":N
	if ( preg_match_all( '/"imageUrl"\s*:\s*"([^"]+)"\s*,\s*"imageId"\s*:\s*(\d+)/', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$url = wp_unslash( $match[1] );
			$results[] = [
				'attachment_id' => (int) $match[2],
				'attachment_url' => clean_url( $url ),
				'modified_url'  => $url,
			];
		}
	}

	// 4. Gutenberg block attributes: "id":N,"src":"..." (e.g. video/file blocks).
	if ( preg_match_all( '/"id"\s*:\s*(\d+)\s*,\s*"src"\s*:\s*"([^"]+)"/', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$url = wp_unslash( $match[2] );
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $url ),
				'modified_url'  => $url,
			];
		}
	}

	// 5. Video blocks: <!-- wp:video {"id":N} --> with <video src="...">
	if ( preg_match_all( '/<!--\s*wp:video\s+\{[^}]*"id"\s*:\s*(\d+)[^}]*\}\s*-->.*?(?:src|data-src)="([^"]+)"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $match[2] ),
				'modified_url'  => $match[2],
			];
		}
	}

	// 6. File blocks: wp-file-{id} class — href may be on a child element.
	if ( preg_match_all( '/class="[^"]*wp-file-(\d+)[^"]*".*?href="([^"]+)"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $match[2] ),
				'modified_url'  => $match[2],
			];
		}
	}

	// 7. Video blocks with wp-video-{id} class — src may be on a child element.
	if ( preg_match_all( '/class="[^"]*wp-video-(\d+)[^"]*".*?(?:src|data-src)="([^"]+)"/s', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$results[] = [
				'attachment_id' => (int) $match[1],
				'attachment_url' => clean_url( $match[2] ),
				'modified_url'  => $match[2],
			];
		}
	}

	// Deduplicate by attachment_id (keep first occurrence).
	$seen = [];
	$unique_results = [];
	foreach ( $results as $result ) {
		$id = $result['attachment_id'];
		if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$unique_results[] = $result;
		}
	}

	return $unique_results;
}

/**
 * Extract unique attachment IDs from post content.
 *
 * Convenience wrapper around extract_attachments_from_content().
 *
 * @param string $content The post content to parse.
 * @return int[] Array of unique attachment IDs.
 */
function extract_attachment_ids_from_content( string $content ) : array {
	$attachments = extract_attachments_from_content( $content );
	return array_values( array_unique( array_column( $attachments, 'attachment_id' ) ) );
}

/**
 * Clean a URL by stripping query parameters and converting CDN/Tachyon URLs to canonical S3 paths.
 *
 * @param string $url The URL to clean.
 * @return string The cleaned URL.
 */
function clean_url( string $url ) : string {
	// Strip query parameters.
	$url = strtok( $url, '?' );
	if ( $url === false ) {
		return '';
	}

	// Convert Tachyon URLs to canonical upload paths.
	// Tachyon URLs typically follow the pattern: /tachyon/uploads/path/to/file.jpg
	$url = preg_replace( '#/tachyon/(uploads/.+)$#', '/wp-content/$1', $url );

	return $url;
}
