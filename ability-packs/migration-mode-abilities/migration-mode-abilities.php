<?php
/**
 * Plugin Name:       Migration Mode Abilities for Agent Connector
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       Lets an agent flip WordPress plugins on/off per request so one URL can be rendered as if only a chosen builder/stack were active — built for migrating between page builders (e.g. Elementor → Oxygen) without a staging clone. Registers MCP abilities (auto-exposed by Agent Connector's mcp-adapter) and installs a must-use plugin that does the per-request switching. Not IP-based: a secret token gates the switch.
 * Version:           0.0.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  agent-connector-for-wp
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * Text Domain:       migration-mode-abilities
 *
 * Agent Connector: Ability Pack
 *
 * Unlike the per-plugin packs, this one has no single "Agent Connector Target":
 * it arbitrates the whole active-plugin set so a site can be previewed as
 * different builder/plugin stacks during a migration.
 *
 * You write NO permission / auth / domain-lock / audit code. Agent Connector
 * injects all of it for every ability registered here.
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities;

defined( 'ABSPATH' ) || exit;

define( 'ACM_VERSION', '1.0.0' );
define( 'ACM_FILE', __FILE__ );
define( 'ACM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACM_BASENAME', plugin_basename( __FILE__ ) );

// Minimal PSR-4 autoloader for MigrationMode\Abilities\, rooted at src/.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$path = ACM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Seed config + install the mu-plugin on activation.
register_activation_hook(
	__FILE__,
	static function (): void {
		Config::seed_defaults();
		MuInstaller::ensure();
	}
);

// Self-heal: keep the mu-plugin present and current on every load.
add_action( 'plugins_loaded', array( MuInstaller::class, 'ensure' ), 5 );

// Register abilities once Agent Connector's Abilities API is available. The
// actual host-active guard lives in Plugin::register_abilities().
add_action(
	'plugins_loaded',
	static function (): void {
		( new Plugin() )->register();
	},
	11
);
