<?php
/**
 * Test signed URL previews (spec Section 5).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Signed_URLs;
use Codeception\TestCase\WPTestCase;

class SignedUrlsTest extends WPTestCase {
	use S3MockTrait;

	protected $tester;

	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();

		// In the codecept integration env, plugins_loaded fires before Altis
		// bootstraps, so Altis\Media\load_plugins() never runs and Tachyon
		// isn't required. Force it now so tachyon_url() is available.
		if ( ! function_exists( 'tachyon_url' ) && function_exists( 'Altis\\Media\\load_plugins' ) ) {
			\Altis\Media\load_plugins();
		}
	}

	public function tearDown() : void {
		$this->teardown_s3_mock();
		parent::tearDown();
	}

	public function testSrcsetDisabledForPrivateAttachment() {
		$attachment_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$sources = [
			300 => [ 'url' => 'https://example.com/photo-300.jpg', 'descriptor' => 'w', 'value' => 300 ],
			600 => [ 'url' => 'https://example.com/photo-600.jpg', 'descriptor' => 'w', 'value' => 600 ],
		];

		$result = Signed_URLs\disable_srcset_for_private_attachments(
			$sources,
			[ 800, 600 ],
			'https://example.com/photo.jpg',
			[],
			$attachment_id
		);

		$this->assertEmpty( $result );
	}

	public function testSrcsetPreservedForPublicAttachment() {
		// Force public via override so the priority check returns true regardless
		// of whether the attachment is referenced by a published post.
		$attachment_id = $this->create_test_attachment( [], [
			'altis_override_visibility' => 'public',
		] );

		$sources = [
			300 => [ 'url' => 'https://example.com/photo-300.jpg', 'descriptor' => 'w', 'value' => 300 ],
		];

		$result = Signed_URLs\disable_srcset_for_private_attachments(
			$sources,
			[ 800, 600 ],
			'https://example.com/photo.jpg',
			[],
			$attachment_id
		);

		$this->assertEquals( $sources, $result );
	}

	public function testNonPreviewContentUnchanged() {
		$content = '<img class="wp-image-1" src="https://example.com/photo.jpg" />';

		// Not in preview mode.
		$result = Signed_URLs\replace_private_urls_in_preview( $content );
		$this->assertEquals( $content, $result );
	}


	public function testAttachmentLinkHrefWrappedInTachyonForPrivateImage() {
		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not loaded.' );
		}

		$attachment_id = $this->create_test_attachment(
			[ 'post_status' => 'private' ],
			[ 'file' => '2026/05/photo.jpg', 'width' => 800, 'height' => 600 ]
		);
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/05/photo.jpg' );

		$uploads = wp_get_upload_dir();
		$href    = $uploads['baseurl'] . '/2026/05/photo.jpg?X-Amz-Signature=abc&X-Amz-Date=20260525T000000Z';

		$result = Signed_URLs\sign_attachment_link_href( [ 'href' => $href ], $attachment_id );

		$this->assertStringStartsWith( rtrim( TACHYON_URL, '/' ), $result['href'] );
		$this->assertStringContainsString( 'presign=', $result['href'] );
		$this->assertStringNotContainsString( 'X-Amz-Signature=', $result['href'] );
	}

	public function testAttachmentLinkHrefUnchangedForPublicImage() {
		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not loaded.' );
		}

		$attachment_id = $this->create_test_attachment(
			[ 'post_status' => 'publish' ],
			[ 'file' => '2026/05/photo.jpg' ]
		);
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/05/photo.jpg' );

		$href = 'https://example.com/wp-content/uploads/2026/05/photo.jpg';

		$result = Signed_URLs\sign_attachment_link_href( [ 'href' => $href ], $attachment_id );

		$this->assertEquals( $href, $result['href'] );
	}

	public function testAttachmentLinkHrefUnchangedForNonImage() {
		if ( ! function_exists( 'tachyon_url' ) ) {
			$this->markTestSkipped( 'Tachyon plugin not loaded.' );
		}

		$attachment_id = $this->create_test_attachment(
			[ 'post_status' => 'private', 'post_mime_type' => 'application/pdf' ],
			[ 'file' => '2026/05/doc.pdf' ]
		);
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/05/doc.pdf' );

		$href = 'https://example.com/wp-content/uploads/2026/05/doc.pdf?X-Amz-Signature=abc';

		$result = Signed_URLs\sign_attachment_link_href( [ 'href' => $href ], $attachment_id );

		$this->assertEquals( $href, $result['href'] );
	}
}
