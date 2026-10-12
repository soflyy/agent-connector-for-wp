<?php
/**
 * Host-managed updates for installed ability packs.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps installed "ability pack" companion plugins updatable straight from GitHub,
 * without each pack having to bundle its own update checker.
 *
 * It detects installed packs by their marker header, reads the latest version +
 * download URL for each from the same directory the Ability Packs screen uses
 * (PluginDirectory — one cached request, manifest-backed, scales to many packs),
 * and injects the results into WordPress's `update_plugins` site transient so the
 * normal Plugins-screen update flow (and auto-updates) handle the rest.
 *
 * Nothing here touches the plugin-update-checker library — that stays dedicated to
 * the main agent-connector-for-wp plugin.
 */
final class PackUpdater {

	/** Plugin header whose presence marks a file as an ability pack. */
	private const MARKER_HEADER = 'Agent Connector';

	/** Plugin header naming the host plugin a pack extends. */
	private const TARGET_HEADER = 'Agent Connector Target';

	/**
	 * Option remembering the WordPress `last_checked` timestamp we last refreshed
	 * our manifests against, so we refetch exactly once per real WP update check.
	 */
	private const SYNC_OPTION = 'agent_connector_for_wp_manifest_synced_checked';

	public function register(): void {
		add_filter( 'extra_plugin_headers', array( $this, 'declare_headers' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_updates' ) );
	}

	/**
	 * Surface the ability-pack marker headers through get_plugins()/get_plugin_data().
	 *
	 * Core only parses a fixed header set unless extended via this filter (applied
	 * by get_file_data() as `extra_plugin_headers`). Note get_file_data() does
	 * `array_combine( $extra, $extra )`, so each entry's VALUE is the header name
	 * AND the key the parsed value is returned under — i.e. the data comes back
	 * keyed by the literal header name (`$data['Agent Connector']`), regardless of
	 * the keys used here.
	 *
	 * @param array<int|string,string> $headers Extra plugin headers to parse.
	 *
	 * @return array<int|string,string>
	 */
	public function declare_headers( $headers ) {
		$headers   = (array) $headers;
		$headers[] = self::MARKER_HEADER; // "Agent Connector" -> value "Ability Pack"
		$headers[] = self::TARGET_HEADER; // "Agent Connector Target" -> host plugin file

		return $headers;
	}

	/**
	 * Inject available pack updates into the update_plugins transient.
	 *
	 * This runs inside WordPress's own update-check flow (the
	 * pre_set_site_transient_update_plugins filter). It must NEVER break that
	 * flow: any error is swallowed and the transient is handed back untouched, so
	 * a bug or a bad network response here can only ever result in "no update
	 * offered", never a broken Plugins screen or a failed core update check.
	 *
	 * @param mixed $transient The update_plugins site transient (object) or empty.
	 *
	 * @return mixed
	 */
	public function inject_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		try {
			return $this->do_inject( $transient );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Agent Connector pack updater skipped: ' . $e->getMessage() );
			}
			return $transient; // Hand WordPress back exactly what it gave us.
		}
	}

