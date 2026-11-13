<?php
/**
 * Purges the site's caches: object cache, page-cache plugins, transients,
 * OPcache, and rewrite rules.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Clears whichever caches the agent asks for and reports exactly what ran.
 * Page caching has no core API, so we detect the common caching plugins and
 * hosts and call each one's own "purge everything" entry point. Every purge is
 * best-effort: one failing provider is reported and never stops the others.
 */
final class CachePurger {

	public const TARGETS = array( 'object_cache', 'page_cache', 'transients', 'opcache', 'rewrite_rules' );

	public const DEFAULT_TARGETS = array( 'object_cache', 'page_cache' );

	/**
	 * Purge the requested cache targets.
	 *
	 * @param array{targets?: string[]} $input
	 * @return array{targets: string[], results: array<int, array{target: string, provider: string, status: string, message: string}>}|\WP_Error
	 */
	public function purge( array $input ) {
		$targets = $this->resolve_targets( $input['targets'] ?? self::DEFAULT_TARGETS );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$results = array();
		foreach ( $targets as $target ) {
			switch ( $target ) {
				case 'object_cache':
					$results[] = $this->purge_object_cache();
					break;
				case 'page_cache':
					$results = array_merge( $results, $this->purge_page_cache() );
					break;
				case 'transients':
					$results[] = $this->purge_transients();
					break;
				case 'opcache':
					$results[] = $this->purge_opcache();
					break;
				case 'rewrite_rules':
					$results[] = $this->purge_rewrite_rules();
					break;
			}
		}

		/**
		 * Fires after the purge-cache ability runs, so other plugins can clear
		 * caches this service doesn't know about.
		 *
		 * @param string[] $targets The targets that were purged.
		 */
		do_action( 'agent_connector_for_wp_purge_cache', $targets );

		return array(
			'targets' => $targets,
			'results' => $results,
		);
	}

	/**
	 * Normalize the targets input: "all" expands to every target, order follows
	 * TARGETS, and unknown names are rejected.
	 *
	 * @param mixed $raw
	 * @return string[]|\WP_Error
	 */
	private function resolve_targets( $raw ) {
		$requested = array_map( 'strval', (array) $raw );
		if ( in_array( 'all', $requested, true ) ) {
			return self::TARGETS;
		}

		$unknown = array_diff( $requested, self::TARGETS );
		if ( array() !== $unknown ) {
			return new \WP_Error(
				'agent_connector_purge_cache_unknown_target',
				sprintf(
					'Unknown cache target(s): %s. Valid targets: %s, or "all".',
					implode( ', ', $unknown ),
					implode( ', ', self::TARGETS )
				),
				array( 'status' => 400 )
			);
		}

		$targets = array_values( array_intersect( self::TARGETS, $requested ) );
		return array() === $targets ? self::DEFAULT_TARGETS : $targets;
	}

	/**
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function purge_object_cache(): array {
		$provider = wp_using_ext_object_cache() ? 'persistent object cache' : 'runtime object cache';
		return $this->attempt(
			'object_cache',
			$provider,
			static function (): string {
				if ( ! wp_cache_flush() ) {
					throw new \RuntimeException( 'wp_cache_flush() reported failure.' );
				}
				return 'Flushed.';
			}
		);
	}

	/**
	 * Call the "purge everything" entry point of each detected page-cache
	 * plugin or host. Nothing detected is reported as a single skipped row.
	 *
	 * @return array<int, array{target: string, provider: string, status: string, message: string}>
	 */
	private function purge_page_cache(): array {
		$results = array();
		foreach ( $this->page_cache_providers() as $provider => $purge ) {
			$results[] = $this->attempt( 'page_cache', $provider, $purge );
		}

		if ( array() === $results ) {
			$results[] = $this->row( 'page_cache', 'none', 'skipped', 'No supported page-cache plugin or host detected.' );
		}
		return $results;
	}

