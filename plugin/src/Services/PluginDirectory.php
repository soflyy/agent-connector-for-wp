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

		if ( ! $force ) {
			$cached = self::cached();
			// Only serve the cache when it was built for the same installed-plugin
			// set; otherwise a newly installed plugin would stay hidden for 12h.
			if ( null !== $cached && isset( $cached['fp'] ) && $cached['fp'] === $fp ) {
				return array(
					'entries' => $cached['entries'],
					'stale'   => false,
					'error'   => '',
					'url'     => $url,
				);
			}
		}

		$fetched = self::fetch( $url );

		if ( null !== $fetched ) {
			set_transient( self::CACHE_KEY, array( 'fp' => $fp, 'entries' => $fetched ), self::CACHE_TTL );

			return array(
				'entries' => $fetched,
				'stale'   => false,
				'error'   => '',
				'url'     => $url,
			);
		}

		// Network/parse failed — fall back to any (possibly stale) cached copy,
		// even if the installed set has since changed: stale-but-real beats empty.
		$cached = self::cached();
		if ( null !== $cached ) {
			return array(
				'entries' => $cached['entries'],
				'stale'   => true,
				'error'   => __( 'Could not refresh the directory; showing the last cached copy.', 'agent-connector-for-wp' ),
				'url'     => $url,
			);
		}

		return array(
			'entries' => array(),
			'stale'   => false,
			'error'   => __( 'The ability-pack directory is currently unavailable.', 'agent-connector-for-wp' ),
			'url'     => $url,
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
	 * @return array<int,array<string,string>>|null Normalized entries, or null on any failure.
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
		if ( is_array( $decoded ) && isset( $decoded['entries'] ) && is_array( $decoded['entries'] ) ) {
			$raw = $decoded['entries'];
		} elseif ( is_array( $decoded ) ) {
			$raw = $decoded;
		} else {
			return array();
		}

		$entries = array();
		foreach ( $raw as $item ) {
			$entry = self::normalize_entry( $item );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
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
