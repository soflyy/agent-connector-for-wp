<?php
/**
 * REST API controller: the ability-pack directory (list, refresh, install,
 * activate, deactivate). Backs the "Ability Packs" tab this plugin adds to
 * Agent Connector's Abilities screen.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Rest;

use AgentConnectorForWp\DefaultAbilities\Services\PackDirectory;
use AgentConnectorForWp\Support\Config;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class DirectoryController {

	public const NAMESPACE = 'universal-abilities/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/directory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_directory' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/directory/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_directory' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		$pack_args = array(
			'pack_slug' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/directory/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $pack_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/directory/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $pack_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/directory/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate_pack' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $pack_args,
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( Config::CAP );
	}

	public function get_directory( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->directory_data() );
	}

	public function refresh_directory( WP_REST_Request $request ): WP_REST_Response {
		PackDirectory::clear_cache();
		PackDirectory::get( true );
		return new WP_REST_Response( $this->directory_data() );
	}

	public function install_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'install_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to install plugins.', 'universal-abilities-plugin' ), array( 'status' => 403 ) );
		}

		$slug  = (string) $request->get_param( 'pack_slug' );
		$entry = $this->find_directory_entry( $slug );
		$url   = null !== $entry ? (string) ( $entry['download_url'] ?? '' ) : '';

		if ( '' === $url || ! self::is_pack_download_allowed( $url ) ) {
			return new WP_Error( 'invalid_pack', __( 'Could not find a valid download URL for this pack.', 'universal-abilities-plugin' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error( 'fs_unavailable', __( 'This site cannot install plugins directly (no direct filesystem access).', 'universal-abilities-plugin' ), array( 'status' => 500 ) );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $url );

		if ( is_wp_error( $result ) || true !== $result ) {
			return new WP_Error( 'install_failed', __( 'Could not install the ability pack.', 'universal-abilities-plugin' ), array( 'status' => 500 ) );
		}

		$plugin_file = (string) $upgrader->plugin_info();
		if ( '' === $plugin_file ) {
			$plugin_file = (string) ( PackDirectory::installed_file_for_slug( $slug ) ?? '' );
		}

		if ( '' !== $plugin_file ) {
			activate_plugin( $plugin_file, '', false, true );
		}

		PackDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	public function activate_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to activate plugins.', 'universal-abilities-plugin' ), array( 'status' => 403 ) );
		}

		$slug = (string) $request->get_param( 'pack_slug' );
		$file = PackDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'universal-abilities-plugin' ), array( 'status' => 404 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result = activate_plugin( $file, '', false, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		PackDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	public function deactivate_pack( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to deactivate plugins.', 'universal-abilities-plugin' ), array( 'status' => 403 ) );
		}

		$slug = (string) $request->get_param( 'pack_slug' );
		$file = PackDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'universal-abilities-plugin' ), array( 'status' => 404 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $file, true );

		PackDirectory::clear_cache();
		return new WP_REST_Response( array( 'success' => true, 'directory' => $this->directory_data() ) );
	}

	private function directory_data(): array {
		$result = PackDirectory::installed_overview();
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
		foreach ( PackDirectory::get()['entries'] as $entry ) {
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
}
