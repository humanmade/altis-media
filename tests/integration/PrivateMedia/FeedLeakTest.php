<?php
/**
 * Regression test: private media must not leak signed S3 URLs via feeds.
 *
 * The signing layer (S3 Uploads) presigns wp_get_attachment_url() for any
 * private attachment in *any* request context — feeds included. The feature's
 * the_content signer is gated on is_preview(), and no core feed template emits
 * the attachment file URL, so feeds should never expose a presigned URL.
 * These tests check that.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Signed_URLs;
use Altis\Media\Private_Media\Visibility;
use Codeception\TestCase\WPTestCase;

class FeedLeakTest extends WPTestCase {
	use S3MockTrait;

	/**
	 * Query string a stub signer appends to a private attachment's URL.
	 *
	 * Mirrors the X-Amz-* params S3 Uploads adds when presigning. Its presence
	 * in feed output is the leak we are guarding against.
	 *
	 * @var string
	 */
	private const SIGNED_MARKER = 'X-Amz-Signature=DONOTLEAK0000000000';

	/**
	 * Whether this test added the_content signer (so tearDown can remove it).
	 *
	 * @var bool
	 */
	private bool $added_content_filter = false;

	/**
	 * Set up the S3 mock and a stub presigning layer.
	 *
	 * @return void
	 */
	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();

		// Stand in for S3 Uploads: presign wp_get_attachment_url() for private
		// attachments, in every context, exactly as the real plugin would.
		add_filter( 'wp_get_attachment_url', [ $this, 'simulate_s3_signing' ], 10, 2 );

		// Ensure the feature's the_content signer is active so the is_preview()
		// gate is genuinely exercised end-to-end through the feed render. If the
		// gate ever regressed, replace_private_urls() would call our stub signer
		// and the marker would surface in the feed below.
		if ( ! has_filter( 'the_content', 'Altis\\Media\\Private_Media\\Signed_URLs\\replace_private_urls_in_preview' ) ) {
			add_filter( 'the_content', 'Altis\\Media\\Private_Media\\Signed_URLs\\replace_private_urls_in_preview' );
			$this->added_content_filter = true;
		}
	}

	/**
	 * Remove the stub signer and reset state.
	 *
	 * @return void
	 */
	public function tearDown() : void {
		remove_filter( 'wp_get_attachment_url', [ $this, 'simulate_s3_signing' ], 10 );
		if ( $this->added_content_filter ) {
			remove_filter( 'the_content', 'Altis\\Media\\Private_Media\\Signed_URLs\\replace_private_urls_in_preview' );
		}
		$this->teardown_s3_mock();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Stub S3 Uploads: append a presign marker to private attachment URLs.
	 *
	 * @param string $url     The attachment URL.
	 * @param int    $post_id The attachment ID.
	 * @return string The (possibly signed) URL.
	 */
	public function simulate_s3_signing( $url, $post_id ) : string {
		if ( Visibility\check_attachment_is_public( (int) $post_id ) ) {
			return (string) $url;
		}

		$separator = strpos( (string) $url, '?' ) === false ? '?' : '&';
		return $url . $separator . self::SIGNED_MARKER;
	}

	/**
	 * Render a feed for the given URL and return its body.
	 *
	 * @param string $url Relative URL to route to (e.g. '/?feed=rss2').
	 * @return string The rendered feed XML.
	 */
	private function capture_feed( string $url ) : string {
		$this->go_to( $url );

		$this->assertTrue( is_feed(), "Sanity: {$url} should resolve to a feed." );

		ob_start();
		do_feed();
		return (string) ob_get_clean();
	}

	/**
	 * Build a private attachment with a real upload path and return [id, url].
	 *
	 * The URL is the raw, unsigned upload URL — built without the getter so the
	 * stub signer never contaminates content we embed.
	 *
	 * @return array{0:int,1:string}
	 */
	private function create_private_attachment_with_file() : array {
		$file = '2026/06/private-photo.jpg';

		// Force-private override (priority 1) keeps the attachment private even
		// once it is referenced by a published post (priority 3).
		$id = $this->create_test_attachment(
			[ 'post_status' => 'private' ],
			[ 'altis_override_visibility' => 'private', 'file' => $file, 'width' => 800, 'height' => 600 ]
		);
		update_post_meta( $id, '_wp_attached_file', $file );

		$url = wp_get_upload_dir()['baseurl'] . '/' . $file;

		return [ $id, $url ];
	}

	/**
	 * Sanity: the stub signer must actually sign private attachment URLs.
	 *
	 * Guards the other assertions from being vacuously true — if signing were
	 * inert, "no marker in the feed" would prove nothing.
	 */
	public function testStubSignerSignsPrivateAttachments() {
		[ $id ] = $this->create_private_attachment_with_file();

		$this->assertStringContainsString(
			self::SIGNED_MARKER,
			(string) wp_get_attachment_url( $id ),
			'The stub signer should presign private attachment URLs.'
		);
	}

	/**
	 * A force-private image embedded in a published post must not be signed
	 * when that post is rendered into the main RSS feed.
	 */
	public function testMainFeedDoesNotSignEmbeddedPrivateImage() {
		[ $id, $url ] = $this->create_private_attachment_with_file();

		$content = sprintf(
			'<p>Intro.</p><img class="wp-image-%d" src="%s" alt="" />',
			$id,
			$url
		);

		self::factory()->post->create( [
			'post_status'  => 'publish',
			'post_title'   => 'Post With Private Image',
			'post_content' => $content,
		] );

		$feed = $this->capture_feed( '/?feed=rss2' );

		// Sanity: the post (and its raw, unsigned image URL) did render.
		$this->assertStringContainsString( 'Post With Private Image', $feed, 'The published post should appear in the feed.' );
		$this->assertStringContainsString( $url, $feed, 'The raw (unsigned) image URL should appear in the feed.' );

		// The guarantee: no presigned URL leaked.
		$this->assertStringNotContainsString( self::SIGNED_MARKER, $feed, 'The feed must not contain a presigned URL.' );
		$this->assertStringNotContainsString( 'X-Amz-', $feed, 'The feed must not contain any S3 signature params.' );
	}

	/**
	 * A private attachment's own comment feed must not expose a signed URL,
	 * even when a comment embeds the image markup.
	 */
	public function testAttachmentCommentFeedDoesNotSignPrivateImage() {
		[ $id, $url ] = $this->create_private_attachment_with_file();

		self::factory()->comment->create( [
			'comment_post_ID'  => $id,
			'comment_approved' => '1',
			'comment_content'  => sprintf( 'See <img class="wp-image-%d" src="%s" alt="" />', $id, $url ),
		] );

		$feed = $this->capture_feed( "/?attachment_id={$id}&feed=comments-rss2" );

		$this->assertTrue( is_comment_feed(), 'Sanity: the request should be a comment feed.' );

		// The guarantee: comment rendering does not run the attachment URL
		// through the signer, so no presigned URL appears.
		$this->assertStringNotContainsString( self::SIGNED_MARKER, $feed, 'The comment feed must not contain a presigned URL.' );
		$this->assertStringNotContainsString( 'X-Amz-', $feed, 'The comment feed must not contain any S3 signature params.' );
	}
}
