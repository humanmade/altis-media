<?php

namespace Altis\Media; // @codingStandardsIgnoreLine

use function Altis\register_module;

require_once __DIR__ . '/inc/namespace.php';

// Don't self-initialize if this is not an Altis execution.
if ( ! function_exists( 'add_action' ) ) {
	return;
}

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
