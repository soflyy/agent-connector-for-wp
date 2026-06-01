<?php
/**
 * Ability: agent-connector-for-wp/create-admin-login-link
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Abilities;

use AgentConnectorForWp\Services\AdminLoginLink;
use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AdminLoginAbility {

	public const NAME = 'agent-connector-for-wp/create-admin-login-link';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Create Admin Login Link',
			'description'         => 'Mint a one-time, short-lived URL that logs a browser into wp-admin as the current super-admin user. Intended for browser-driving agents (e.g. Playwright) that authenticate to MCP with an application password and have no admin session of their own: open the returned login_url in the browser to get a logged-in wp-admin. The link works once and expires (default 5 minutes). The URL is built from the host used to reach this site, so it is reachable from wherever the request originated.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'redirect_to' => array(
						'type'        => 'string',
						'description' => 'Admin-relative page to land on after login, e.g. "plugins.php" or "options-general.php". Defaults to the dashboard ("index.php"). Absolute URLs are rejected.',
					),
					'ttl_seconds' => array(
						'type'        => 'integer',
						'description' => 'How long the link stays valid, in seconds. Clamped to [30, 900]. Defaults to 300 (5 minutes).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'login_url'          => array(
						'type'        => 'string',
						'description' => 'Open this once in a browser to land in wp-admin, logged in.',
					),
					'expires_at'         => array(
						'type'        => 'string',
						'description' => 'ISO-8601 (UTC) expiry timestamp.',
					),
					'expires_in_seconds' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => AuditLogger::wrap(
				self::NAME,
				array( self::class, 'execute' ),
				array( self::class, 'summarize' )
			),
			'permission_callback' => static function (): bool {
				return Config::has_admin_access();
			},
			'meta'                => array(
				'mcp'          => array( 'public' => true ),
				'annotations'  => array(
					'readonly'        => false,
					'destructiveHint' => false,
					'openWorldHint'   => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	public static function execute( array $input ) {
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
			return new WP_Error( 'agent_connector_login_no_user', 'Could not determine the current user.', array( 'status' => 400 ) );
		}

		$redirect_to = isset( $input['redirect_to'] ) ? (string) $input['redirect_to'] : '';
		$ttl_seconds = isset( $input['ttl_seconds'] ) ? (int) $input['ttl_seconds'] : AdminLoginLink::DEFAULT_TTL;

		return AdminLoginLink::create( $user->ID, $redirect_to, $ttl_seconds );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array(
			'redirect_to' => isset( $input['redirect_to'] ) ? (string) $input['redirect_to'] : '',
			'ttl_seconds' => isset( $input['ttl_seconds'] ) ? (int) $input['ttl_seconds'] : AdminLoginLink::DEFAULT_TTL,
		);
	}
}
