<?php
/**
 * Client for the remote "ability pack" companion-plugin directory.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a remote JSON directory of companion "ability pack" plugins and matches
 * its entries against the WP plugins installed on this site.
 *
 * The directory is a published list of third-party companion plugins, each of
 * which targets a specific host WP plugin (e.g. an ability pack "for WooCommerce")
 * and registers AI abilities through Agent Connector's API. This client tells the
 * admin which of *their* installed plugins have an ability pack available.
 *
 * Network access is best-effort and never fatal: the result is cached in a
 * transient, failures fall back to the last good copy, and malformed JSON or
 * entries are ignored defensively. See docs/directory-schema.md for the schema.
 */
final class PluginDirectory {

	/**
	 * Default ability-pack match endpoint (the agent-ready-plugins-tracker app).
	 *
	 * This site POSTs its installed plugins here and gets back the ability packs
	 * that target any of them (the tracker reads them from the GitHub `pack-index`
	 * manifest). Override with the `agent_connector_for_wp_directory_url` filter.
	 * A failure is handled gracefully: the UI shows a clean "directory unavailable"
	 * state and falls back to the last cached copy, never a fatal.
	 */
	public const DEFAULT_DIRECTORY_URL = 'https://agent-ready-plugins-tracker-git-master-future-layer.vercel.app/api/ability-packs/match';

	/**
	 * Transient holding the last successfully fetched + normalized directory.
	 *
	 * @var array<int,array<string,string>>|false
	 */
	public const CACHE_KEY = 'agent_connector_for_wp_directory_cache';

	/**
	 * Transient lifetime: 12 hours.
	 */
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Network timeout for the directory fetch, in seconds. Kept short so a slow
	 * or unreachable endpoint never stalls an admin page render for long.
	 */
	private const HTTP_TIMEOUT = 5;

	/**
	 * The remote directory URL, after the override filter.
	 */
	public static function directory_url(): string {
		/**
		 * Filters the URL of the remote ability-pack directory.
		 *
		 * @param string $url Default directory URL.
		 */
		$url = (string) apply_filters( 'agent_connector_for_wp_directory_url', self::DEFAULT_DIRECTORY_URL );

		return $url;
	}

	/**
	 * Return the cached payload, or null when nothing usable has been cached yet.
	 *
	 * The payload wraps the entries with the installed-plugins fingerprint that
	 * was current when it was stored, so a changed plugin set can invalidate it.
	 *
	 * @return array{fp:string,entries:array<int,array<string,string>>}|null
	 */
	public static function cached(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		return ( is_array( $cached ) && isset( $cached['entries'] ) && is_array( $cached['entries'] ) )
			? $cached
			: null;
	}

	/**
	 * Fetch the directory, preferring the cache. On a cache miss it hits the
	 * network; on a network/parse failure it falls back to any stale cached copy.
	 *
	 * @param bool $force When true, bypass the cache and refetch from the network.
	 *
	 * @return array{
	 *     entries: array<int,array<string,string>>,
	 *     stale: bool,
	 *     error: string,
	 *     url: string
	 * }
	 */
	public static function get( bool $force = false ): array {
		$url = self::directory_url();
		$fp  = self::installed_fingerprint();

		$cached_statuses = static function ( array $cached ): array {
			return isset( $cached['statuses'] ) && is_array( $cached['statuses'] ) ? $cached['statuses'] : array();
		};

		if ( ! $force ) {
			$cached = self::cached();
			// Only serve the cache when it was built for the same installed-plugin
			// set; otherwise a newly installed plugin would stay hidden for 12h.
			if ( null !== $cached && isset( $cached['fp'] ) && $cached['fp'] === $fp ) {
				return array(
					'entries'  => $cached['entries'],
					'statuses' => $cached_statuses( $cached ),
					'stale'    => false,
					'error'    => '',
					'url'      => $url,
				);
			}
		}

		$fetched = self::fetch( $url );

		if ( null !== $fetched ) {
			set_transient(
				self::CACHE_KEY,
				array(
					'fp'       => $fp,
					'entries'  => $fetched['entries'],
					'statuses' => $fetched['statuses'],
				),
				self::CACHE_TTL
			);

			return array(
				'entries'  => $fetched['entries'],
				'statuses' => $fetched['statuses'],
				'stale'    => false,
				'error'    => '',
				'url'      => $url,
			);
		}

		// Network/parse failed — fall back to any (possibly stale) cached copy,
		// even if the installed set has since changed: stale-but-real beats empty.
		$cached = self::cached();
		if ( null !== $cached ) {
			return array(
				'entries'  => $cached['entries'],
				'statuses' => $cached_statuses( $cached ),
				'stale'    => true,
				'error'    => __( 'Could not refresh the directory; showing the last cached copy.', 'agent-connector-for-wp' ),
				'url'      => $url,
			);
		}

		return array(
			'entries'  => array(),
			'statuses' => array(),
			'stale'    => false,
			'error'    => __( 'The ability-pack directory is currently unavailable.', 'agent-connector-for-wp' ),
			'url'      => $url,
		);
	}

