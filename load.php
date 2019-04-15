<?php

namespace HM\Platform\Media;

use function HM\Platform\register_module;

require_once __DIR__ . '/inc/namespace.php';

// Don't self-initialize if this is not a Platform execution.
if ( ! function_exists( 'add_action' ) ) {
	return;
}

add_action( 'hm-platform.modules.init', function () {
	$default_settings = [
		'enabled'     => true,
		'tachyon'     => true,
		'smart-media' => true,
		'gaussholder' => true,
		'rekognition' => true,
	];
	register_module( 'media', __DIR__, 'Media', $default_settings, __NAMESPACE__ . '\\bootstrap' );
} );
