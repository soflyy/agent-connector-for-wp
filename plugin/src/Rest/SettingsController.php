<?php
/**
 * REST API controller: plugin settings, domain reconnect, and connection generation.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Rest;

use AgentConnectorForWp\Observability\EventsTable;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Connection;
use AgentConnectorForWp\Support\Helpers;
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
					'enabled'                 => array( 'type' => 'boolean', 'required' => true ),
					'block_production'        => array( 'type' => 'boolean', 'default' => false ),
					'domain_lock_enabled'     => array( 'type' => 'boolean', 'default' => false ),
					'hide_production_warning' => array( 'type' => 'boolean', 'default' => false ),
					'mcp_debug'               => array( 'type' => 'boolean', 'default' => false ),
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
				'args'                => array(
					'name' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/dismiss-production-warning',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_production_warning' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/dismiss-uap-notice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_uap_notice' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/dismiss-gs-banner',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_gs_banner' ),
				'permission_callback' => array( $this, 'check_permission' ),
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

		register_rest_route(
			$this->namespace,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'          => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'event_filter'  => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'status_filter' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/logs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_log_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/logs/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_logs' ),
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
				'enabled'                 => Config::is_enabled(),
				'active'                  => Config::can_boot(),
				'prod_blocked'            => Config::is_blocked_by_production(),
				'mcp_debug'               => Config::mcp_debug_enabled(),
				'block_production'        => Config::block_production_enabled(),
				'domain_lock_enabled'     => Config::domain_lock_enabled(),
				'hide_production_warning' => Config::production_warning_hidden(),
				'is_non_prod'             => Config::is_non_production_env(),
				'locked_host'             => Config::locked_host(),
				'declared_host'           => Config::declared_host(),
				'env_type'                => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
				'server_url'              => Connection::endpoint_url(),
				'username'                => $user instanceof \WP_User ? $user->user_login : '',
				'pw_available'            => $this->pw_available( $user instanceof \WP_User ? $user : null ),
				'uap_active'              => $this->is_uap_active(),
			)
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$enable       = (bool) $request->get_param( 'enabled' );
		$block_prod   = (bool) $request->get_param( 'block_production' );
		$domain_lock  = (bool) $request->get_param( 'domain_lock_enabled' );
		$hide_warning = (bool) $request->get_param( 'hide_production_warning' );
		$mcp_debug    = (bool) $request->get_param( 'mcp_debug' );

		update_option( Config::ENABLED_OPTION, $enable, true );
		update_option( Config::BLOCK_PRODUCTION_OPTION, $block_prod, true );
		update_option( Config::DOMAIN_LOCK_OPTION, $domain_lock, true );
		update_option( Config::HIDE_PRODUCTION_WARNING_OPTION, $hide_warning, true );
		update_option( Config::MCP_DEBUG_OPTION, $mcp_debug, true );

		/** This action is documented in src/Admin/ConnectionPage.php. */
		do_action( 'agent_connector_for_wp_settings_saved' );

		// A host is normally pinned at activation; installs that predate that
		// (or had the option wiped) get pinned when the lock is first enabled.
		if ( $domain_lock && '' === Config::locked_host() ) {
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

		$name = (string) $request->get_param( 'name' );
		if ( '' === $name ) {
			$name = sprintf(
				/* translators: %s: date/time the password was created. */
				__( 'Agent Connector for WP (MCP) — %s', 'agent-connector-for-wp' ),
				gmdate( 'Y-m-d H:i:s' )
			) . ' [' . wp_generate_password( 4, false ) . ']';
		}

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );

		if ( $created instanceof WP_Error ) {
			return $created;
		}

		$password = is_array( $created ) ? (string) ( $created[0] ?? '' ) : '';
		if ( '' === $password ) {
			return new WP_Error( 'pw_failed', __( 'Failed to generate an application password.', 'agent-connector-for-wp' ), array( 'status' => 500 ) );
		}

		$result               = Connection::build_artifacts( $user->user_login, $password );
		$result['password']   = $password;
		return new WP_REST_Response( $result );
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

	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		EventsTable::maybe_create();
		$table = EventsTable::name();

		$per_page      = 20;
		$page          = max( 1, (int) $request->get_param( 'page' ) );
		$event_filter  = (string) $request->get_param( 'event_filter' );
		$status_filter = (string) $request->get_param( 'status_filter' );
		$offset        = ( $page - 1 ) * $per_page;

		$hidden       = array( 'mcp.component.registration', 'mcp.server.created' );
		$where        = array();
		$where_values = array();

		if ( '' !== $event_filter ) {
			$where[]        = '`event` = %s';
			$where_values[] = $event_filter;
		} elseif ( ! empty( $hidden ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $hidden ), '%s' ) );
			$where[]      = "`event` NOT IN ({$placeholders})";
			foreach ( $hidden as $h ) {
				$where_values[] = $h;
			}
		}

		if ( '' !== $status_filter ) {
			$where[]        = '`status` = %s';
			$where_values[] = $status_filter;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- bespoke log table, no core API covers it.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB -- $where_sql is built above from literal SQL fragments and %s placeholders only; the table name (%i) and every value go through $wpdb->prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i {$where_sql}",
				array_merge( array( $table ), $where_values )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, event, method, tool_name, prompt_name, status, duration_ms, user_id FROM %i {$where_sql} ORDER BY `id` DESC LIMIT %d OFFSET %d",
				array_merge( array( $table ), $where_values, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$distinct_events   = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT `event` FROM %i ORDER BY `event` ASC', $table ) ) ?: array();
		$distinct_statuses = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT `status` FROM %i WHERE `status` IS NOT NULL AND `status` != '' ORDER BY `status` ASC", $table ) ) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB

		return new WP_REST_Response( array(
			'rows'              => array_map( array( $this, 'format_log_row' ), $rows ?: array() ),
			'total'             => $total,
			'per_page'          => $per_page,
			'distinct_events'   => $distinct_events,
			'distinct_statuses' => $distinct_statuses,
			'debug_enabled'     => Config::mcp_debug_enabled(),
		) );
	}

	public function get_log_event( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$table = EventsTable::name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bespoke log table, no core API covers it.
			$wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', $table, $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'agent-connector-for-wp' ), array( 'status' => 404 ) );
		}

		$tags = array();
		if ( ! empty( $row['tags_json'] ) ) {
			$decoded = json_decode( (string) $row['tags_json'], true );
			if ( is_array( $decoded ) ) {
				$tags = $decoded;
			}
		}

		$data = $this->format_log_row( $row );
		foreach ( array( 'server_id', 'transport', 'resource_uri', 'session_id', 'request_id', 'error_code', 'error_category', 'failure_reason', 'input_body', 'output_body' ) as $key ) {
			$data[ $key ] = (string) ( $row[ $key ] ?? '' );
		}
		$data['tags'] = $tags;

		return new WP_REST_Response( $data );
	}

	public function clear_logs( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		EventsTable::maybe_create();
		$table = EventsTable::name();
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bespoke log table, no core API covers it.

		return new WP_REST_Response( array( 'success' => true ) );
	}

	private function format_log_row( array $row ): array {
		$user_id    = (int) ( $row['user_id'] ?? 0 );
		$user_login = '';
		if ( $user_id > 0 ) {
			$user       = get_userdata( $user_id );
			$user_login = $user ? (string) $user->user_login : '#' . $user_id;
		}
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
			'event'       => (string) ( $row['event'] ?? '' ),
			'method'      => (string) ( $row['method'] ?? '' ),
			'tool_name'   => (string) ( $row['tool_name'] ?? '' ),
			'prompt_name' => (string) ( $row['prompt_name'] ?? '' ),
			'status'      => (string) ( $row['status'] ?? '' ),
			'duration_ms' => isset( $row['duration_ms'] ) && '' !== (string) $row['duration_ms'] ? (float) $row['duration_ms'] : null,
			'user_login'  => $user_login,
		);
	}

	private function is_uap_active(): bool {
		return Helpers::is_universal_abilities_active();
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

	public function dismiss_production_warning(): WP_REST_Response {
		update_option( Config::HIDE_PRODUCTION_WARNING_OPTION, true, true );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}

	public function dismiss_uap_notice(): WP_REST_Response {
		update_option( Config::HIDE_UAP_NOTICE_OPTION, true, true );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}

	public function dismiss_gs_banner(): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'ac4wp_gs_banner_dismissed', '1' );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}
}
