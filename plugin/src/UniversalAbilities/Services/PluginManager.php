<?php
/**
 * Plugin management through WordPress's own installer: list, search the
 * wordpress.org directory, install, activate, deactivate, update, delete and
 * auto-updates.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Services;

use AgentConnectorForWp\Services\CompanionPlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and updates come only from the wordpress.org directory, by slug:
 * there is no zip or URL input. Every action checks the matching WordPress
 * capability, so DISALLOW_FILE_MODS and multisite rules apply as they do in
 * wp-admin.
 *
 * Agent Connector and MCP Adapter can't be deactivated, updated or deleted
 * this way: the agent would cut its own connection, or replace the code
 * serving the request. Those are left to wp-admin.
 */
final class PluginManager {

	/**
	 * wordpress.org plugin slugs: lowercase letters, digits and dashes.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

	/**
	 * Installed plugins with their status, available update and auto-update
	 * setting.
	 *
	 * @param string $status 'all', 'active' or 'inactive'.
	 * @return array{plugins:array<int,array<string,mixed>>}
	 */
	public function list( string $status = 'all' ): array {
		self::load_admin_api();

		$updates      = self::available_updates();
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$plugins      = array();

		foreach ( get_plugins() as $file => $data ) {
			$active = is_plugin_active( $file );
			if ( ( 'active' === $status && ! $active ) || ( 'inactive' === $status && $active ) ) {
				continue;
			}
			$plugins[] = array(
				'file'           => $file,
				'slug'           => self::slug_from_file( $file ),
				'name'           => (string) $data['Name'],
				'version'        => (string) $data['Version'],
				'active'         => $active,
				'network_active' => is_multisite() && is_plugin_active_for_network( $file ),
				'update_version' => isset( $updates[ $file ]->new_version ) ? (string) $updates[ $file ]->new_version : null,
				'auto_update'    => in_array( $file, $auto_updates, true ),
				'protected'      => self::is_protected( $file ),
			);
		}

		return array( 'plugins' => $plugins );
	}

