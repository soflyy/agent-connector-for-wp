<?php
/**
 * Plugin Name:       Agent Connector for WP
 * Plugin URI:        https://github.com/soflyy/agent-connector-for-wp
 * Description:       Connect AI agents to your WordPress site over MCP. Runs the WordPress MCP server, exposes abilities registered by your plugins to connected agents, and optionally audit-logs every call — with optional protections (production blocking, domain lock) under Settings → Protection.
 * Version:           1.33.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Soflyy
 * Author URI:        https://github.com/soflyy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-connector-for-wp
 *
 * Agents connected through this plugin can act with the full capability of a
 * super admin, through whatever abilities other plugins register. That is the
 * product, not an accident — the operator installed it to give an
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

define( 'AGENT_CONNECTOR_FOR_WP_VERSION', '1.33.1' );
define( 'AGENT_CONNECTOR_FOR_WP_FILE', __FILE__ );
define( 'AGENT_CONNECTOR_FOR_WP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Version of the admin extension points (Admin\Extensions): the
 * agent_connector_for_wp_settings_rows filter, the
 * agent_connector_for_wp_enqueue_admin_assets action, and the app's wp.hooks
 * filters. Other plugins check for this constant before using them.
 */
define( 'AGENT_CONNECTOR_FOR_WP_EXTENSIONS_API', 1 );

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
			// Site-wide warning on every wp-admin page while running on a
			// production environment, until explicitly dismissed.
			( new Admin\ProductionNotice() )->register();
		}

		// REST API: settings, reconnect, connection generation.
		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\SettingsController() )->register_routes();
			}
		);

		if ( ! Support\Config::can_boot() ) {
			// Disabled, or held back by the opt-in production protection. The
			// operator chose this state, so no global nag — the plugin's own
			// screens (Header badge, Connect page) explain why it's inactive.
			return;
		}

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

		// Remember the latest authenticated MCP request (any auth method) so a
		// setup wizard — ours or another plugin's — can confirm an agent has
		// actually connected. See Support\ConnectionActivity.
		Support\ConnectionActivity::register();

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
		// Gated behind the transport check core applies to Application
		// Passwords (HTTPS or a local environment) — plain HTTP on a public
		// site would put Bearer tokens on the wire in cleartext. Not booting
		// means none of it exists: no discovery, no registration, no consent,
		// and previously issued tokens stop authenticating; the
		// application-password path is unaffected.
		if ( Support\Config::oauth_transport_allowed() ) {
			OAuth\Server::init();
		}

		// This plugin ships NO abilities of its own. Other plugins register them
		// (through the Abilities API or src/api.php) and are governed above.
	}
);

/**
 * GitHub-distributed builds only: self-updates from GitHub Releases, plus
 * compatibility for sites upgrading from versions that bundled features which
 * now live in other plugins. See legacy/bootstrap.php. The wordpress.org build
 * doesn't include the legacy/ directory (see .distignore), so it updates from
 * wordpress.org like any other plugin.
 */
if ( is_readable( AGENT_CONNECTOR_FOR_WP_DIR . 'legacy/bootstrap.php' ) ) {
	require_once AGENT_CONNECTOR_FOR_WP_DIR . 'legacy/bootstrap.php';
}
