<?php
/**
 * Altis Media Module.
 *
 * @package altis/media
 */

namespace Altis\Media; // phpcs:ignore

use Altis;

add_action( 'altis.modules.init', function () {
	$default_settings = [
		'enabled' => true,
		'tachyon' => true,
		'smart-media' => [
			'srcset-modifiers' => [ 2, 1.5, 0.5, 0.25 ],
		],
		'gaussholder' => true,
		'rekognition' => [
			'labels' => true,
			'moderation' => false,
			'faces' => false,
			'celebrities' => false,
			'text' => false,
		],
		'global-media-library' => false,
		'local-media-library' => true,
	];
	$options = [
		'defaults' => $default_settings,
	];
	Altis\register_module( 'media', __DIR__, 'Media', $options, __NAMESPACE__ . '\\bootstrap' );
} );