	/**
	 * Search the wordpress.org plugin directory.
	 *
	 * @return array{total:int,plugins:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function search( string $query, int $page = 1, int $per_page = 10 ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_search_empty', 'Provide something to search for.' );
		}
		self::load_admin_api();

		$result = plugins_api(
			'query_plugins',
			array(
				'search'   => $query,
				'page'     => max( 1, $page ),
				'per_page' => min( 30, max( 1, $per_page ) ),
				'fields'   => array(
					'short_description' => true,
					'description'       => false,
					'sections'          => false,
					'icons'             => false,
					'banners'           => false,
					'tags'              => false,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plugins = array();
		foreach ( (array) $result->plugins as $item ) {
			$item      = (array) $item;
			$slug      = (string) ( $item['slug'] ?? '' );
			$plugins[] = array(
				'slug'              => $slug,
				'name'              => wp_strip_all_tags( (string) ( $item['name'] ?? '' ) ),
				'version'           => (string) ( $item['version'] ?? '' ),
				'author'            => wp_strip_all_tags( (string) ( $item['author'] ?? '' ) ),
				'short_description' => wp_strip_all_tags( (string) ( $item['short_description'] ?? '' ) ),
				'rating'            => (int) ( $item['rating'] ?? 0 ),
				'active_installs'   => (int) ( $item['active_installs'] ?? 0 ),
				'last_updated'      => (string) ( $item['last_updated'] ?? '' ),
				'requires_wp'       => (string) ( $item['requires'] ?? '' ),
				'requires_php'      => (string) ( $item['requires_php'] ?? '' ),
				'tested_up_to'      => (string) ( $item['tested'] ?? '' ),
				'installed'         => null !== CompanionPlugins::installed_file_for_slug( $slug ),
			);
		}

		return array(
			'total'   => (int) ( $result->info['results'] ?? count( $plugins ) ),
			'plugins' => $plugins,
		);
	}

	/**
	 * Install a plugin from wordpress.org by slug, optionally a specific
	 * version, and optionally activate it.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function install( string $slug, string $version = '', bool $activate = false ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return self::forbidden( 'install plugins' );
		}
		$slug = strtolower( trim( $slug ) );
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_bad_slug', sprintf( 'Not a wordpress.org plugin slug: "%s". Use plugins-search to find the slug.', $slug ) );
		}
		$existing = CompanionPlugins::installed_file_for_slug( $slug );
		if ( null !== $existing ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_installed', sprintf( 'Plugin "%1$s" is already installed (%2$s). Use plugin-update to update it.', $slug, $existing ) );
		}

		self::load_admin_api();
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections' => false,
					'versions' => '' !== $version,
				),
			)
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$package = (string) $info->download_link;
		if ( '' !== $version && $version !== $info->version ) {
			$versions = (array) ( $info->versions ?? array() );
			if ( ! isset( $versions[ $version ] ) ) {
				return new \WP_Error( 'agent_connector_for_wp_plugin_bad_version', sprintf( 'Version %1$s of "%2$s" isn\'t available on wordpress.org.', $version, $slug ) );
			}
			$package = (string) $versions[ $version ];
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = self::run_upgrader( $upgrader, static fn() => $upgrader->install( $package ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$file = (string) $upgrader->plugin_info();
		if ( '' === $file ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_install_failed', sprintf( 'Installed "%s", but couldn\'t find its main file.', $slug ) );
		}

		$response = array(
			'file'      => $file,
			'slug'      => $slug,
			'version'   => '' !== $version ? $version : (string) $info->version,
			'installed' => true,
			'active'    => false,
		);

		if ( $activate ) {
			$activated = $this->activate( $file );
			if ( is_wp_error( $activated ) ) {
				$response['activation_error'] = $activated->get_error_message();
			} else {
				$response['active'] = true;
			}
		}

		return $response;
	}

	/**
	 * Activate an installed plugin, running its activation hooks.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( string $plugin, bool $network = false ) {
		$file = self::resolve( $plugin );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( $network && ! is_multisite() ) {
			$network = false;
		}
		if ( ! current_user_can( $network ? 'manage_network_plugins' : 'activate_plugin', $file ) ) {
			return self::forbidden( 'activate this plugin' );
		}
		if ( $network ? is_plugin_active_for_network( $file ) : is_plugin_active( $file ) ) {
			return array(
				'file'   => $file,
				'active' => true,
				'note'   => 'Already active.',
			);
		}

		ob_start();
		$result = activate_plugin( $file, '', $network );
		ob_end_clean();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'file'           => $file,
			'active'         => true,
			'network_active' => $network,
		);
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate( string $plugin, bool $network = false ) {
		$file = self::resolve( $plugin );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$protected = self::refuse_protected( $file, 'deactivate' );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}
		if ( $network && ! is_multisite() ) {
			$network = false;
		}
		if ( ! current_user_can( $network ? 'manage_network_plugins' : 'deactivate_plugin', $file ) ) {
			return self::forbidden( 'deactivate this plugin' );
		}

		deactivate_plugins( $file, false, $network );

		return array(
			'file'   => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * Update one plugin, or every plugin with an update available, from
	 * wordpress.org (or wherever the plugin's own updater points, as in
	 * wp-admin).
	 *
	 * @param string $plugin A slug or plugin file, or "all".
	 * @return array<string,mixed>|\WP_Error
	 */
	public function update( string $plugin ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return self::forbidden( 'update plugins' );
		}
		self::load_admin_api();

		wp_update_plugins();
		$updates = self::available_updates();
		$skipped = array();

		if ( 'all' === $plugin ) {
			$files = array();
			foreach ( array_keys( $updates ) as $file ) {
				if ( self::is_protected( $file ) ) {
					$skipped[ $file ] = 'Update this one from wp-admin: updating it would replace the code serving this connection.';
					continue;
				}
				$files[] = $file;
			}
			if ( empty( $files ) ) {
				return array(
					'updated' => array(),
					'skipped' => $skipped,
					'note'    => 'No plugin updates to install.',
				);
			}
		} else {
			$file = self::resolve( $plugin );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$protected = self::refuse_protected( $file, 'update' );
			if ( is_wp_error( $protected ) ) {
				return $protected;
			}
			if ( ! isset( $updates[ $file ] ) ) {
				return array(
					'updated' => array(),
					'skipped' => array(),
					'note'    => sprintf( '%s is already up to date.', $file ),
				);
			}
			$files = array( $file );
		}

