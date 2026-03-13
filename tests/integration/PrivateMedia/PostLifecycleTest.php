<?php
/**
 * Test post lifecycle transitions (spec Section 1).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

require_once __DIR__ . '/S3MockTrait.php';

use Altis\Media\Private_Media\Post_Lifecycle;
use Altis\Media\Private_Media\Visibility;

class PostLifecycleTest extends \Codeception\TestCase\WPTestCase {
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

	public function testPublishMakesAttachmentsPublic() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf( '<img class="wp-image-%d" src="https://example.com/photo.jpg" />', $attachment_id ),
		] );

		// Publish the post.
		wp_update_post( [
			'ID'          => $post_id,
			'post_status' => 'publish',
		] );

		$this->assertEquals( 'publish', get_post_status( $attachment_id ) );
		$this->assertContains( $post_id, Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'public-read' );
	}

	public function testUnpublishMakesAttachmentsPrivate() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'publish' ], [
			'altis_used_in_published_post' => [],
		] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf( '<img class="wp-image-%d" src="https://example.com/photo.jpg" />', $attachment_id ),
		] );

		// Publish then unpublish.
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
		$this->acl_calls = []; // Reset calls.

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		$this->assertEquals( 'private', get_post_status( $attachment_id ) );
		$this->assertEmpty( Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'private' );
	}

	public function testFeaturedImageTransitions() {
		// Set current user to admin so they can set thumbnails on private attachments.
		wp_set_current_user( 1 );

		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => '',
		] );

		// Use update_post_meta directly since set_post_thumbnail checks caps.
		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );

		// Publish.
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertEquals( 'publish', get_post_status( $attachment_id ), 'Featured image should become publish.' );
		$this->assertContains( $post_id, Visibility\get_used_in_posts( $attachment_id ) );

		wp_set_current_user( 0 );
	}

	public function testMultiPostReferencesKeepPublic() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		$content = sprintf( '<img class="wp-image-%d" src="https://example.com/photo.jpg" />', $attachment_id );

		$post1 = $this->factory()->post->create( [ 'post_status' => 'draft', 'post_content' => $content ] );
		$post2 = $this->factory()->post->create( [ 'post_status' => 'draft', 'post_content' => $content ] );

		// Publish both.
		wp_update_post( [ 'ID' => $post1, 'post_status' => 'publish' ] );
		wp_update_post( [ 'ID' => $post2, 'post_status' => 'publish' ] );

		// Unpublish one — should stay public.
		wp_update_post( [ 'ID' => $post1, 'post_status' => 'draft' ] );

		$this->assertEquals( 'publish', get_post_status( $attachment_id ) );
		$this->assertContains( $post2, Visibility\get_used_in_posts( $attachment_id ) );
	}

	public function testRemovedAttachmentDetection() {
		$att1 = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		$att2 = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$content_both = sprintf(
			'<img class="wp-image-%d" src="https://example.com/a.jpg" /><img class="wp-image-%d" src="https://example.com/b.jpg" />',
			$att1,
			$att2
		);

		$post_id = $this->factory()->post->create( [
			'post_status'  => 'publish',
			'post_content' => $content_both,
		] );

		// Both should be public now.
		// Force the lifecycle to process (since the post was created as publish).
		Post_Lifecycle\handle_publish( get_post( $post_id ) );

		$this->assertEquals( 'publish', get_post_status( $att1 ) );
		$this->assertEquals( 'publish', get_post_status( $att2 ) );

		// Remove att2 from content and resave.
		$content_one = sprintf( '<img class="wp-image-%d" src="https://example.com/a.jpg" />', $att1 );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $content_one ] );

		// Manually call resave handler since save_post fires during transition_post_status context.
		Post_Lifecycle\handle_resave( get_post( $post_id ) );

		$this->assertEquals( 'publish', get_post_status( $att1 ), 'att1 should still be public.' );
		$this->assertEquals( 'private', get_post_status( $att2 ), 'att2 should now be private.' );
	}

	public function testFilterExtensibility() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		// Register a custom meta key.
		add_filter( 'private_media/post_meta_attachment_keys', function ( $keys ) {
			$keys[] = '_custom_image_id';
			return $keys;
		} );

		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => '',
		] );

		update_post_meta( $post_id, '_custom_image_id', $attachment_id );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertEquals( 'publish', get_post_status( $attachment_id ) );

		// Clean up.
		remove_all_filters( 'private_media/post_meta_attachment_keys' );
	}

	public function testIsAllowedPostType() {
		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'post' ) );
		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'page' ) );
		$this->assertFalse( Post_Lifecycle\is_allowed_post_type( 'attachment' ) );
	}

	public function testAllowedPostTypesFilter() {
		add_filter( 'private_media/allowed_post_types', function ( $types ) {
			$types[] = 'custom_type';
			return $types;
		} );

		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'custom_type' ) );

		remove_all_filters( 'private_media/allowed_post_types' );
	}
}
