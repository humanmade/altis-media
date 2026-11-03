<?php
/**
 * Private Media — WP-CLI Command.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media;

use Altis\Media\Private_Media\Visibility;
use Altis\Media\Private_Media\Post_Lifecycle;
use WP_CLI;
use WP_CLI\Utils;
use WP_CLI_Command;
use WP_Query;

/**
 * Manage private media visibility.
 */
class CLI_Command extends WP_CLI_Command {
	/**
	 * Set the visibility of a specific attachment.
	 *
	 * ## OPTIONS
	 *
	 * <visibility>
	 * : The visibility to set. 'public' or 'private'.
	 *
	 * <id>
	 * : The attachment ID or filename.
	 *
	 * [--dry-run]
	 * : Preview changes without applying them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp private-media set-visibility public 123
	 *     wp private-media set-visibility private my-image.jpg --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function set_visibility( array $args, array $assoc_args ) : void { // phpcs:ignore HM.Functions.NamespacedFunctions
		$visibility = $args[0];
		$identifier = $args[1];
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( ! in_array( $visibility, [ 'public', 'private' ], true ) ) {
			WP_CLI::error( 'Visibility must be "public" or "private".' );
		}

		// Resolve attachment ID from filename or ID.
		$attachment_id = $this->resolve_attachment_id( $identifier );

		if ( ! $attachment_id ) {
			WP_CLI::error( sprintf( 'Attachment not found: %s', $identifier ) );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			WP_CLI::error( sprintf( 'ID %d is not an attachment.', $attachment_id ) );
		}

		WP_CLI::log( sprintf(
			'Setting attachment %d (%s) to %s.',
			$attachment_id,
			$post->post_title,
			$visibility
		) );

		if ( ! $dry_run ) {
			Visibility\set_override( $attachment_id, $visibility );
		}

		WP_CLI::success( $dry_run ? 'Would update attachment.' : 'Attachment updated.' );
	}

	/**
	 * Fix attachment visibility for published posts in a date range.
	 *
	 * Scans published posts and re-evaluates all their attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--start-date=<date>]
	 * : Start date (Y-m-d). Defaults to 30 days ago.
	 *
	 * [--end-date=<date>]
	 * : End date (Y-m-d). Defaults to today.
	 *
	 * [--dry-run]
	 * : Preview changes without applying them.
	 *
	 * [--verbose]
	 * : Show detailed output for each post.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function fix_attachments( array $args, array $assoc_args ) : void { // phpcs:ignore HM.Functions.NamespacedFunctions
		$start_date = Utils\get_flag_value( $assoc_args, 'start-date', gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end_date = Utils\get_flag_value( $assoc_args, 'end-date', gmdate( 'Y-m-d' ) );
		$dry_run = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$verbose = Utils\get_flag_value( $assoc_args, 'verbose', false );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run mode — no changes will be made.' );
		}

		$post_types = Post_Lifecycle\is_allowed_post_type( 'post' ) ? get_post_types_by_support( 'editor' ) : [];
		if ( empty( $post_types ) ) {
			$post_types = [ 'post', 'page' ];
		}

		$query = new WP_Query( [
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'date_query'     => [
				[
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				],
			],
			'posts_per_page' => 50,
			'paged'          => 1,
			'no_found_rows'  => false,
		] );

		$total = $query->found_posts;
		WP_CLI::log( sprintf( 'Found %d published posts in date range %s to %s.', $total, $start_date, $end_date ) );

		$progress = Utils\make_progress_bar( 'Fixing attachments', $total );
		$fixed = 0;
		$page = 1;

		while ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$attachment_ids = Post_Lifecycle\get_post_attachment_ids( $post );

				if ( $verbose ) {
					WP_CLI::log( sprintf(
						'Post %d (%s): %d attachments found.',
						$post->ID,
						$post->post_title,
						count( $attachment_ids )
					) );
				}

				foreach ( $attachment_ids as $attachment_id ) {
					if ( ! $dry_run ) {
						Visibility\add_post_reference( $attachment_id, $post->ID );
						Visibility\set_attachment_visibility( $attachment_id );
					}
					$fixed++;
				}

				if ( ! $dry_run ) {
					update_post_meta( $post->ID, 'altis_private_media_post', $attachment_ids );
				}

				$progress->tick();
			}

			$page++;
			$query = new WP_Query( [
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'date_query'     => [
					[
						'after'     => $start_date,
						'before'    => $end_date,
						'inclusive' => true,
					],
				],
				'posts_per_page' => 50,
				'paged'          => $page,
				'no_found_rows'  => false,
			] );
		}

		$progress->finish();
		WP_CLI::success( sprintf(
			'%d attachment references %s across %d posts.',
			$fixed,
			$dry_run ? 'would be fixed' : 'fixed',
			$total
		) );
	}

	/**
	 * Resolve an attachment ID from an ID number or filename.
	 *
	 * @param string $identifier The attachment ID or filename.
	 * @return int|null The attachment ID, or null if not found.
	 */
	protected function resolve_attachment_id( string $identifier ) : ?int {
		if ( is_numeric( $identifier ) ) {
			return (int) $identifier;
		}

		// Search by filename.
		global $wpdb;
		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $identifier )
		) );

		return $attachment_id ? (int) $attachment_id : null;
	}
}
