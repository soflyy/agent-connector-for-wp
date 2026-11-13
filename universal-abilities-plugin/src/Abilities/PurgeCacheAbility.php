<?php
/**
 * Ability: agent-connector-for-wp/purge-cache
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\DefaultAbilities\Services\CachePurger;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class PurgeCacheAbility {

	public const NAME = 'agent-connector-for-wp/purge-cache';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Purge Cache',
			'description'         => 'Purges the site\'s caches so changes show up immediately. Targets: "object_cache" (wp_cache_flush, including Redis/Memcached drop-ins), "page_cache" (calls the purge-all entry point of each detected caching plugin or host: WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, Cache Enabler, SiteGround Optimizer, Breeze, Autoptimize, WP Engine, Pantheon, plus Elementor\'s generated CSS), "transients" (deletes every transient), "opcache" (resets PHP OPcache), and "rewrite_rules" (soft-flushes permalinks). Defaults to object_cache + page_cache; pass "all" for everything. Returns one result row per cache cleared, so you can see which providers ran, were skipped, or failed.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'targets' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array_merge( CachePurger::TARGETS, array( 'all' ) ),
						),
						'default'     => CachePurger::DEFAULT_TARGETS,
						'description' => 'Which caches to purge. Defaults to ["object_cache", "page_cache"]. Include "all" to purge every target.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'targets' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'The targets that were purged, after expanding "all".',
					),
					'results' => array(
						'type'        => 'array',
						'description' => 'One row per cache provider that ran or was skipped.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'target'   => array(
									'type'        => 'string',
									'description' => 'The target this row belongs to.',
								),
								'provider' => array(
									'type'        => 'string',
									'description' => 'What was purged, e.g. "WP Rocket" or "persistent object cache".',
								),
								'status'   => array(
									'type'        => 'string',
									'enum'        => array( 'purged', 'skipped', 'error' ),
									'description' => 'Outcome for this provider.',
								),
								'message'  => array(
									'type'        => 'string',
									'description' => 'Human-readable detail.',
								),
							),
						),
					),
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
					'idempotentHint'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new CachePurger() )->purge( $input );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array(
			'targets' => isset( $input['targets'] ) ? array_map( 'strval', (array) $input['targets'] ) : CachePurger::DEFAULT_TARGETS,
		);
	}
}
