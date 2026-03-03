<?php
/**
 * Test private uploads URL generation chain.
 *
 * Verifies that the WordPress URL filters produce correct output for private
 * attachments: presigned params on the S3 URL, and presign passthrough on
 * Tachyon URLs.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 */

namespace PrivateUploads;

use Altis\Media\Private_Uploads;

/**
 * Test the URL generation chain for private uploads.
 */
class PrivateUploadsUrlChainTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * Tester
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Check that S3 Uploads is fully active (not just autoloadable).
	 *
	 * The class may exist via the autoloader even when the plugin's setup()
	 * has not been called (e.g. in test environments where plugins_loaded
	 * doesn't trigger S3 Uploads init). This checks for the actual filter.
	 *
	 * @return void
	 */
	private function requireS3UploadsActive(): void {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		if ( ! has_filter( 'wp_get_attachment_url' ) ) {
			$this->markTestSkipped( 'S3 Uploads is not active (presigning filter not registered).' );
		}
	}

	/**
	 * Test that wp_get_attachment_url() includes presigned params for a private attachment.
	 *
	 * @return void
	 */
	public function testPresignedUrlForPrivateAttachment() {
		$this->requireS3UploadsActive();

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		// Factory-created attachments lack _wp_attached_file meta, which
		// causes wp_get_attachment_url() to return ?attachment_id=N instead
		// of an S3-style URL. Set it explicitly.
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/03/test-factory-image.jpg' );
		// Enrol in the private uploads feature.
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		// Confirm our filter marks this as private.
		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on draft post should be private.'
		);

		$url = wp_get_attachment_url( $attachment_id );

		$this->assertStringContainsString(
			'X-Amz-Algorithm',
			$url,
			'Private attachment URL should contain presigned X-Amz-Algorithm parameter.'
		);
		$this->assertStringContainsString(
			'X-Amz-Signature',
			$url,
			'Private attachment URL should contain presigned X-Amz-Signature parameter.'
		);
		$this->assertStringContainsString(
			'X-Amz-Expires',
			$url,
			'Private attachment URL should contain presigned X-Amz-Expires parameter.'
		);
	}

	/**
	 * Test that wp_get_attachment_url() does NOT include presigned params for a public attachment.
	 *
	 * @return void
	 */
	public function testNoPresignedUrlForPublicAttachment() {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		// Set _wp_attached_file so wp_get_attachment_url() returns an S3-style URL.
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/03/test-factory-public.jpg' );
		// Enrol in the feature (auto on a published post = public).
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		// Confirm our filter marks this as public.
		$this->assertFalse(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on published post should be public.'
		);

		$url = wp_get_attachment_url( $attachment_id );

		$this->assertStringNotContainsString(
			'X-Amz-Algorithm',
			$url,
			'Public attachment URL should NOT contain presigned parameters.'
		);
	}

	/**
	 * Test that tachyon_url() passes presigned params via the presign parameter.
	 *
	 * @return void
	 */
	public function testTachyonUrlIncludesPresignParam() {
		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not available.' );
		}

		if ( ! defined( 'TACHYON_URL' ) || ! TACHYON_URL ) {
			$this->markTestSkipped( 'TACHYON_URL not defined.' );
		}

		if ( ! defined( 'TACHYON_SERVER_VERSION' ) || version_compare( TACHYON_SERVER_VERSION, '3.0.0', '<' ) ) {
			$this->markTestSkipped(
				'TACHYON_SERVER_VERSION must be >= 3.0.0 for presigned URL passthrough. '
				. 'Current: ' . ( defined( 'TACHYON_SERVER_VERSION' ) ? TACHYON_SERVER_VERSION : 'not defined' )
			);
		}

		// Construct a fake S3 URL with presigned params, as tachyon_url() only
		// looks at the URL string, not the database.
		$upload_dir = wp_upload_dir();
		$s3_url = $upload_dir['baseurl'] . '/2024/01/test-image.jpg';
		$s3_url .= '?X-Amz-Algorithm=AWS4-HMAC-SHA256';
		$s3_url .= '&X-Amz-Credential=test';
		$s3_url .= '&X-Amz-Date=20240101T000000Z';
		$s3_url .= '&X-Amz-Expires=21600';
		$s3_url .= '&X-Amz-Signature=abc123';
		$s3_url .= '&X-Amz-SignedHeaders=host';

		$tachyon = tachyon_url( $s3_url, [ 'w' => 800 ] );

		$this->assertStringContainsString(
			TACHYON_URL,
			$tachyon,
			'URL should be rewritten to Tachyon URL.'
		);
		$this->assertStringContainsString(
			'presign=',
			$tachyon,
			'Tachyon URL should contain a presign parameter with the AWS signature.'
		);
		$this->assertStringNotContainsString(
			'X-Amz-Algorithm',
			parse_url( $tachyon, PHP_URL_QUERY ),
			'X-Amz-* params should be moved into the presign param, not left as separate query args.'
		);
	}

	/**
	 * Test that tachyon_url() does NOT include presign param for public URLs (no X-Amz-* params).
	 *
	 * @return void
	 */
	public function testTachyonUrlNoPresignForPublicUrl() {
		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not available.' );
		}

		if ( ! defined( 'TACHYON_URL' ) || ! TACHYON_URL ) {
			$this->markTestSkipped( 'TACHYON_URL not defined.' );
		}

		$upload_dir = wp_upload_dir();
		$s3_url = $upload_dir['baseurl'] . '/2024/01/test-image.jpg';

		$tachyon = tachyon_url( $s3_url, [ 'w' => 800 ] );

		$this->assertStringNotContainsString(
			'presign=',
			$tachyon,
			'Public Tachyon URL should NOT contain a presign parameter.'
		);
	}

	/**
	 * Test that the full chain works: private attachment → presigned S3 URL → Tachyon URL with presign.
	 *
	 * @return void
	 */
	public function testFullChainPrivateAttachmentToTachyonUrl() {
		$this->requireS3UploadsActive();

		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not available.' );
		}

		if ( ! defined( 'TACHYON_URL' ) || ! TACHYON_URL ) {
			$this->markTestSkipped( 'TACHYON_URL not defined.' );
		}

		if ( ! defined( 'TACHYON_SERVER_VERSION' ) || version_compare( TACHYON_SERVER_VERSION, '3.0.0', '<' ) ) {
			$this->markTestSkipped( 'TACHYON_SERVER_VERSION must be >= 3.0.0.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		// Set _wp_attached_file so wp_get_attachment_url() returns an S3-style URL.
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/03/test-factory-chain.jpg' );
		// Enrol in the private uploads feature.
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		// Get the presigned S3 URL.
		$s3_url = wp_get_attachment_url( $attachment_id );

		$this->assertStringContainsString(
			'X-Amz-Algorithm',
			$s3_url,
			'S3 URL for private attachment should be presigned.'
		);

		// Pass through tachyon_url to simulate Tachyon rewriting.
		$tachyon = tachyon_url( $s3_url, [ 'w' => 800 ] );

		$this->assertStringContainsString(
			TACHYON_URL,
			$tachyon,
			'Should be rewritten to a Tachyon URL.'
		);
		$this->assertStringContainsString(
			'presign=',
			$tachyon,
			'Tachyon URL should carry presigned params via the presign query arg.'
		);
	}

	/**
	 * Test that ACLs are set on all files after metadata is saved.
	 *
	 * @return void
	 */
	public function testAclSetAfterMetadataSave() {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		// Note: set_acl_on_metadata_save() will set _s3_privacy to 'auto'
		// automatically when metadata is saved, so no need to set it here.

		$acl_calls = [];
		add_action( 's3_uploads_set_attachment_files_acl', function ( $id, $acl ) use ( &$acl_calls ) {
			$acl_calls[] = [
				'attachment_id' => $id,
				'acl' => $acl,
			];
		}, 10, 2 );

		// Simulate metadata being saved (triggers our added_post_meta / updated_post_meta hook).
		$metadata = [ 'width' => 100, 'height' => 100, 'file' => '2024/01/test.jpg' ];
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Our hook should have triggered set_attachment_files_acl.
		$matching = array_filter( $acl_calls, function ( $call ) use ( $attachment_id ) {
			return $call['attachment_id'] === $attachment_id && $call['acl'] === 'private';
		} );

		$this->assertNotEmpty(
			$matching,
			'set_attachment_files_acl should be called with "private" ACL after metadata save for a private attachment.'
		);
	}
}
