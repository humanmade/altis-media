<?php

namespace Altis\Media;

use Aws\Rekognition\RekognitionClient;
use const Altis\ROOT_DIR;
use function Altis\get_aws_sdk;
use function Altis\get_config;
use function HM\AWS_Rekognition\get_attachment_labels;

/**
 * Bootstrap function to set up the module.
 *
 * Called from the Altis module loader if the module is activated.
 *
 * @return void
 */
function bootstrap() {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_plugins', 1 );

	// Remove AWS_Rekognition filter for performance purposes, as ElasticSearch is used instead.
	add_action( 'plugins_loaded', function () {
		remove_filter( 'posts_clauses', 'HM\\AWS_Rekognition\\filter_query_attachment_keywords' );
	}, 11 );
}

/**
 * Load all the WordPress plugins that are part of the media module.
 */
function load_plugins() {
	$config = get_config()['modules']['media'];
	$vendor_dir = ROOT_DIR . '/vendor';

	if ( $config['tachyon'] ) {
		require_once $vendor_dir . '/humanmade/tachyon-plugin/tachyon.php';
	}

	// Smart Media requires Tachyon to work.
	if ( $config['tachyon'] && $config['smart-media'] ) {
		if ( isset( $config['smart-media']['srcset-modifiers'] ) ) {
			add_filter( 'hm.smart-media.image-size-modifiers', function () use ( $config ) : array {
				$modifiers = array_map( 'floatval', (array) $config['smart-media']['srcset-modifiers'] );
				$modifiers = array_unique( $modifiers );
				return $modifiers;
			}, 9 );
		}
		require_once $vendor_dir . '/humanmade/smart-media/plugin.php';
	}

	if ( $config['gaussholder'] ) {
		if ( ! empty( $config['gaussholder']['image-sizes'] ) ) {
			add_filter( 'gaussholder.image_sizes', __NAMESPACE__ . '\\set_gaussholder_image_sizes' );
		}
		require_once $vendor_dir . '/humanmade/gaussholder/gaussholder.php';
		if ( $config['tachyon'] ) {
			add_action( 'plugins_loaded', __NAMESPACE__ . '\\set_gaussholder_filter_after_tachyon', 11 );
		}
	}

	if ( $config['rekognition'] ) {
		/**
		 * We override the rekognition client to one from our AWS SDK, which
		 * will correctly pick up the authentication, and global middlewares.
		 */
		add_filter( 'hm.aws.rekognition.client', __NAMESPACE__ . '\\override_aws_rekognition_aws_client', 10, 2 );
		add_filter( 'ep_post_sync_args_post_prepare_meta', __NAMESPACE__ . '\\add_rekognition_keywords_to_search_index', 10, 2 );
		add_filter( 'ep_search_fields', __NAMESPACE__ . '\\add_rekognition_keywords_to_search_fields' );

		/**
		 * Configure Rekognition features.
		 */
		if ( is_array( $config['rekognition'] ) ) {
			$rekognition = $config['rekognition'];
			add_filter( 'hm.aws.rekognition.labels', get_bool_callback( $rekognition['labels'] ?? true ) );
			add_filter( 'hm.aws.rekognition.moderation', get_bool_callback( $rekognition['moderation'] ?? false ) );
			add_filter( 'hm.aws.rekognition.faces', get_bool_callback( $rekognition['faces'] ?? false ) );
			add_filter( 'hm.aws.rekognition.celebrities', get_bool_callback( $rekognition['celebrities'] ?? false ) );
			add_filter( 'hm.aws.rekognition.text', get_bool_callback( $rekognition['text'] ?? false ) );
		}

		require_once $vendor_dir . '/humanmade/aws-rekognition/plugin.php';
	}

	// Load Safe SVG.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load_safe_svg', 9 );
}

/**
 * Returns a callable that return true or false.
 *
 * @param boolean $value The value to check.
 * @return callable A callback that returns $value.
 */
function get_bool_callback( bool $value ) : callable {
	return $value ? '__return_true' : '__return_false';
}

/**
 * Set the image sizes that Gaussholder is applied to.
 *
 * @param array $sizes
 * @return array
 */
function set_gaussholder_image_sizes( array $sizes ) : array {
	$config = get_config()['modules']['media'];
	$sizes = array_merge( $sizes, $config['gaussholder']['image-sizes'] );
	return $sizes;
}

/**
 * Re-order the Gaussholder content filter after Tachyon.
 *
 * Because Gaussholder and Tachyon filter the post content via the
 * the_content filter, we need to make sure it happens in the correct
 * order, as we want Gaussholder to hook in after Tachyon has done
 * all the image URL replacements.
 *
 * @return void
 */
function set_gaussholder_filter_after_tachyon() {
	remove_filter( 'the_content', 'Gaussholder\\Frontend\\mangle_images', 30 );
	// Tachyon hooks the content at 999999.
	add_filter( 'the_content', 'Gaussholder\\Frontend\\mangle_images', 999999 + 1 );
}

/**
 * Override the AWS Rekognition Client with the one from Altis.
 *
 * @param null|RekognitionClient $client
 * @param array $params
 * @return RekognitionClient
 */
function override_aws_rekognition_aws_client( $client, array $params ) : RekognitionClient {
	return get_aws_sdk()->createRekognition( $params );
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

/**
 * Load the safe SVG upload handler.
 */
function load_safe_svg() {
	require_once ROOT_DIR . '/vendor/darylldoyle/safe-svg/safe-svg.php';
}
