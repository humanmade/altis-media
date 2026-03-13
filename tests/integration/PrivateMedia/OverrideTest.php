<?php
/**
 * Test manual override (spec Section 2).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

require_once __DIR__ . '/S3MockTrait.php';

use Altis\Media\Private_Media\Visibility;

class OverrideTest extends \Codeception\TestCase\WPTestCase {
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

	public function testForcePublicWithNoReferences() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		Visibility\set_override( $id, 'public' );

		$this->assertTrue( Visibility\check_attachment_is_public( $id ) );
		$this->assertEquals( 'publish', get_post_status( $id ) );
		$this->assertAclSetTo( $id, 'public-read' );
	}

	public function testForcePrivateWithReferences() {
		$id = $this->create_test_attachment( [ 'post_status' => 'publish' ], [
			'altis_used_in_published_post' => [ 42 ],
		] );

		Visibility\set_override( $id, 'private' );

		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
		$this->assertEquals( 'private', get_post_status( $id ) );
		$this->assertAclSetTo( $id, 'private' );
	}

	public function testRemoveOverride() {
		$id = $this->create_test_attachment( [ 'post_status' => 'publish' ], [
			'altis_override_visibility' => 'public',
		] );

		Visibility\set_override( $id, 'auto' );

		$this->assertEquals( 'auto', Visibility\get_override( $id ) );
		// Without references, should be private.
		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
	}

	public function testForcePrivateOverridesLegacy() {
		$id = $this->create_test_attachment( [], [
			'legacy_attachment' => true,
			'altis_override_visibility' => 'private',
		] );

		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
	}

	public function testForcePrivateOverridesSiteIcon() {
		$id = $this->create_test_attachment( [], [
			'site_icon' => true,
			'altis_override_visibility' => 'private',
		] );

		$this->assertFalse( Visibility\check_attachment_is_public( $id ) );
	}

	public function testInvalidOverrideIgnored() {
		$id = $this->create_test_attachment();

		Visibility\set_override( $id, 'invalid_value' );

		// Should remain auto.
		$this->assertEquals( 'auto', Visibility\get_override( $id ) );
	}

	public function testOverrideToAutoRemovesMetadata() {
		$id = $this->create_test_attachment( [], [
			'altis_override_visibility' => 'public',
		] );

		Visibility\set_override( $id, 'auto' );

		$metadata = wp_get_attachment_metadata( $id );
		if ( is_array( $metadata ) ) {
			$this->assertArrayNotHasKey( 'altis_override_visibility', $metadata );
		} else {
			// No metadata at all means the key is definitely not present.
			$this->assertTrue( true );
		}
	}
}
