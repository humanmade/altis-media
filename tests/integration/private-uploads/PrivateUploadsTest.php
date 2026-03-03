<?php
/**
 * Test private uploads functionality.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 */

namespace PrivateUploads;

use Altis\Media\Private_Uploads;

/**
 * Test private uploads feature.
 */
class PrivateUploadsTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * Tester
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Test that auto-managed unattached media (post_parent = 0) is private.
	 *
	 * @return void
	 */
	public function testUnattachedAutoMediaIsPrivate() {
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => 0,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertTrue( $result, 'Auto-managed unattached media should be private.' );
	}

	/**
	 * Test that auto-managed media attached to a draft post is private.
	 *
	 * @return void
	 */
	public function testAutoAttachmentOnDraftPostIsPrivate() {
		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertTrue( $result, 'Auto-managed attachment on draft post should be private.' );
	}

	/**
	 * Test that auto-managed media attached to a published post is public.
	 *
	 * @return void
	 */
	public function testAutoAttachmentOnPublishedPostIsPublic() {
		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertFalse( $result, 'Auto-managed attachment on published post should be public.' );
	}

	/**
	 * Test that legacy images (no _s3_privacy meta) are always public.
	 *
	 * Pre-existing images uploaded before the private uploads feature
	 * was enabled should remain unaffected and publicly accessible.
	 *
	 * @return void
	 */
	public function testLegacyUnattachedMediaIsPublic() {
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => 0,
		] );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertFalse( $result, 'Legacy unattached media (no _s3_privacy meta) should be public.' );
	}

	/**
	 * Test that legacy images on draft posts are still public.
	 *
	 * @return void
	 */
	public function testLegacyAttachmentOnDraftPostIsPublic() {
		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertFalse( $result, 'Legacy attachment on draft post (no _s3_privacy meta) should be public.' );
	}

	/**
	 * Test that manual private override wins over published parent.
	 *
	 * @return void
	 */
	public function testManualPrivateOverridesParentPublished() {
		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		update_post_meta( $attachment_id, '_s3_privacy', 'private' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertTrue( $result, 'Manual private override should win over published parent.' );
	}

	/**
	 * Test that manual public override wins over draft parent.
	 *
	 * @return void
	 */
	public function testManualPublicOverridesParentDraft() {
		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		update_post_meta( $attachment_id, '_s3_privacy', 'public' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		$this->assertFalse( $result, 'Manual public override should win over draft parent.' );
	}

	/**
	 * Test that global site media is always public.
	 *
	 * @return void
	 */
	public function testGlobalSiteMediaAlwaysPublic() {
		$site_id = \Altis\Global_Content\get_site_id();

		if ( empty( $site_id ) ) {
			$this->markTestSkipped( 'Global content site not available.' );
		}

		switch_to_blog( $site_id );

		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => 0,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$result = Private_Uploads\is_attachment_private( false, $attachment_id );

		restore_current_blog();

		$this->assertFalse( $result, 'Global site media should always be public.' );
	}

	/**
	 * Test that publishing a post updates its attachment ACLs to public-read.
	 *
	 * This test mocks the S3 plugin to verify the correct method is called.
	 *
	 * @return void
	 */
	public function testPostPublishTransitionUpdatesAttachments() {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$acl_calls = [];
		add_action( 's3_uploads_set_attachment_files_acl', function ( $id, $acl ) use ( &$acl_calls ) {
			$acl_calls[] = [
				'attachment_id' => $id,
				'acl' => $acl,
			];
		}, 10, 2 );

		// Simulate publish transition.
		$post = get_post( $post_id );
		Private_Uploads\handle_post_status_transition( 'publish', 'draft', $post );

		$this->assertNotEmpty( $acl_calls, 'ACL should be updated when post is published.' );
		$this->assertEquals( 'public-read', $acl_calls[0]['acl'], 'ACL should be set to public-read on publish.' );
		$this->assertEquals( $attachment_id, $acl_calls[0]['attachment_id'], 'Correct attachment should be updated.' );
	}

	/**
	 * Test that unpublishing a post updates its attachment ACLs to private.
	 *
	 * @return void
	 */
	public function testPostUnpublishTransitionUpdatesAttachments() {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'publish',
		] );
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $attachment_id, '_s3_privacy', 'auto' );

		$acl_calls = [];
		add_action( 's3_uploads_set_attachment_files_acl', function ( $id, $acl ) use ( &$acl_calls ) {
			$acl_calls[] = [
				'attachment_id' => $id,
				'acl' => $acl,
			];
		}, 10, 2 );

		// Simulate unpublish transition.
		$post = get_post( $post_id );
		Private_Uploads\handle_post_status_transition( 'draft', 'publish', $post );

		$this->assertNotEmpty( $acl_calls, 'ACL should be updated when post is unpublished.' );
		$this->assertEquals( 'private', $acl_calls[0]['acl'], 'ACL should be set to private on unpublish.' );
	}

	/**
	 * Test that post status transitions skip attachments with manual override.
	 *
	 * @return void
	 */
	public function testPostTransitionSkipsManualOverride() {
		if ( ! class_exists( 'S3_Uploads\\Plugin' ) ) {
			$this->markTestSkipped( 'S3 Uploads plugin not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_status' => 'draft',
		] );

		// Create two attachments: one with manual override, one without.
		$manual_attachment = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $manual_attachment, '_s3_privacy', 'private' );

		$auto_attachment = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $auto_attachment, '_s3_privacy', 'auto' );

		// Also create a legacy attachment (no _s3_privacy meta).
		$legacy_attachment = self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );

		$acl_calls = [];
		add_action( 's3_uploads_set_attachment_files_acl', function ( $id, $acl ) use ( &$acl_calls ) {
			$acl_calls[] = [
				'attachment_id' => $id,
				'acl' => $acl,
			];
		}, 10, 2 );

		// Simulate publish transition.
		$post = get_post( $post_id );
		Private_Uploads\handle_post_status_transition( 'publish', 'draft', $post );

		// Only the auto attachment should be updated.
		$updated_ids = array_column( $acl_calls, 'attachment_id' );
		$this->assertContains( $auto_attachment, $updated_ids, 'Auto attachment should be updated.' );
		$this->assertNotContains( $manual_attachment, $updated_ids, 'Manual override attachment should be skipped.' );
		$this->assertNotContains( $legacy_attachment, $updated_ids, 'Legacy attachment (no _s3_privacy meta) should be skipped.' );
	}

	/**
	 * Test that when the feature is disabled, the filter returns default false.
	 *
	 * @return void
	 */
	public function testFeatureDisabledReturnsDefaultFalse() {
		$attachment_id = self::factory()->attachment->create( [
			'post_parent' => 0,
		] );

		// When the filter is not hooked, calling with default false should stay false.
		// Remove our filter temporarily.
		remove_filter( 's3_uploads_is_attachment_private', 'Altis\\Media\\Private_Uploads\\is_attachment_private', 10 );

		$result = apply_filters( 's3_uploads_is_attachment_private', false, $attachment_id );

		// Re-add our filter.
		add_filter( 's3_uploads_is_attachment_private', 'Altis\\Media\\Private_Uploads\\is_attachment_private', 10, 2 );

		$this->assertFalse( $result, 'With feature disabled, default should be false.' );
	}

	/**
	 * Test that the privacy field exists in attachment edit form.
	 *
	 * @return void
	 */
	public function testPrivacyFieldExists() {
		$attachment_id = self::factory()->attachment->create();
		$post = get_post( $attachment_id );

		$fields = Private_Uploads\add_privacy_field( [], $post );

		$this->assertArrayHasKey( 's3_privacy', $fields, 'Privacy field should exist in attachment form.' );
		$this->assertEquals( 'Privacy', $fields['s3_privacy']['label'], 'Field label should be "Privacy".' );
	}

	/**
	 * Test that saving the privacy field updates post meta.
	 *
	 * @return void
	 */
	public function testSavePrivacyFieldSetsPostMeta() {
		$attachment_id = self::factory()->attachment->create();

		$post_data = [ 'ID' => $attachment_id ];
		$attachment_data = [ 's3_privacy' => 'private' ];

		// If S3 Uploads isn't available, we need to handle the ACL update gracefully.
		Private_Uploads\save_privacy_field( $post_data, $attachment_data );

		$meta = get_post_meta( $attachment_id, '_s3_privacy', true );
		$this->assertEquals( 'private', $meta, 'Privacy meta should be set to private.' );
	}
}
