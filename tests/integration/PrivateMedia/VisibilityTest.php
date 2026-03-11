<?php
/**
 * Test visibility priority logic (spec Section 3).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

require_once __DIR__ . '/S3MockTrait.php';

use Altis\Media\Private_Media\Visibility;

class VisibilityTest extends \Codeception\TestCase\WPTestCase {
	use S3MockTrait;

	protected $tester;

	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();
	}

	public function tearDown() : void {
		$this->teardown_s3_mock();
		parent::tearDown();
	}

	public function testDefaultIsPrivate() {
		$id = $this->create_test_attachment();
		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
	}

	public function testForcePrivateOverrideTakesPrecedence() {
		$id = $this->create_test_attachment( [], [
			'altis_override_visibility' => 'private',
			'altis_used_in_published_post' => [ 999 ],
		] );

		$this->assertFalse( Visibility\check_attachment_is_public( $id ), 'Force-private should override used-in-post.' );
	}

	public function testForcePublicOverride() {
		$id = $this->create_test_attachment( [], [
			'altis_override_visibility' => 'public',
		] );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testUsedInPublishedPostIsPublic() {
		$id = $this->create_test_attachment( [], [
			'altis_used_in_published_post' => [ 42 ],
		] );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testLegacyAttachmentIsPublic() {
		$id = $this->create_test_attachment( [], [
			'legacy_attachment' => true,
		] );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testSiteIconMetadataIsPublic() {
		$id = $this->create_test_attachment( [], [
			'site_icon' => true,
		] );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testSiteIconOptionFallback() {
		$id = $this->create_test_attachment();
		update_option( 'site_icon', $id );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );

		// Clean up.
		delete_option( 'site_icon' );
	}

	public function testSetAttachmentVisibilityUpdatesStatus() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ], [
			'altis_override_visibility' => 'public',
		] );

		Visibility\set_attachment_visibility( $id );

		$this->assertEquals( 'publish', get_post_status( $id ) );
		$this->assertAclSetTo( $id, 'public-read' );
	}

	public function testSetAttachmentVisibilityToPrivate() {
		$id = $this->create_test_attachment( [ 'post_status' => 'publish' ] );

		Visibility\set_attachment_visibility( $id );

		$this->assertEquals( 'private', get_post_status( $id ) );
		$this->assertAclSetTo( $id, 'private' );
	}

	public function testGetOverrideDefaultsToAuto() {
		$id = $this->create_test_attachment();
		$this->assertEquals( 'auto', Visibility\get_override( $id ) );
	}

	public function testSetOverride() {
		$id = $this->create_test_attachment();

		Visibility\set_override( $id, 'public' );
		$this->assertEquals( 'public', Visibility\get_override( $id ) );

		Visibility\set_override( $id, 'private' );
		$this->assertEquals( 'private', Visibility\get_override( $id ) );

		Visibility\set_override( $id, 'auto' );
		$this->assertEquals( 'auto', Visibility\get_override( $id ) );
	}

	public function testSetOverrideInvalidValueIgnored() {
		$id = $this->create_test_attachment( [], [
			'altis_override_visibility' => 'public',
		] );

		Visibility\set_override( $id, 'invalid' );
		$this->assertEquals( 'public', Visibility\get_override( $id ) );
	}

	public function testPostReferenceTracking() {
		$id = $this->create_test_attachment();

		$this->assertEmpty( Visibility\get_used_in_posts( $id ) );

		Visibility\add_post_reference( $id, 100 );
		$this->assertEquals( [ 100 ], Visibility\get_used_in_posts( $id ) );

		Visibility\add_post_reference( $id, 200 );
		$this->assertEquals( [ 100, 200 ], Visibility\get_used_in_posts( $id ) );

		// Duplicate add should not create duplicate.
		Visibility\add_post_reference( $id, 100 );
		$this->assertEquals( [ 100, 200 ], Visibility\get_used_in_posts( $id ) );

		Visibility\remove_post_reference( $id, 100 );
		$this->assertEquals( [ 200 ], Visibility\get_used_in_posts( $id ) );

		Visibility\remove_post_reference( $id, 200 );
		$this->assertEmpty( Visibility\get_used_in_posts( $id ) );
	}

	public function testNewAttachmentDefaultsToPrivate() {
		// Test the add_attachment hook directly.
		$id = wp_insert_attachment( [
			'post_type'      => 'attachment',
			'post_title'     => 'New Upload',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		] );

		$this->assertEquals( 'private', get_post_status( $id ) );
		$this->assertAclSetTo( $id, 'private' );
	}

	public function testAuthorCanReadPrivateAttachment() {
		$author_id = $this->factory()->user->create( [ 'role' => 'author' ] );
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		wp_set_current_user( $author_id );

		// Authors have upload_files, so they should be able to read private attachments.
		$this->assertTrue( current_user_can( 'read_post', $attachment_id ), 'Author should be able to read private attachments.' );

		// wp_get_attachment_url should work for private attachments.
		$url = wp_get_attachment_url( $attachment_id );
		$this->assertNotFalse( $url, 'wp_get_attachment_url should work for authors with private attachments.' );

		wp_set_current_user( 0 );
	}

	public function testSubscriberCannotReadPrivateAttachment() {
		$subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		wp_set_current_user( $subscriber_id );

		// Subscribers don't have upload_files, so they should NOT be able to read.
		$this->assertFalse( current_user_can( 'read_post', $attachment_id ), 'Subscriber should not be able to read private attachments.' );

		wp_set_current_user( 0 );
	}
}
