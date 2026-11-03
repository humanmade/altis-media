<?php
/**
 * Test the front-end attachment page guard.
 *
 * Covers the fix for private attachments leaking via core's attachment-page
 * redirect: with wp_attachment_pages_enabled off, redirect_canonical() would
 * 301 the attachment URL straight to a signed presigned S3 URL, exposing the
 * private image to anonymous visitors. The guard 404s those requests.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Attachment_Page;
use Codeception\TestCase\WPTestCase;

class AttachmentPageTest extends WPTestCase {
	use S3MockTrait;

	/**
	 * Set up s3 mock object
	 *
	 * @return void
	 */
	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();
		// Mirror a default WP 6.4+ install: attachment pages disabled, so core
		// redirects attachment URLs to the file. This is the leak path.
		update_option( 'wp_attachment_pages_enabled', 0 );
	}

	/**
	 * Tear down s3 mock object and reset user
	 *
	 * @return void
	 */
	public function tearDown() : void {
		$this->teardown_s3_mock();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * An anonymous visitor must not be able to reach a private attachment's page.
	 */
	public function testPrivateAttachmentPageIs404ForAnonymousUser() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $id ) );

		$this->assertTrue( is_attachment(), 'Sanity: the query should resolve to the attachment.' );

		Attachment_Page\guard_private_attachment_page();

		$this->assertTrue( is_404(), 'Private attachment page should be a 404 for anonymous users.' );
	}

	/**
	 * A subscriber (no edit capability) must also be blocked.
	 */
	public function testPrivateAttachmentPageIs404ForUserWithoutEditCap() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$this->go_to( get_permalink( $id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertTrue( is_404(), 'Private attachment page should be a 404 for users who cannot edit it.' );
	}

	/**
	 * A user who can edit the attachment may still view its page.
	 */
	public function testPrivateAttachmentPageIsAllowedForEditor() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->go_to( get_permalink( $id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertFalse( is_404(), 'A user who can edit the attachment should not be blocked.' );
	}

	/**
	 * A public attachment's page must be left untouched (core handles it).
	 */
	public function testPublicAttachmentPageIsNotBlocked() {
		$id = $this->create_test_attachment( [], [
			'altis_used_in_published_post' => [ 42 ],
		] );

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertFalse( is_404(), 'Public attachment pages should not be blocked.' );
	}

	/**
	 * The guard must cancel core's canonical redirect for a blocked private
	 * attachment, so ?attachment_id=N does not 301 to the slug permalink
	 * (which would leak the title/filename and confirm the ID exists).
	 */
	public function testGuardCancelsCanonicalRedirectForPrivateAttachment() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertTrue( is_404(), 'Sanity: the private attachment page should be a 404.' );
		$this->assertNotFalse(
			has_filter( 'redirect_canonical', '__return_false' ),
			'The guard should suppress redirect_canonical so no slug redirect leaks.'
		);

		// With the filter applied, core resolves no canonical redirect URL
		// (returns null/empty) instead of the slug permalink.
		$this->assertEmpty(
			redirect_canonical( get_permalink( $id ), false ),
			'redirect_canonical() must not produce a redirect for a blocked private attachment.'
		);
	}

	/**
	 * A public attachment must still be allowed to redirect canonically — the
	 * guard only suppresses redirects for blocked private attachments.
	 */
	public function testGuardLeavesCanonicalRedirectForPublicAttachment() {
		$id = $this->create_test_attachment( [], [
			'altis_used_in_published_post' => [ 42 ],
		] );

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertFalse(
			has_filter( 'redirect_canonical', '__return_false' ),
			'The guard must not suppress canonical redirects for public attachments.'
		);
	}

	/**
	 * The guard is a no-op on non-attachment requests.
	 */
	public function testGuardIgnoresNonAttachmentRequests() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );

		Attachment_Page\guard_private_attachment_page();

		$this->assertFalse( is_404(), 'Ordinary posts should be unaffected by the attachment guard.' );
	}
}
