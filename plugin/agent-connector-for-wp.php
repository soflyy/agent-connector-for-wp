<?php
/**
 * Plugin Name:       Agent Connector for WP
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       Give agents unrestricted execution capability on WordPress. Adds shell access, PHP eval, filesystem operations, and process execution to the WordPress MCP stack. Designed for trusted development environments where agents need the same operational capabilities as a human administrator.
 * Version:           1.14.0
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

define( 'AGENT_CONNECTOR_FOR_WP_VERSION', '1.14.0' );
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
 * Load the public ability-registration API (plain functions, global namespace).
 *
 * This is a functions file, not a PSR-4 class, so it is required explicitly
 * rather than autoloaded. It is loaded unconditionally and early so a companion
 * "ability pack" plugin can call agent_connector_for_wp_register_ability() the
 * moment this plugin's file has run, regardless of the enable gates below — the
 * gates still decide whether the MCP server actually exposes anything.
 */
require_once AGENT_CONNECTOR_FOR_WP_DIR . 'src/api.php';

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
			// Browser of available companion "ability pack" plugins (with install).
			( new Admin\DirectoryPage() )->register();
		}

		// REST API: settings, reconnect, connection generation.
		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\SettingsController() )->register_routes();
			}
		);

		// Keep installed ability packs updatable from GitHub in every context
		// (admin + cron), independent of whether the plugin is switched "on".
		( new Services\PackUpdater() )->register();

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

			// Log MCP traffic to a dedicated table and expose the "MCP
			// Events" admin page. Attaches its handler to the adapter's
			// default server via the mcp_adapter_default_server_config
			// filter, so it must register before the server is created on
			// mcp_adapter_init.
			( new Observability\Observability() )->register();
		}

		// Security backstop: force our auth + domain-lock + audit onto EVERY
		// ability the MCP server will expose (mcp.public), no matter who
		// registered it — including raw third-party registrations that ship
		// their own (or no) permission callback. Hooks the core
		// wp_register_ability_args filter; see Support\Governance.
		Support\Governance::register();

		// This plugin ships NO abilities of its own. The powerful built-in
		// abilities (shell, PHP eval, filesystem, WP-CLI, admin login) now live in
		// the separate "Universal Abilities for Agent Connector" plugin, which can be
		// installed in one click from the Connection screen. Third-party ability
		// packs register through src/api.php and are governed above.
	}
);

/**
 * Self-update from GitHub Releases via yahnis-elsts/plugin-update-checker.
 *
 * We pull updates straight from this plugin's GitHub repo instead of the
 * wordpress.org directory (the plugin is intentionally dev-only and is not, and
 * will not be, hosted there). The library is bundled through the same Jetpack
 * Autoloader as the rest of vendor/: its loader (load-v5p7.php) is registered in
 * the autoloader's filemap, so YahnisElsts\PluginUpdateChecker\v5\PucFactory is
 * available once vendor/ is present — no separate require needed here.
 *
 * IMPORTANT — release assets, not the source tarball:
 * enableReleaseAssets() makes the checker install the built ZIP attached to each
 * GitHub Release (which bundles vendor/) rather than GitHub's auto-generated
 * source tarball. The source tarball would be BROKEN as an update because
 * vendor/ is gitignored, so it ships without the Jetpack autoloader or the MCP
 * adapter and the plugin would fatal on load. The release ZIP is produced by
 * .github/workflows/auto-release.yml / release.yml.
 *
 * Maintainer action required for updates to flow: cut a GitHub Release on a
 * vX.Y.Z tag with the built `agent-connector-for-wp.zip` attached as a release
 * asset. The auto-release workflow does this automatically on merge to master.
 *
 * The repo is assumed PUBLIC. If it's made private, supply a GitHub token via
 * the `agent_connector_for_wp_github_auth_token` filter (or define the
 * AGENT_CONNECTOR_FOR_WP_GITHUB_TOKEN constant). No token is hardcoded.
 *
 * Guarded to admin context: update checks only need to run in wp-admin, never on
 * every front-end request.
 */
add_action(
	'admin_init',
	static function (): void {
		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			// vendor/ missing (e.g. a source checkout without `composer install`).
			return;
		}

		$update_checker = $factory::buildUpdateChecker(
			'https://github.com/soflyy/agent-connector-for-wp/',
			AGENT_CONNECTOR_FOR_WP_FILE,
			'agent-connector-for-wp'
		);

		// Default branch holding the stable tags/releases.
		$update_checker->setBranch( 'master' );

		/**
		 * GitHub auth token for the update checker.
		 *
		 * The repo is public, so this is empty by default. To support a private
		 * repo later, return a token here (or define
		 * AGENT_CONNECTOR_FOR_WP_GITHUB_TOKEN). Never hardcode a token in source.
		 *
		 * @param string $token GitHub personal access token. Empty for public repos.
		 */
		$github_token = (string) apply_filters(
			'agent_connector_for_wp_github_auth_token',
			defined( 'AGENT_CONNECTOR_FOR_WP_GITHUB_TOKEN' ) ? (string) AGENT_CONNECTOR_FOR_WP_GITHUB_TOKEN : ''
		);
		if ( '' !== $github_token ) {
			$update_checker->setAuthentication( $github_token );
		}

		// Install the built release ZIP (with bundled vendor/) attached to each
		// GitHub Release — NOT the source tarball, which lacks vendor/.
		$update_checker->getVcsApi()->enableReleaseAssets();
	}
);