	/**
	 * The actual injection, wrapped by inject_updates() so nothing it does can
	 * escape into WordPress's update flow.
	 *
	 * @param object $transient The update_plugins site transient.
	 *
	 * @return object
	 */
	private function do_inject( $transient ) {
		$packs = $this->installed_packs();
		if ( empty( $packs ) ) {
			return $transient;
		}

		// Only hit the network when WordPress itself just ran a real update check
		// (scheduled cron or a manual "Check Again"); otherwise serve cache.
		$by_slug = $this->entries_by_slug( $this->should_refresh( $transient ) );
		if ( empty( $by_slug ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		foreach ( $packs as $file => $installed_version ) {
			$slug = $this->folder_slug( $file );
			if ( ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}

			$entry   = $by_slug[ $slug ];
			$latest  = (string) ( $entry['version'] ?? '' );
			$package = (string) ( $entry['download_url'] ?? '' );
			if ( '' === $latest ) {
				continue;
			}

			$info = (object) array(
				'slug'        => $slug,
				'plugin'      => $file,
				'new_version' => $latest,
				'url'         => (string) ( $entry['source_url'] ?? '' ),
				'package'     => $package,
				'icons'       => array(),
				'banners'     => array(),
			);

			if ( '' !== $package && version_compare( $installed_version, $latest, '<' ) ) {
				$transient->response[ $file ] = $info;
				unset( $transient->no_update[ $file ] );
			} else {
				// Up to date — list under no_update so core treats it as managed.
				$info->new_version             = $installed_version;
				$transient->no_update[ $file ] = $info;
			}
		}

		return $transient;
	}

	/**
	 * Installed ability packs as plugin_file => installed version.
	 *
	 * @return array<string,string>
	 */
	private function installed_packs(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$marker = isset( $data[ self::MARKER_HEADER ] ) ? trim( (string) $data[ self::MARKER_HEADER ] ) : '';
			if ( '' === $marker ) {
				continue;
			}
			$out[ $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '0';
		}

		return $out;
	}

	/**
	 * Update sources keyed by folder slug (cache-aware, manifest-backed).
	 *
	 * Combines two manifests, both consumed the same way by inject_updates():
	 *   - the ability-pack manifest (many entries, keyed by ability_pack_slug), and
	 *   - the Universal Abilities manifest (one entry). Universal Abilities carries
	 *     the same "Agent Connector" marker header as a pack, so installed_packs()
	 *     already detects it; adding its manifest entry here is all that's needed
	 *     for it to update through the very same path.
	 *
	 * @param bool $force When true, refetch the manifests from the network
	 *                    (bypassing the cache). Otherwise serve cache.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function entries_by_slug( bool $force = false ): array {
		$result  = PluginDirectory::get( $force );
		$by_slug = array();
		foreach ( (array) $result['entries'] as $entry ) {
			$slug = isset( $entry['ability_pack_slug'] ) ? (string) $entry['ability_pack_slug'] : '';
			if ( '' !== $slug ) {
				$by_slug[ $slug ] = $entry;
			}
		}

		$uap = PluginDirectory::universal_abilities_entry( $force );
		if ( null !== $uap ) {
			$by_slug[ PluginDirectory::UNIVERSAL_ABILITIES_SLUG ] = $uap;
		}

		return $by_slug;
	}

	/**
	 * Whether to refetch the manifests from the network on this pass.
	 *
	 * True only when BOTH: (1) we're in a context where blocking I/O is
	 * acceptable — admin, cron, or WP-CLI, never a front-end request — and
	 * (2) WordPress's own `last_checked` timestamp has advanced since our last
	 * refresh, i.e. WP just performed a real update check (scheduled or the
	 * manual "Check Again"). This ties our manifest freshness to WP's own cadence
	 * — one fetch per WP check, none in between — instead of an independent TTL.
	 *
	 * @param object $transient The update_plugins site transient.
	 */
	private function should_refresh( $transient ): bool {
		$ok_context = is_admin()
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $ok_context ) {
			return false;
		}

		$wp_checked = isset( $transient->last_checked ) ? (int) $transient->last_checked : 0;
		if ( $wp_checked <= 0 ) {
			return false;
		}

		if ( $wp_checked > (int) get_option( self::SYNC_OPTION, 0 ) ) {
			update_option( self::SYNC_OPTION, $wp_checked, false );
			return true;
		}

		return false;
	}

	/**
	 * "unofficial-abilities-for-x/unofficial-abilities-for-x.php" → folder slug.
	 */
	private function folder_slug( string $file ): string {
		if ( false !== strpos( $file, '/' ) ) {
			return dirname( $file );
		}

		return preg_replace( '/\.php$/', '', $file ) ?? $file;
	}
}
