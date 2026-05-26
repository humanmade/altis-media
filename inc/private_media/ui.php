<?php
/**
 * Private Media — UI.
 *
 * Media library UI: row actions, bulk action, modal sidebar controls,
 * and lock icon overlay for private attachments.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\UI;

use Altis\Media\Private_Media\Visibility;
use Altis\Media\Private_Media\Post_Lifecycle;
use S3_Uploads\Plugin;
use WP_Post;

/**
 * Bootstrap UI hooks.
 *
 * @return void
 */
function bootstrap() {
	// Media list row actions.
	add_filter( 'media_row_actions', __NAMESPACE__ . '\\add_media_row_actions', 10, 2 );

	// Post/page row actions.
	add_filter( 'post_row_actions', __NAMESPACE__ . '\\add_post_row_actions', 10, 2 );
	add_filter( 'page_row_actions', __NAMESPACE__ . '\\add_post_row_actions', 10, 2 );

	// Bulk actions.
	add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\\add_bulk_actions' );
	add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\\handle_bulk_actions', 10, 3 );

	// Media modal sidebar: visibility dropdown.
	add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\add_visibility_field', 10, 2 );
	add_filter( 'attachment_fields_to_save', __NAMESPACE__ . '\\save_visibility_field', 10, 2 );

	// AJAX handler for modal visibility changes.
	add_action( 'wp_ajax_private_media_set_visibility', __NAMESPACE__ . '\\ajax_set_visibility' );

	// Media list visibility column.
	add_filter( 'manage_media_columns', __NAMESPACE__ . '\\add_visibility_column' );
	add_action( 'manage_media_custom_column', __NAMESPACE__ . '\\render_visibility_column', 10, 2 );

	// Expose visibility data to the JS attachment model (grid view).
	add_filter( 'wp_prepare_attachment_for_js', __NAMESPACE__ . '\\add_visibility_to_js', 10, 2 );

	// Sign sub-size URLs for non-image private attachments (PDF covers, video posters)
	// that S3 Uploads can't resolve via get_s3_location_for_url(). Runs after S3 Uploads
	// at priority 10 so we only handle URLs that came back unsigned.
	add_filter( 'wp_get_attachment_image_src', __NAMESPACE__ . '\\sign_non_image_subsize_url', 11, 2 );

	// Enqueue assets.
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

	// Admin action handlers.
	add_action( 'admin_init', __NAMESPACE__ . '\\handle_row_actions' );

	// Admin notices.
	add_action( 'admin_notices', __NAMESPACE__ . '\\display_admin_notices' );
}

/**
 * Add row actions to media list table.
 *
 * @param array   $actions Existing row actions.
 * @param WP_Post $post    The attachment post object.
 * @return array Modified actions.
 */
function add_media_row_actions( array $actions, WP_Post $post ) : array {
	if ( $post->post_type !== 'attachment' ) {
		return $actions;
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$override = Visibility\get_override( $post->ID );
	$nonce = wp_create_nonce( 'private_media_action_' . $post->ID );

	if ( $override !== 'public' ) {
		$actions['private_media_public'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf(
				'upload.php?action=private_media_set_public&attachment_id=%d&_wpnonce=%s',
				$post->ID,
				$nonce
			) ) ),
			esc_html__( 'Make Public', 'altis' )
		);
	}

	if ( $override !== 'private' ) {
		$actions['private_media_private'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf(
				'upload.php?action=private_media_set_private&attachment_id=%d&_wpnonce=%s',
				$post->ID,
				$nonce
			) ) ),
			esc_html__( 'Make Private', 'altis' )
		);
	}

	if ( $override !== 'auto' ) {
		$actions['private_media_auto'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf(
				'upload.php?action=private_media_set_auto&attachment_id=%d&_wpnonce=%s',
				$post->ID,
				$nonce
			) ) ),
			esc_html__( 'Restore Default Visibility', 'altis' )
		);
	}

	return $actions;
}

/**
 * Add row actions to post/page list tables.
 *
 * @param array   $actions Existing row actions.
 * @param WP_Post $post    The post object.
 * @return array Modified actions.
 */
