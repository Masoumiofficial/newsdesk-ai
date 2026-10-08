<?php
/**
 * Uninstall handler — data is kept unless explicitly deleted (§58).
 *
 * @package NewsDesk\AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Core/Autoloader.php';
NewsDesk\AI\Core\Autoloader::register();

NewsDesk\AI\Core\Uninstaller::run();
