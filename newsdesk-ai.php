<?php
/**
 * Plugin Name:       NewsDesk AI
 * Plugin URI:        https://etehadwp.com/newsdesk-ai/
 * Description:       An AI newsroom that refuses to publish what it cannot verify. Discovers news from your sources, removes duplicates, fact-checks every claim, audits the result, and leaves you a sourced draft. Never auto-publishes.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            EtehadWP
 * Author URI:        https://etehadwp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       newsdesk-ai
 * Domain Path:       /languages
 *
 * @package NewsDesk\AI
 */

defined( 'ABSPATH' ) || exit;

define( 'NEWSDESK_VERSION', '1.0.1' );
define( 'NEWSDESK_DB_VERSION', '1.0.0' ); // must equal Migrations::latestVersion()
define( 'NEWSDESK_FILE', __FILE__ );
define( 'NEWSDESK_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEWSDESK_URL', plugin_dir_url( __FILE__ ) );
define( 'NEWSDESK_MIN_PHP', '7.4' );
define( 'NEWSDESK_REST_NAMESPACE', 'nd-newsroom/v1' );

/* Auto-publish is a permanent, non-negotiable rule: OFF. Not configurable. */
if ( ! defined( 'NEWSDESK_AUTO_PUBLISH' ) ) {
	define( 'NEWSDESK_AUTO_PUBLISH', false );
}

require_once NEWSDESK_DIR . 'src/Core/Autoloader.php';
NewsDesk\AI\Core\Autoloader::register();

/**
 * Boot the plugin when all plugins are loaded (Action Scheduler may register later).
 */
add_action(
	'plugins_loaded',
	static function () {
		NewsDesk\AI\Core\Plugin::boot();
	},
	5
);

register_activation_hook( __FILE__, array( NewsDesk\AI\Core\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( NewsDesk\AI\Core\Plugin::class, 'deactivate' ) );
