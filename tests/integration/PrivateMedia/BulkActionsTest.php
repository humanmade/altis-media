<?php
/**
 * Test the bulk visibility actions in the media list table.
 *
 * The bulk handler runs inline during the `handle_bulk_actions-upload`
 * filter, applying the chosen override to each editable attachment and
 * communicating the result via a query arg on the redirect URL. These
 * tests cover the three discrete actions, the cap check, and the no-op
 * passthrough for unrelated actions.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\UI;
use Altis\Media\Private_Media\Visibility;
use Codeception\TestCase\WPTestCase;

class BulkActionsTest extends WPTestCase {
	use S3MockTrait;

	protected $tester;

	/**
	 * Admin user ID — set as the current user so cap checks pass by default.
	 */
	protected int $admin_id;

	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown() : void {
		$this->teardown_s3_mock();
		parent::tearDown();
	}

	public function testForcePublicAppliesOverrideToAllSelectedAttachments() {
		$ids = [
			$this->create_test_attachment( [ 'post_status' => 'private' ] ),
			$this->create_test_attachment( [ 'post_status' => 'private' ] ),
			$this->create_test_attachment( [ 'post_status' => 'private' ] ),
		];

		$result = UI\handle_bulk_actions(
			admin_url( 'upload.php' ),
			'private_media_force_public',
			$ids
		);

		foreach ( $ids as $id ) {
			$this->assertEquals( 'public', Visibility\get_override( $id ) );
		}
		$this->assertStringContainsString( 'private_media_bulk_updated=3', $result );
	}

	public function testForcePrivateAppliesOverrideToAllSelectedAttachments() {
		$ids = [
			$this->create_test_attachment( [ 'post_status' => 'publish' ] ),
			$this->create_test_attachment( [ 'post_status' => 'publish' ] ),
		];

		$result = UI\handle_bulk_actions(
			admin_url( 'upload.php' ),
			'private_media_force_private',
			$ids
		);

		foreach ( $ids as $id ) {
			$this->assertEquals( 'private', Visibility\get_override( $id ) );
		}
		$this->assertStringContainsString( 'private_media_bulk_updated=2', $result );
	}

	public function testRemoveOverrideClearsForcedVisibility() {
		$ids = [
			$this->create_test_attachment( [], [ 'altis_override_visibility' => 'public' ] ),
			$this->create_test_attachment( [], [ 'altis_override_visibility' => 'private' ] ),
		];

		$result = UI\handle_bulk_actions(
			admin_url( 'upload.php' ),
			'private_media_remove_override',
			$ids
		);

		foreach ( $ids as $id ) {
			$this->assertEquals( 'auto', Visibility\get_override( $id ) );
		}
		$this->assertStringContainsString( 'private_media_bulk_updated=2', $result );
	}

	public function testUnrelatedActionReturnsUrlUnchanged() {
		// Our callback is registered on handle_bulk_actions-upload; WP core only
		// fires that filter for actions it doesn't handle itself (trash, delete,
		// etc. are handled directly in upload.php before the filter runs). This
		// asserts our function passes through any action that isn't ours, using
		// an obviously-unrelated name so the intent reads clearly.
		$id = $this->create_test_attachment();
		$redirect = admin_url( 'upload.php?some=value' );

		$result = UI\handle_bulk_actions( $redirect, 'some_other_plugins_action', [ $id ] );

		$this->assertEquals( $redirect, $result );
		$this->assertEquals( 'auto', Visibility\get_override( $id ), 'Override must not be modified for an unrelated action.' );
	}

	public function testAttachmentsWithoutEditCapAreSkipped() {
		$editable_id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		$denied_id   = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		// Block edit_post for one specific attachment so the bulk handler must skip it.
		$cap_filter = function ( array $caps, string $cap, int $user_id, array $args ) use ( $denied_id ) : array {
			if ( $cap === 'edit_post' && isset( $args[0] ) && (int) $args[0] === $denied_id ) {
				return [ 'do_not_allow' ];
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $cap_filter, 10, 4 );

		try {
			$result = UI\handle_bulk_actions(
				admin_url( 'upload.php' ),
				'private_media_force_public',
				[ $editable_id, $denied_id ]
			);
		} finally {
			remove_filter( 'map_meta_cap', $cap_filter, 10 );
		}

		$this->assertEquals( 'public', Visibility\get_override( $editable_id ) );
		$this->assertEquals( 'auto', Visibility\get_override( $denied_id ), 'Denied attachment must not have its override changed.' );
		$this->assertStringContainsString( 'private_media_bulk_updated=1', $result );
	}
}
