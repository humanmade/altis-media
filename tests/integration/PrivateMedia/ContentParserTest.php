<?php
/**
 * Test content parsing (spec Section 4).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Content_Parser;

class ContentParserTest extends \Codeception\TestCase\WPTestCase {

	protected $tester;

	public function testEmptyContentReturnsEmpty() {
		$this->assertEmpty( Content_Parser\extract_attachments_from_content( '' ) );
	}

	public function testImageWithWpImageClass() {
		$content = '<img class="wp-image-42 size-large" src="https://example.com/uploads/2024/01/photo.jpg" />';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 1, $results );
		$this->assertEquals( 42, $results[0]['attachment_id'] );
		$this->assertStringContainsString( 'photo.jpg', $results[0]['attachment_url'] );
	}

	public function testImageWithSrcBeforeClass() {
		$content = '<img src="https://example.com/uploads/photo.jpg" class="wp-image-55 size-full" />';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 1, $results );
		$this->assertEquals( 55, $results[0]['attachment_id'] );
	}

	public function testImageWithDataFullUrl() {
		$content = '<img class="wp-image-10" src="https://example.com/small.jpg" data-full-url="https://example.com/uploads/full.jpg" />';
		$results = Content_Parser\extract_attachments_from_content( $content );

		// Should find the image (may have multiple matches but deduplicated by ID).
		$ids = array_column( $results, 'attachment_id' );
		$this->assertContains( 10, $ids );
	}

	public function testGutenbergImageUrlImageId() {
		$content = '<!-- wp:cover {"imageUrl":"https:\/\/example.com\/uploads\/hero.jpg","imageId":77} -->';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 1, $results );
		$this->assertEquals( 77, $results[0]['attachment_id'] );
	}

	public function testGutenbergIdSrc() {
		$content = '<!-- wp:video {"id":33,"src":"https:\/\/example.com\/uploads\/video.mp4"} -->';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 1, $results );
		$this->assertEquals( 33, $results[0]['attachment_id'] );
	}

	public function testVideoBlockWithCommentAndSrc() {
		$content = '<!-- wp:video {"id":88} --><figure class="wp-block-video"><video src="https://example.com/uploads/clip.mp4"></video></figure><!-- /wp:video -->';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$ids = array_column( $results, 'attachment_id' );
		$this->assertContains( 88, $ids );
	}

	public function testFileBlockWithWpFileClass() {
		$content = '<div class="wp-block-file wp-file-22"><a href="https://example.com/uploads/doc.pdf">Download</a></div>';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$ids = array_column( $results, 'attachment_id' );
		$this->assertContains( 22, $ids );
	}

	public function testVideoBlockWithWpVideoClass() {
		$content = '<figure class="wp-block-video wp-video-44"><video src="https://example.com/uploads/movie.mp4"></video></figure>';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$ids = array_column( $results, 'attachment_id' );
		$this->assertContains( 44, $ids );
	}

	public function testDeduplication() {
		$content = '<img class="wp-image-5" src="https://example.com/a.jpg" />'
			. '<img src="https://example.com/b.jpg" class="wp-image-5" />';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 1, $results );
		$this->assertEquals( 5, $results[0]['attachment_id'] );
	}

	public function testMultipleAttachments() {
		$content = '<img class="wp-image-1" src="https://example.com/a.jpg" />'
			. '<img class="wp-image-2" src="https://example.com/b.jpg" />'
			. '<img class="wp-image-3" src="https://example.com/c.jpg" />';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$this->assertCount( 3, $results );
		$ids = array_column( $results, 'attachment_id' );
		$this->assertEquals( [ 1, 2, 3 ], $ids );
	}

	public function testExtractAttachmentIds() {
		$content = '<img class="wp-image-10" src="https://example.com/a.jpg" />'
			. '<img class="wp-image-20" src="https://example.com/b.jpg" />';
		$ids = Content_Parser\extract_attachment_ids_from_content( $content );

		$this->assertEquals( [ 10, 20 ], $ids );
	}

	public function testCleanUrlStripsQueryParams() {
		$url = 'https://example.com/uploads/photo.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=test';
		$clean = Content_Parser\clean_url( $url );

		$this->assertEquals( 'https://example.com/uploads/photo.jpg', $clean );
	}

	public function testCleanUrlConvertsTachyon() {
		$url = 'https://example.com/tachyon/uploads/2024/01/photo.jpg';
		$clean = Content_Parser\clean_url( $url );

		$this->assertEquals( 'https://example.com/wp-content/uploads/2024/01/photo.jpg', $clean );
	}

	public function testLazyVideoDataSrc() {
		$content = '<!-- wp:video {"id":99} --><figure class="wp-block-video"><video data-src="https://example.com/uploads/lazy.mp4"></video></figure><!-- /wp:video -->';
		$results = Content_Parser\extract_attachments_from_content( $content );

		$ids = array_column( $results, 'attachment_id' );
		$this->assertContains( 99, $ids );
	}
}