function add_post_row_actions( array $actions, WP_Post $post ) : array {
	if ( ! Post_Lifecycle\is_allowed_post_type( $post->post_type ) ) {
		return $actions;
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$nonce = wp_create_nonce( 'private_media_rescan_' . $post->ID );

	$actions['private_media_rescan'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( sprintf(
			'admin.php?action=private_media_rescan&post_id=%d&_wpnonce=%s',
			$post->ID,
			$nonce
		) ) ),
		esc_html__( 'Rescan attachment visibility', 'altis' )
	);

	return $actions;
}

/**
 * Handle row action requests.
 *
 * @return void
 */
function handle_row_actions() : void {
	// Handle media visibility row actions.
	$action = sanitize_text_field( $_GET['action'] ?? '' );
	$attachment_id = absint( $_GET['attachment_id'] ?? 0 );
	$post_id = absint( $_GET['post_id'] ?? 0 );

	// Media visibility actions.
	if ( in_array( $action, [ 'private_media_set_public', 'private_media_set_private', 'private_media_set_auto' ], true ) && $attachment_id > 0 ) {
		check_admin_referer( 'private_media_action_' . $attachment_id );

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to modify this attachment.', 'altis' ) );
		}

		$visibility_map = [
			'private_media_set_public'  => 'public',
			'private_media_set_private' => 'private',
			'private_media_set_auto'    => 'auto',
		];

		Visibility\set_override( $attachment_id, $visibility_map[ $action ] );

		wp_safe_redirect( add_query_arg( 'private_media_updated', '1', admin_url( 'upload.php' ) ) );
		exit;
	}

	// Post rescan action.
	if ( $action === 'private_media_rescan' && $post_id > 0 ) {
		check_admin_referer( 'private_media_rescan_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to modify this post.', 'altis' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'altis' ) );
		}

		// Derive the right rescan path from the post's current status — a
		// published post wants its attachments to reflect the published state,
		// anything else wants the inverse.
		if ( $post->post_status === 'publish' ) {
			Post_Lifecycle\handle_publish( $post );
		} else {
			Post_Lifecycle\handle_unpublish( $post );
		}

		// Mark as manually rescanned.
		update_post_meta( $post_id, '_private_media_rescanned', true );

		$redirect = admin_url( sprintf( 'edit.php?post_type=%s&private_media_rescanned=1', $post->post_type ) );
		wp_safe_redirect( $redirect );
		exit;
	}
}

/**
 * Register bulk visibility actions in the media list table.
 *
 * Three discrete actions are registered so each is self-contained: the user
 * picks which visibility they want and Apply runs it inline via WP's normal
 * bulk-action handling (with the `bulk-media` nonce already verified by core).
 *
 * @param array $actions Existing bulk actions.
 * @return array Modified bulk actions.
 */
function add_bulk_actions( array $actions ) : array {
	$actions['private_media_force_public']    = __( 'Set Visibility: Force Public', 'altis' );
	$actions['private_media_force_private']   = __( 'Set Visibility: Force Private', 'altis' );
	$actions['private_media_remove_override'] = __( 'Set Visibility: Automatic', 'altis' );
	return $actions;
}

/**
 * Handle the private-media bulk visibility actions inline.
 *
 * Runs in the same request as the bulk-form POST, so WP core has already
 * verified the `bulk-media` nonce — no separate nonce or confirmation screen
 * is needed. Result is communicated by adding a query arg to the redirect URL,
 * which `display_admin_notices` picks up.
 *
 * @param string $redirect_to The redirect URL.
 * @param string $doaction    The action being taken.
 * @param array  $post_ids    Array of post IDs.
 * @return string Modified redirect URL.
 */
function handle_bulk_actions( string $redirect_to, string $doaction, array $post_ids ) : string {
	$map = [
		'private_media_force_public'    => 'public',
		'private_media_force_private'   => 'private',
		'private_media_remove_override' => 'auto',
	];

	if ( ! isset( $map[ $doaction ] ) ) {
		return $redirect_to;
	}

	$target = $map[ $doaction ];
	$count  = 0;
	foreach ( $post_ids as $id ) {
		$id = (int) $id;
		if ( current_user_can( 'edit_post', $id ) ) {
			Visibility\set_override( $id, $target );
			$count++;
		}
	}

	return add_query_arg( 'private_media_bulk_updated', $count, $redirect_to );
}