	/**
	 * Drop the cached directory so the next read refetches.
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Fetch + decode + normalize the directory from the network.
	 *
	 * @param string $url Directory URL.
	 *
	 * @return array{entries:array<int,array<string,string>>,statuses:array<string,array<string,mixed>>}|null
	 *         Normalized entries + per-slug statuses, or null on any failure.
	 */
	private static function fetch( string $url ): ?array {
		if ( '' === $url || ! function_exists( 'wp_remote_post' ) ) {
			return null;
		}

		$payload = array(
			'site_url' => home_url(),
			'plugins'  => self::installed_plugin_slugs(),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => 'AgentConnectorForWp/' . ( defined( 'AGENT_CONNECTOR_FOR_WP_VERSION' ) ? AGENT_CONNECTOR_FOR_WP_VERSION : 'dev' ),
				'headers'    => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'       => (string) wp_json_encode( $payload ),
			)
		);

		if ( $response instanceof \WP_Error ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return self::normalize( $decoded );
	}

	/**
	 * Normalize a decoded directory payload into a clean list of entries.
	 *
	 * Accepts either a top-level array of entries, or an object with an
	 * `entries` key holding that array. Malformed entries (missing the two
	 * required keys) are silently dropped.
	 *
	 * @param mixed $decoded Decoded JSON.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function normalize( $decoded ): array {
		$raw_entries  = array();
		$raw_statuses = array();

		if ( is_array( $decoded ) && isset( $decoded['entries'] ) && is_array( $decoded['entries'] ) ) {
			$raw_entries = $decoded['entries'];
			if ( isset( $decoded['statuses'] ) && is_array( $decoded['statuses'] ) ) {
				$raw_statuses = $decoded['statuses'];
			}
		} elseif ( is_array( $decoded ) ) {
			$raw_entries = $decoded;
		}

		$entries = array();
		foreach ( $raw_entries as $item ) {
			$entry = self::normalize_entry( $item );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return array(
			'entries'  => $entries,
			'statuses' => self::normalize_status_map( $raw_statuses ),
		);
	}

	/**
	 * Normalize the per-plugin AI-status list into a map keyed by plugin slug.
	 *
	 * Each value: level, official_since, official_docs_url, abilities_count, and
	 * unofficial_plugins[] (name, plugin_url, description, author, author_url).
	 *
	 * @param array<int,mixed> $raw Raw statuses array from the endpoint.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalize_status_map( array $raw ): array {
		$str = static function ( $value ): string {
			return is_string( $value ) ? trim( $value ) : '';
		};

		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = $str( $item['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$unofficial = array();
			if ( isset( $item['unofficial_plugins'] ) && is_array( $item['unofficial_plugins'] ) ) {
				foreach ( $item['unofficial_plugins'] as $u ) {
					if ( ! is_array( $u ) || '' === $str( $u['name'] ?? '' ) ) {
						continue;
					}
					$unofficial[] = array(
						'name'        => $str( $u['name'] ?? '' ),
						'plugin_url'  => $str( $u['plugin_url'] ?? '' ),
						'description' => $str( $u['description'] ?? '' ),
						'author'      => $str( $u['author'] ?? '' ),
						'author_url'  => $str( $u['author_url'] ?? '' ),
					);
				}
			}

			$out[ $slug ] = array(
				'level'              => $str( $item['level'] ?? '' ),
				'official_since'     => $str( $item['official_since'] ?? '' ),
				'official_docs_url'  => $str( $item['official_docs_url'] ?? '' ),
				'abilities_count'    => isset( $item['abilities_count'] ) && is_numeric( $item['abilities_count'] ) ? (int) $item['abilities_count'] : 0,
				'unofficial_plugins' => $unofficial,
			);
		}

		return $out;
	}

	/**
	 * Validate + normalize a single directory entry, or null if it is unusable.
	 *
	 * Required: target_plugin, ability_pack_name. Everything else is optional and
	 * defaulted to an empty string. `target_plugin` is the WP plugin the pack
	 * extends — the same value a pack declares in its `Agent Connector Target:`
	 * header.
	 *
	 * @param mixed $item Raw entry.
	 *
	 * @return array<string,string>|null
	 */
	private static function normalize_entry( $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$str = static function ( $value ): string {
			return is_string( $value ) ? trim( $value ) : '';
		};

		$target = $str( $item['target_plugin'] ?? '' );
		// Prefer ability_pack_name; fall back to the generic `name` field.
		$pack_name = $str( $item['ability_pack_name'] ?? '' );
		if ( '' === $pack_name ) {
			$pack_name = $str( $item['name'] ?? '' );
		}

		if ( '' === $target || '' === $pack_name ) {
			return null;
		}

		return array(
			'target_plugin'      => $target,
			'target_plugin_name' => $str( $item['target_plugin_name'] ?? '' ),
			'ability_pack_slug' => $str( $item['ability_pack_slug'] ?? '' ),
			'ability_pack_name' => $pack_name,
			'source_url'        => $str( $item['source_url'] ?? '' ),
			'description'       => $str( $item['description'] ?? '' ),
			'version'           => $str( $item['version'] ?? '' ),
			'download_url'      => $str( $item['download_url'] ?? '' ),
		);
	}

