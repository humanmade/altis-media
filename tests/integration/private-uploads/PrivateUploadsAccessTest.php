<?php
/**
 * E2E tests for private uploads HTTP access.
 *
 * These tests verify that private attachments are NOT accessible via direct
 * URL or Tachyon URL without presigned parameters.
 *
 * IMPORTANT: These tests require a running local-server environment with S3
 * and Tachyon. They create real files on S3 and make real HTTP requests.
 * They commit database changes (not transaction-isolated) and clean up
 * after themselves.
 *
 * Run with: codecept run integration private-uploads/PrivateUploadsAccessTest
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions, WordPress.WP.AlternativeFunctions
 */

namespace PrivateUploads;

use Altis\Media\Private_Uploads;
use S3_Uploads\Plugin as S3_Plugin;

/**
 * E2E test: verify private attachments are not publicly accessible.
 *
 * @group e2e
 * @group private-uploads
 */
class PrivateUploadsAccessTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * Tester
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Attachment IDs created during tests, for cleanup.
	 *
	 * @var int[]
	 */
	private static array $created_attachment_ids = [];

	/**
	 * Post IDs created during tests, for cleanup.
	 *
	 * @var int[]
	 */
	private static array $created_post_ids = [];

	/**
	 * Whether the S3 server supports object-level ACLs.
	 *
	 * @var bool|null Null means not yet checked.
	 */
	private static ?bool $s3_supports_object_acls = null;

	/**
	 * Skip all tests if prerequisites are not met.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		if ( ! defined( 'S3_UPLOADS_BUCKET' ) ) {
			$this->markTestSkipped( 'S3_UPLOADS_BUCKET not defined - S3 not configured.' );
		}

		if ( ! defined( 'TACHYON_URL' ) || ! TACHYON_URL ) {
			$this->markTestSkipped( 'TACHYON_URL not defined.' );
		}
	}

	/**
	 * Check whether the S3 server supports object-level ACLs.
	 *
	 * VersityGW returns 501 Not Implemented for GetObjectAcl. When object
	 * ACLs are not supported, tests that depend on per-object access control
	 * enforcement should be skipped.
	 *
	 * @return bool True if object ACLs are supported.
	 */
	private static function s3_supports_object_acls(): bool {
		if ( self::$s3_supports_object_acls !== null ) {
			return self::$s3_supports_object_acls;
		}

		try {
			$s3 = S3_Plugin::get_instance()->s3();
			$bucket = defined( 'S3_UPLOADS_BUCKET' ) ? S3_UPLOADS_BUCKET : '';
			// Strip any path prefix from the bucket name.
			$bucket = explode( '/', $bucket )[0];

			// Try to get the ACL of a non-existent object. Servers that
			// support ACLs will return NoSuchKey; servers that don't
			// (like VersityGW) return 501 Not Implemented.
			$s3->getObjectAcl( [
				'Bucket' => $bucket,
				'Key'    => 'acl-support-test-' . uniqid() . '.txt',
			] );
			// If we get here without exception, ACLs are supported.
			self::$s3_supports_object_acls = true;
		} catch ( \Aws\S3\Exception\S3Exception $e ) {
			$status_code = $e->getStatusCode();
			if ( $status_code === 501 ) {
				self::$s3_supports_object_acls = false;
			} else {
				// Other errors (like 404 NoSuchKey) mean the API is supported.
				self::$s3_supports_object_acls = true;
			}
		} catch ( \Exception $e ) {
			// If we can't determine, assume not supported.
			self::$s3_supports_object_acls = false;
		}

		return self::$s3_supports_object_acls;
	}

	/**
	 * Skip the current test if the S3 server does not support object ACLs.
	 *
	 * @return void
	 */
	private function requireObjectAclSupport(): void {
		if ( ! self::s3_supports_object_acls() ) {
			$this->markTestSkipped( 'S3 server does not support object ACLs (e.g. VersityGW).' );
		}
	}

	/**
	 * Clean up all created posts and attachments after all tests.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		foreach ( self::$created_attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}
		foreach ( self::$created_post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		parent::tearDownAfterClass();
	}

	/**
	 * Create a real image file and upload it as an attachment.
	 *
	 * Uses wp_insert_attachment() and wp_generate_attachment_metadata()
	 * to go through the full upload pipeline including S3.
	 *
	 * @param int $post_parent Parent post ID.
	 * @return int Attachment ID.
	 */
	private function create_real_attachment( int $post_parent = 0 ): int {
		// Create a real image file in a temp location.
		$tmp_file = wp_tempnam( 'private-uploads-test-' );
		$image = imagecreatetruecolor( 100, 100 );
		$red = imagecolorallocate( $image, 255, 0, 0 );
		imagefill( $image, 0, 0, $red );
		imagejpeg( $image, $tmp_file, 90 );
		imagedestroy( $image );

		// Build the upload array.
		$filename = 'private-uploads-test-' . uniqid() . '.jpg';
		$upload = wp_upload_bits( $filename, null, file_get_contents( $tmp_file ) );
		unlink( $tmp_file );

		if ( ! empty( $upload['error'] ) ) {
			$this->fail( 'Failed to upload test file: ' . $upload['error'] );
		}

		$attachment_data = [
			'post_title' => 'Private Uploads Test Image',
			'post_mime_type' => 'image/jpeg',
			'post_status' => 'inherit',
			'post_parent' => $post_parent,
		];

		$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $post_parent );
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		// Generate metadata (this triggers S3 Uploads' ACL hook).
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		self::$created_attachment_ids[] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Make an HTTP GET request and return the status code.
	 *
	 * Uses wp_remote_get with SSL verification disabled for local dev.
	 *
	 * @param string $url URL to request.
	 * @return int HTTP status code.
	 */
	private function get_http_status( string $url ): int {
		$response = wp_remote_get( $url, [
			'sslverify' => false,
			'timeout' => 15,
			'redirection' => 0,
			// Don't send cookies - simulate anonymous access.
			'cookies' => [],
			'headers' => [],
		] );

		if ( is_wp_error( $response ) ) {
			$this->fail( 'HTTP request failed: ' . $response->get_error_message() . ' (URL: ' . $url . ')' );
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Extract the uploads-relative path from an attachment URL.
	 *
	 * Strips the base URL to get e.g. "2024/01/image.jpg".
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Relative path within uploads.
	 */
	private function get_uploads_relative_path( int $attachment_id ): string {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->assertNotEmpty( $file, 'Attachment should have _wp_attached_file meta.' );
		return $file;
	}

	/**
	 * Build the direct uploads URL for an attachment.
	 *
	 * This is the /uploads/year/month/filename.jpg path that is proxied
	 * directly to S3 by Traefik/nginx.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Full URL.
	 */
	private function get_direct_url( int $attachment_id ): string {
		$relative = $this->get_uploads_relative_path( $attachment_id );
		return home_url( '/uploads/' . $relative );
	}

	/**
	 * Build the Tachyon URL for an attachment WITHOUT presigned params.
	 *
	 * This simulates someone guessing the Tachyon URL structure.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Full Tachyon URL without presigned params.
	 */
	private function get_tachyon_url_without_presign( int $attachment_id ): string {
		$relative = $this->get_uploads_relative_path( $attachment_id );
		return home_url( '/tachyon/' . $relative . '?w=100' );
	}

	/**
	 * Build the Tachyon URL for an attachment WITH presigned params.
	 *
	 * Goes through the full WordPress filter chain to get the correctly
	 * signed URL that Tachyon can use to access private S3 objects.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Full Tachyon URL with presigned params.
	 */
	private function get_tachyon_url_with_presign( int $attachment_id ): string {
		// Get the presigned S3 URL via the WordPress filter chain.
		$presigned_url = wp_get_attachment_url( $attachment_id );

		// Pass it through tachyon_url() which extracts X-Amz-* params
		// into the presign query arg.
		if ( function_exists( 'tachyon_url' ) ) {
			return tachyon_url( $presigned_url, [ 'w' => 100 ] );
		}

		// Fallback: manually construct a Tachyon URL.
		return $presigned_url;
	}

	/**
	 * Test: a public attachment is accessible via direct URL.
	 *
	 * Baseline test to confirm the test infrastructure works.
	 *
	 * @return void
	 */
	public function testPublicAttachmentAccessibleViaDirectUrl() {
		$this->requireObjectAclSupport();

		// Commit so the web server can see our data.
		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		// Sanity check: this should be public.
		$this->assertFalse(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on published post should be public.'
		);

		$direct_url = $this->get_direct_url( $attachment_id );
		$status = $this->get_http_status( $direct_url );

		$this->assertEquals(
			200,
			$status,
			"Public attachment should be accessible via direct URL. URL: $direct_url"
		);
	}

	/**
	 * Test: a public attachment is accessible via Tachyon.
	 *
	 * @return void
	 */
	public function testPublicAttachmentAccessibleViaTachyon() {
		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		$tachyon_url = $this->get_tachyon_url_without_presign( $attachment_id );
		$status = $this->get_http_status( $tachyon_url );

		$this->assertEquals(
			200,
			$status,
			"Public attachment should be accessible via Tachyon. URL: $tachyon_url"
		);
	}

	/**
	 * Test: a private attachment is NOT accessible via direct URL.
	 *
	 * When the S3 object ACL is set to 'private', direct unauthenticated
	 * access via the /uploads/ path should be denied.
	 *
	 * @return void
	 */
	public function testPrivateAttachmentNotAccessibleViaDirectUrl() {
		$this->requireObjectAclSupport();

		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		// Confirm the attachment is private.
		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on draft post should be private.'
		);

		// Explicitly set the ACL to private to be sure.
		S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, 'private' );

		$direct_url = $this->get_direct_url( $attachment_id );
		$status = $this->get_http_status( $direct_url );

		$this->assertContains(
			$status,
			[ 403, 404 ],
			"Private attachment should NOT be accessible via direct URL (expected 403 or 404, got $status). URL: $direct_url"
		);
	}

	/**
	 * Test: a private attachment is NOT accessible via Tachyon without presigned params.
	 *
	 * When someone constructs a Tachyon URL without the presign parameter,
	 * the Tachyon server should fail to access the private S3 object and
	 * return a 404.
	 *
	 * @return void
	 */
	public function testPrivateAttachmentNotAccessibleViaTachyonWithoutPresign() {
		$this->requireObjectAclSupport();

		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on draft post should be private.'
		);

		// Explicitly set the ACL to private.
		S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, 'private' );

		$tachyon_url = $this->get_tachyon_url_without_presign( $attachment_id );
		$status = $this->get_http_status( $tachyon_url );

		$this->assertContains(
			$status,
			[ 403, 404 ],
			"Private attachment should NOT be accessible via Tachyon without presigned params (expected 403 or 404, got $status). URL: $tachyon_url"
		);
	}

	/**
	 * Test: a private attachment IS accessible via Tachyon WITH presigned params.
	 *
	 * The WordPress URL chain should produce a Tachyon URL with a presign
	 * parameter that allows the Tachyon server to fetch the private S3 object.
	 *
	 * @return void
	 */
	public function testPrivateAttachmentAccessibleViaTachyonWithPresign() {
		$this->requireObjectAclSupport();

		if ( ! defined( 'TACHYON_SERVER_VERSION' ) || version_compare( TACHYON_SERVER_VERSION, '3.0.0', '<' ) ) {
			$this->markTestSkipped( 'TACHYON_SERVER_VERSION must be >= 3.0.0 for presigned URL passthrough.' );
		}

		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on draft post should be private.'
		);

		// Explicitly set the ACL to private.
		S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, 'private' );

		$tachyon_url = $this->get_tachyon_url_with_presign( $attachment_id );

		$this->assertStringContainsString(
			'presign=',
			$tachyon_url,
			'Tachyon URL for private attachment should contain presign parameter.'
		);

		$status = $this->get_http_status( $tachyon_url );

		$this->assertEquals(
			200,
			$status,
			"Private attachment should be accessible via Tachyon WITH presigned params. URL: $tachyon_url"
		);
	}

	/**
	 * Test: unattached media is not accessible via direct URL.
	 *
	 * @return void
	 */
	public function testUnattachedMediaNotAccessibleViaDirectUrl() {
		$this->requireObjectAclSupport();

		static::commit_transaction();

		$attachment_id = $this->create_real_attachment( 0 );

		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Unattached media should be private.'
		);

		S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, 'private' );

		$direct_url = $this->get_direct_url( $attachment_id );
		$status = $this->get_http_status( $direct_url );

		$this->assertContains(
			$status,
			[ 403, 404 ],
			"Unattached media should NOT be accessible via direct URL (expected 403 or 404, got $status). URL: $direct_url"
		);
	}

	/**
	 * Test: after publishing a draft post, its attachment becomes publicly accessible.
	 *
	 * @return void
	 */
	public function testPublishingPostMakesAttachmentAccessible() {
		$this->requireObjectAclSupport();

		static::commit_transaction();

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		self::$created_post_ids[] = $post_id;

		$attachment_id = $this->create_real_attachment( $post_id );

		// Confirm private while draft.
		$this->assertTrue(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment on draft post should be private.'
		);

		S3_Plugin::get_instance()->set_attachment_files_acl( $attachment_id, 'private' );

		// Verify not accessible while private.
		$direct_url = $this->get_direct_url( $attachment_id );
		$status_before = $this->get_http_status( $direct_url );

		$this->assertContains(
			$status_before,
			[ 403, 404 ],
			'Attachment should NOT be accessible while post is draft.'
		);

		// Publish the post - this should trigger ACL update via transition_post_status.
		wp_update_post( [
			'ID' => $post_id,
			'post_status' => 'publish',
		] );

		// Now the attachment should be public.
		$this->assertFalse(
			Private_Uploads\is_attachment_private( false, $attachment_id ),
			'Attachment should be public after post is published.'
		);

		$status_after = $this->get_http_status( $direct_url );

		$this->assertEquals(
			200,
			$status_after,
			"Attachment should be accessible via direct URL after post is published. URL: $direct_url"
		);
	}
}
