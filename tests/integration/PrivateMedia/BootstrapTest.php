<?php
/**
 * Test the opt-in gate for the Private Media feature.
 *
 * The feature ships off by default. is_private_media_active() should return
 * false unless the project's Altis config explicitly sets
 * `modules.media.private-media` to a truthy value.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use function Altis\Media\Private_Media\is_private_media_active;

class BootstrapTest extends \Codeception\TestCase\WPTestCase {

	public function testFeatureOffWhenConfigEmpty() {
		$this->assertFalse( is_private_media_active( [] ) );
	}

	public function testFeatureOffWhenConfigFalse() {
		$this->assertFalse( is_private_media_active( [ 'private-media' => false ] ) );
	}

	public function testFeatureOffWhenConfigZero() {
		$this->assertFalse( is_private_media_active( [ 'private-media' => 0 ] ) );
	}

	public function testFeatureOnWhenConfigTrue() {
		// May still be false if the test site is the global media library
		// site — in that case the gate forces off regardless of config. The
		// product-dev test env is not the GML site, so this should pass.
		$this->assertTrue( is_private_media_active( [ 'private-media' => true ] ) );
	}
}
