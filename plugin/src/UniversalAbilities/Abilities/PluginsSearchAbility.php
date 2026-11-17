<?php
/**
 * Ability: agent-connector-for-wp/plugins-search
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginsSearchAbility {

	public const NAME = 'agent-connector-for-wp/plugins-search';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Search wordpress.org Plugins',
			'description'         => 'Search the wordpress.org plugin directory. Returns slugs to pass to plugin-install, with ratings, active installs, compatibility and whether each is already installed.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'What to search for.',
					),
					'page' => array(
						'type'        => 'integer',
						'description' => 'Results page. Defaults to 1.',
					),
					'per_page' => array(
						'type'        => 'integer',
						'description' => 'Results per page, up to 30. Defaults to 10.',
					),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
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
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new PluginManager() )->search( (string) ( $input['query'] ?? '' ), (int) ( $input['page'] ?? 1 ), (int) ( $input['per_page'] ?? 10 ) );
	}

	/**
	 * The input is small and holds no secrets, so it is logged as is.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return $input;
	}
}
