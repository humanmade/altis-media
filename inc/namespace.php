<?php

namespace HM\Platform\Media;

use Aws\Rekognition\RekognitionClient;
use function HM\AWS_Rekognition\get_attachment_labels;
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
		add_filter( 'ep_post_sync_args_post_prepare_meta', __NAMESPACE__ . '\\add_rekognition_keywords_to_search_index', 10, 2 );
		add_filter( 'ep_search_fields', __NAMESPACE__ . '\\add_rekognition_keywords_to_search_fields' );
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
	// The get_aws_sdk() function will set the correct region automatically.
	if ( isset( $params['region'] ) ) {
		unset( $params['region'] );
	}
	return Platform\get_aws_sdk()->createRekognition( $params );
}

/**
 * Add rekognition keywords to the search index
 *
 * @param array $post_data
 * @param integer $post_id
 * @return array
 */
function add_rekognition_keywords_to_search_index( array $post_data, int $post_id ) : array {

	// This filter is called for all post types, so only add data to our "attachemnts" post type.
	if ( $post_data['post_type'] !== 'attachment' ) {
		return $post_data;
	}

	$post_data['alt'] = get_post_meta( $post_id, '_wp_attachment_image_alt', true );

	$labels = get_attachment_labels( $post_id );
	$labels = array_filter( $labels, function ( array $label ) : bool {
		return $label['Confidence'] > 50;
	} );
	$labels = array_map( function ( array $label ) : string {
		return $label['Name'];
	}, $labels );
	$post_data['rekognition_labels'] = $labels;

	return $post_data;
}

/**
 * Add attachment field for search
 *
 * @param $search_fields
 * @return array
 */
function add_rekognition_keywords_to_search_fields( array $search_fields ) : array {
	$search_fields[] = 'alt';
	$search_fields[] = 'rekognition_labels';

	return $search_fields;
}
