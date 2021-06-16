<?php
/**
 * Tachyon Provider for Global Assets.
 *
 * @package altis/media
 */

namespace Altis\Media\Global_Assets;

use AMFWordPress\Provider;
use AssetManagerFramework\Resizable;
use WP_Post;

/**
 * Tachyon extension for the AMF WordPress provider.
 */
class Tachyon_Provider extends Provider implements Resizable {

	/**
	 * Handles resizing of an AMF WordPress attachment.
	 *
	 * @param WP_Post $attachment The attachment post.
	 * @param int $width Target width.
	 * @param int $height Target height.
	 * @param bool|array $crop If truthy crop to the given dimensions, can be a non-associative
	 *                         array of x and y positions where x is 'left', 'center' or 'right'
	 *                         and y is 'top', 'center' or 'bottom'.
	 * @return string The resized asset URL.
	 */
	public function resize( WP_Post $attachment, int $width, int $height, $crop = false ) : string {
		// Determine resize method.
		$method = $crop ? 'resize' : 'fit';

		$tachyon_args = [
			$method => "{$width},{$height}",
		];

		// If crop is true.
		if ( $crop ) {
			if ( is_array( $crop ) ) {
				$tachyon_args['gravity'] = implode( '', array_map( function ( $v ) {
					$map = [
						'top' => 'north',
						'center' => '',
						'bottom' => 'south',
						'left' => 'west',
						'right' => 'east',
					];
					return $map[ $v ];
				}, array_reverse( $crop ) ) );
			} else {
				$tachyon_args = array_merge(
					$tachyon_args,
					$this->get_crop( $attachment, $width, $height )
				);
			}
		}

		$url = add_query_arg(
			urlencode_deep( $tachyon_args ),
			wp_get_attachment_url( $attachment->ID )
		);

		return $url;
	}

	/**
	 * Get Tachyon crop arguments.
	 *
	 * Uses focal point meta data if provided.
	 *
	 * @param WP_Post $attachment The attachment post.
	 * @param integer $width The crop width.
	 * @param integer $height The crop height.
	 * @return array
	 */
	protected function get_crop( WP_Post $attachment, int $width, int $height ) : array {
		$metadata = wp_get_attachment_metadata( $attachment->ID, true );

		$focal_point = get_post_meta( $attachment->ID, '_focal_point', true );

		if ( ! empty( $focal_point ) ) {
			// Get max size of crop aspect ratio within original image.
			$dimensions = wp_constrain_dimensions( $width, $height, $metadata['width'], $metadata['height'] );

			if ( $dimensions[0] === $metadata['width'] && $dimensions[1] === $metadata['height'] ) {
				return [];
			}

			$crop = [];
			$crop['width']  = $dimensions[0];
			$crop['height'] = $dimensions[1];

			// Set x & y but constrain within original image bounds.
			$crop['x'] = min( $metadata['width'] - $crop['width'], max( 0, $focal_point['x'] - ( $crop['width'] / 2 ) ) );
			$crop['y'] = min( $metadata['height'] - $crop['height'], max( 0, $focal_point['y'] - ( $crop['height'] / 2 ) ) );

			return [ 'crop' => sprintf( '%dpx,%dpx,%dpx,%dpx', $crop['x'], $crop['y'], $crop['width'], $crop['height'] ) ];
		} else {
			return [ 'crop_stategy' => 'smart' ];
		}
	}

}