	/**
	 * Detected page-cache providers, keyed by display name.
	 *
	 * @return array<string, callable(): ?string>
	 */
	private function page_cache_providers(): array {
		$providers = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$providers['WP Rocket'] = static function (): void {
				rocket_clean_domain();
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
			};
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$providers['W3 Total Cache'] = static function (): void {
				w3tc_flush_all();
			};
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$providers['WP Super Cache'] = static function (): void {
				wp_cache_clear_cache();
			};
		}
		if ( defined( 'LSCWP_V' ) ) {
			$providers['LiteSpeed Cache'] = static function (): void {
				do_action( 'litespeed_purge_all' );
			};
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			$providers['WP Fastest Cache'] = static function (): void {
				wpfc_clear_all_cache( true );
			};
		}
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			$providers['Cache Enabler'] = static function (): void {
				\Cache_Enabler::clear_complete_cache();
			};
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$providers['SiteGround Optimizer'] = static function (): void {
				sg_cachepress_purge_cache();
			};
		}
		if ( class_exists( 'Breeze_PurgeCache' ) ) {
			$providers['Breeze'] = static function (): void {
				do_action( 'breeze_clear_all_cache' );
			};
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			$providers['Autoptimize'] = static function (): void {
				\autoptimizeCache::clearall();
			};
		}
		if ( class_exists( 'WpeCommon' ) ) {
			$providers['WP Engine'] = static function (): void {
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					\WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
			};
		}
		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
			$providers['Pantheon'] = static function (): void {
				pantheon_wp_clear_edge_all();
			};
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			$providers['Elementor CSS'] = static function (): void {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			};
		}

		return $providers;
	}

	/**
	 * Delete every transient. With a persistent object cache, transients live
	 * there rather than in the options table, so flushing that cache clears
	 * them; otherwise we delete the rows directly (as `wp transient delete
	 * --all` does).
	 *
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function purge_transients(): array {
		if ( wp_using_ext_object_cache() ) {
			return $this->attempt(
				'transients',
				'persistent object cache',
				static function (): string {
					if ( ! wp_cache_flush() ) {
						throw new \RuntimeException( 'wp_cache_flush() reported failure.' );
					}
					return 'Transients are stored in the persistent object cache; flushed it.';
				}
			);
		}

		return $this->attempt(
			'transients',
			'database',
			static function (): string {
				global $wpdb;

				$deleted = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%'
					)
				);
				if ( is_multisite() ) {
					$deleted += (int) $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
							$wpdb->esc_like( '_site_transient_' ) . '%'
						)
					);
				}
				// The deleted rows may still sit in the runtime cache (alloptions).
				wp_cache_flush();

				return sprintf( 'Deleted %d transient row(s).', $deleted );
			}
		);
	}

	/**
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function purge_opcache(): array {
		if ( ! function_exists( 'opcache_reset' ) || ! function_exists( 'opcache_get_status' ) || false === @opcache_get_status( false ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $this->row( 'opcache', 'PHP OPcache', 'skipped', 'OPcache is not enabled for this PHP process.' );
		}
		return $this->attempt(
			'opcache',
			'PHP OPcache',
			static function (): string {
				if ( ! opcache_reset() ) {
					throw new \RuntimeException( 'opcache_reset() reported failure (it may be restricted by opcache.restrict_api).' );
				}
				return 'Reset.';
			}
		);
	}

	/**
	 * Soft flush: regenerates the rules option without rewriting .htaccess.
	 *
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function purge_rewrite_rules(): array {
		return $this->attempt(
			'rewrite_rules',
			'WordPress rewrite rules',
			static function (): void {
				flush_rewrite_rules( false );
			}
		);
	}

	/**
	 * Run one purge and turn its outcome into a result row. The callback may
	 * return a message; anything it throws is reported as an error.
	 *
	 * @param callable(): ?string $purge
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function attempt( string $target, string $provider, callable $purge ): array {
		try {
			$message = $purge();
		} catch ( \Throwable $e ) {
			return $this->row( $target, $provider, 'error', $e->getMessage() );
		}

		return $this->row( $target, $provider, 'purged', is_string( $message ) && '' !== $message ? $message : 'Purged.' );
	}

	/**
	 * @return array{target: string, provider: string, status: string, message: string}
	 */
	private function row( string $target, string $provider, string $status, string $message ): array {
		return array(
			'target'   => $target,
			'provider' => $provider,
			'status'   => $status,
			'message'  => $message,
		);
	}
}