		$before = array();
		foreach ( $files as $file ) {
			$before[ $file ] = self::installed_version( $file );
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$results  = self::run_upgrader( $upgrader, static fn() => $upgrader->bulk_upgrade( $files ) );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$updated = array();
		$failed  = array();
		foreach ( $files as $file ) {
			$result = is_array( $results ) ? ( $results[ $file ] ?? null ) : null;
			if ( is_wp_error( $result ) || empty( $result ) ) {
				$failed[ $file ] = is_wp_error( $result ) ? $result->get_error_message() : 'The update failed.';
				continue;
			}
			$updated[] = array(
				'file'         => $file,
				'from_version' => $before[ $file ],
				'to_version'   => self::installed_version( $file ),
			);
		}

		return array(
			'updated' => $updated,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Delete a plugin's files, deactivating it first and running its
	 * uninstall routine, as the Plugins screen does.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function delete( string $plugin ) {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return self::forbidden( 'delete plugins' );
		}
		$file = self::resolve( $plugin );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$protected = self::refuse_protected( $file, 'delete' );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
			deactivate_plugins( $file, false, is_multisite() && is_plugin_active_for_network( $file ) );
		}

		ob_start();
		$result = delete_plugins( array( $file ) );
		ob_end_clean();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_delete_failed', sprintf( 'Couldn\'t delete %s: WordPress has no direct access to the filesystem.', $file ) );
		}

		return array(
			'file'    => $file,
			'deleted' => true,
		);
	}

	/**
	 * Turn automatic updates on or off for a plugin.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function set_auto_updates( string $plugin, bool $enabled ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return self::forbidden( 'change plugin auto-updates' );
		}
		self::load_admin_api();
		if ( ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			return new \WP_Error( 'agent_connector_for_wp_auto_updates_unavailable', 'Plugin auto-updates are turned off for this site (by a constant or filter).' );
		}
		$file = self::resolve( $plugin );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$files = (array) get_site_option( 'auto_update_plugins', array() );
		$files = $enabled ? array_merge( $files, array( $file ) ) : array_diff( $files, array( $file ) );
		update_site_option( 'auto_update_plugins', array_values( array_unique( $files ) ) );

		return array(
			'file'        => $file,
			'auto_update' => $enabled,
		);
	}

	/**
	 * Resolve a slug or "folder/file.php" to an installed plugin file.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve( string $plugin ) {
		self::load_admin_api();
		$file = CompanionPlugins::installed_file_for_slug( $plugin );
		if ( null === $file ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_not_found', sprintf( 'Plugin "%s" isn\'t installed. Use plugins-list to see installed plugins.', $plugin ) );
		}
		return $file;
	}

	/**
	 * Whether a plugin is one this connection depends on: Agent Connector itself
	 * or MCP Adapter.
	 */
	private static function is_protected( string $file ): bool {
		return in_array( $file, array( plugin_basename( AGENT_CONNECTOR_FOR_WP_FILE ), CompanionPlugins::mcp_adapter_file() ), true );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function refuse_protected( string $file, string $action ) {
		if ( ! self::is_protected( $file ) ) {
			return true;
		}
		return new \WP_Error(
			'agent_connector_for_wp_plugin_protected',
			sprintf( 'Can\'t %1$s %2$s over MCP: this connection depends on it. Ask the user to do it from the Plugins screen in wp-admin.', $action, $file ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Run an upgrader action, turning every way it reports failure into a
	 * WP_Error, and keeping its output out of the response.
	 *
	 * @param \Plugin_Upgrader $upgrader The upgrader.
	 * @param callable         $action   Runs the install or upgrade.
	 * @return mixed|\WP_Error The action's result.
	 */
	private static function run_upgrader( \Plugin_Upgrader $upgrader, callable $action ) {
		global $wp_filesystem;

		ob_start();
		$result = $action();
		ob_end_clean();

		$skin = $upgrader->skin;
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin instanceof \WP_Ajax_Upgrader_Skin && is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( $skin instanceof \WP_Ajax_Upgrader_Skin && $skin->get_errors()->has_errors() ) {
			return new \WP_Error( 'agent_connector_for_wp_plugin_upgrader_failed', $skin->get_error_messages() );
		}
		if ( null === $result || false === $result ) {
			if ( $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				return new \WP_Error( 'agent_connector_for_wp_filesystem', $wp_filesystem->errors->get_error_message() );
			}
			return new \WP_Error( 'agent_connector_for_wp_filesystem', 'Couldn\'t write to the plugins folder: WordPress has no direct access to the filesystem here.' );
		}
		return $result;
	}

	/**
	 * Available plugin updates, keyed by plugin file.
	 *
	 * @return array<string,object>
	 */
	private static function available_updates(): array {
		$transient = get_site_transient( 'update_plugins' );
		return is_object( $transient ) && isset( $transient->response ) ? (array) $transient->response : array();
	}

	private static function installed_version( string $file ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$data = get_plugin_data( $path, false, false );
		return (string) ( $data['Version'] ?? '' );
	}

	private static function slug_from_file( string $file ): string {
		return false !== strpos( $file, '/' ) ? dirname( $file ) : (string) preg_replace( '/\.php$/', '', $file );
	}

	private static function forbidden( string $what ): \WP_Error {
		return new \WP_Error(
			'agent_connector_for_wp_plugin_forbidden',
			sprintf( 'Not allowed to %s on this site (file changes may be disabled, e.g. by DISALLOW_FILE_MODS).', $what ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Load the wp-admin plugin and upgrader APIs, which REST requests don't.
	 */
	private static function load_admin_api(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . WPINC . '/update.php';
	}
}
