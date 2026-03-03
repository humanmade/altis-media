<?php
/**
 * Private Uploads feature.
 *
 * Controls S3 object ACLs based on post parent status, ensuring media
 * attached to unpublished posts is not publicly accessible.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Uploads;

use Altis;
use Altis\Global_Content;
use S3_Uploads\Plugin as S3_Plugin;
use WP_Post;

/**
 * Bootstrap the private uploads feature.
 *
 * @return void
 */
function bootstrap() : void {
	$config = Altis\get_config();
	$enabled = $config['modules']['media']['private-uploads'] ?? true;

	if ( ! $enabled ) {
		return;
	}

	// Bail if S3 Uploads is not active.
	if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
		return;
	}

	add_filter( 's3_uploads_is_attachment_private', __NAMESPACE__ . '\\is_attachment_private', 10, 2 );
	add_action( 'transition_post_status', __NAMESPACE__ . '\\handle_post_status_transition', 10, 3 );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_admin_hooks' );

	// S3 Uploads sets ACLs during wp_generate_attachment_metadata, but at that
	// point the metadata (including thumbnail sizes) hasn't been saved to the
	// database yet, so get_attachment_files() misses generated thumbnails.
	// Hook after metadata is persisted to ensure ALL files get correct ACLs.
	add_action( 'added_post_meta', __NAMESPACE__ . '\\set_acl_on_metadata_save', 10, 4 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\set_acl_on_metadata_save', 10, 4 );
}

/**
 * Set correct ACLs after attachment metadata is persisted to the database.
 *
 * This ensures all files (original + thumbnails) get the correct ACL,
 * working around the timing issue where S3 Uploads' own hook runs before
 * metadata is saved and therefore misses generated thumbnail sizes.
 *
 * Also enrolls new uploads in the private uploads feature by setting
 * `_s3_privacy` to 'auto' if not already set, so that pre-existing
 * images (without the meta) remain unaffected.
 *
 * @param int    $meta_id    The meta ID.
 * @param int    $post_id    The post (attachment) ID.
 * @param string $meta_key   The meta key.
 * @param mixed  $meta_value The meta value.
 * @return void
 */
function set_acl_on_metadata_save( int $meta_id, int $post_id, string $meta_key, $meta_value ) : void {
	if ( $meta_key !== '_wp_attachment_metadata' ) {
		return;
	}

	if ( get_post_type( $post_id ) !== 'attachment' ) {
		return;
	}

	// Enrol new uploads in the feature. Pre-existing images without
	// this meta will remain public (see is_attachment_private()).
	$current_privacy = get_post_meta( $post_id, '_s3_privacy', true );
	if ( empty( $current_privacy ) ) {
		update_post_meta( $post_id, '_s3_privacy', 'auto' );
	}

	if ( ! is_attachment_private( false, $post_id ) ) {
		return;
	}

	S3_Plugin::get_instance()->set_attachment_files_acl( $post_id, 'private' );
}

/**
 * Determine whether an attachment should be private.
 *
 * Priority order:
 * 1. Manual override via `_s3_privacy` post meta ('public' or 'private')
 * 2. Legacy/pre-existing images (no `_s3_privacy` meta) are always public
 * 3. Auto-managed images (`_s3_privacy` = 'auto'):
 *    a. Global media library attachments are always public
 *    b. Unattached media (post_parent = 0) defaults to private
 *    c. Based on parent post status: published = public, otherwise private
 *
 * @param bool $is_private Current private status.
 * @param int  $attachment_id The attachment post ID.
 * @return bool Whether the attachment should be private.
 */
function is_attachment_private( bool $is_private, int $attachment_id ) : bool {
	// 1. Check for manual override.
	$privacy = get_post_meta( $attachment_id, '_s3_privacy', true );
	if ( $privacy === 'public' ) {
		return false;
	}
	if ( $privacy === 'private' ) {
		return true;
	}

	// 2. Legacy/pre-existing images without privacy meta are always public.
	// Only images explicitly enrolled in the feature (via 'auto' meta set
	// during upload) are subject to automatic privacy management.
	if ( $privacy !== 'auto' ) {
		return false;
	}

	// 3. Auto-managed: determine based on context.
	// 3a. Global media library is always public.
	if ( function_exists( 'Altis\\Global_Content\\is_global_site' ) && Global_Content\is_global_site() ) {
		return false;
	}

	// 3b. Unattached media defaults to private.
	$attachment = get_post( $attachment_id );
	if ( ! $attachment || empty( $attachment->post_parent ) ) {
		return true;
	}

	// 3c. Based on parent post status.
	$parent = get_post( $attachment->post_parent );
	if ( ! $parent ) {
		return true;
	}

	return $parent->post_status !== 'publish';
}

/**
 * Handle post status transitions to update attachment ACLs.
 *
 * When a post is published, all non-manually-overridden attachments
 * are set to public-read. When unpublished, they are set to private.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       The post object.
 * @return void
 */
