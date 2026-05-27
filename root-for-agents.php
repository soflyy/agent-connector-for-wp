<?php
/**
 * Plugin Name:       Root for Agents
 * Plugin URI:        https://github.com/soflyy/root-for-agents
 * Description:       Give agents unrestricted execution capability on WordPress. Adds shell access, PHP eval, filesystem operations, and process execution to the WordPress MCP stack. Designed for trusted development environments where agents need the same operational capabilities as a human administrator.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       root-for-agents
 *
 * ---------------------------------------------------------------------------
 *  DANGER: This plugin intentionally grants root-equivalent operational
 *  capability (arbitrary shell, PHP eval, filesystem access) to authenticated
 *  administrators and the agents acting on their behalf. It is NOT sandboxed
 *  and NOT intended for production. Enable only in trusted local/dev/staging
 *  environments. See readme.txt.
 * ---------------------------------------------------------------------------
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents;

defined( 'ABSPATH' ) || exit;

define( 'ROOT_FOR_AGENTS_VERSION', '0.1.0' );
define( 'ROOT_FOR_AGENTS_FILE', __FILE__ );
define( 'ROOT_FOR_AGENTS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the RootForAgents\ namespace, rooted at src/.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = ROOT_FOR_AGENTS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Boot the plugin once WordPress is loaded.
 *
 * The plugin is inert unless the operator has explicitly opted in via the
 * mandatory environment gates (see Support\Config). This keeps a stray
 * activation from ever exposing dangerous abilities by accident.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! Support\Config::can_boot() ) {
			// Surface *why* in the admin so the gate isn't a silent mystery.
			add_action( 'admin_notices', array( Support\Config::class, 'render_gate_notice' ) );
			return;
		}
		( new Plugin() )->register();
	}
);
