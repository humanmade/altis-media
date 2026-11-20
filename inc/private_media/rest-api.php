<?php
/**
 * Private Media — REST API Guard.
 *
 * WordPress core treats attachments with `inherit` status as readable: the
 * attachments controller lets anonymous callers fetch `/wp/v2/media/<id>` and
 * list `/wp/v2/media` for any `inherit` attachment whose parent is published or
 * absent. For a private attachment that exposes both its metadata (filename,
 * title, dimensions) and a freshly signed 15-minute presigned S3 `source_url`
 * — to anyone.
 *
 * Like the front-end attachment-page guard, this makes private attachments
 * simply not exist over REST for users who can't manage media: the single-item
 * endpoint 404s (indistinguishable from an unknown ID) and the collection
 * endpoint omits them. A belt-and-braces filter also strips any signed URL that
 * still reaches a prepared response through some other route (e.g. `_embed`).
 *
 * The boundary is the `upload_files` capability — the feature's promise that
 * private files stay fully available to the authors, editors and admins who can
 * upload media, and to nobody else.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\REST_API;

use Altis\Media\Private_Media\Visibility;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * WP_Query var flagging a collection query that must hide private attachments.
 */
const HIDE_QUERY_VAR = 'altis_private_media_hide';

/**
 * Bootstrap the REST API guard.
 *
 * @return void
 */
function bootstrap() : void {
	// 404 the single-item endpoint for private attachments.
	add_filter( 'rest_pre_dispatch', __NAMESPACE__ . '\\hide_private_attachment_item', 10, 3 );

	// Omit private attachments from collection listings.
	add_filter( 'rest_attachment_query', __NAMESPACE__ . '\\exclude_private_attachments_from_query', 10, 2 );
	add_filter( 'posts_where', __NAMESPACE__ . '\\filter_hide_private_where', 10, 2 );

	// Defence in depth: strip signed URLs from any private attachment that
	// still reaches a prepared response (e.g. embedded via `_embed`).
	add_filter( 'rest_prepare_attachment', __NAMESPACE__ . '\\scrub_private_attachment_urls', 10, 2 );
}

/**
 * Whether the current user is allowed to see private attachments over REST.
 *
 * @return bool
 */
function current_user_can_view_private() : bool {
	return current_user_can( 'upload_files' );
}

/**
 * Return a 404 for the single-item media endpoint of a private attachment.
 *
 * @param mixed           $result  Response to replace the requested version with. Non-null short-circuits dispatch.
 * @param WP_REST_Server  $server  The REST server instance (unused).
 * @param WP_REST_Request $request The request being dispatched.
 * @return mixed The original $result, or a 404 WP_Error for a hidden attachment.
 */
function hide_private_attachment_item( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	// Respect a response another handler has already produced.
	if ( $result !== null ) {
		return $result;
	}

	// Only guard reads — writes to this route already require capabilities a
	// non-media user doesn't have.
	if ( ! in_array( $request->get_method(), [ 'GET', 'HEAD' ], true ) ) {
		return $result;
	}

	// Match the core single-attachment route: /wp/v2/media/<id>.
	if ( ! preg_match( '#^/wp/v2/media/(?P<id>\d+)$#', $request->get_route(), $matches ) ) {
		return $result;
	}

	$id = (int) $matches['id'];
	if ( get_post_type( $id ) !== 'attachment' ) {
		return $result;
	}

	// Media-capable users see everything.
	if ( current_user_can_view_private() ) {
		return $result;
	}

	// Public attachments are served normally.
	if ( Visibility\check_attachment_is_public( $id ) ) {
		return $result;
	}

	// Private attachment, non-media user: pretend it doesn't exist. Match core's
	// exact error code, message and status (see WP_REST_Posts_Controller::get_post()).
	return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), [ 'status' => 404 ] );
}

/**
 * Tag a REST attachment collection query to hide private attachments.
 *
 * @param array           $args    WP_Query args for the collection request.
 * @param WP_REST_Request $request The collection request (unused).
 * @return array The (possibly) tagged query args.
 */
function exclude_private_attachments_from_query( array $args, WP_REST_Request $request ) : array {
	// Media-capable users see everything.
	if ( current_user_can_view_private() ) {
		return $args;
	}

	$args[ HIDE_QUERY_VAR ] = true;

	return $args;
}

/**
 * Exclude private attachments from a tagged collection query.
 *
 * Appends a correlated `NOT EXISTS` subquery on the stored `_altis_media_acl`
 * meta.
 *
 * @param string   $where The WHERE clause of the query.
 * @param WP_Query $query The query being run.
 * @return string The (possibly) augmented WHERE clause.
 */
function filter_hide_private_where( string $where, WP_Query $query ) : string {
	if ( ! $query->get( HIDE_QUERY_VAR ) ) {
		return $where;
	}

	global $wpdb;

	$where .= $wpdb->prepare(
		" AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta}
			WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
			AND {$wpdb->postmeta}.meta_key = %s
			AND {$wpdb->postmeta}.meta_value = %s
		)",
		Visibility\META_KEY,
		'private'
	);

	return $where;
}

/**
 * Strip signed S3 URLs from the REST response of a private attachment.
 *
 * Belt-and-braces backstop to the single-item 404 and collection exclusion: if
 * a private attachment is prepared through some other route (e.g. `_embed`).
 *
 * @param WP_REST_Response $response The prepared attachment response.
 * @param WP_Post          $post     The attachment post.
 * @return WP_REST_Response The response with private URLs removed when applicable.
 */
function scrub_private_attachment_urls( WP_REST_Response $response, WP_Post $post ) : WP_REST_Response {
	if ( Visibility\check_attachment_is_public( $post->ID ) ) {
		return $response;
	}

	// Media-capable users are allowed the signed URLs (block editor preview etc).
	if ( current_user_can_view_private() ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	// Blank every `source_url` in the response. The nested structures arrive
	// as arrays or stdClass depending on context, so walk both shapes.
	$data = blank_source_urls( $data );

	// The original upload URL — unsigned, but still points at the private S3
	// object.
	if ( isset( $data['guid'] ) ) {
		if ( is_array( $data['guid'] ) && isset( $data['guid']['rendered'] ) ) {
			$data['guid']['rendered'] = '';
		} elseif ( $data['guid'] instanceof \stdClass && isset( $data['guid']->rendered ) ) {
			$data['guid']->rendered = '';
		}
	}

	$response->set_data( $data );

	return $response;
}

/**
 * Recursively blank every `source_url` string in a REST data structure.
 *
 * Handles values that are arrays or stdClass objects interchangeably.
 *
 * @param mixed $value The value to walk.
 * @return mixed The value with all nested `source_url` strings blanked.
 */
function blank_source_urls( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = ( $key === 'source_url' && is_string( $item ) ) ? '' : blank_source_urls( $item );
		}
	} elseif ( $value instanceof \stdClass ) {
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$value->$key = ( $key === 'source_url' && is_string( $item ) ) ? '' : blank_source_urls( $item );
		}
	}

	return $value;
}