/**
 * Add visibility column to media list table.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function add_visibility_column( array $columns ) : array {
	// Insert after the 'title' column.
	$new_columns = [];
	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;
		if ( $key === 'title' ) {
			$new_columns['private_media_visibility'] = __( 'Visibility', 'altis' );
		}
	}

	return $new_columns;
}

/**
 * Render the visibility column content.
 *
 * @param string $column_name The column name.
 * @param int    $post_id     The attachment ID.
 * @return void
 */
function render_visibility_column( string $column_name, int $post_id ) : void {
	if ( $column_name !== 'private_media_visibility' ) {
		return;
	}

	$is_public = Visibility\check_attachment_is_public( $post_id );
	$override = Visibility\get_override( $post_id );

	if ( $override === 'public' ) {
		echo '<span class="private-media-status private-media-status--public">' . wp_kses( __( 'Public <em>(overridden)</em>', 'altis' ), [ 'em' => [] ] ) . '</span>';
	} elseif ( $override === 'private' ) {
		echo '<span class="private-media-status private-media-status--private">' . wp_kses( __( 'Private <em>(overridden)</em>', 'altis' ), [ 'em' => [] ] ) . '</span>';
	} elseif ( $is_public ) {
		echo '<span class="private-media-status private-media-status--public">' . esc_html__( 'Public', 'altis' ) . '</span>';
	} else {
		echo '<span class="private-media-status private-media-status--private">' . esc_html__( 'Private', 'altis' ) . '</span>';
	}
}

/**
 * Add visibility data to the JS attachment model for grid view.
 *
 * @param array   $response   The prepared attachment response.
 * @param WP_Post $attachment The attachment post.
 * @return array Modified response.
 */
function add_visibility_to_js( array $response, WP_Post $attachment ) : array {
	$response['privateMediaOverride'] = Visibility\get_override( $attachment->ID );
	$response['privateMediaIsPublic'] = Visibility\check_attachment_is_public( $attachment->ID );

	// For private non-image attachments with image sub-sizes (PDF covers,
	// video posters), the URLs in $response['sizes'] and $response['image']
	// were built by core before S3 Uploads' image_src filter could sign them
	// (and S3 Uploads can't resolve them anyway — see sign_non_image_subsize_url).
	// Re-sign each one here so the JS model has working URLs.
	if (
		! wp_attachment_is_image( $attachment->ID )
		&& ! Visibility\check_attachment_is_public( $attachment->ID )
		&& ! empty( $response['sizes'] )
	) {
		foreach ( $response['sizes'] as &$size_data ) {
			if ( ! empty( $size_data['url'] ) ) {
				$size_data['url'] = sign_cover_url_for_attachment( $size_data['url'], $attachment->ID );
			}
		}
		unset( $size_data );

		if ( ! empty( $response['image']['src'] ) ) {
			$response['image']['src'] = sign_cover_url_for_attachment( $response['image']['src'], $attachment->ID );
		}
	}

	return $response;
}

/**
 * Filter callback: sign cover/poster sub-size URLs for non-image private
 * attachments. Runs at priority 11 on `wp_get_attachment_image_src`, after
 * S3 Uploads' own filter (priority 10) which fails to resolve the URL.
 *
 * @param array{0: string, 1: int, 2: int}|false $image   Image src array.
 * @param int|string                              $post_id Attachment ID.
 * @return array{0: string, 1: int, 2: int}|false
 */
function sign_non_image_subsize_url( $image, $post_id ) {
	if ( $image === false || empty( $image[0] ) || ! $post_id ) {
		return $image;
	}

	$post_id = (int) $post_id;

	// Only process non-image attachments (PDFs, videos, etc.). Image
	// attachments are signed correctly by S3 Uploads at priority 10.
	if ( wp_attachment_is_image( $post_id ) ) {
		return $image;
	}

	// Only sign URLs for private attachments.
	if ( Visibility\check_attachment_is_public( $post_id ) ) {
		return $image;
	}

	$image[0] = sign_cover_url_for_attachment( $image[0], $post_id );
	return $image;
}

