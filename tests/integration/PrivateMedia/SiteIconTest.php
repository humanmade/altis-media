<?php
/**
 * Test site icon handling (spec Section 12).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

require_once __DIR__ . '/S3MockTrait.php';

use Altis\Media\Private_Media\Site_Icon;
use Altis\Media\Private_Media\Visibility;

class SiteIconTest extends \Codeception\TestCase\WPTestCase {
	use S3MockTrait;

	protected $tester;

	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();
	}

	public function tearDown() : void {
		$this->teardown_s3_mock();
		// Reset site_icon option.
		delete_option( 'site_icon' );
		parent::tearDown();
	}

	public function testSiteIconMarkedPublic() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		// Simulate updating the site_icon option.
		Site_Icon\on_site_icon_update( 0, $id );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertTrue( $metadata['site_icon'] ?? false );
		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testOldSiteIconCleared() {
		$old_id = $this->create_test_attachment( [], [ 'site_icon' => true ] );
		$new_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		Site_Icon\on_site_icon_update( $old_id, $new_id );

		// Old icon should have site_icon cleared.
		$old_metadata = wp_get_attachment_metadata( $old_id );
		$this->assertTrue( ! is_array( $old_metadata ) || ! isset( $old_metadata['site_icon'] ), 'site_icon should be removed from old icon metadata.' );

		// New icon should have site_icon set.
		$new_metadata = wp_get_attachment_metadata( $new_id );
		$this->assertTrue( $new_metadata['site_icon'] ?? false );
	}

	public function testSiteIconAlwaysPublic() {
		$id = $this->create_test_attachment( [], [ 'site_icon' => true ] );
		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}

	public function testSiteIconOptionFallback() {
		$id = $this->create_test_attachment();
		update_option( 'site_icon', $id );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
	}
}
