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

	// Bulk action.
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

	// Enqueue assets.
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

	// Admin action handlers.
	add_action( 'admin_init', __NAMESPACE__ . '\\handle_row_actions' );

	// Admin notices.
	add_action( 'admin_notices', __NAMESPACE__ . '\\display_admin_notices' );

	// Bulk action confirmation screen.
	add_action( 'admin_action_private_media_bulk_visibility', __NAMESPACE__ . '\\bulk_visibility_confirmation' );
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
			esc_html__( 'Remove Override', 'altis' )
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

	if ( $post->post_status === 'publish' ) {
		$actions['private_media_publish_images'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( sprintf(
				'admin.php?action=private_media_rescan&post_id=%d&visibility=publish&_wpnonce=%s',
				$post->ID,
				$nonce
			) ) ),
			esc_html__( 'Publish image(s)', 'altis' )
		);
	}

	$actions['private_media_unpublish_images'] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( sprintf(
			'admin.php?action=private_media_rescan&post_id=%d&visibility=unpublish&_wpnonce=%s',
			$post->ID,
			$nonce
		) ) ),
		esc_html__( 'Unpublish image(s)', 'altis' )
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
	$attachment_id = (int) ( $_GET['attachment_id'] ?? 0 );
	$post_id = (int) ( $_GET['post_id'] ?? 0 );

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

		$visibility = sanitize_text_field( $_GET['visibility'] ?? 'publish' );
		if ( $visibility === 'publish' ) {
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
 * Register bulk action in media list table.
 *
 * @param array $actions Existing bulk actions.
 * @return array Modified bulk actions.
 */
function add_bulk_actions( array $actions ) : array {
	$actions['private_media_set_visibility'] = __( 'Set Visibility', 'altis' );
	return $actions;
}

/**
 * Handle bulk visibility action.
 *
 * @param string $redirect_to The redirect URL.
 * @param string $doaction    The action being taken.
 * @param array  $post_ids    Array of post IDs.
 * @return string Modified redirect URL.
 */
function handle_bulk_actions( string $redirect_to, string $doaction, array $post_ids ) : string {
	if ( $doaction !== 'private_media_set_visibility' ) {
		return $redirect_to;
	}

	// Redirect to confirmation screen.
	$ids = implode( ',', array_map( 'intval', $post_ids ) );
	return admin_url( 'admin.php?action=private_media_bulk_visibility&attachment_ids=' . $ids . '&_wpnonce=' . wp_create_nonce( 'private_media_bulk' ) );
}

/**
 * Display the bulk visibility confirmation screen.
 *
 * @return void
 */
function bulk_visibility_confirmation() : void {
	check_admin_referer( 'private_media_bulk' );

	$ids_string = sanitize_text_field( $_GET['attachment_ids'] ?? '' );
	$ids = array_filter( array_map( 'intval', explode( ',', $ids_string ) ) );

	if ( empty( $ids ) ) {
		wp_die( esc_html__( 'No attachments selected.', 'altis' ) );
	}

	// Handle form submission.
	if ( isset( $_POST['private_media_bulk_submit'] ) ) {
		check_admin_referer( 'private_media_bulk_apply' );
		$target = sanitize_text_field( $_POST['visibility'] ?? 'auto' );

		$count = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				Visibility\set_override( $id, $target );
				$count++;
			}
		}

		wp_safe_redirect( add_query_arg( 'private_media_bulk_updated', $count, admin_url( 'upload.php' ) ) );
		exit;
	}

	// Display confirmation form.
	require_once ABSPATH . 'wp-admin/admin-header.php';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Set Media Visibility', 'altis' ); ?></h1>
		<p><?php printf( esc_html__( 'You are about to change visibility for %d attachment(s).', 'altis' ), count( $ids ) ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'private_media_bulk_apply' ); ?>
			<input type="hidden" name="attachment_ids" value="<?php echo esc_attr( $ids_string ); ?>" />

			<table class="form-table">
				<tr>
					<th scope="row"><label for="visibility"><?php esc_html_e( 'Target Visibility', 'altis' ); ?></label></th>
					<td>
						<select name="visibility" id="visibility">
							<option value="public"><?php esc_html_e( 'Force Public', 'altis' ); ?></option>
							<option value="private"><?php esc_html_e( 'Force Private', 'altis' ); ?></option>
							<option value="auto"><?php esc_html_e( 'Remove Override (Automatic)', 'altis' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Selected Attachments', 'altis' ); ?></h2>
			<ul>
			<?php foreach ( $ids as $id ) : ?>
				<?php $post = get_post( $id ); ?>
				<?php if ( $post ) : ?>
					<li><?php echo esc_html( $post->post_title ); ?> (ID: <?php echo esc_html( $id ); ?>)</li>
				<?php endif; ?>
			<?php endforeach; ?>
			</ul>

			<?php submit_button( __( 'Apply', 'altis' ), 'primary', 'private_media_bulk_submit' ); ?>
		</form>
	</div>
	<?php
	require_once ABSPATH . 'wp-admin/admin-footer.php';
	exit;
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
		echo '<span class="private-media-status private-media-status--public">' . esc_html__( 'Public (forced)', 'altis' ) . '</span>';
	} elseif ( $override === 'private' ) {
		echo '<span class="private-media-status private-media-status--private">' . esc_html__( 'Private (forced)', 'altis' ) . '</span>';
	} elseif ( $is_public ) {
		echo '<span class="private-media-status private-media-status--public">' . esc_html__( 'Public', 'altis' ) . '</span>';
	} else {
		echo '<span class="private-media-status private-media-status--private">' . esc_html__( 'Private', 'altis' ) . '</span>';
	}
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
		$status_label = __( 'Forced Public', 'altis' );
	} elseif ( $override === 'private' ) {
		$status_label = __( 'Forced Private', 'altis' );
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
	$html .= sprintf( '<option value="auto" %s>%s</option>', selected( $override, 'auto', false ), esc_html__( 'Automatic', 'altis' ) );
	$html .= sprintf( '<option value="public" %s>%s</option>', selected( $override, 'public', false ), esc_html__( 'Force Public', 'altis' ) );
	$html .= sprintf( '<option value="private" %s>%s</option>', selected( $override, 'private', false ), esc_html__( 'Force Private', 'altis' ) );
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

	$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
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

	$base_url = plugin_dir_url( dirname( __DIR__ ) );

	wp_enqueue_script(
		'private-media',
		$base_url . 'assets/private-media.js',
		[ 'jquery', 'media-views' ],
		'1.0.0',
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
		'1.0.0'
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
		$count = (int) $_GET['private_media_bulk_updated'];
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
