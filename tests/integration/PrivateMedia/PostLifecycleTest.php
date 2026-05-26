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
use Codeception\TestCase\WPTestCase;

class PostLifecycleTest extends WPTestCase {
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

		$this->assertAclMeta( $attachment_id, 'public-read' );
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

		$this->assertAclMeta( $attachment_id, 'private' );
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

		$this->assertAclMeta( $attachment_id, 'public-read' );
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

		$this->assertAclMeta( $attachment_id, 'public-read' );
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

		$this->assertAclMeta( $att1, 'public-read' );
		$this->assertAclMeta( $att2, 'public-read' );

		// Remove att2 from content and resave.
		$content_one = sprintf( '<img class="wp-image-%d" src="https://example.com/a.jpg" />', $att1 );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $content_one ] );

		// Manually call resave handler since save_post fires during transition_post_status context.
		Post_Lifecycle\handle_resave( get_post( $post_id ) );

		$this->assertAclMeta( $att1, 'public-read' );
		$this->assertAclMeta( $att2, 'private' );
	}

	public function testFilterExtensibility() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		// Register a custom meta key.
		add_filter( 'altis.media.private_media.post_meta_attachment_keys', function ( $keys ) {
			$keys[] = '_custom_image_id';
			return $keys;
		} );

		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => '',
		] );

		update_post_meta( $post_id, '_custom_image_id', $attachment_id );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertAclMeta( $attachment_id, 'public-read' );

		// Clean up.
		remove_all_filters( 'altis.media.private_media.post_meta_attachment_keys' );
	}

	public function testPublishMakesFileAttachmentPublic() {
		$attachment_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'application/pdf',
		] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf(
				'<!-- wp:file {"id":%d,"href":"https://example.com/uploads/doc.pdf"} -->'
				. '<div class="wp-block-file"><a href="https://example.com/uploads/doc.pdf">Doc</a></div>'
				. '<!-- /wp:file -->',
				$attachment_id
			),
		] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertAclMeta( $attachment_id, 'public-read' );
		$this->assertContains( $post_id, Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'public-read' );
	}

	public function testPublishMakesAudioAttachmentPublic() {
		$attachment_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'audio/mpeg',
		] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf(
				'<!-- wp:audio {"id":%d} -->'
				. '<figure class="wp-block-audio"><audio controls src="https://example.com/uploads/song.mp3"></audio></figure>'
				. '<!-- /wp:audio -->',
				$attachment_id
			),
		] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertAclMeta( $attachment_id, 'public-read' );
		$this->assertContains( $post_id, Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'public-read' );
	}

	public function testPublishMakesVideoAttachmentPublic() {
		$attachment_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'video/mp4',
		] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf(
				'<!-- wp:video {"id":%d} -->'
				. '<figure class="wp-block-video"><video src="https://example.com/uploads/clip.mp4"></video></figure>'
				. '<!-- /wp:video -->',
				$attachment_id
			),
		] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertAclMeta( $attachment_id, 'public-read' );
		$this->assertContains( $post_id, Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'public-read' );
	}

	public function testUnpublishMakesAudioAttachmentPrivate() {
		$attachment_id = $this->create_test_attachment( [
			'post_status'    => 'publish',
			'post_mime_type' => 'audio/mpeg',
		], [
			'altis_used_in_published_post' => [],
		] );
		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf(
				'<!-- wp:audio {"id":%d} -->'
				. '<figure class="wp-block-audio"><audio controls src="https://example.com/uploads/song.mp3"></audio></figure>'
				. '<!-- /wp:audio -->',
				$attachment_id
			),
		] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
		$this->acl_calls = [];

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		$this->assertAclMeta( $attachment_id, 'private' );
		$this->assertEmpty( Visibility\get_used_in_posts( $attachment_id ) );
		$this->assertAclSetTo( $attachment_id, 'private' );
	}

	public function testMixedMediaPostLifecycle() {
		$image_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'image/jpeg',
		] );
		$audio_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'audio/mpeg',
		] );
		$file_id = $this->create_test_attachment( [
			'post_status'    => 'private',
			'post_mime_type' => 'application/pdf',
		] );

		$post_id = $this->factory()->post->create( [
			'post_status'  => 'draft',
			'post_content' => sprintf(
				'<img class="wp-image-%d" src="https://example.com/photo.jpg" />'
				. '<!-- wp:audio {"id":%d} --><figure class="wp-block-audio"><audio controls src="https://example.com/song.mp3"></audio></figure><!-- /wp:audio -->'
				. '<!-- wp:file {"id":%d,"href":"https://example.com/doc.pdf"} --><div class="wp-block-file"><a href="https://example.com/doc.pdf">Doc</a></div><!-- /wp:file -->',
				$image_id,
				$audio_id,
				$file_id
			),
		] );

		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );

		$this->assertAclMeta( $image_id, 'public-read' );
		$this->assertAclMeta( $audio_id, 'public-read' );
		$this->assertAclMeta( $file_id, 'public-read' );
	}

	public function testIsAllowedPostType() {
		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'post' ) );
		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'page' ) );
		$this->assertFalse( Post_Lifecycle\is_allowed_post_type( 'attachment' ) );
	}

	public function testAllowedPostTypesFilter() {
		add_filter( 'altis.media.private_media.allowed_post_types', function ( $types ) {
			$types[] = 'custom_type';
			return $types;
		} );

		$this->assertTrue( Post_Lifecycle\is_allowed_post_type( 'custom_type' ) );

		remove_all_filters( 'altis.media.private_media.allowed_post_types' );
	}
}
