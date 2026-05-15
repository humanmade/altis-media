<?php
/**
 * Test signed URL previews (spec Section 5).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

require_once __DIR__ . '/S3MockTrait.php';

use Altis\Media\Private_Media\Signed_Urls;

class SignedUrlsTest extends \Codeception\TestCase\WPTestCase {
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

	public function testSrcsetDisabledInPreview() {
		// Simulate preview context.
		$GLOBALS['wp_query'] = new \WP_Query();
		$GLOBALS['wp_query']->is_preview = true;

		$sources = [
			300 => [ 'url' => 'https://example.com/photo-300.jpg', 'descriptor' => 'w', 'value' => 300 ],
			600 => [ 'url' => 'https://example.com/photo-600.jpg', 'descriptor' => 'w', 'value' => 600 ],
		];

		$result = Signed_Urls\disable_srcset_in_preview( $sources );
		$this->assertEmpty( $result );

		// Reset.
		$GLOBALS['wp_query']->is_preview = false;
	}

	public function testSrcsetPreservedNormally() {
		$sources = [
			300 => [ 'url' => 'https://example.com/photo-300.jpg', 'descriptor' => 'w', 'value' => 300 ],
		];

		$result = Signed_Urls\disable_srcset_in_preview( $sources );
		$this->assertEquals( $sources, $result );
	}

	public function testNonPreviewContentUnchanged() {
		$content = '<img class="wp-image-1" src="https://example.com/photo.jpg" />';

		// Not in preview mode.
		$result = Signed_Urls\replace_private_urls_in_preview( $content );
		$this->assertEquals( $content, $result );
	}
}
