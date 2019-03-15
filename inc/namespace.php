<?php

namespace HM\Platform\Media;

use HM\Platform;
use Aws\Rekognition\RekognitionClient;

/**
 * Bootstrap function to set up the module.
 *
 * Called from the HM Platform module loader if the module is activated.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'plugins_loaded', function () {
		$config = Platform\get_config()['modules']['media'];
		$vendor_dir = Platform\ROOT_DIR . '/vendor';
		if ( $config['tachyon'] ) {
			require_once $vendor_dir . '/humanmade/tachyon-plugin/tachyon.php';
		}

		if ( $config['smart-media'] ) {
			require_once $vendor_dir . '/humanmade/smart-media/plugin.php';
			remove_filter( 'intermediate_image_sizes_advanced', 'HM\\Media\\Cropper\\prevent_thumbnail_generation' );
		}

		if ( $config['gaussholder'] ) {
			if ( ! empty( $config['gaussholder']['image-sizes'] ) ) {
				add_filter( 'gaussholder.image_sizes', function ( $sizes ) use ( $config ) {
					$sizes = array_merge( $sizes, $config['gaussholder']['image-sizes'] );
					return $sizes;
				} );
			}
			require_once $vendor_dir . '/humanmade/gaussholder/gaussholder.php';
		}

		if ( $config['rekognition'] ) {
			/**
			 * We override the rekognition client to one from our AWS SDK, which
			 * will correctly pick up the authentication, and global middlewares.
			 */
			add_filter( 'hm.aws.rekognition.client', function ( $client, array $params ) : RekognitionClient {
				return Platform\get_aws_sdk()->createRekognition( $params );
			}, 10, 2 );
			require_once $vendor_dir . '/humanmade/aws-rekognition/plugin.php';
		}
	}, 1 );
}