/**
 * Sign a cover/poster sub-size URL belonging to a non-image attachment.
 *
 * S3 Uploads' `add_s3_signed_params_to_attachment_url()` will only sign a
 * URL if `get_s3_location_for_url()` can resolve it, which it does for the
 * upload baseurl (`https://site/uploads/...`) but not for regional S3 URLs
 * (`https://bucket.s3.region.amazonaws.com/...`). PDF cover URLs come back
 * with the regional host because they're derived from `wp_get_attachment_url`
 * on the parent. We rewrite to the upload baseurl first so S3 Uploads can
 * resolve and sign the URL against the cover's actual S3 key.
 *
 * Already-signed URLs are returned unchanged.
 *
 * @param string $url           The cover/poster URL to sign.
 * @param int    $attachment_id The parent (non-image) attachment ID.
 * @return string Signed URL, or unchanged if signing isn't possible.
 */
function sign_cover_url_for_attachment( string $url, int $attachment_id ) : string {
	// Already signed — leave alone.
	if ( strpos( $url, 'X-Amz-Signature' ) !== false ) {
		return $url;
	}

	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['baseurl'] ) ) {
		return $url;
	}

	// Strip any existing query, then rewrite the host+path so the URL starts
	// with the upload baseurl. We preserve the part of the path after `/uploads/`.
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! $path ) {
		return $url;
	}

	$marker = '/uploads/';
	$pos = strpos( $path, $marker );
	if ( $pos === false ) {
		return $url;
	}

	$relative = substr( $path, $pos + strlen( $marker ) );
	$normalised = trailingslashit( $upload_dir['baseurl'] ) . $relative;

	$plugin = Plugin::get_instance();
	$signed = $plugin->add_s3_signed_params_to_attachment_url( $normalised, $attachment_id );

	// If signing failed (URL unchanged), fall back to the original URL.
	return $signed === $normalised ? $url : $signed;
}

/**
 * Add visibility field to media modal sidebar.
 *
 * @param array   $form_fields Existing form fields.
 * @param WP_Post $post        The attachment post.
 * @return array Modified form fields.
 */
function add_visibility_field( array $form_fields, WP_Post $post ) : array {
	if ( $post->post_type !== 'attachment' ) {
		return $form_fields;
	}

	$override = Visibility\get_override( $post->ID );
	$is_public = Visibility\check_attachment_is_public( $post->ID );
	$used_in = Visibility\get_used_in_posts( $post->ID );

	// Status display.
	$status_label = $is_public ? __( 'Public', 'altis' ) : __( 'Private', 'altis' );
	if ( $override === 'public' ) {
		$status_label = __( 'Public (overridden)', 'altis' );
	} elseif ( $override === 'private' ) {
		$status_label = __( 'Private (overridden)', 'altis' );
	}

	$form_fields['private_media_status'] = [
		'label' => __( 'Attachment Status', 'altis' ),
		'input' => 'html',
		'html'  => '<strong>' . esc_html( $status_label ) . '</strong>',
	];

	// Visibility override dropdown.
	$html = sprintf(
		'<select name="attachments[%d][private_media_override]" class="private-media-override" data-attachment-id="%d">',
		$post->ID,
		$post->ID
	);
	$automatic_label = Visibility\compute_automatic_visibility( $post->ID )
		? esc_html__( 'Automatic (currently Public)', 'altis' )
		: esc_html__( 'Automatic (currently Private)', 'altis' );
	$html .= sprintf( '<option value="auto" %s>%s</option>', selected( $override, 'auto', false ), $automatic_label );
	$html .= sprintf( '<option value="public" %s>%s</option>', selected( $override, 'public', false ), esc_html__( 'Public', 'altis' ) );
	$html .= sprintf( '<option value="private" %s>%s</option>', selected( $override, 'private', false ), esc_html__( 'Private', 'altis' ) );
	$html .= '</select>';

	$form_fields['private_media_override'] = [
		'label' => __( 'Visibility Override', 'altis' ),
		'input' => 'html',
		'html'  => $html,
	];

	// Used In section.
	if ( ! empty( $used_in ) ) {
		$links = [];
		foreach ( $used_in as $pid ) {
			$title = get_the_title( $pid );
			$edit_link = get_edit_post_link( $pid );
			if ( $edit_link ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $title ?: "#{$pid}" ) );
			} else {
				$links[] = esc_html( $title ?: "#{$pid}" );
			}
		}

		$form_fields['private_media_used_in'] = [
			'label' => __( 'Used In', 'altis' ),
			'input' => 'html',
			'html'  => implode( ', ', $links ),
		];
	}

	// Legacy label.
	$metadata = wp_get_attachment_metadata( $post->ID );
	if ( is_array( $metadata ) && ! empty( $metadata['legacy_attachment'] ) ) {
		$form_fields['private_media_legacy'] = [
			'label' => __( 'Legacy', 'altis' ),
			'input' => 'html',
			'html'  => '<em>' . esc_html__( 'Pre-migration attachment', 'altis' ) . '</em>',
		];
	}

	return $form_fields;
}

