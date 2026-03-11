<?php
/**
 * Test attachment query compatibility (spec Section 9).
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Query_Compat;

class QueryCompatTest extends \Codeception\TestCase\WPTestCase {

	protected $tester;

	public function testInheritExpandedForAttachmentQueries() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_status', 'inherit' );

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$this->assertIsArray( $status );
		$this->assertContains( 'inherit', $status );
		$this->assertContains( 'publish', $status );
		$this->assertContains( 'private', $status );
	}

	public function testArrayStatusExpanded() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_status', [ 'inherit' ] );

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$this->assertContains( 'publish', $status );
		$this->assertContains( 'private', $status );
	}

	public function testNonAttachmentQueryUnaffected() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'post' );
		$query->set( 'post_status', 'inherit' );

		Query_Compat\filter_attachment_query( $query );

		// Should remain unchanged.
		$this->assertEquals( 'inherit', $query->get( 'post_status' ) );
	}

	public function testNonInheritStatusUnaffected() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_status', [ 'publish' ] );

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$this->assertNotContains( 'private', $status );
	}

	public function testNoDuplicateStatuses() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 'post_status', [ 'inherit', 'publish' ] );

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$publish_count = count( array_filter( $status, function ( $s ) {
			return $s === 'publish';
		} ) );
		$this->assertEquals( 1, $publish_count );
	}

	public function testEmptyStatusDefaultsToInherit() {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		// Don't set post_status — should default to treating as inherit.

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$this->assertIsArray( $status );
		$this->assertContains( 'inherit', $status );
		$this->assertContains( 'publish', $status );
		$this->assertContains( 'private', $status );
	}

	public function testAttachmentInArrayPostType() {
		$query = new \WP_Query();
		$query->set( 'post_type', [ 'post', 'attachment' ] );
		$query->set( 'post_status', 'inherit' );

		Query_Compat\filter_attachment_query( $query );

		$status = $query->get( 'post_status' );
		$this->assertIsArray( $status );
		$this->assertContains( 'publish', $status );
	}
}
