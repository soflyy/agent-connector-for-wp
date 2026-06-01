<?php
/**
 * Plugin Name:       Agent Connector for WP
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       Give agents unrestricted execution capability on WordPress. Adds shell access, PHP eval, filesystem operations, and process execution to the WordPress MCP stack. Designed for trusted development environments where agents need the same operational capabilities as a human administrator.
 * Version:           1.3.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-connector-for-wp
 *
 * ---------------------------------------------------------------------------
 *  DANGER: This plugin intentionally grants root-equivalent operational
 *  capability (arbitrary shell, PHP eval, filesystem access) to authenticated
 *  administrators/super admins and the agents acting on their behalf. It is
 *  NOT sandboxed
 *  and NOT intended for production. Enable only in trusted local/dev/staging
 *  environments. See readme.txt.
 * ---------------------------------------------------------------------------
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp;

defined( 'ABSPATH' ) || exit;

define( 'AGENT_CONNECTOR_FOR_WP_VERSION', '1.3.0' );
define( 'AGENT_CONNECTOR_FOR_WP_FILE', __FILE__ );
define( 'AGENT_CONNECTOR_FOR_WP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load bundled Composer dependencies via the Jetpack Autoloader.
 *
 * We ship wordpress/mcp-adapter (and its php-mcp-schema dependency) inside this
 * plugin so it works standalone — the separate "MCP Adapter" plugin does not
 * need to be installed. The Jetpack Autoloader (autoload_packages.php, not the
 * plain vendor/autoload.php) deduplicates shared packages across plugins: when
 * several plugins bundle the adapter, only the newest version is loaded, which
 * prevents fatal "class already declared" / version-mismatch conflicts.
 *
 * It also registers this plugin's own AgentConnectorForWp\ namespace (PSR-4, src/),
 * so the fallback autoloader below is only used when dependencies are missing
 * (e.g. a source checkout where `composer install` has not been run yet).
 */
$agent_connector_for_wp_jetpack_autoloader = AGENT_CONNECTOR_FOR_WP_DIR . 'vendor/autoload_packages.php';
$agent_connector_for_wp_has_vendor         = is_readable( $agent_connector_for_wp_jetpack_autoloader );
if ( $agent_connector_for_wp_has_vendor ) {
	require_once $agent_connector_for_wp_jetpack_autoloader;
}
unset( $agent_connector_for_wp_jetpack_autoloader );

/**
 * Minimal PSR-4 fallback autoloader for the AgentConnectorForWp\ namespace, rooted at
 * src/.
 *
 * Registered only when the Jetpack autoloader is absent (e.g. a source checkout
 * where `composer install` has not been run). When vendor/ is present the
 * Jetpack autoloader already maps the AgentConnectorForWp\ namespace, and registering
 * a second loader here would re-require the class files and trigger a "Cannot
 * declare class … already in use" fatal.
 */
if ( ! $agent_connector_for_wp_has_vendor ) {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = AGENT_CONNECTOR_FOR_WP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}
unset( $agent_connector_for_wp_has_vendor );

/**
 * Boot the plugin once WordPress is loaded.
 *
 * The plugin is inert unless the operator has explicitly switched it on from the
 * Connection screen (see Support\Config). This keeps a stray activation from ever
 * exposing dangerous abilities by accident.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// The Connection screen is always available — even while inert — because
		// it's the only place to switch the plugin on. It registers nothing
		// dangerous; the gates below still guard the MCP server and abilities.
		if ( is_admin() ) {
			( new Admin\ConnectionPage() )->register();
		}

		if ( ! Support\Config::can_boot() ) {
			// Surface *why* it's off so the disabled state isn't a silent mystery.
			add_action( 'admin_notices', array( Support\Config::class, 'render_gate_notice' ) );
			return;
		}

		// Auto-load AI-written PHP "plugins" from the sandbox directory, with
		// crash recovery (safe mode) so a fatal in generated code can't take the
		// site down. See Services\SandboxLoader.
		( new Services\SandboxLoader() )->run();

		/**
		 * Ensure the MCP Adapter is running.
		 *
		 * This is the plugin's first job: run the MCP server so the abilities
		 * other plugins registered (third-party abilities) are exposed. It's
		 * always on while the plugin is enabled.
		 *
		 * If the standalone "MCP Adapter" plugin is active it will already have
		 * booted the adapter; McpAdapter::instance() is an idempotent singleton,
		 * so calling it again is safe. If that plugin is *not* installed, this is
		 * what brings the bundled adapter (and its default MCP server) to life.
		 */
		if ( class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}

		// The plugin's own (built-in) abilities are opt-in and off by default;
		// register them only when the operator has turned them on.
		if ( Support\Config::builtin_abilities_enabled() ) {
			( new Plugin() )->register();
		}
	}
);
