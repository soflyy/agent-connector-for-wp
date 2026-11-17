<?php
/**
 * Installs the MCP Adapter plugin on sites that updated from a bundled release.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\GitHub\Services;

use AgentConnectorForWp\Services\CompanionPlugins;
use AgentConnectorForWp\Support\McpAdapterPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Releases up to 1.33 bundled the MCP Adapter library. Later ones depend on
 * the MCP Adapter plugin instead (`Requires Plugins: mcp-adapter`), but
 * WordPress only checks that header on activation, so a GitHub install that
 * updates in place is left with no MCP server.
 *
 * When the adapter is missing, this schedules a one-off cron job that installs
 * MCP Adapter from wordpress.org (unless file changes are disallowed) and
 * activates it, network-wide when Agent Connector is. It tries once per
 * Agent Connector version; if that fails, Admin\McpAdapterNotice still tells
 * the operator what to do.
 *
 * GitHub build only: wordpress.org installs are fresh, so the header covers
 * them, and wordpress.org plugins may not install other plugins on their own.
 */
final class McpAdapterInstaller {

	/**
	 * Cron hook that runs the install.
	 */
	public const HOOK = 'agent_connector_for_wp_install_mcp_adapter';

	/**
	 * Site option recording the last attempt: the Agent Connector version it
	 * ran under, and its outcome.
	 */
	private const ATTEMPT_OPTION = 'agent_connector_for_wp_mcp_adapter_install';

	/**
	 * Hook the cron job, and schedule it when the adapter is missing. Call
	 * from plugins_loaded, once every active plugin has been included.
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );

		if ( ! self::is_due() || wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Install (if needed) and activate MCP Adapter.
	 */
	public static function run(): void {
		if ( ! self::is_due() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Record the attempt before making it, so a fatal partway through can't
		// turn into a retry on every cron run.
		self::record( 'started' );

		$result = self::install_and_activate();
		self::record( is_wp_error( $result ) ? $result->get_error_message() : 'activated' );
	}

	private static function record( string $result ): void {
		update_site_option(
			self::ATTEMPT_OPTION,
			array(
				'version' => AGENT_CONNECTOR_FOR_WP_VERSION,
				'result'  => $result,
			)
		);
	}

	/**
	 * Whether the adapter is missing and this version hasn't tried yet. An
	 * adapter that's loaded but too old is left to the notice.
	 */
	private static function is_due(): bool {
		if ( McpAdapterPlugin::STATUS_MISSING !== McpAdapterPlugin::status() ) {
			return false;
		}

		$attempt = get_site_option( self::ATTEMPT_OPTION );
		return ! ( is_array( $attempt ) && AGENT_CONNECTOR_FOR_WP_VERSION === ( $attempt['version'] ?? null ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function install_and_activate() {
		$file = CompanionPlugins::mcp_adapter_file();

		if ( null === $file ) {
			if ( ! wp_is_file_mod_allowed( self::HOOK ) ) {
				return new \WP_Error( 'file_mods_disallowed', 'File changes are disallowed on this site.' );
			}

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => CompanionPlugins::MCP_ADAPTER_SLUG,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $api ) ) {
				return $api;
			}

			// The Ajax skin collects errors instead of printing them, and returns
			// false instead of printing a credentials form when the filesystem
			// isn't directly writable. Plugin_Upgrader::install() returns null
			// for most failures, so the skin is where the reason is.
			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			ob_start();
			$installed = $upgrader->install( $api->download_link );
			ob_end_clean();

			if ( is_wp_error( $installed ) ) {
				return $installed;
			}
			if ( is_wp_error( $skin->result ) ) {
				return $skin->result;
			}
			if ( $skin->get_errors()->has_errors() ) {
				return $skin->get_errors();
			}
			if ( true !== $installed ) {
				return new \WP_Error( 'install_failed', 'MCP Adapter could not be installed. The plugins folder may not be writable without FTP credentials.' );
			}

			$file = $upgrader->plugin_info();
			if ( ! $file ) {
				return new \WP_Error( 'install_failed', 'MCP Adapter was installed, but its plugin file was not found.' );
			}
		}

		$network = is_multisite() && is_plugin_active_for_network( plugin_basename( AGENT_CONNECTOR_FOR_WP_FILE ) );
		$active  = activate_plugin( $file, '', $network );

		return is_wp_error( $active ) ? $active : true;
	}
}
