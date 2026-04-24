<?php
/**
 * Private Media — Attachment Query Compatibility.
 *
 * Ensures attachment queries include 'publish' and 'private' statuses alongside 'inherit'.
 * Runs unconditionally (even when private media feature is inactive) to prevent data loss.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Query_Compat;

/**
 * Bootstrap query compatibility hooks.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\filter_attachment_query' );
}

/**
 * Ensure attachment queries include publish and private statuses.
 *
 * WordPress core media queries filter by post_status='inherit'. Since private media
 * uses 'private' and 'publish' statuses, we need to expand the query to include them.
 *
 * @param \WP_Query $query The WP_Query instance.
 * @return void
 */
function filter_attachment_query( \WP_Query $query ) {
	// Only modify queries targeting the attachment post type.
	$post_type = $query->get( 'post_type' );
	if ( $post_type !== 'attachment' && ! ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) ) {
		return;
	}

	$post_status = $query->get( 'post_status' );

	// Handle string status.
	if ( is_string( $post_status ) && ! empty( $post_status ) ) {
		$post_status = array_map( 'trim', explode( ',', $post_status ) );
	}

	// Handle empty / default status — WP defaults to 'inherit' for attachment queries.
	if ( empty( $post_status ) ) {
		$post_status = [ 'inherit' ];
	}

	if ( ! is_array( $post_status ) ) {
		return;
	}

	// If 'inherit' is in the statuses, also add 'publish' and 'private'.
	if ( in_array( 'inherit', $post_status, true ) ) {
		if ( ! in_array( 'publish', $post_status, true ) ) {
			$post_status[] = 'publish';
		}
		if ( ! in_array( 'private', $post_status, true ) ) {
			$post_status[] = 'private';
		}
		$query->set( 'post_status', $post_status );
	}
}
