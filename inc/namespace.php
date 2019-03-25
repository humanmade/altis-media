<?php

namespace HM\Platform\Media;

use Aws\Rekognition\RekognitionClient;
use HM\Platform;

/**
 * Bootstrap function to set up the module.
 *
 * Called from the HM Platform module loader if the module is activated.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_plugins', 1 );
}

/**
 * Load all the WordPress plugins that are part of the media module.
 */
function load_plugins() {
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
			add_filter( 'gaussholder.image_sizes', __NAMESPACE__ . '\\set_gaussholder_image_sizes' );
		}
		require_once $vendor_dir . '/humanmade/gaussholder/gaussholder.php';
	}

	if ( $config['rekognition'] ) {
		/**
		 * We override the rekognition client to one from our AWS SDK, which
		 * will correctly pick up the authentication, and global middlewares.
		 */
		add_filter( 'hm.aws.rekognition.client', __NAMESPACE__ . '\\override_aws_rekognition_aws_client', 10, 2 );
		require_once $vendor_dir . '/humanmade/aws-rekognition/plugin.php';
	}
}

/**
 * Set the image sizes that Gaussholder is applied to.
 *
 * @param array $sizes
 * @return array
 */
function set_gaussholder_image_sizes( array $sizes ) : array {
	$config = Platform\get_config()['modules']['media'];
	$sizes = array_merge( $sizes, $config['gaussholder']['image-sizes'] );
	return $sizes;
}

/**
 * Override the AWS Rekognition Client with the one from Platform.
 *
 * @param null|RekognitionClient $client
 * @param array $params
 * @return RekognitionClient
 */
function override_aws_rekognition_aws_client( $client, array $params ) : RekognitionClient {
	return Platform\get_aws_sdk()->createRekognition( $params );
}