function handle_post_status_transition( string $new_status, string $old_status, WP_Post $post ) : void {
	// Skip if status hasn't changed.
	if ( $new_status === $old_status ) {
		return;
	}

	// Skip attachments and revisions.
	if ( in_array( $post->post_type, [ 'attachment', 'revision' ], true ) ) {
		return;
	}

	// Skip global media library site.
	if ( function_exists( 'Altis\\Global_Content\\is_global_site' ) && Global_Content\is_global_site() ) {
		return;
	}

	// Determine the target ACL based on transition direction.
	$is_publishing = $new_status === 'publish' && $old_status !== 'publish';
	$is_unpublishing = $old_status === 'publish' && $new_status !== 'publish';

	if ( ! $is_publishing && ! $is_unpublishing ) {
		return;
	}

	$acl = $is_publishing ? 'public-read' : 'private';

	// Get all attachments for this post.
	$attachments = get_posts( [
		'post_type' => 'attachment',
		'post_parent' => $post->ID,
		'posts_per_page' => -1,
		'post_status' => 'any',
		'fields' => 'ids',
	] );

	if ( empty( $attachments ) ) {
		return;
	}

	$plugin = S3_Plugin::get_instance();

	foreach ( $attachments as $attachment_id ) {
		// Only process auto-managed attachments. Skip legacy images
		// (no meta) and those with manual overrides ('public'/'private').
		$privacy = get_post_meta( $attachment_id, '_s3_privacy', true );
		if ( $privacy !== 'auto' ) {
			continue;
		}

		$plugin->set_attachment_files_acl( $attachment_id, $acl );
	}
}

/**
 * Get the effective privacy status for an attachment.
 *
 * @param int $attachment_id The attachment post ID.
 * @return string 'private' or 'public'.
 */
function get_privacy_status( int $attachment_id ) : string {
	$is_private = is_attachment_private( false, $attachment_id );
	return $is_private ? 'private' : 'public';
}

/**
 * Set the privacy value for an attachment.
 *
 * Pass 'auto' to enrol in automatic management, 'public' or 'private'
 * for a manual override, or an empty string to remove the meta entirely
 * (reverting to legacy/unmanaged public behaviour).
 *
 * @param int    $attachment_id The attachment post ID.
 * @param string $privacy       'auto', 'public', 'private', or '' to clear.
 * @return void
 */
function set_manual_privacy( int $attachment_id, string $privacy ) : void {
	if ( empty( $privacy ) ) {
		delete_post_meta( $attachment_id, '_s3_privacy' );
	} else {
		update_post_meta( $attachment_id, '_s3_privacy', $privacy );
	}

	// Update the S3 ACL to match.
	if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
		return;
	}

	$effective_status = get_privacy_status( $attachment_id );
	$acl = $effective_status === 'private' ? 'private' : 'public-read';
	S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, $acl );
}

/**
 * Register admin UI hooks for the private uploads feature.
 *
 * @return void
 */
function register_admin_hooks() : void {
	// Media library list view columns.
	add_filter( 'manage_media_columns', __NAMESPACE__ . '\\add_privacy_column' );
	add_action( 'manage_media_custom_column', __NAMESPACE__ . '\\render_privacy_column', 10, 2 );

	// Attachment edit screen fields.
	add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\add_privacy_field', 10, 2 );
	add_filter( 'attachment_fields_to_save', __NAMESPACE__ . '\\save_privacy_field', 10, 2 );
}

/**
 * Add a Privacy column to the media library list view.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function add_privacy_column( array $columns ) : array {
	$columns['s3_privacy'] = __( 'Privacy', 'altis' );
	return $columns;
}

/**
 * Render the Privacy column content in the media library list view.
 *
 * @param string $column_name The column being rendered.
 * @param int    $attachment_id The attachment post ID.
 * @return void
 */
function render_privacy_column( string $column_name, int $attachment_id ) : void {
	if ( $column_name !== 's3_privacy' ) {
		return;
	}

	$status = get_privacy_status( $attachment_id );

	if ( $status === 'private' ) {
		printf(
			'<span class="dashicons dashicons-lock" title="%s"></span> %s',
			esc_attr__( 'Private', 'altis' ),
			esc_html__( 'Private', 'altis' )
		);
	} else {
		printf(
			'<span class="dashicons dashicons-admin-site-alt3" title="%s"></span> %s',
			esc_attr__( 'Public', 'altis' ),
			esc_html__( 'Public', 'altis' )
		);
	}
}

/**
 * Add a privacy field to the attachment edit form.
 *
 * @param array   $form_fields Existing form fields.
 * @param WP_Post $post        The attachment post object.
 * @return array Modified form fields.
 */
function add_privacy_field( array $form_fields, WP_Post $post ) : array {
	$manual = get_post_meta( $post->ID, '_s3_privacy', true );
	$current = $manual ?: 'auto';

	$options = [
		'auto' => __( 'Auto (based on parent post status)', 'altis' ),
		'private' => __( 'Private', 'altis' ),
		'public' => __( 'Public', 'altis' ),
	];

	$html = '<select name="attachments[' . esc_attr( $post->ID ) . '][s3_privacy]" id="attachments-' . esc_attr( $post->ID ) . '-s3_privacy">';
	foreach ( $options as $value => $label ) {
		$html .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}
	$html .= '</select>';

	$effective = get_privacy_status( $post->ID );
	$html .= sprintf(
		'<p class="description">%s: <strong>%s</strong></p>',
		esc_html__( 'Current status', 'altis' ),
		esc_html( ucfirst( $effective ) )
	);

	$form_fields['s3_privacy'] = [
		'label' => __( 'Privacy', 'altis' ),
		'input' => 'html',
		'html' => $html,
		'helps' => __( 'Control whether this file is publicly accessible. "Auto" bases privacy on the parent post status.', 'altis' ),
	];

	return $form_fields;
}

/**
 * Save the privacy field from the attachment edit form.
 *
 * @param array $post       The post data array.
 * @param array $attachment The attachment fields from the form.
 * @return array The post data array.
 */
function save_privacy_field( array $post, array $attachment ) : array {
	$privacy = $attachment['s3_privacy'] ?? '';

	if ( ! in_array( $privacy, [ 'auto', 'private', 'public' ], true ) ) {
		return $post;
	}

	set_manual_privacy( (int) $post['ID'], $privacy );

	return $post;
}
