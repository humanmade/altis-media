<?php
/**
 * Private Media — CLI Command Registration.
 *
 * @package altis/media
 */

namespace Altis\Media\Private_Media\CLI;

/**
 * Bootstrap CLI commands.
 *
 * @return void
 */
function bootstrap() {
	\WP_CLI::add_command( 'private-media', '\\Altis\\Media\\Private_Media\\CLI_Command' );
}
