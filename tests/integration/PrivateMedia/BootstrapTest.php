<?php
/**
 * Test Private Media bootstrap wiring.
 *
 * The query-compat layer must register its `pre_get_posts` hook so that
 * legacy attachments stored as `post_status = private` remain visible in
 * the media library. Private_Media\bootstrap() calls Query_Compat\bootstrap()
 * unconditionally — even when the feature flag is off — but that's a one-line
 * contract best verified by reading namespace.php. What this test guards is
 * the function-level contract of Query_Compat\bootstrap() itself: when called,
 * it registers the hook.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 *
 * @package altis/media
 */

namespace PrivateMedia;

use Altis\Media\Private_Media\Query_Compat;

class BootstrapTest extends \Codeception\TestCase\WPTestCase {

	protected $tester;

	/**
	 * Query_Compat\bootstrap() must register filter_attachment_query on pre_get_posts.
	 */
	public function testQueryCompatBootstrapRegistersHook() {
		$callback = 'Altis\\Media\\Private_Media\\Query_Compat\\filter_attachment_query';

		// Start from a clean slate in case the module's runtime bootstrap already ran.
		remove_action( 'pre_get_posts', $callback );

		Query_Compat\bootstrap();

		$this->assertNotFalse(
			has_action( 'pre_get_posts', $callback ),
			'Query_Compat\\bootstrap() must register filter_attachment_query on pre_get_posts.'
		);
	}
}
