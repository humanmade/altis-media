<?php
/**
 * Trait for mocking S3 ACL operations in tests.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

/**
 * Provides S3 ACL mocking for private media tests.
 *
 * Usage: Use this trait in your test class, and call $this->setup_s3_mock()
 * in your setUp() method.
 */
trait S3MockTrait {
	/**
	 * Recorded ACL calls: [ attachment_id => acl ].
	 *
	 * @var array<int, string>
	 */
	protected array $acl_calls = [];

	/**
	 * Set up S3 mock filters.
	 *
	 * @return void
	 */
	protected function setup_s3_mock() : void {
		$this->acl_calls = [];

		add_filter( 'altis.media.private_media.s3_acl', function ( $acl, $attachment_id ) {
			$this->acl_calls[ $attachment_id ] = $acl;
			return ''; // Short-circuit real S3 call.
		}, 10, 2 );
	}

	/**
	 * Assert that the ACL was set to a specific value for an attachment.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $expected_acl  The expected ACL value.
	 * @return void
	 */
	protected function assertAclSetTo( int $attachment_id, string $expected_acl ) : void {
		$this->assertArrayHasKey( $attachment_id, $this->acl_calls, "ACL was not set for attachment {$attachment_id}." );
		$this->assertEquals( $expected_acl, $this->acl_calls[ $attachment_id ], "ACL for attachment {$attachment_id} was not set to {$expected_acl}." );
	}

	/**
	 * Assert that the ACL was NOT called for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return void
	 */
	protected function assertAclNotCalled( int $attachment_id ) : void {
		$this->assertArrayNotHasKey( $attachment_id, $this->acl_calls, "ACL was unexpectedly called for attachment {$attachment_id}." );
	}

	/**
	 * Create a test attachment with optional metadata.
	 *
	 * For convenience, passing `post_status => 'private'` or `'publish'` in
	 * $args is translated to the equivalent `_altis_media_acl` post meta value
	 * (`'private'` or `'public-read'`). The attachment itself is always stored
	 * at WP's default `inherit` status — the feature no longer touches the
	 * status field.
	 *
	 * @param array $args     Optional post args.
	 * @param array $metadata Optional metadata to set.
	 * @return int The created attachment ID.
	 */
	protected function create_test_attachment( array $args = [], array $metadata = [] ) : int {
		$acl = null;
		if ( isset( $args['post_status'] ) ) {
			$map = [
				'private' => 'private',
				'publish' => 'public-read',
			];
			if ( isset( $map[ $args['post_status'] ] ) ) {
				$acl = $map[ $args['post_status'] ];
				unset( $args['post_status'] );
			}
		}

		$defaults = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Test Attachment',
			'post_mime_type' => 'image/jpeg',
		];

		// Remove the private-by-default hook so we can set the ACL meta explicitly.
		// The hook is registered at priority 0 (see visibility.php) so we must match.
		remove_action( 'add_attachment', 'Altis\\Media\\Private_Media\\Visibility\\set_new_attachment_private', 0 );

		$id = wp_insert_attachment( array_merge( $defaults, $args ) );

		add_action( 'add_attachment', 'Altis\\Media\\Private_Media\\Visibility\\set_new_attachment_private', 0 );

		if ( $acl !== null ) {
			update_post_meta( $id, '_altis_media_acl', $acl );
		}

		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		// Reset ACL calls from creation.
		unset( $this->acl_calls[ $id ] );

		return $id;
	}

	/**
	 * Read the recorded ACL meta for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string The meta value, or empty string if not set.
	 */
	protected function get_attachment_acl_meta( int $attachment_id ) : string {
		return (string) get_post_meta( $attachment_id, '_altis_media_acl', true );
	}

	/**
	 * Assert the recorded ACL meta for an attachment.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $expected      The expected meta value (e.g. 'private' or 'public-read').
	 * @return void
	 */
	protected function assertAclMeta( int $attachment_id, string $expected ) : void {
		$this->assertSame(
			$expected,
			$this->get_attachment_acl_meta( $attachment_id ),
			"Attachment {$attachment_id} _altis_media_acl meta should be {$expected}."
		);
	}

	/**
	 * Tear down S3 mock filters.
	 *
	 * @return void
	 */
	protected function teardown_s3_mock() : void {
		remove_all_filters( 'altis.media.private_media.s3_acl' );
	}
}
