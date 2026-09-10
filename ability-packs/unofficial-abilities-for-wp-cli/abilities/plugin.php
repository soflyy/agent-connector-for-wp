<?php
/**
 * `wp plugin` — list, inspect, (de)activate, install, update, and delete plugins.
 *
 * Reimplemented in native PHP using WordPress core (get_plugins(),
 * activate_plugin(), Plugin_Upgrader, plugins_api, …) so no `wp` binary or
 * shell is required. Install/update/network operations need outbound network
 * access to wordpress.org.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_resolve_plugin' ) ) {
	/**
	 * Resolve a user-supplied plugin identifier to its plugin file (the key used
	 * in get_plugins(), e.g. "akismet/akismet.php"). Accepts either the full
	 * plugin file or the folder/single-file slug ("akismet").
	 *
	 * @param string $slug_or_file Plugin file or folder slug.
	 * @return string|null The plugin file relative to the plugins dir, or null if not found.
	 */
	function wpcli_pack_resolve_plugin( string $slug_or_file ) {
		$slug_or_file = trim( $slug_or_file );
		if ( '' === $slug_or_file ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		// Exact match on the plugin file key.
		if ( isset( $all[ $slug_or_file ] ) ) {
			return $slug_or_file;
		}
		foreach ( array_keys( $all ) as $file ) {
			// Folder-based plugin: "<slug>/anything.php".
			if ( 0 === strpos( $file, $slug_or_file . '/' ) ) {
				return $file;
			}
			// Single-file plugin: "<slug>.php".
			if ( $file === $slug_or_file . '.php' ) {
				return $file;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'wpcli_pack_plugin_status' ) ) {
	/**
	 * Compute the WP-CLI-style status string for a plugin file.
	 *
	 * @param string $file Plugin file (key from get_plugins()).
	 * @return string active|inactive|active-network|must-use|dropin
	 */
	function wpcli_pack_plugin_status( string $file ): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( $file ) ) {
			return 'active-network';
		}
		if ( is_plugin_active( $file ) ) {
			return 'active';
		}
		return 'inactive';
	}
}

if ( ! function_exists( 'wpcli_pack_plugin_inputs' ) ) {
	/**
	 * Collect plugin identifiers from `plugin` (string) and/or `plugins` (array).
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<int,string>
	 */
	function wpcli_pack_plugin_inputs( array $input ): array {
		$list = array();
		if ( isset( $input['plugin'] ) && '' !== $input['plugin'] ) {
			$list[] = (string) $input['plugin'];
		}
		if ( ! empty( $input['plugins'] ) && is_array( $input['plugins'] ) ) {
			foreach ( $input['plugins'] as $p ) {
				$list[] = (string) $p;
			}
		}
		return array_values( array_unique( $list ) );
	}
}

if ( ! function_exists( 'wpcli_pack_plugin_update_available' ) ) {
	/**
	 * Read the update transient and return a map of plugin file => new version.
	 *
	 * @return array<string,string>
	 */
	function wpcli_pack_plugin_update_available(): array {
		$out     = array();
		$updates = get_site_transient( 'update_plugins' );
		if ( $updates && ! empty( $updates->response ) && is_array( $updates->response ) ) {
			foreach ( $updates->response as $file => $data ) {
				$out[ $file ] = isset( $data->new_version ) ? (string) $data->new_version : '';
			}
		}
		return $out;
	}
}

/**
 * Return a quiet upgrader skin, defining the class lazily. Called only from
 * inside execute callbacks AFTER class-wp-upgrader.php is loaded — never at
 * plugin-load time, since WP_Upgrader_Skin is an admin-only class that is not
 * available on a normal front-end request.
 */
