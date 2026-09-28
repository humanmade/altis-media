<?php
/**
 * Test Safe SVG integration.
 *
 * phpcs:disable WordPress.Files, HM.Files, HM.Functions.NamespacedFunctions, WordPress.NamingConventions
 */

namespace SafeSvg;

/**
 * Test Safe SVG integration.
 */
class SafeSvgTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * Tester.
	 *
	 * @var \IntegrationTester
	 */
	protected $tester;

	/**
	 * Test the remote-fetching dimension callback is removed.
	 *
	 * @return void
	 */
	public function testOnePixelFixCallbackIsRemoved() {
		global $wp_filter;

		\Altis\Media\load_safe_svg();

		$this->assertTrue( class_exists( \SafeSvg\safe_svg::class ) );

		$hook = $wp_filter['wp_get_attachment_image_src'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			$this->assertNull( $hook );
			return;
		}

		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				$is_one_pixel_fix = is_array( $function )
					&& $function[0] instanceof \SafeSvg\safe_svg
					&& $function[1] === 'one_pixel_fix';

				$this->assertFalse( $is_one_pixel_fix, 'Safe SVG one_pixel_fix callback should be removed.' );
			}
		}
	}
}
