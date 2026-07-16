<?php
/**
 * Plugin Name:       Universal Abilities for Agent Connector
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       The companion pack that lets an AI agent actually do things on your site: run shell commands, evaluate PHP, read/write files, run WP-CLI, and log into wp-admin. Complete access to this WordPress install, exposed over MCP to super admins.
 * Version:           1.0.1
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
 * These abilities give a connected agent the full operational reach of a super
 * admin — shell, PHP eval, filesystem, WP-CLI. That is what the pack is for.
 * Execution is restricted to super admins and audit-logged by the host plugin;
 * the host plugin's UI surfaces the warnings the operator needs to see.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities;

defined( 'ABSPATH' ) || exit;

define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_VERSION', '1.0.1' );
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

		// Register abilities whenever the host MCP server is active.
		// No separate opt-in toggle: if this plugin is installed and active, the
		// abilities are on. The operator controls exposure by activating/deactivating
		// this plugin.
		if ( class_exists( \AgentConnectorForWp\Support\Config::class ) && \AgentConnectorForWp\Support\Config::can_boot() ) {
			( new Plugin() )->register();
		}
	},
	// Run after the main plugin's own plugins_loaded boot (default priority 10).
	11
);
