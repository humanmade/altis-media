<?php
/**
 * Private Media — Post Lifecycle.
 *
 * Handles the publish/unpublish transitions that drive automatic attachment
 * visibility changes. The heart of the Private Media feature.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\Post_Lifecycle;

use Altis\Media\Private_Media\Content_Parser;
use Altis\Media\Private_Media\Visibility;
use WP_Post;

/**
 * Bootstrap post lifecycle hooks.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'transition_post_status', __NAMESPACE__ . '\\on_post_status_transition', 10, 3 );
	add_action( 'save_post', __NAMESPACE__ . '\\on_save_post', 10, 2 );

	// Add CSS classes to video and file block output for content parser detection.
	add_filter( 'render_block_core/video', __NAMESPACE__ . '\\add_video_block_class', 10, 2 );
	add_filter( 'render_block_core/file', __NAMESPACE__ . '\\add_file_block_class', 10, 2 );
}

/**
 * Handle post status transitions.
 *
 * @param string  $new_status The new post status.
 * @param string  $old_status The old post status.
 * @param WP_Post $post       The post object.
 * @return void
 */
function on_post_status_transition( string $new_status, string $old_status, WP_Post $post ) : void {
	if ( ! is_allowed_post_type( $post->post_type ) ) {
		return;
	}

	// Publishing: make attachments public.
	if ( $new_status === 'publish' && $old_status !== 'publish' ) {
		handle_publish( $post );
		return;
	}

	// Unpublishing: make attachments private (if not used elsewhere).
	if ( $old_status === 'publish' && $new_status !== 'publish' ) {
		handle_unpublish( $post );
		return;
	}
}

/**
 * Handle saving of a published post (detects removed attachments).
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post    The post object.
 * @return void
 */
function on_save_post( int $post_id, WP_Post $post ) : void {
	if ( $post->post_status !== 'publish' ) {
		return;
	}

	if ( ! is_allowed_post_type( $post->post_type ) ) {
		return;
	}

	// Avoid running during the transition_post_status handler.
	if ( doing_action( 'transition_post_status' ) ) {
		return;
	}

	handle_resave( $post );
}

/**
 * Handle a post being published.
 *
 * Gathers attachment IDs, adds post references, sets visibility, and stores
 * the ID list in post meta for future diff detection.
 *
 * @param WP_Post $post The post being published.
 * @return void
 */
function handle_publish( WP_Post $post ) : void {
	$attachment_ids = get_post_attachment_ids( $post );

	foreach ( $attachment_ids as $attachment_id ) {
		Visibility\add_post_reference( $attachment_id, $post->ID );
		Visibility\set_attachment_visibility( $attachment_id );
	}

	// Store current attachment IDs for future removed-attachment diffing.
	update_post_meta( $post->ID, 'altis_private_media_post', $attachment_ids );
}

/**
 * Handle a post being unpublished.
 *
 * Removes post references and re-evaluates visibility for each attachment.
 *
 * @param WP_Post $post The post being unpublished.
 * @return void
 */
function handle_unpublish( WP_Post $post ) : void {
	$attachment_ids = get_post_attachment_ids( $post );

	// Also include previously stored IDs (in case content was changed before unpublish).
	$stored_ids = get_post_meta( $post->ID, 'altis_private_media_post', true );
	if ( is_array( $stored_ids ) ) {
		$attachment_ids = array_unique( array_merge( $attachment_ids, $stored_ids ) );
	}

	foreach ( $attachment_ids as $attachment_id ) {
		Visibility\remove_post_reference( $attachment_id, $post->ID );
		Visibility\set_attachment_visibility( $attachment_id );
	}

	delete_post_meta( $post->ID, 'altis_private_media_post' );
}

/**
 * Handle re-saving a published post.
 *
 * Diffs current attachment IDs against previously stored ones to detect
 * removed attachments that may need to go private.
 *
 * @param WP_Post $post The post being re-saved.
 * @return void
 */
