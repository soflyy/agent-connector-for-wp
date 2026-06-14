<?php
/**
 * REST API controller: plugin settings, domain reconnect, and connection generation.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Rest;

use AgentConnectorForWp\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Connection;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends WP_REST_Controller {

	protected $namespace = 'agent-connector-for-wp/v1';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'enabled'             => array( 'type' => 'boolean', 'required' => true ),
					'production_override' => array( 'type' => 'boolean', 'default' => false ),
					'mcp_debug'           => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/reconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reconnect' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/directory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_directory' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/directory/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_directory' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/directory/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'pack_slug' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/directory/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'pack_slug' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/directory/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'pack_slug' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/registered-abilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_registered_abilities' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( Config::CAP );
	}

	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();
		return new WP_REST_Response(
			array(
				'enabled'             => Config::is_enabled(),
				'active'              => Config::can_boot(),
				'prod_blocked'        => Config::is_blocked_by_production(),
				'mcp_debug'           => Config::mcp_debug_enabled(),
				'production_override' => Config::production_override_enabled(),
				'is_non_prod'         => Config::is_non_production_env(),
				'locked_host'         => Config::locked_host(),
				'declared_host'       => Config::declared_host(),
				'env_type'            => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
				'server_url'          => Connection::endpoint_url(),
				'username'            => $user instanceof \WP_User ? $user->user_login : '',
				'pw_available'        => $this->pw_available( $user instanceof \WP_User ? $user : null ),
			)
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$was_enabled = Config::is_enabled();
		$enable      = (bool) $request->get_param( 'enabled' );
		$allow_prod  = (bool) $request->get_param( 'production_override' );
		$mcp_debug   = (bool) $request->get_param( 'mcp_debug' );

		update_option( Config::ENABLED_OPTION, $enable, true );
		update_option( Config::PRODUCTION_OVERRIDE_OPTION, $allow_prod, true );
		update_option( Config::MCP_DEBUG_OPTION, $mcp_debug, true );

		/** This action is documented in src/Admin/ConnectionPage.php. */
		do_action( 'agent_connector_for_wp_settings_saved' );

		if ( $enable && ! $was_enabled ) {
			Config::lock_to_current_host();
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $this->get_status( $request )->get_data(),
			)
		);
	}

	public function reconnect( WP_REST_Request $request ): WP_REST_Response {
		Config::lock_to_current_host();
		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $this->get_status( $request )->get_data(),
			)
		);
	}

	public function generate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = wp_get_current_user();

		if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
			return new WP_Error( 'no_user', __( 'Could not determine the current user.', 'agent-connector-for-wp' ), array( 'status' => 400 ) );
		}

		if ( ! $this->pw_available( $user ) ) {
			return new WP_Error( 'pw_unavailable', __( 'Application passwords are not available on this site.', 'agent-connector-for-wp' ), array( 'status' => 400 ) );
		}

		$name = sprintf(
			/* translators: %s: date/time the password was created. */
			__( 'Agent Connector for WP (MCP) — %s', 'agent-connector-for-wp' ),
			gmdate( 'Y-m-d H:i:s' )
		) . ' [' . wp_generate_password( 4, false ) . ']';

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );

		if ( $created instanceof WP_Error ) {
			return $created;
		}

		$password = is_array( $created ) ? (string) ( $created[0] ?? '' ) : '';
		if ( '' === $password ) {
			return new WP_Error( 'pw_failed', __( 'Failed to generate an application password.', 'agent-connector-for-wp' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( Connection::build_artifacts( $user->user_login, $password ) );
	}

	public function get_directory( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->directory_data() );
	}

	public function refresh_directory( WP_REST_Request $request ): WP_REST_Response {
		PluginDirectory::clear_cache();
		PluginDirectory::get( true );
		return new WP_REST_Response( $this->directory_data() );
	}

	public function install_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'install_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to install plugins.', 'agent-connector-for-wp' ), array( 'status' => 403 ) );
		}

		$slug  = (string) $request->get_param( 'pack_slug' );
		$entry = $this->find_directory_entry( $slug );
		$url   = null !== $entry ? (string) ( $entry['download_url'] ?? '' ) : '';

		if ( '' === $url || ! self::is_pack_download_allowed( $url ) ) {
			return new WP_Error( 'invalid_pack', __( 'Could not find a valid download URL for this pack.', 'agent-connector-for-wp' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'fs_unavailable', __( 'This site cannot install plugins directly (no direct filesystem access).', 'agent-connector-for-wp' ), array( 'status' => 500 ) );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $url );

		if ( is_wp_error( $result ) || true !== $result ) {
			return new WP_Error( 'install_failed', __( 'Could not install the ability pack.', 'agent-connector-for-wp' ), array( 'status' => 500 ) );
		}

		$plugin_file = (string) $upgrader->plugin_info();
		if ( '' === $plugin_file ) {
			$plugin_file = (string) ( PluginDirectory::installed_file_for_slug( $slug ) ?? '' );
		}

		if ( '' !== $plugin_file ) {
			activate_plugin( $plugin_file, '', false, true );
		}

		PluginDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	public function activate_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to activate plugins.', 'agent-connector-for-wp' ), array( 'status' => 403 ) );
		}

		$slug = (string) $request->get_param( 'pack_slug' );
		$file = PluginDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'agent-connector-for-wp' ), array( 'status' => 404 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result = activate_plugin( $file, '', false, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PluginDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	public function deactivate_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to deactivate plugins.', 'agent-connector-for-wp' ), array( 'status' => 403 ) );
		}

		$slug = (string) $request->get_param( 'pack_slug' );
		$file = PluginDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'agent-connector-for-wp' ), array( 'status' => 404 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $file, true );

		PluginDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	public function get_registered_abilities( WP_REST_Request $request ): WP_REST_Response {
		$all       = function_exists( 'wp_get_abilities' ) ? (array) wp_get_abilities() : array();
		$abilities = array();

		foreach ( $all as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			$meta        = (array) $ability->get_meta();
			$mcp_public  = isset( $meta['mcp']['public'] ) && true === $meta['mcp']['public'];
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$output      = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null;

			$abilities[] = array(
				'name'         => (string) $ability->get_name(),
				'label'        => (string) $ability->get_label(),
				'description'  => (string) $ability->get_description(),
				'category'     => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
				'mcp_public'   => $mcp_public,
				'annotations'  => $annotations,
				'input_schema' => $ability->get_input_schema(),
				'output_schema' => $output,
			);
		}

		usort(
			$abilities,
			static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
		);

		return new WP_REST_Response( array( 'abilities' => $abilities, 'total' => count( $abilities ) ) );
	}

	private function directory_data(): array {
		$result = PluginDirectory::installed_overview();
		return array(
			'rows'       => $result['rows'],
			'stale'      => $result['stale'],
			'error'      => $result['error'],
			'url'        => $result['url'],
			'total'      => $result['total'],
			'can_manage' => current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
		);
	}

	private function find_directory_entry( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}
		foreach ( PluginDirectory::get()['entries'] as $entry ) {
			if ( isset( $entry['ability_pack_slug'] ) && $entry['ability_pack_slug'] === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	private static function is_pack_download_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host    = strtolower( (string) $parts['host'] );
		$allowed = (array) apply_filters(
			'agent_connector_for_wp_pack_download_hosts',
			array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' )
		);
		foreach ( $allowed as $h ) {
			$h = strtolower( (string) $h );
			if ( '' === $h ) {
				continue;
			}
			if ( $host === $h || substr( $host, - strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}
		return false;
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
