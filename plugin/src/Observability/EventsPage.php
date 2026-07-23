<?php
/**
 * The "MCP Events" admin page: a paginated log of MCP traffic with detail view.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

use AgentConnectorForWp\Admin\ConnectionPage;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the MCP events log as a submenu under the Agent Connector menu.
 *
 * Mirrors the data model written by {@see DatabaseObservabilityHandler}: a list
 * view with event/status filters and a Clear-log button, plus a per-event
 * detail view (?event=ID) showing every column, the raw JSON-RPC bodies and the
 * full tag map.
 */
final class EventsPage {

	public const PAGE_SLUG = 'agent-connector-for-wp-mcp-events';

	private const PER_PAGE = 20;

	private const CLEAR_ACTION = 'agent_connector_for_wp_mcp_clear_log';

	/**
	 * Lifecycle events the adapter emits on every page load (server creation,
	 * component registration). They're noisy and rarely useful for debugging
	 * request flows, so the list view hides them unless explicitly filtered for.
	 *
	 * @var string[]
	 */
	private const HIDDEN_EVENTS_BY_DEFAULT = array(
		'mcp.component.registration',
		'mcp.server.created',
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'maybe_handle_clear_log' ) );
	}

	/**
	 * Previously registered "MCP Events" as a standalone submenu page. Removed
	 * now that the Log tab lives inside the React SPA on the main Connection
	 * screen, served through the REST API.
	 */
	public function register_menu(): void {}

	/**
	 * Handle the "Clear log" POST before any output. Runs on admin_init so the
	 * post-truncation redirect works without "headers already sent" warnings.
	 */
	public function maybe_handle_clear_log(): void {
		if ( ! isset( $_POST[ self::CLEAR_ACTION ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::CLEAR_ACTION ) ) {
			return;
		}

		global $wpdb;
		$table = EventsTable::name();
		$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE %i", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'cleared' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Top-level renderer: routes to the detail view (?event=ID) or the list.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'agent-connector' ) );
		}

		EventsTable::maybe_create();

		$event_id = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MCP Events', 'agent-connector' ) . '</h1>';

		if ( ! Config::mcp_debug_enabled() ) {
			$settings_url = add_query_arg( array( 'page' => ConnectionPage::MENU_SLUG ), admin_url( 'admin.php' ) );
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: Connection screen URL. */
						__( 'MCP event logging is off — new events are not being recorded. Turn on the Debug setting on the <a href="%s">Connection screen</a> to start logging.', 'agent-connector' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}

		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'MCP event log cleared.', 'agent-connector' )
				. '</p></div>';
		}

		if ( $event_id > 0 ) {
			$this->render_event_detail( $event_id );
		} else {
			$this->render_event_list();
		}

		echo '</div>';
	}

	/**
	 * Render the paginated list of events with filters and the Clear-log button.
	 */
	private function render_event_list(): void {
		global $wpdb;

		$table = EventsTable::name();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$event_filter  = isset( $_GET['event_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['event_filter'] ) ) : '';
		$status_filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$where        = array();
		$where_values = array();

		if ( '' !== $event_filter ) {
			$where[]        = '`event` = %s';
			$where_values[] = $event_filter;
		} elseif ( ! empty( self::HIDDEN_EVENTS_BY_DEFAULT ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( self::HIDDEN_EVENTS_BY_DEFAULT ), '%s' ) );
			$where[]      = "`event` NOT IN ({$placeholders})";
			foreach ( self::HIDDEN_EVENTS_BY_DEFAULT as $hidden_event ) {
				$where_values[] = $hidden_event;
			}
		}

		if ( '' !== $status_filter ) {
			$where[]        = '`status` = %s';
			$where_values[] = $status_filter;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );

		// Reads from the plugin's own observability table. The table name is passed
		// as an identifier via the %i placeholder; the WHERE clause is built only
		// from static column names and %s placeholders. Admin-only and uncached.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_sql}", array_merge( array( $table ), $where_values ) )
		);

		$list_sql_values = array_merge( array( $table ), $where_values, array( self::PER_PAGE, $offset ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i {$where_sql} ORDER BY `id` DESC LIMIT %d OFFSET %d",
				$list_sql_values
			),
			ARRAY_A
		);

		$distinct_events   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT `event` FROM %i ORDER BY `event` ASC", $table ) );
		$distinct_statuses = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT `status` FROM %i WHERE `status` IS NOT NULL AND `status` != '' ORDER BY `status` ASC", $table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$this->render_filter_form( $event_filter, $status_filter, $distinct_events ?: array(), $distinct_statuses ?: array() );
		$this->render_clear_log_form( $total );
		$this->render_events_table( $rows ?: array() );
		$this->render_pagination( $total, $paged, $event_filter, $status_filter );
	}

	/**
	 * @param string        $event_filter  Selected event filter.
	 * @param string        $status_filter Selected status filter.
	 * @param array<string> $events        Distinct event names.
	 * @param array<string> $statuses      Distinct statuses.
	 */
	private function render_filter_form( string $event_filter, string $status_filter, array $events, array $statuses ): void {
		echo '<form method="get" style="margin: 16px 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

		echo '<label for="acfw-mcp-event-filter" class="screen-reader-text">'
			. esc_html__( 'Filter by event', 'agent-connector' ) . '</label>';
		echo '<select id="acfw-mcp-event-filter" name="event_filter" style="margin-right: 8px;">';
		echo '<option value="">' . esc_html__( 'All events (excluding lifecycle)', 'agent-connector' ) . '</option>';
		foreach ( $events as $event ) {
			$event = (string) $event;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $event ),
				selected( $event, $event_filter, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $event )
			);
		}
		echo '</select>';

		echo '<label for="acfw-mcp-status-filter" class="screen-reader-text">'
			. esc_html__( 'Filter by status', 'agent-connector' ) . '</label>';
		echo '<select id="acfw-mcp-status-filter" name="status_filter" style="margin-right: 8px;">';
		echo '<option value="">' . esc_html__( 'All statuses', 'agent-connector' ) . '</option>';
		foreach ( $statuses as $status ) {
			$status = (string) $status;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $status ),
				selected( $status, $status_filter, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $status )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'agent-connector' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Render the "Clear log" form button.
	 *
	 * @param int $total Number of events currently logged.
	 */
	private function render_clear_log_form( int $total ): void {
		$confirm = esc_js( __( 'Clear all MCP events? This cannot be undone.', 'agent-connector' ) );

		echo '<form method="post" style="margin: 16px 0;" onsubmit="return confirm(\'' . $confirm . '\');">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( self::CLEAR_ACTION );
		echo '<input type="hidden" name="' . esc_attr( self::CLEAR_ACTION ) . '" value="1" />';
		/* translators: %d: number of events currently logged. */
		echo '<p>' . esc_html( sprintf( _n( '%d event logged.', '%d events logged.', $total, 'agent-connector' ), $total ) ) . ' ';
		submit_button( __( 'Clear log', 'agent-connector' ), 'delete', '', false );
		echo '</p></form>';
	}

	/**
	 * Render the events `<table>`.
	 *
	 * @param array<int, array<string, mixed>> $rows Event rows.
	 */
	private function render_events_table( array $rows ): void {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width: 160px;">' . esc_html__( 'Time', 'agent-connector' ) . '</th>';
		echo '<th style="width: 120px;">' . esc_html__( 'Event', 'agent-connector' ) . '</th>';
		echo '<th style="width: 160px;">' . esc_html__( 'Method', 'agent-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Tool / Prompt', 'agent-connector' ) . '</th>';
		echo '<th style="width: 90px;">' . esc_html__( 'Status', 'agent-connector' ) . '</th>';
		echo '<th style="width: 90px;">' . esc_html__( 'Duration', 'agent-connector' ) . '</th>';
		echo '<th style="width: 110px;">' . esc_html__( 'User', 'agent-connector' ) . '</th>';
		echo '<th style="width: 70px;">' . esc_html__( 'Details', 'agent-connector' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No MCP events recorded yet.', 'agent-connector' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$this->render_event_row( $row );
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $row Event row.
	 */
	private function render_event_row( array $row ): void {
		$id          = (int) ( $row['id'] ?? 0 );
		$created_at  = (string) ( $row['created_at'] ?? '' );
		$event       = (string) ( $row['event'] ?? '' );
		$method      = (string) ( $row['method'] ?? '' );
		$tool        = (string) ( $row['tool_name'] ?? '' );
		$prompt      = (string) ( $row['prompt_name'] ?? '' );
		$status      = (string) ( $row['status'] ?? '' );
		$duration_ms = $row['duration_ms'];
		$user_id     = (int) ( $row['user_id'] ?? 0 );

		$tool_or_prompt = '' !== $tool ? $tool : $prompt;

		$detail_url = add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'event' => $id,
			),
			admin_url( 'admin.php' )
		);

		$user_label = '';
		if ( $user_id > 0 ) {
			$user       = get_userdata( $user_id );
			$user_label = $user ? (string) $user->user_login : '#' . $user_id;
		}

		echo '<tr>';
		echo '<td>' . esc_html( $this->format_local_datetime( $created_at ) ) . '</td>';
		echo '<td><code>' . esc_html( $event ) . '</code></td>';
		echo '<td>' . ( '' !== $method ? '<code>' . esc_html( $method ) . '</code>' : '&mdash;' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . ( '' !== $tool_or_prompt ? '<code>' . esc_html( $tool_or_prompt ) . '</code>' : '&mdash;' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->render_status_badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . esc_html( $this->format_duration( $duration_ms ) ) . '</td>';
		echo '<td>' . esc_html( '' !== $user_label ? $user_label : '—' ) . '</td>';
		echo '<td><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View', 'agent-connector' ) . '</a></td>';
		echo '</tr>';
	}

	/**
	 * @return string HTML-safe markup.
	 */
	private function render_status_badge( string $status ): string {
		if ( '' === $status ) {
			return '&mdash;';
		}

		$color = 'success' === $status ? '#1e7e34' : ( 'error' === $status ? '#a00' : '#555' );

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:%s;color:#fff;font-size:11px;">%s</span>',
			esc_attr( $color ),
			esc_html( $status )
		);
	}

	/**
	 * Render the pagination controls below the events list.
	 *
	 * @param int    $total         Total matching rows.
	 * @param int    $paged         Current page (1-based).
	 * @param string $event_filter  Active event filter.
	 * @param string $status_filter Active status filter.
	 */
	private function render_pagination( int $total, int $paged, string $event_filter, string $status_filter ): void {
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages <= 1 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'          => self::PAGE_SLUG,
				'event_filter'  => '' !== $event_filter ? $event_filter : false,
				'status_filter' => '' !== $status_filter ? $status_filter : false,
				'paged'         => '%#%',
			),
			admin_url( 'admin.php' )
		);

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( empty( $links ) ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( is_array( $links ) ? implode( ' ', $links ) : (string) $links );
		echo '</div></div>';
	}

	/**
	 * Render a single event's detail view.
	 *
	 * @param int $event_id Event row id.
	 */
	private function render_event_detail( int $event_id ): void {
		global $wpdb;

		$table = EventsTable::name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM %i WHERE `id` = %d", $table, $event_id ),
			ARRAY_A
		);

		$back_url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );

		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to events', 'agent-connector' ) . '</a></p>';

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Event not found.', 'agent-connector' ) . '</p>';
			return;
		}

		$tags = array();
		if ( ! empty( $row['tags_json'] ) ) {
			$decoded = json_decode( (string) $row['tags_json'], true );
			if ( is_array( $decoded ) ) {
				$tags = $decoded;
			}
		}

		/* translators: %d: event ID. */
		echo '<h2>' . esc_html( sprintf( __( 'Event #%d', 'agent-connector' ), $event_id ) ) . '</h2>';

		echo '<table class="widefat striped" style="max-width: 900px;">';
		echo '<tbody>';

		$fields = array(
			'created_at'     => __( 'Time (UTC)', 'agent-connector' ),
			'event'          => __( 'Event', 'agent-connector' ),
			'method'         => __( 'Method', 'agent-connector' ),
			'server_id'      => __( 'Server ID', 'agent-connector' ),
			'transport'      => __( 'Transport', 'agent-connector' ),
			'status'         => __( 'Status', 'agent-connector' ),
			'tool_name'      => __( 'Tool', 'agent-connector' ),
			'prompt_name'    => __( 'Prompt', 'agent-connector' ),
			'resource_uri'   => __( 'Resource URI', 'agent-connector' ),
			'session_id'     => __( 'Session ID', 'agent-connector' ),
			'request_id'     => __( 'Request ID', 'agent-connector' ),
			'user_id'        => __( 'User ID', 'agent-connector' ),
			'error_code'     => __( 'Error code', 'agent-connector' ),
			'error_category' => __( 'Error category', 'agent-connector' ),
			'failure_reason' => __( 'Failure reason', 'agent-connector' ),
			'duration_ms'    => __( 'Duration', 'agent-connector' ),
		);

		foreach ( $fields as $key => $label ) {
			$value = $row[ $key ] ?? null;

			if ( 'duration_ms' === $key ) {
				$display = $this->format_duration( $value );
			} elseif ( null === $value || '' === $value ) {
				$display = '—';
			} else {
				$display = (string) $value;
			}

			echo '<tr>';
			echo '<th style="width: 200px; text-align: left;">' . esc_html( (string) $label ) . '</th>';
			echo '<td>' . esc_html( $display ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$input_body  = isset( $row['input_body'] ) ? (string) $row['input_body'] : '';
		$output_body = isset( $row['output_body'] ) ? (string) $row['output_body'] : '';

		$this->render_body_block( __( 'Input (JSON-RPC request)', 'agent-connector' ), $input_body );
		$this->render_body_block( __( 'Output (JSON-RPC response)', 'agent-connector' ), $output_body );

		echo '<h3>' . esc_html__( 'Tags', 'agent-connector' ) . '</h3>';

		if ( empty( $tags ) ) {
			echo '<p>' . esc_html__( 'No tags recorded.', 'agent-connector' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width: 900px;">';
			echo '<tbody>';
			foreach ( $tags as $key => $value ) {
				$display_value = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
				echo '<tr>';
				echo '<th style="width: 200px; text-align: left;"><code>' . esc_html( (string) $key ) . '</code></th>';
				echo '<td><code style="white-space: pre-wrap; word-break: break-all;">' . esc_html( $display_value ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Render a labeled, pretty-printed JSON block. Falls back to the raw string
	 * if the body isn't valid JSON.
	 *
	 * @param string $label Block heading.
	 * @param string $body  Raw body.
	 */
	private function render_body_block( string $label, string $body ): void {
		echo '<h3>' . esc_html( $label ) . '</h3>';

		if ( '' === $body ) {
			echo '<p><em>' . esc_html__( 'Not captured.', 'agent-connector' ) . '</em></p>';
			return;
		}

		$decoded = json_decode( $body, true );
		if ( null !== $decoded || 'null' === trim( $body ) ) {
			$pretty  = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$display = false === $pretty ? $body : (string) $pretty;
		} else {
			$display = $body;
		}

		echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;border-radius:4px;max-width:900px;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:12px;">'
			. esc_html( $display )
			. '</pre>';
	}

	/**
	 * Format a UTC datetime string into the site's locale.
	 *
	 * @param string $utc UTC datetime ('Y-m-d H:i:s').
	 */
	private function format_local_datetime( string $utc ): string {
		if ( '' === $utc ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return $utc;
		}

		$date_format = (string) get_option( 'date_format' );
		$time_format = (string) get_option( 'time_format' );

		return (string) wp_date( $date_format . ' ' . $time_format, $timestamp );
	}

	/**
	 * Format a duration in milliseconds as a human-readable string.
	 *
	 * @param mixed $duration_ms Duration in milliseconds.
	 */
	private function format_duration( $duration_ms ): string {
		if ( null === $duration_ms || '' === $duration_ms ) {
			return '—';
		}

		$ms = (float) $duration_ms;

		if ( $ms < 1 ) {
			return number_format( $ms, 2 ) . ' ms';
		}

		if ( $ms < 1000 ) {
			return number_format( $ms, 1 ) . ' ms';
		}

		return number_format( $ms / 1000, 2 ) . ' s';
	}
}
