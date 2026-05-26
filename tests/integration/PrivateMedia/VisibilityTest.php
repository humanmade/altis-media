<?php
/**
 * Test visibility priority logic (spec Section 3).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Visibility;
use Codeception\TestCase\WPTestCase;

class VisibilityTest extends WPTestCase {
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

	public function testFeatureTrackedAttachmentDefaultsToPrivate() {
		// An attachment with the ACL meta set (i.e. touched by the feature) and
		// no other priority signals — overrides, post references, legacy flag,
		// site icon — falls through to the default-private branch.
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
	}

	public function testPreFeatureAttachmentIsPublic() {
		// An attachment with no _altis_media_acl meta predates the feature.
		// Without this safety net, enabling private media on an existing site
		// would silently break every previously-uploaded image until migrate ran.
		$id = $this->create_test_attachment();

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
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

	public function testSetAttachmentVisibilityUpdatesMeta() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ], [
			'altis_override_visibility' => 'public',
		] );

		Visibility\set_attachment_visibility( $id );

		$this->assertAclMeta( $id, 'public-read' );
		$this->assertAclSetTo( $id, 'public-read' );
	}

	public function testSetAttachmentVisibilityToPrivate() {
		$id = $this->create_test_attachment( [ 'post_status' => 'publish' ] );

		Visibility\set_attachment_visibility( $id );

		$this->assertAclMeta( $id, 'private' );
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

		$this->assertAclMeta( $id, 'private' );
		$this->assertAclSetTo( $id, 'private' );
		// Attachment post_status must stay at WP's default — the feature stores
		// privacy in post meta, never in the status field. Use get_post_field
		// to read the raw DB value; get_post_status() resolves 'inherit' to the
		// parent's status (or 'publish' for orphans).
		$this->assertEquals( 'inherit', get_post_field( 'post_status', $id ) );
	}

	public function testPrivateAttachmentStillUsableByAuthors() {
		// Attachments live at WP's default `inherit` status regardless of
		// their ACL meta, so authors retain the read access WP grants by
		// default. This regression-guards against re-introducing a status flip
		// that would re-trigger WP's read_private_posts cap check.
		$author_id = $this->factory()->user->create( [ 'role' => 'author' ] );
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		wp_set_current_user( $author_id );

		$url = wp_get_attachment_url( $attachment_id );
		$this->assertNotFalse( $url, 'wp_get_attachment_url should work for authors with private attachments.' );

		wp_set_current_user( 0 );
	}
}
