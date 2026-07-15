<?php
/**
 * The "Connection" admin screen — React SPA mount point.
 *
 * The screen itself is a React application built from plugin/admin/. This PHP
 * file registers the menu, enqueues the built assets, and passes the initial
 * page state as window.AgentConnectorForWpAdmin so the app boots without a
 * round-trip REST call.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Connection;
use WP_Application_Passwords;

defined( 'ABSPATH' ) || exit;

final class ConnectionPage {

	public const MENU_SLUG = 'agent-connector-for-wp';

	/** Page hook suffix, captured at registration so assets load only here. */
	private string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Agent Connector', 'agent-connector-for-wp' ),
			__( 'Agent Connector', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"></path><path d="M9 8V2"></path><path d="M15 8V2"></path><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"></path></svg>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Agent Connector — Connection', 'agent-connector-for-wp' ),
			__( 'Connection', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$build_dir = AGENT_CONNECTOR_FOR_WP_DIR . 'admin/build/assets/';
		$js_file   = $build_dir . 'index.js';
		$css_file  = $build_dir . 'index.css';

		if ( ! file_exists( $js_file ) ) {
			return;
		}

		$version = AGENT_CONNECTOR_FOR_WP_VERSION . '.' . filemtime( $js_file );

		wp_enqueue_script(
			'agent-connector-for-wp-admin',
			plugins_url( 'admin/build/assets/index.js', AGENT_CONNECTOR_FOR_WP_FILE ),
			array(),
			$version,
			true
		);

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'agent-connector-for-wp-admin',
				plugins_url( 'admin/build/assets/index.css', AGENT_CONNECTOR_FOR_WP_FILE ),
				array(),
				$version
			);
		}

		$user = wp_get_current_user();

		wp_localize_script(
			'agent-connector-for-wp-admin',
			'AgentConnectorForWpAdmin',
			array(
				'restUrl'             => rest_url( 'agent-connector-for-wp/v1/' ),
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'adminUrl'            => admin_url(),
				'enabled'             => Config::is_enabled(),
				'active'              => Config::can_boot(),
				'prodBlocked'         => Config::is_blocked_by_production(),
				'mcpDebug'            => Config::mcp_debug_enabled(),
				'productionOverride'  => Config::production_override_enabled(),
				'isNonProd'           => Config::is_non_production_env(),
				'lockedHost'          => Config::locked_host(),
				'declaredHost'        => Config::declared_host(),
				'envType'             => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
				'serverUrl'           => Connection::endpoint_url(),
				'serverName'          => Connection::server_name(),
				'siteName'            => (string) get_bloginfo( 'name' ),
				'username'            => $user instanceof \WP_User ? $user->user_login : '',
				'pwAvailable'         => $this->pw_available( $user instanceof \WP_User ? $user : null ),
				'uapActive'           => $this->is_uap_active(),
				'mcpAdapterAvailable' => PluginDirectory::is_mcp_adapter_available(),
				'mcpAdapterActive'    => PluginDirectory::is_mcp_adapter_active(),
				'showGsBanner'        => ! get_user_meta( get_current_user_id(), 'ac4wp_gs_banner_dismissed', true ),
			)
		);

		// Let the React app know which WordPress admin body classes to add.
		add_filter(
			'admin_body_class',
			static function ( string $classes ): string {
				return "$classes acfw-app-page";
			}
		);

		// Hide the WP admin footer and remove default content-area padding on our page.
		add_action(
			'admin_head',
			static function (): void {
				echo '<style>
					.acfw-app-page #wpfooter { display: none !important; }
					.acfw-app-page #wpcontent { padding-left: 0 !important; }
					.acfw-app-page #wpbody-content { padding-bottom: 0 !important; }
				</style>';
			}
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}
		echo '<div id="agent-connector-for-wp-app"></div>';
	}

	private function is_uap_active(): bool {
		return PluginDirectory::is_universal_abilities_active();
	}

	private function pw_available( ?\WP_User $user ): bool {
		if ( ! class_exists( WP_Application_Passwords::class ) || ! function_exists( 'wp_is_application_passwords_available' ) ) {
			return false;
		}
		if ( ! wp_is_application_passwords_available() ) {
			return false;
		}
		if ( $user instanceof \WP_User && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			return (bool) wp_is_application_passwords_available_for_user( $user );
		}
		return true;
	}
}
