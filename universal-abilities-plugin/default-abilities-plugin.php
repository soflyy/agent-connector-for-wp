<?php
/**
 * Plugin Name:       Universal Abilities for Agent Connector
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       The companion pack that lets an AI agent actually do things on your site: run shell commands, evaluate PHP, read/write files, run WP-CLI, and log into wp-admin. Complete access to this WordPress install, exposed over MCP to super admins.
 * Version:           1.0.2
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-abilities-plugin
 * Update URI:        https://github.com/soflyy/agent-connector-for-wp
 *
 * Agent Connector: Default Abilities
 *
 * These abilities give a connected agent the full operational reach of a super
 * admin — shell, PHP eval, filesystem, WP-CLI. That is what the pack is for.
 * Execution is restricted to super admins and audit-logged by the host plugin;
 * the host plugin's UI surfaces the warnings the operator needs to see.
 *
 * Besides the abilities, this plugin owns everything around them that isn't
 * part of Agent Connector itself: the sandbox loader for AI-written PHP, the
 * ability-pack directory (a tab on Agent Connector's Abilities page), and
 * updates from GitHub for itself and installed ability packs. The Update URI
 * header keeps WordPress from matching this plugin against wordpress.org.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities;

defined( 'ABSPATH' ) || exit;

define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_VERSION', '1.0.2' );
define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_FILE', __FILE__ );
define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Tells Agent Connector this build runs the sandbox loader and its own updates,
 * so the compatibility layer in Agent Connector's GitHub build stands down.
 */
define( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_STANDALONE', true );

/**
 * Minimal PSR-4 autoloader for this pack's AgentConnectorForWp\DefaultAbilities\
 * namespace, rooted at src/.
 *
 * The shared infrastructure these classes lean on (Support\Config,
 * Services\AuditLogger) belongs to Agent Connector and is loaded by ITS
 * autoloader; everything below only touches it from plugins_loaded on, after
 * every plugin's main file has run.
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
 * Updates for this plugin and installed ability packs from GitHub, and the
 * sandbox loader. The updater is registered whether or not Agent Connector is
 * active, so this plugin can always update.
 *
 * Agent Connector versions without the extension API (older releases) still
 * run their own copies of the updater and the sandbox loader, so
 * against those this plugin only registers its abilities.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_VERSION' ) && ! defined( 'AGENT_CONNECTOR_FOR_WP_EXTENSIONS_API' ) ) {
			return;
		}

		( new Services\PackUpdater() )->register();

		// Auto-load AI-written PHP "plugins" from the sandbox directory, with
		// crash recovery (safe mode) so a fatal in generated code can't take the
		// site down. See Services\SandboxLoader. Same timing as when Agent
		// Connector ran it: during its own plugins_loaded boot (priority 10).
		if ( class_exists( \AgentConnectorForWp\Support\Config::class ) && \AgentConnectorForWp\Support\Config::can_boot() ) {
			( new Services\SandboxLoader() )->run();
		}
	}
);

/**
 * Boot once WordPress (and Agent Connector) is loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'AGENT_CONNECTOR_FOR_WP_VERSION' ) ) {
			// Agent Connector is the MCP server these abilities are exposed
			// through; without it there is nothing to wire up.
			add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_host_notice' );
			return;
		}

		if ( defined( 'AGENT_CONNECTOR_FOR_WP_EXTENSIONS_API' ) ) {
			// The Ability Packs tab on Agent Connector's Abilities page.
			( new Rest\DirectoryController() )->register();
			if ( is_admin() ) {
				( new Admin\AbilityPacksTab() )->register();
			}
		}

		if ( ! \AgentConnectorForWp\Support\Config::can_boot() ) {
			return;
		}

		// Register abilities whenever the host MCP server is active.
		// No separate opt-in toggle: if this plugin is installed and active, the
		// abilities are on. The operator controls exposure by activating/deactivating
		// this plugin.
		( new Plugin() )->register();
	},
	// Run after Agent Connector's own plugins_loaded boot (default priority 10).
	11
);

/**
 * Admin notice while Agent Connector isn't active.
 */
function render_missing_host_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Universal Abilities for Agent Connector needs the Agent Connector plugin. Install and activate Agent Connector to use these abilities.', 'universal-abilities-plugin' )
	);
}
