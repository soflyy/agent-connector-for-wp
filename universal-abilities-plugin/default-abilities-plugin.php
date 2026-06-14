<?php
/**
 * Plugin Name:       Universal Abilities for Agent Connector
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       The Default Abilities pack for Agent Connector for WP. Adds the powerful built-in abilities — arbitrary shell commands, PHP evaluation, filesystem read/write, WP-CLI, and a one-time admin login link — exposed over MCP. Off by default; opt in from the Agent Connector Connection screen.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  agent-connector-for-wp
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-abilities-plugin
 *
 * Agent Connector: Default Abilities
 *
 * ---------------------------------------------------------------------------
 *  DANGER: This plugin intentionally grants root-equivalent operational
 *  capability (arbitrary shell, PHP eval, filesystem access) to authenticated
 *  super admins and the agents acting on their behalf. It is NOT sandboxed and
 *  NOT intended for production. Enable only in trusted local/dev/staging
 *  environments.
 * ---------------------------------------------------------------------------
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities;

defined( 'ABSPATH' ) || exit;

define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_VERSION', '1.0.0' );
define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_FILE', __FILE__ );
define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for this pack's AgentConnectorForWp\DefaultAbilities\
 * namespace, rooted at src/.
 *
 * The shared infrastructure these classes lean on (Support\Config,
 * Services\AuditLogger, Support\Helpers, Support\Sandbox) belongs to the main
 * Agent Connector for WP plugin and is loaded by ITS autoloader — this pack
 * declares "Requires Plugins: agent-connector-for-wp" so that always runs first.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = AGENT_CONNECTOR_DEFAULT_ABILITIES_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Boot once WordPress (and the main plugin) is loaded.
 *
 * The Settings injector hooks the Agent Connector Connection screen so the
 * "Built-in abilities" status, toggle, and warnings render there. The abilities
 * themselves are registered only when Agent Connector is active and the toggle
 * is on.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Degrade silently if the host plugin is missing — its classes/hooks won't
		// exist, so there is nothing to wire up.
		if ( ! defined( 'AGENT_CONNECTOR_FOR_WP_VERSION' ) ) {
			return;
		}

		// Always available in admin so the operator can find (and flip) the toggle.
		if ( is_admin() ) {
			( new Admin\Settings() )->register();
		}

		// Register the powerful abilities only when the host MCP server is active
		// and the operator opted in.
		if ( Admin\Settings::active() ) {
			( new Plugin() )->register();
		}
	},
	// Run after the main plugin's own plugins_loaded boot (default priority 10).
	11
);
