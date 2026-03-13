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
	 * Recorded CDN purge calls: [ attachment_id, ... ].
	 *
	 * @var int[]
	 */
	protected array $cdn_purge_calls = [];

	/**
	 * Set up S3 mock filters.
	 *
	 * @return void
	 */
	protected function setup_s3_mock() : void {
		$this->acl_calls = [];
		$this->cdn_purge_calls = [];

		add_filter( 'private_media/update_s3_acl', function ( $result, $attachment_id, $acl ) {
			$this->acl_calls[ $attachment_id ] = $acl;
			return true; // Short-circuit real S3 call.
		}, 10, 3 );

		add_filter( 'private_media/purge_cdn_cache', function ( $result, $attachment_id ) {
			$this->cdn_purge_calls[] = $attachment_id;
			return true; // Short-circuit.
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
	 * @param array $args     Optional post args.
	 * @param array $metadata Optional metadata to set.
	 * @return int The created attachment ID.
	 */
	protected function create_test_attachment( array $args = [], array $metadata = [] ) : int {
		$defaults = [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'Test Attachment',
			'post_mime_type' => 'image/jpeg',
		];

		// Remove the private-by-default hook temporarily so we can set our own status.
		remove_action( 'add_attachment', 'Altis\\Media\\Private_Media\\Visibility\\set_new_attachment_private' );

		$id = wp_insert_attachment( array_merge( $defaults, $args ) );

		// Re-add the hook.
		add_action( 'add_attachment', 'Altis\\Media\\Private_Media\\Visibility\\set_new_attachment_private' );

		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		// Reset ACL calls from creation.
		unset( $this->acl_calls[ $id ] );

		return $id;
	}

	/**
	 * Tear down S3 mock filters.
	 *
	 * @return void
	 */
	protected function teardown_s3_mock() : void {
		remove_all_filters( 'private_media/update_s3_acl' );
		remove_all_filters( 'private_media/purge_cdn_cache' );
	}
}