function handle_resave( WP_Post $post ) : void {
	$current_ids = get_post_attachment_ids( $post );
	$stored_ids = get_post_meta( $post->ID, 'altis_private_media_post', true );

	if ( ! is_array( $stored_ids ) ) {
		$stored_ids = [];
	}

	// Newly added attachments.
	$added = array_diff( $current_ids, $stored_ids );
	foreach ( $added as $attachment_id ) {
		Visibility\add_post_reference( $attachment_id, $post->ID );
		Visibility\set_attachment_visibility( $attachment_id );
	}

	// Removed attachments — may need to go private.
	$removed = array_diff( $stored_ids, $current_ids );
	foreach ( $removed as $attachment_id ) {
		Visibility\remove_post_reference( $attachment_id, $post->ID );
		Visibility\set_attachment_visibility( $attachment_id );
	}

	// Update stored list.
	update_post_meta( $post->ID, 'altis_private_media_post', $current_ids );
}

/**
 * Get all attachment IDs associated with a post.
 *
 * Combines content parsing, featured image, and filterable additional sources.
 *
 * @param WP_Post $post The post.
 * @return int[] Array of attachment IDs.
 */
function get_post_attachment_ids( WP_Post $post ) : array {
	// Parse content for embedded attachments.
	$ids = Content_Parser\extract_attachment_ids_from_content( $post->post_content );

	// Include featured image.
	$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
	if ( $thumbnail_id > 0 ) {
		$ids[] = $thumbnail_id;
	}

	// Check registered meta keys that store attachment IDs.
	$meta_keys = apply_filters( 'private_media/post_meta_attachment_keys', [], $post->ID );
	foreach ( $meta_keys as $meta_key ) {
		$meta_value = get_post_meta( $post->ID, $meta_key, true );
		if ( is_numeric( $meta_value ) && (int) $meta_value > 0 ) {
			$ids[] = (int) $meta_value;
		}
	}

	/**
	 * Filter the attachment IDs associated with a post.
	 *
	 * @param int[]   $ids     Array of attachment IDs.
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 */
	$ids = apply_filters( 'private_media/post_attachment_ids', $ids, $post->ID, $post );

	// Ensure all IDs are valid attachment IDs.
	$ids = array_filter( array_unique( array_map( 'intval', $ids ) ), function ( int $id ) : bool {
		return $id > 0 && get_post_type( $id ) === 'attachment';
	} );

	return array_values( $ids );
}

/**
 * Check if a post type is allowed for private media transitions.
 *
 * @param string $post_type The post type to check.
 * @return bool True if the post type should trigger attachment transitions.
 */
function is_allowed_post_type( string $post_type ) : bool {
	// Default: all post types with editor support, plus customize_changeset.
	$allowed = get_post_types_by_support( 'editor' );
	$allowed[] = 'customize_changeset';

	/**
	 * Filter which post types trigger attachment visibility transitions.
	 *
	 * @param string[] $allowed Array of allowed post type names.
	 */
	$allowed = apply_filters( 'private_media/allowed_post_types', $allowed );

	return in_array( $post_type, $allowed, true );
}

/**
 * Add wp-video-{id} class to video block output.
 *
 * This allows the content parser to identify video attachments in rendered content.
 *
 * @param string $block_content The block content.
 * @param array  $block         The block data.
 * @return string Modified block content.
 */
function add_video_block_class( string $block_content, array $block ) : string {
	if ( empty( $block['attrs']['id'] ) ) {
		return $block_content;
	}

	$id = (int) $block['attrs']['id'];
	$class = 'wp-video-' . $id;

	// Add class to the <figure> wrapper.
	$block_content = preg_replace(
		'/(<figure\b[^>]*class="[^"]*)(")/s',
		'$1 ' . esc_attr( $class ) . '$2',
		$block_content,
		1
	);

	return $block_content;
}

/**
 * Add wp-file-{id} class to file block output.
 *
 * This allows the content parser to identify file attachments in rendered content.
 *
 * @param string $block_content The block content.
 * @param array  $block         The block data.
 * @return string Modified block content.
 */
function add_file_block_class( string $block_content, array $block ) : string {
	if ( empty( $block['attrs']['id'] ) ) {
		return $block_content;
	}

	$id = (int) $block['attrs']['id'];
	$class = 'wp-file-' . $id;

	// Add class to the block wrapper element.
	$block_content = preg_replace(
		'/(<div\b[^>]*class="[^"]*wp-block-file[^"]*)(")/s',
		'$1 ' . esc_attr( $class ) . '$2',
		$block_content,
		1
	);

	return $block_content;
}
