<?php

namespace Altis\Media; // @codingStandardsIgnoreLine

use function Altis\register_module;

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
	];
	register_module( 'media', __DIR__, 'Media', $default_settings, __NAMESPACE__ . '\\bootstrap' );
} );