function wpcli_pack_plugin_skin() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! class_exists( 'Wpcli_Pack_Quiet_Upgrader_Skin' ) ) {
	/**
	 * Minimal silent upgrader skin: swallows all feedback/output so the upgrader
	 * can run inside an ability without emitting HTML. Errors are still captured
	 * by the upgrader result and surfaced via wpcli_pack_err.
	 */
	class Wpcli_Pack_Quiet_Upgrader_Skin extends WP_Upgrader_Skin {
		/** @var array<int,string> */
		public $messages = array();

		public function header() {}
		public function footer() {}
		public function before() {}
		public function after() {}

		/**
		 * @param string|WP_Error $errors Error(s) to record.
		 */
		public function error( $errors ) {
			if ( is_wp_error( $errors ) ) {
				$this->messages[] = $errors->get_error_message();
			} elseif ( is_string( $errors ) && '' !== $errors ) {
				$this->messages[] = $errors;
			}
		}

		/**
		 * @param string $feedback Feedback string (or message key).
		 * @param mixed  ...$args  Sprintf args.
		 */
		public function feedback( $feedback, ...$args ) {}

		public function set_upgrader( &$upgrader ) {} // phpcs:ignore
		public function set_result( $result ) {} // phpcs:ignore
	}
	}
	return new Wpcli_Pack_Quiet_Upgrader_Skin();
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-list',
		'label'            => 'List Plugins',
		'description'      => 'List installed plugins (like `wp plugin list`). Optionally filter by `status` (active/inactive/must-use/dropin/all), a name/title `search`, and limit the returned `fields`. `update_available` is derived from the cached update_plugins transient.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'must-use', 'dropin', 'all' ), 'description' => 'Filter by status. Default "all".' ),
				'search' => array( 'type' => 'string', 'description' => 'Case-insensitive substring matched against slug and title.' ),
				'fields' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of fields to return per plugin (name,file,title,status,version,update_available,author,description).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'   => array( 'type' => 'integer' ),
				'plugins' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$status_filter = isset( $input['status'] ) ? (string) $input['status'] : 'all';
			$search        = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
			$fields        = ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) ? array_map( 'strval', $input['fields'] ) : array();
			$updates       = wpcli_pack_plugin_update_available();

			$rows = array();

			// Regular + must-use + dropins.
			$regular = get_plugins();
			$mustuse = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
			$dropins = function_exists( 'get_dropins' ) ? get_dropins() : array();

			$add_row = static function ( $file, $data, $kind, $updates ) {
				$slug   = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
				$status = 'must-use' === $kind ? 'must-use' : ( 'dropin' === $kind ? 'dropin' : wpcli_pack_plugin_status( $file ) );
				return array(
					'name'             => $slug,
					'file'             => $file,
					'title'            => isset( $data['Name'] ) ? (string) $data['Name'] : $slug,
					'status'           => $status,
					'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
					'update_available' => isset( $updates[ $file ] ),
					'author'           => isset( $data['Author'] ) ? wp_strip_all_tags( (string) $data['Author'] ) : '',
					'description'      => isset( $data['Description'] ) ? wp_strip_all_tags( (string) $data['Description'] ) : '',
				);
			};

			foreach ( $regular as $file => $data ) {
				$rows[] = $add_row( $file, $data, 'regular', $updates );
			}
			foreach ( $mustuse as $file => $data ) {
				$rows[] = $add_row( $file, $data, 'must-use', $updates );
			}
			foreach ( $dropins as $file => $data ) {
				$rows[] = $add_row( $file, $data, 'dropin', $updates );
			}

			$out = array();
			foreach ( $rows as $row ) {
				if ( 'all' !== $status_filter && $row['status'] !== $status_filter ) {
					// "active" filter should also exclude active-network unless asked; keep simple exact match.
					if ( ! ( 'active' === $status_filter && 'active-network' === $row['status'] ) ) {
						continue;
					}
				}
				if ( '' !== $search ) {
					$haystack = strtolower( $row['name'] . ' ' . $row['title'] );
					if ( false === strpos( $haystack, $search ) ) {
						continue;
					}
				}
				$out[] = empty( $fields ) ? $row : wpcli_pack_pick( $row, $fields );
			}

			return array( 'count' => count( $out ), 'plugins' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-get',
		'label'            => 'Get Plugin',
		'description'      => 'Get the full header/metadata for one installed plugin (like `wp plugin get <plugin>`). Accepts the plugin file ("akismet/akismet.php") or the folder slug ("akismet").',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string' ),
				'file'        => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'version'     => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'author'      => array( 'type' => 'string' ),
				'author_uri'  => array( 'type' => 'string' ),
				'plugin_uri'  => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'requires_wp' => array( 'type' => 'string' ),
				'requires_php' => array( 'type' => 'string' ),
				'network'     => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
			if ( null === $file ) {
				return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
			}
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
			return array(
				'name'         => $slug,
				'file'         => $file,
				'title'        => (string) $data['Name'],
				'version'      => (string) $data['Version'],
				'status'       => wpcli_pack_plugin_status( $file ),
				'author'       => wp_strip_all_tags( (string) $data['Author'] ),
				'author_uri'   => (string) $data['AuthorURI'],
				'plugin_uri'   => (string) $data['PluginURI'],
				'description'  => wp_strip_all_tags( (string) $data['Description'] ),
				'requires_wp'  => isset( $data['RequiresWP'] ) ? (string) $data['RequiresWP'] : '',
				'requires_php' => isset( $data['RequiresPHP'] ) ? (string) $data['RequiresPHP'] : '',
				'network'      => ! empty( $data['Network'] ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-is-active',
		'label'            => 'Is Plugin Active',
		'description'      => 'Check whether a plugin is active (like `wp plugin is-active <plugin>`). Accepts plugin file or folder slug.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'active' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
			if ( null === $file ) {
				return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
			}
			return array( 'active' => ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-is-installed',
		'label'            => 'Is Plugin Installed',
		'description'      => 'Check whether a plugin is installed (like `wp plugin is-installed <plugin>`). Accepts plugin file or folder slug.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'installed' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			return array( 'installed' => null !== wpcli_pack_resolve_plugin( (string) $input['plugin'] ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-status',
		'label'            => 'Plugin Status',
		'description'      => 'Report status for one plugin, or a summary of all plugins when `plugin` is omitted (like `wp plugin status [<plugin>]`).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug. Omit to summarize all plugins.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'plugin'  => array( 'type' => 'object' ),
				'summary' => array( 'type' => 'object' ),
				'plugins' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$updates = wpcli_pack_plugin_update_available();

			if ( ! empty( $input['plugin'] ) ) {
				$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
				if ( null === $file ) {
					return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
				}
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
				$slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
				return array(
					'plugin' => array(
						'name'             => $slug,
						'file'             => $file,
						'title'            => (string) $data['Name'],
						'status'           => wpcli_pack_plugin_status( $file ),
						'version'          => (string) $data['Version'],
						'update_available' => isset( $updates[ $file ] ),
						'new_version'      => isset( $updates[ $file ] ) ? $updates[ $file ] : '',
					),
				);
			}

			$all     = get_plugins();
			$active  = 0;
			$plugins = array();
			foreach ( $all as $file => $data ) {
				$status = wpcli_pack_plugin_status( $file );
				if ( 'active' === $status || 'active-network' === $status ) {
					$active++;
				}
				$plugins[] = array(
					'file'             => $file,
					'status'           => $status,
					'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
					'update_available' => isset( $updates[ $file ] ),
				);
			}
			return array(
				'summary' => array(
					'total'            => count( $all ),
					'active'           => $active,
					'inactive'         => count( $all ) - $active,
					'updates_available' => count( array_intersect_key( $updates, $all ) ),
				),
				'plugins' => $plugins,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-path',
		'label'            => 'Plugin Path',
		'description'      => 'Return the absolute filesystem path to a plugin file, or to the plugins directory when `plugin` is omitted (like `wp plugin path [<plugin>]`).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug. Omit for the plugins directory.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'path' => array( 'type' => 'string' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( empty( $input['plugin'] ) ) {
				return array( 'path' => WP_PLUGIN_DIR );
			}
			$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
			if ( null === $file ) {
				return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
			}
			return array( 'path' => WP_PLUGIN_DIR . '/' . $file );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-activate',
		'label'            => 'Activate Plugin',
		'description'      => 'Activate one or more plugins (like `wp plugin activate <plugin>...`). Pass `plugin` (single) or `plugins` (array). Set `network` to activate network-wide on multisite. Returns per-plugin activation results and any errors.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
				'network' => array( 'type' => 'boolean', 'description' => 'Activate network-wide (multisite). Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'activated' => array( 'type' => 'array' ),
				'errors'    => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$targets = wpcli_pack_plugin_inputs( $input );
			if ( empty( $targets ) ) {
				return wpcli_pack_err( 'no_plugin', 'Provide "plugin" or "plugins".' );
			}
			$network   = ! empty( $input['network'] );
			$activated = array();
			$errors    = array();
			foreach ( $targets as $target ) {
				$file = wpcli_pack_resolve_plugin( $target );
				if ( null === $file ) {
					$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
					continue;
				}
				$result = activate_plugin( $file, '', $network );
				if ( is_wp_error( $result ) ) {
					$errors[] = array( 'plugin' => $file, 'message' => $result->get_error_message() );
					continue;
				}
				$activated[] = $file;
			}
			return array( 'activated' => $activated, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-deactivate',
		'label'            => 'Deactivate Plugin',
		'description'      => 'Deactivate one or more plugins (like `wp plugin deactivate <plugin>...`). Pass `plugin` or `plugins`. `network` deactivates network-wide; `silent` skips deactivation hooks.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
				'network' => array( 'type' => 'boolean', 'description' => 'Deactivate network-wide (multisite). Default false.' ),
				'silent'  => array( 'type' => 'boolean', 'description' => 'Skip deactivation hooks. Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deactivated' => array( 'type' => 'array' ),
				'errors'      => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$targets = wpcli_pack_plugin_inputs( $input );
			if ( empty( $targets ) ) {
				return wpcli_pack_err( 'no_plugin', 'Provide "plugin" or "plugins".' );
			}
			$network     = ! empty( $input['network'] );
			$silent      = ! empty( $input['silent'] );
			$deactivated = array();
			$errors      = array();
			$files       = array();
			foreach ( $targets as $target ) {
				$file = wpcli_pack_resolve_plugin( $target );
				if ( null === $file ) {
					$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
					continue;
				}
				$files[]       = $file;
				$deactivated[] = $file;
			}
			if ( ! empty( $files ) ) {
				deactivate_plugins( $files, $silent, $network ? true : null );
			}
			return array( 'deactivated' => $deactivated, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-toggle',
		'label'            => 'Toggle Plugin',
		'description'      => 'Flip a plugin between active and inactive (like `wp plugin toggle <plugin>`). Accepts plugin file or folder slug.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'plugin'     => array( 'type' => 'string' ),
				'now_active' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
			if ( null === $file ) {
				return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
			}
			if ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
				deactivate_plugins( array( $file ) );
				return array( 'plugin' => $file, 'now_active' => false );
			}
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'activate_failed', $result->get_error_message() );
			}
			return array( 'plugin' => $file, 'now_active' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-delete',
		'label'            => 'Delete Plugin',
		'description'      => 'Delete one or more installed plugins from disk (like `wp plugin delete <plugin>...`). Pass `plugin` or `plugins`. Active plugins are deactivated as part of deletion by WordPress.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'array' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$targets = wpcli_pack_plugin_inputs( $input );
			if ( empty( $targets ) ) {
				return wpcli_pack_err( 'no_plugin', 'Provide "plugin" or "plugins".' );
			}
			$files  = array();
			$errors = array();
			foreach ( $targets as $target ) {
				$file = wpcli_pack_resolve_plugin( $target );
				if ( null === $file ) {
					$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
					continue;
				}
				$files[] = $file;
			}
			$deleted = array();
			if ( ! empty( $files ) ) {
				$result = delete_plugins( $files );
				if ( is_wp_error( $result ) ) {
					return wpcli_pack_err( 'delete_failed', $result->get_error_message() );
				}
				if ( false === $result ) {
					$errors[] = array( 'plugin' => implode( ',', $files ), 'message' => 'Filesystem credentials required or deletion failed.' );
				} else {
					$deleted = $files;
				}
			}
			return array( 'deleted' => $deleted, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-install',
		'label'            => 'Install Plugin',
		'description'      => 'Install plugins from the wordpress.org directory by slug (like `wp plugin install <slug>...`). Pass `slug` (single) or `plugins` (array of slugs). `activate` activates after install; `force` reinstalls/overwrites. Requires outbound network access to wordpress.org.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'     => array( 'type' => 'string', 'description' => 'A single wordpress.org plugin slug, e.g. "akismet".' ),
				'plugins'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple wordpress.org slugs.' ),
				'activate' => array( 'type' => 'boolean', 'description' => 'Activate each plugin after installing. Default false.' ),
				'force'    => array( 'type' => 'boolean', 'description' => 'Reinstall even if already present (overwrite). Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'installed' => array( 'type' => 'array' ),
				'errors'    => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$slugs = array();
			if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
				$slugs[] = (string) $input['slug'];
			}
			if ( ! empty( $input['plugins'] ) && is_array( $input['plugins'] ) ) {
				foreach ( $input['plugins'] as $s ) {
					$slugs[] = (string) $s;
				}
			}
			$slugs = array_values( array_unique( $slugs ) );
			if ( empty( $slugs ) ) {
				return wpcli_pack_err( 'no_slug', 'Provide "slug" or "plugins".' );
			}

			$activate  = ! empty( $input['activate'] );
			$force     = ! empty( $input['force'] );
			$installed = array();
			$errors    = array();

			foreach ( $slugs as $slug ) {
				$api = plugins_api(
					'plugin_information',
					array(
						'slug'   => $slug,
						'fields' => array( 'sections' => false ),
					)
				);
				if ( is_wp_error( $api ) ) {
					$errors[] = array( 'plugin' => $slug, 'message' => $api->get_error_message() );
					continue;
				}

				$skin     = wpcli_pack_plugin_skin();
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $api->download_link, array( 'overwrite_package' => $force ) );

				if ( is_wp_error( $result ) ) {
					$errors[] = array( 'plugin' => $slug, 'message' => $result->get_error_message() );
					continue;
				}
				if ( false === $result || null === $result ) {
					$msg      = ! empty( $skin->messages ) ? implode( '; ', $skin->messages ) : 'Installation failed (check filesystem permissions / network).';
					$errors[] = array( 'plugin' => $slug, 'message' => $msg );
					continue;
				}

				$file = $upgrader->plugin_info();
				if ( ! $file ) {
					$file = wpcli_pack_resolve_plugin( $slug );
				}
				$entry = array( 'slug' => $slug, 'file' => $file, 'activated' => false );

				if ( $activate && $file ) {
					$act = activate_plugin( $file );
					if ( is_wp_error( $act ) ) {
						$errors[] = array( 'plugin' => $file, 'message' => 'Installed but activation failed: ' . $act->get_error_message() );
					} else {
						$entry['activated'] = true;
					}
				}
				$installed[] = $entry;
			}

			return array( 'installed' => $installed, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-update',
		'label'            => 'Update Plugin',
		'description'      => 'Update installed plugins to their latest versions (like `wp plugin update <plugin>... | --all`). Pass `plugin`/`plugins`, or `all` to update everything with a pending update. Requires outbound network access to wordpress.org.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
				'all'     => array( 'type' => 'boolean', 'description' => 'Update every plugin that has a pending update.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'updated' => array( 'type' => 'array' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/update.php';

			// Refresh the update cache so we operate on current data.
			if ( function_exists( 'wp_update_plugins' ) ) {
				wp_update_plugins();
			}
			$available = wpcli_pack_plugin_update_available();

			$files  = array();
			$errors = array();

			if ( ! empty( $input['all'] ) ) {
				$files = array_keys( $available );
			} else {
				$targets = wpcli_pack_plugin_inputs( $input );
				if ( empty( $targets ) ) {
					return wpcli_pack_err( 'no_plugin', 'Provide "plugin", "plugins", or set "all".' );
				}
				foreach ( $targets as $target ) {
					$file = wpcli_pack_resolve_plugin( $target );
					if ( null === $file ) {
						$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
						continue;
					}
					if ( ! isset( $available[ $file ] ) ) {
						$errors[] = array( 'plugin' => $file, 'message' => 'No update available.' );
						continue;
					}
					$files[] = $file;
				}
			}

			$files = array_values( array_unique( $files ) );
			if ( empty( $files ) ) {
				return array( 'updated' => array(), 'errors' => $errors );
			}

			$skin     = wpcli_pack_plugin_skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$results  = $upgrader->bulk_upgrade( $files );

			$updated = array();
			if ( is_array( $results ) ) {
				foreach ( $files as $file ) {
					$res = isset( $results[ $file ] ) ? $results[ $file ] : null;
					if ( is_wp_error( $res ) ) {
						$errors[] = array( 'plugin' => $file, 'message' => $res->get_error_message() );
					} elseif ( ! $res ) {
						$msg      = ! empty( $skin->messages ) ? implode( '; ', $skin->messages ) : 'Update failed.';
						$errors[] = array( 'plugin' => $file, 'message' => $msg );
					} else {
						$updated[] = array( 'file' => $file, 'new_version' => isset( $available[ $file ] ) ? $available[ $file ] : '' );
					}
				}
			} else {
				$msg = ! empty( $skin->messages ) ? implode( '; ', $skin->messages ) : 'Bulk update failed.';
				return wpcli_pack_err( 'update_failed', $msg );
			}

			return array( 'updated' => $updated, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-auto-updates-status',
		'label'            => 'Plugin Auto-Updates Status',
		'description'      => 'Report whether automatic updates are enabled for a plugin (like `wp plugin auto-updates status <plugin>`). Reads the "auto_update_plugins" option.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'enabled' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$file = wpcli_pack_resolve_plugin( (string) $input['plugin'] );
			if ( null === $file ) {
				return wpcli_pack_err( 'plugin_not_found', sprintf( 'Plugin "%s" is not installed.', (string) $input['plugin'] ), 404 );
			}
			$auto = (array) get_option( 'auto_update_plugins', array() );
			return array( 'enabled' => in_array( $file, $auto, true ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-auto-updates-enable',
		'label'            => 'Enable Plugin Auto-Updates',
		'description'      => 'Enable automatic updates for one or more plugins (like `wp plugin auto-updates enable <plugin>...`). Updates the "auto_update_plugins" option. Pass `plugin` or `plugins`.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$targets = wpcli_pack_plugin_inputs( $input );
			if ( empty( $targets ) ) {
				return wpcli_pack_err( 'no_plugin', 'Provide "plugin" or "plugins".' );
			}
			$auto   = (array) get_option( 'auto_update_plugins', array() );
			$errors = array();
			foreach ( $targets as $target ) {
				$file = wpcli_pack_resolve_plugin( $target );
				if ( null === $file ) {
					$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
					continue;
				}
				if ( ! in_array( $file, $auto, true ) ) {
					$auto[] = $file;
				}
			}
			update_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
			return array( 'success' => true, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/plugin-auto-updates-disable',
		'label'            => 'Disable Plugin Auto-Updates',
		'description'      => 'Disable automatic updates for one or more plugins (like `wp plugin auto-updates disable <plugin>...`). Updates the "auto_update_plugins" option. Pass `plugin` or `plugins`.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin'  => array( 'type' => 'string', 'description' => 'Plugin file or folder slug.' ),
				'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple plugin files or slugs.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$targets = wpcli_pack_plugin_inputs( $input );
			if ( empty( $targets ) ) {
				return wpcli_pack_err( 'no_plugin', 'Provide "plugin" or "plugins".' );
			}
			$auto   = (array) get_option( 'auto_update_plugins', array() );
			$errors = array();
			$remove = array();
			foreach ( $targets as $target ) {
				$file = wpcli_pack_resolve_plugin( $target );
				if ( null === $file ) {
					$errors[] = array( 'plugin' => $target, 'message' => 'Plugin is not installed.' );
					continue;
				}
				$remove[] = $file;
			}
			$auto = array_values( array_diff( $auto, $remove ) );
			update_option( 'auto_update_plugins', $auto );
			return array( 'success' => true, 'errors' => $errors );
		},
	)
);