	/**
	 * Match directory entries against the plugins installed on this site.
	 *
	 * @param array<int,array<string,string>> $entries Normalized directory entries.
	 *
	 * @return array<int,array<string,mixed>> One row per match, each with the
	 *     directory entry plus target_plugin_file / target_plugin_name /
	 *     target_active and pack_installed / pack_active booleans.
	 */
	public static function match_installed( array $entries ): array {
		$installed = self::installed_plugins();

		$matches = array();
		foreach ( $entries as $entry ) {
			$target_key = self::find_installed_key( $entry['target_plugin'], $installed );
			if ( null === $target_key ) {
				continue;
			}

			$pack_key = '' !== $entry['ability_pack_slug']
				? self::find_installed_key( $entry['ability_pack_slug'], $installed )
				: null;

			$matches[] = array(
				'entry'              => $entry,
				'target_plugin_file' => $target_key,
				'target_plugin_name' => (string) ( $installed[ $target_key ]['Name'] ?? $entry['target_plugin_name'] ),
				'target_active'      => self::is_active( $target_key ),
				'pack_installed'    => null !== $pack_key,
				'pack_active'       => null !== $pack_key && self::is_active( $pack_key ),
			);
		}

		return $matches;
	}

	/**
	 * Convenience: fetch (cache-aware) and match in one call.
	 *
	 * @param bool $force Force a network refresh.
	 *
	 * @return array{
	 *     matches: array<int,array<string,mixed>>,
	 *     stale: bool,
	 *     error: string,
	 *     url: string,
	 *     total: int
	 * }
	 */
	public static function matches( bool $force = false ): array {
		$result = self::get( $force );

		return array(
			'matches' => self::match_installed( $result['entries'] ),
			'stale'   => $result['stale'],
			'error'   => $result['error'],
			'url'     => $result['url'],
			'total'   => count( $result['entries'] ),
		);
	}