/**
 * Save visibility field from media modal.
 *
 * @param array $post       The post data.
 * @param array $attachment The attachment fields.
 * @return array The post data.
 */
function save_visibility_field( array $post, array $attachment ) : array {
	if ( ! empty( $attachment['private_media_override'] ) ) {
		$override = sanitize_text_field( $attachment['private_media_override'] );
		Visibility\set_override( (int) $post['ID'], $override );
	}

	return $post;
}

/**
 * AJAX handler for setting visibility from the media modal.
 *
 * @return void
 */
function ajax_set_visibility() : void {
	check_ajax_referer( 'private_media_ajax', 'nonce' );

	$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
	$override = sanitize_text_field( $_POST['override'] ?? '' );

	if ( $attachment_id <= 0 || ! in_array( $override, [ 'auto', 'public', 'private' ], true ) ) {
		wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'altis' ) ] );
	}

	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'altis' ) ] );
	}

	Visibility\set_override( $attachment_id, $override );

	$is_public = Visibility\check_attachment_is_public( $attachment_id );
	wp_send_json_success( [
		'status'   => $is_public ? 'public' : 'private',
		'override' => $override,
	] );
}

/**
 * Enqueue admin assets for private media UI.
 *
 * @param string $hook_suffix The current admin page hook.
 * @return void
 */
function enqueue_assets( string $hook_suffix ) : void {
	// Only enqueue on relevant pages.
	if ( ! in_array( $hook_suffix, [ 'upload.php', 'post.php', 'post-new.php', 'media.php' ], true ) ) {
		return;
	}

	$base_dir = dirname( __DIR__, 2 );
	$base_url = plugin_dir_url( dirname( __DIR__ ) );

	wp_enqueue_script(
		'private-media',
		$base_url . 'assets/private-media.js',
		[ 'jquery', 'media-views' ],
		(string) filemtime( $base_dir . '/assets/private-media.js' ),
		true
	);

	wp_localize_script( 'private-media', 'privateMedia', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'private_media_ajax' ),
	] );

	wp_enqueue_style(
		'private-media',
		$base_url . 'assets/private-media.css',
		[],
		(string) filemtime( $base_dir . '/assets/private-media.css' )
	);
}

/**
 * Display admin notices for private media actions.
 *
 * @return void
 */
function display_admin_notices() : void {
	if ( ! empty( $_GET['private_media_updated'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Attachment visibility updated.', 'altis' )
		);
	}

	if ( ! empty( $_GET['private_media_bulk_updated'] ) ) {
		$count = absint( $_GET['private_media_bulk_updated'] );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: %d: number of attachments updated */
				esc_html__( '%d attachment(s) updated.', 'altis' ),
				$count
			)
		);
	}

	if ( ! empty( $_GET['private_media_rescanned'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Post images have been rescanned.', 'altis' )
		);
	}
}
