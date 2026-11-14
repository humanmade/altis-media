<?php
/**
 * Test the REST API guard.
 *
 * Private attachments must not exist over REST for users who can't manage media:
 * the single-item endpoint 404s, the collection endpoint omits them, and any
 * signed URL that slips through another route is stripped. Because attachments
 * stay at `inherit` status, core would otherwise serve them — metadata and a
 * signed S3 `source_url` — to anonymous callers.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\REST_API;
use Codeception\TestCase\WPTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test access to private media via the REST API.
 */
class RestApiTest extends WPTestCase {
	use S3MockTrait;

	/**
	 * Set up the S3 mock object
	 *
	 * @return void
	 */
	public function setUp() : void {
		parent::setUp();
		$this->setup_s3_mock();
		$this->ensure_rest_hooks();
	}

	/**
	 * Tear down the S3_mock and user.
	 *
	 * @return void
	 */
	public function tearDown() : void {
		$this->teardown_s3_mock();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Register the guard's hooks if the feature didn't bootstrap them in this env.
	 *
	 * @return void
	 */
	private function ensure_rest_hooks() : void {
		if ( ! has_filter( 'rest_pre_dispatch', 'Altis\\Media\\Private_Media\\REST_API\\hide_private_attachment_item' ) ) {
			REST_API\bootstrap();
		}
	}

	/**
	 * Dispatch a GET request as the current user.
	 *
	 * @param string $route The REST route.
	 * @return WP_REST_Response
	 */
	private function get( string $route ) : WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Extract the attachment IDs from a collection response.
	 *
	 * @param WP_REST_Response $response The collection response.
	 * @return int[]
	 */
	private function ids_in( WP_REST_Response $response ) : array {
		return array_map(
			function ( $item ) {
				return (int) ( is_array( $item ) ? $item['id'] : $item->id );
			},
			(array) $response->get_data()
		);
	}

	// ---- Single item: /wp/v2/media/<id> ----------------------------------

	/**
	 * A private attachment is a 404 for an anonymous caller — as if it doesn't exist.
	 */
	public function testSingleItemIs404ForAnonymousUser() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		wp_set_current_user( 0 );

		$response = $this->get( '/wp/v2/media/' . $id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_post_invalid_id', $response->get_data()['code'] ?? null, 'Should mirror core unknown-ID error.' );
	}

	/**
	 * A subscriber (no upload_files cap) is also given a 404.
	 */
	public function testSingleItemIs404ForUserWithoutUploadCap() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$this->assertSame( 404, $this->get( '/wp/v2/media/' . $id )->get_status() );
	}

	/**
	 * A media-capable user (administrator) can fetch the private attachment with URLs intact.
	 */
	public function testSingleItemReturnsAttachmentForMediaUser() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = $this->get( '/wp/v2/media/' . $id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
		// Media users keep the (signed) URL — not scrubbed.
		$this->assertNotSame( '', $response->get_data()['source_url'] ?? '' );
	}

	/**
	 * A public attachment is served normally to an anonymous caller.
	 *
	 * "Public" means the live visibility check passes — i.e. the attachment is
	 * referenced by a published post (here faked via the used-in metadata), not
	 * merely that its ACL meta reads 'public-read'.
	 */
	public function testSingleItemPublicAttachmentServedToAnonymousUser() {
		$id = $this->create_test_attachment(
			[ 'post_status' => 'publish' ],
			[ 'altis_used_in_published_post' => [ 42 ] ]
		);
		wp_set_current_user( 0 );

		$response = $this->get( '/wp/v2/media/' . $id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
	}

	// ---- Collection: /wp/v2/media ----------------------------------------

	/**
	 * A private attachment is omitted from the collection for anonymous callers,
	 * while public and pre-feature attachments remain listed.
	 */
	public function testCollectionExcludesPrivateForAnonymousUser() {
		$private = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		// Feature-tracked public: ACL meta 'public-read' and referenced by a published post.
		$public  = $this->create_test_attachment( [ 'post_status' => 'publish' ], [ 'altis_used_in_published_post' => [ 42 ] ] );
		$legacy  = $this->create_test_attachment(); // No _altis_media_acl meta — pre-feature, public.

		wp_set_current_user( 0 );
		$ids = $this->ids_in( $this->get( '/wp/v2/media' ) );

		$this->assertNotContains( $private, $ids, 'Private attachment must not appear in the collection.' );
		$this->assertContains( $public, $ids, 'Public attachment should remain listed.' );
		$this->assertContains( $legacy, $ids, 'Pre-feature (no-meta) attachment should remain listed.' );
	}

	/**
	 * A media-capable user sees private attachments in the collection.
	 */
	public function testCollectionIncludesPrivateForMediaUser() {
		$private = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$ids = $this->ids_in( $this->get( '/wp/v2/media' ) );

		$this->assertContains( $private, $ids, 'Media users should see private attachments in the collection.' );
	}

	// ---- Defense in depth: rest_prepare_attachment scrub -----------------

	/**
	 * The scrub backstop blanks every signed URL for a non-media viewer.
	 */
	public function testScrubBlanksSignedUrlsForNonMediaUser() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );
		wp_set_current_user( 0 );

		$response = new WP_REST_Response( [
			'id'            => $id,
			'source_url'    => 'https://s3.example.com/uploads/photo.jpg?X-Amz-Signature=secret',
			'guid'          => [ 'rendered' => 'https://s3.example.com/uploads/photo.jpg' ],
			'media_details' => [
				'sizes' => [
					'thumbnail' => [ 'source_url' => 'https://s3.example.com/uploads/photo-150x150.jpg?X-Amz-Signature=secret' ],
				],
			],
		] );

		$data = REST_API\scrub_private_attachment_urls( $response, get_post( $id ) )->get_data();

		$this->assertSame( '', $data['source_url'] );
		$this->assertSame( '', $data['guid']['rendered'] );
		$this->assertSame( '', $data['media_details']['sizes']['thumbnail']['source_url'] );
	}

	/**
	 * The scrub backstop leaves a media user's URLs untouched.
	 */
	public function testScrubPreservesUrlsForMediaUser() {
		$id = $this->create_test_attachment( [ 'post_status' => 'private' ] );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$response = new WP_REST_Response( [
			'id'         => $id,
			'source_url' => 'https://s3.example.com/uploads/photo.jpg?X-Amz-Signature=secret',
		] );

		$data = REST_API\scrub_private_attachment_urls( $response, get_post( $id ) )->get_data();

		$this->assertStringContainsString( 'X-Amz-Signature', $data['source_url'] );
	}
}
