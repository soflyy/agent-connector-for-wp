<?php
/**
 * Plugin Name:       Agent Connector for WP
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       Connect AI agents to your WordPress site over MCP. Runs the WordPress MCP server, exposes abilities registered by your plugins to connected agents, and optionally audit-logs every call — with optional protections (production blocking, domain lock) under Settings → Protection.
 * Version:           1.29.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-connector-for-wp
 *
 * Agents connected through this plugin can act with the full capability of a
 * super admin (and, with the Universal Abilities pack, run shell/PHP/WP-CLI).
 * That is the product, not an accident — the operator installed it to give an
 * agent that access. The UI is responsible for making sure they understand:
 * a site-wide warning notice renders on production environments until
 * explicitly dismissed, and opt-in protections (production blocking, domain
 * lock) live under Settings → Protection. Ability execution is always
 * restricted to super admins (see Support\Governance) — that gate is not
 * configurable.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp;

defined( 'ABSPATH' ) || exit;

define( 'AGENT_CONNECTOR_FOR_WP_VERSION', '1.29.0' );
define( 'AGENT_CONNECTOR_FOR_WP_FILE', __FILE__ );
define( 'AGENT_CONNECTOR_FOR_WP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load Composer dependencies.
 *
 * vendor/ holds only this plugin's own PSR-4 map and the GitHub update checker.
 * The MCP server itself comes from the canonical "MCP Adapter" plugin, which
 * must be installed alongside this one: it used to be bundled here via Composer
 * and the Jetpack Autoloader, but the adapter project has deprecated that (see
 * https://github.com/WordPress/mcp-adapter/pull/288) because two copies of the
 * library on one site fatal or silently shadow each other. See
 * Support\McpAdapterPlugin for the runtime check and the reason this plugin
 * does not (yet) declare it through the `Requires Plugins` header.
 */
$agent_connector_for_wp_autoloader = AGENT_CONNECTOR_FOR_WP_DIR . 'vendor/autoload.php';
$agent_connector_for_wp_has_vendor = is_readable( $agent_connector_for_wp_autoloader );
if ( $agent_connector_for_wp_has_vendor ) {
	require_once $agent_connector_for_wp_autoloader;
}
unset( $agent_connector_for_wp_autoloader );

/**
 * Minimal PSR-4 fallback autoloader for the AgentConnectorForWp\ namespace, rooted at
 * src/.
 *
 * Registered only when the Composer autoloader is absent (e.g. a source checkout
 * where `composer install` has not been run). When vendor/ is present the
 * Composer autoloader already maps the AgentConnectorForWp\ namespace, and registering
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
 * Activation: switch the plugin on and pin the domain lock to the current host
 * (enforcement of the lock stays off until the operator opts in under
 * Settings → Protection). See Support\Config::activate().
 */
register_activation_hook( __FILE__, array( Support\Config::class, 'activate' ) );

/**
 * Boot the plugin once WordPress is loaded.
 *
 * On by default: activating the plugin is the opt-in. Optional protections
 * (production blocking, domain lock) can still hold it back — see
 * Support\Config::can_boot().
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// The Connection screen is always available — even while held back by a
		// protection — because it's where the operator manages those settings.
		if ( is_admin() ) {
			( new Admin\ConnectionPage() )->register();
			// Browser of available companion "ability pack" plugins (with install).
			( new Admin\DirectoryPage() )->register();
			// Site-wide warning on every wp-admin page while running on a
			// production environment, until explicitly dismissed.
			( new Admin\ProductionNotice() )->register();
			// Site-wide nudge while the Universal Abilities pack is missing —
			// without it a connected agent has almost nothing to do.
			( new Admin\UapNotice() )->register();
			// Site-wide error while the MCP Adapter plugin is missing, inactive
			// or too old — without it there is no MCP server at all. Offers a
			// one-click install from the adapter's GitHub Releases.
			( new Admin\McpAdapterNotice() )->register();
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
			// Disabled, or held back by the opt-in production protection. The
			// operator chose this state, so no global nag — the plugin's own
			// screens (Header badge, Connect page) explain why it's inactive.
			return;
		}

		// Auto-load AI-written PHP "plugins" from the sandbox directory, with
		// crash recovery (safe mode) so a fatal in generated code can't take the
		// site down. See Services\SandboxLoader.
		( new Services\SandboxLoader() )->run();

		/**
		 * Wire into the MCP Adapter plugin.
		 *
		 * This plugin's first job is to expose the abilities other plugins
		 * registered over MCP. The server that does that belongs to the
		 * canonical "MCP Adapter" plugin, which boots itself while its own
		 * main file loads; by `plugins_loaded` its classes are either there or
		 * they are not. This is the availability check the adapter's
		 * installation guide asks dependents to make (class + WP_MCP_VERSION
		 * floor, see Support\McpAdapterPlugin). When it fails, everything that
		 * touches adapter classes is skipped so the site keeps working, and
		 * Admin\McpAdapterNotice tells the operator how to fix it.
		 */
		if ( Support\McpAdapterPlugin::is_ready() ) {
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

		// OAuth 2.1 authorization server: lets MCP clients (e.g. claude.ai
		// remote connectors) authenticate directly over Streamable HTTP with
		// Bearer tokens — discovery (.well-known), dynamic client registration,
		// admin-only consent, PKCE code exchange, refresh rotation, and a
		// Bearer interceptor on the /mcp/ routes. Application-password auth
		// via the mcp-wordpress-remote proxy keeps working unchanged; the
		// interceptor only engages when it sees an Authorization header (or
		// no auth at all, where its 401 advertises the OAuth flow). Consent
		// is restricted to administrators (Config::has_admin_access) because
		// tokens front root-equivalent abilities. See src/OAuth/Server.php.
		//
		// Gated behind an opt-in toggle (Settings → OAuth) while the flow
		// settles, AND behind the transport check core applies to Application
		// Passwords (HTTPS or a local environment) — plain HTTP on a public
		// site would put Bearer tokens on the wire in cleartext. Not booting
		// means none of it exists: no discovery, no registration, no consent,
		// and previously issued tokens stop authenticating; the
		// application-password path is unaffected.
		if ( Support\Config::is_oauth_enabled() && Support\Config::oauth_transport_allowed() ) {
			OAuth\Server::init();
		}

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
 * will not be, hosted there). The library is loaded through the Composer
 * autoloader with the rest of vendor/: its loader (load-v5p7.php) is registered in
 * the autoloader's filemap, so YahnisElsts\PluginUpdateChecker\v5\PucFactory is
 * available once vendor/ is present — no separate require needed here.
 *
 * IMPORTANT — release assets, not the source tarball:
 * enableReleaseAssets() makes the checker install the built ZIP attached to each
 * GitHub Release (which bundles vendor/) rather than GitHub's auto-generated
 * source tarball. The source tarball would be BROKEN as an update because
 * vendor/ is gitignored, so it ships without the Composer autoloader or the
 * update checker and updates would stop flowing. The release ZIP is produced by
 * .github/workflows/auto-release.yml / release.yml.
 *
 * Maintainer action required for updates to flow: cut a GitHub Release on a
 * vX.Y.Z tag with the built `agent-connector-for-wp.zip` attached as a release
 * asset. The auto-release workflow does this automatically on merge to master.
 *
 * The repo is PUBLIC — the checker needs no authentication.
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

		// Install the built release ZIP (with bundled vendor/) attached to each
		// GitHub Release — NOT the source tarball, which lacks vendor/.
		$update_checker->getVcsApi()->enableReleaseAssets();
	}
);
