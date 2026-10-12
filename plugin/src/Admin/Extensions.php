<?php
/**
 * Extension points other plugins use to add to the admin app.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The admin app's PHP-side extension points. Presence of these is signalled by
 * the AGENT_CONNECTOR_FOR_WP_EXTENSIONS_API constant.
 *
 * - Settings rows (filter `agent_connector_for_wp_settings_rows`): declarative
 *   rows appended to the General section of Settings, each with an optional
 *   action button that POSTs to a REST route the extending plugin registers.
 * - Admin assets (action `agent_connector_for_wp_enqueue_admin_assets`): fired
 *   on the Agent Connector screen before the app's own script is enqueued, so
 *   an extension's script runs first and can register JS hooks (wp.hooks
 *   filters, e.g. `agentConnector.abilities.tabs`) before the app renders.
 */
final class Extensions {

	/**
	 * The registered settings rows, validated and shaped for the admin app.
	 *
	 * Row input shape (array):
	 *   id           string  Required, unique.
	 *   section      string  Only 'general' exists. Default 'general'.
	 *   label        string  Required.
	 *   description  string
	 *   status       string  'active' (renders a status pill) or 'available'
	 *                        (renders the action button). Default 'available'.
	 *   status_label string  Pill text. Default "Active".
	 *   action       array   Required for 'available':
	 *     label      string  Button text.
	 *     rest_route string  e.g. '/my-plugin/v1/do-thing'. POSTed with the
	 *                        wp_rest nonce; the app then refetches status.
	 *     confirm    array   Optional dialog: title, subtitle, intro,
	 *                        items (list of [title, description]),
	 *                        confirm_label, busy_label.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function settings_rows(): array {
		/**
		 * Filters the extra rows shown in the General section of Settings.
		 *
		 * @param array<int,array<string,mixed>> $rows Rows (see Extensions::settings_rows()).
		 */
		$raw = apply_filters( 'agent_connector_for_wp_settings_rows', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		$seen = array();
		foreach ( $raw as $row ) {
			$row = is_array( $row ) ? self::normalize_row( $row ) : null;
			if ( null === $row || isset( $seen[ $row['id'] ] ) ) {
				continue;
			}
			$seen[ $row['id'] ] = true;
			$rows[]             = $row;
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $row Raw row.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function normalize_row( array $row ): ?array {
		$id    = sanitize_key( self::str( $row['id'] ?? '' ) );
		$label = self::str( $row['label'] ?? '' );
		if ( '' === $id || '' === $label ) {
			return null;
		}

		$status = 'active' === ( $row['status'] ?? '' ) ? 'active' : 'available';
		$action = null;
		if ( 'available' === $status ) {
			$action = is_array( $row['action'] ?? null ) ? self::normalize_action( $row['action'] ) : null;
			if ( null === $action ) {
				return null;
			}
		}

		return array(
			'id'          => $id,
			'section'     => 'general',
			'label'       => $label,
			'description' => self::str( $row['description'] ?? '' ),
			'status'      => $status,
			'statusLabel' => self::str( $row['status_label'] ?? '' ),
			'action'      => $action,
		);
	}

	/**
	 * @param array<string,mixed> $action Raw action.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function normalize_action( array $action ): ?array {
		$label = self::str( $action['label'] ?? '' );
		$route = self::str( $action['rest_route'] ?? '' );
		if ( '' === $label || '' === $route ) {
			return null;
		}

		$confirm = null;
		if ( is_array( $action['confirm'] ?? null ) ) {
			$c     = $action['confirm'];
			$items = array();
			foreach ( is_array( $c['items'] ?? null ) ? $c['items'] : array() as $item ) {
				if ( is_array( $item ) ) {
					$items[] = array(
						'title'       => self::str( $item[0] ?? ( $item['title'] ?? '' ) ),
						'description' => self::str( $item[1] ?? ( $item['description'] ?? '' ) ),
					);
				}
			}
			$confirm = array(
				'title'        => self::str( $c['title'] ?? '' ),
				'subtitle'     => self::str( $c['subtitle'] ?? '' ),
				'intro'        => self::str( $c['intro'] ?? '' ),
				'items'        => $items,
				'confirmLabel' => self::str( $c['confirm_label'] ?? '' ),
				'busyLabel'    => self::str( $c['busy_label'] ?? '' ),
			);
		}

		return array(
			'label'   => $label,
			'url'     => rest_url( ltrim( $route, '/' ) ),
			'confirm' => $confirm,
		);
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function str( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}
}