	/**
	 * Every installed plugin (minus the host and ability packs themselves), each
	 * joined with its available ability pack when one exists. Drives the admin
	 * screen so plugins without a pack are still listed.
	 *
	 * Only ACTIVE plugins are included. Each row: plugin_file, plugin_name,
	 * plugin_active, has_pack, ai_status (level, official fields, unofficial_plugins;
	 * may be empty), and — when has_pack — entry / pack_installed / pack_active.
	 *
	 * @return array{
	 *     rows: array<int,array<string,mixed>>,
	 *     stale: bool,
	 *     error: string,
	 *     url: string,
	 *     total: int
	 * }
	 */
	public static function installed_overview( bool $force = false ): array {
		$result   = self::get( $force );
		$statuses = isset( $result['statuses'] ) && is_array( $result['statuses'] ) ? $result['statuses'] : array();

		$by_file = array();
		foreach ( self::match_installed( $result['entries'] ) as $match ) {
			$by_file[ $match['target_plugin_file'] ] = $match;
		}

		$rows = array();
		foreach ( self::installed_plugins() as $file => $data ) {
			if ( self::is_self_or_pack( $file, $data ) ) {
				continue;
			}
			if ( ! self::is_active( $file ) ) {
				continue; // Active plugins only.
			}

			if ( isset( $by_file[ $file ] ) ) {
				$row             = $by_file[ $file ];
				$row['has_pack'] = true;
			} else {
				$row = array(
					'has_pack'       => false,
					'entry'          => array(),
					'pack_installed' => false,
					'pack_active'    => false,
				);
			}

			$row['plugin_file']   = $file;
			$row['plugin_name']   = (string) ( $data['Name'] ?? $file );
			$row['plugin_active'] = true;
			$row['ai_status']     = $statuses[ self::folder_slug( $file ) ] ?? array();

			$rows[] = $row;
		}

		// Rank: our pack available first, then any known AI status, then the rest;
		// alphabetical within each group.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$rank = static function ( array $r ): int {
					if ( ! empty( $r['has_pack'] ) ) {
						return 0;
					}
					return self::row_has_ai_info( $r ) ? 1 : 2;
				};
				$ra = $rank( $a );
				$rb = $rank( $b );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return strcasecmp( (string) $a['plugin_name'], (string) $b['plugin_name'] );
			}
		);

		return array(
			'rows'  => $rows,
			'stale' => $result['stale'],
			'error' => $result['error'],
			'url'   => $result['url'],
			'total' => count( $result['entries'] ),
		);
	}

	/**
	 * Whether a row's ai_status indicates any known official/third-party support.
	 *
	 * @param array<string,mixed> $row Overview row.
	 */
	public static function row_has_ai_info( array $row ): bool {
		$s = isset( $row['ai_status'] ) && is_array( $row['ai_status'] ) ? $row['ai_status'] : array();
		$level = (string) ( $s['level'] ?? '' );
		if ( in_array( $level, array( 'official', 'unofficial', 'coming_soon' ), true ) ) {
			return true;
		}
		return ! empty( $s['unofficial_plugins'] );
	}

	/**
	 * Whether a plugin should be hidden from the overview: the host plugin itself,
	 * or an ability pack (carries the marker header, or follows the pack naming).
	 *
	 * @param string               $file Plugin file key.
	 * @param array<string,mixed>  $data get_plugins() entry.
	 */
	private static function is_self_or_pack( string $file, array $data ): bool {
		$slug = self::folder_slug( $file );

		if ( 'agent-connector-for-wp' === $slug ) {
			return true;
		}

		$marker = isset( $data['Agent Connector'] ) ? trim( (string) $data['Agent Connector'] ) : '';
		if ( '' !== $marker ) {
			return true;
		}

		return 0 === strpos( $slug, 'unofficial-abilities-for-' );
	}

	/**
	 * All installed plugins, keyed by plugin file (e.g. "woocommerce/woocommerce.php").
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return (array) get_plugins();
	}

	/**
	 * The installed plugins as a compact list for the match request body.
	 *
	 * @return array<int,array{slug:string,file:string,active:bool}>
	 */
	private static function installed_plugin_slugs(): array {
		$out = array();
		foreach ( array_keys( self::installed_plugins() ) as $file ) {
			$out[] = array(
				'slug'   => self::folder_slug( $file ),
				'file'   => $file,
				'active' => self::is_active( $file ),
			);
		}

		return $out;
	}

	/**
	 * A fingerprint of the installed-plugin set, used to invalidate the cache when
	 * plugins are added/removed (the match response depends on what's installed).
	 */
	private static function installed_fingerprint(): string {
		$files = array_keys( self::installed_plugins() );
		sort( $files );

		return md5( implode( '|', $files ) );
	}

	/**
	 * Resolve an ability-pack (or target) slug to its installed plugin file, or
	 * null when it isn't installed. Public so the admin install/activate flow and
	 * the pack updater can share the same tolerant matching.
	 */
	public static function installed_file_for_slug( string $slug ): ?string {
		return self::find_installed_key( $slug, self::installed_plugins() );
	}

	/**
	 * Resolve a directory slug to an installed plugin file, tolerating slug
	 * format differences (full "folder/file.php" path vs bare folder slug).
	 *
	 * @param string                            $slug      Directory-supplied slug.
	 * @param array<string,array<string,mixed>> $installed get_plugins() output.
	 *
	 * @return string|null The matching plugin file key, or null when not installed.
	 */
	private static function find_installed_key( string $slug, array $installed ): ?string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return null;
		}

		// 1. Exact "folder/file.php" match.
		if ( isset( $installed[ $slug ] ) ) {
			return $slug;
		}

		// 2. Treat the supplied value as a folder slug and match on dirname,
		//    or on the file stem for single-file plugins ("hello.php").
		$needle = self::folder_slug( $slug );
		foreach ( array_keys( $installed ) as $file ) {
			if ( self::folder_slug( $file ) === $needle ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Reduce a plugin file or slug to its comparable folder slug.
	 *
	 * "woocommerce/woocommerce.php" → "woocommerce"
	 * "woocommerce"                 → "woocommerce"
	 * "hello.php"                   → "hello"
	 */
	private static function folder_slug( string $value ): string {
		$value = trim( $value );
		if ( false !== strpos( $value, '/' ) ) {
			return dirname( $value );
		}

		// Single-file plugin or bare slug: strip a trailing ".php".
		return preg_replace( '/\.php$/', '', $value ) ?? $value;
	}

	/**
	 * Whether a plugin file is active (site-wide or, on multisite, network-active).
	 */
	private static function is_active( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
