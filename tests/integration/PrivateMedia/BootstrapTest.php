<?php
/**
 * Test the opt-in gate for the Private Media feature.
 *
 * The feature ships off by default. is_active() should return
 * false unless the project's Altis config explicitly sets
 * `modules.media.private-media` to a truthy value.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media;
use Codeception\TestCase\WPTestCase;

class BootstrapTest extends WPTestCase {

	public function testFeatureOffWhenConfigEmpty() {
		$this->assertFalse( Private_Media\is_active( [] ) );
	}

	public function testFeatureOffWhenConfigFalse() {
		$this->assertFalse( Private_Media\is_active( [ 'private-media' => false ] ) );
	}

	public function testFeatureOffWhenConfigZero() {
		$this->assertFalse( Private_Media\is_active( [ 'private-media' => 0 ] ) );
	}

	public function testFeatureOnWhenConfigTrue() {
		// May still be false if the test site is the global media library
		// site — in that case the gate forces off regardless of config. The
		// product-dev test env is not the GML site, so this should pass.
		$this->assertTrue( Private_Media\is_active( [ 'private-media' => true ] ) );
	}
}
