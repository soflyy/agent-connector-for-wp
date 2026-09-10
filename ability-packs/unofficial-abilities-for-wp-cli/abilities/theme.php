<?php
/**
 * `wp theme` and `wp theme mod` — list, inspect, activate, install, update,
 * delete themes and read/write theme mods, all in native PHP (no shell, no
 * `wp` binary).
 *
 * Themes are identified by their stylesheet/folder slug (e.g. "twentytwentyfive").
 * Install/update talk to the WordPress.org themes API and require outbound
 * network access; those abilities surface any upgrader WP_Error/false through
 * wpcli_pack_err instead of crashing.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Return a quiet theme-upgrader skin, defining the class lazily. Called only
 * from inside execute callbacks AFTER class-wp-upgrader.php is loaded — never at
 * plugin-load time, since WP_Upgrader_Skin is an admin-only class.
 */
function wpcli_pack_theme_skin() {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! class_exists( 'Acfw_WpCli_Theme_Quiet_Skin' ) ) {
	/**
	 * A silent upgrader skin: swallows all feedback/header/footer output so the
	 * Theme_Upgrader can run inside an ability without emitting HTML, while still
	 * collecting error strings for reporting.
	 */
	class Acfw_WpCli_Theme_Quiet_Skin extends WP_Upgrader_Skin {

		/** @var array<int,string> Collected error messages. */
		public $errors_collected = array();

		public function header() {}
		public function footer() {}
		public function feedback( $string, ...$args ) {}

		/**
		 * @param string|WP_Error $errors Error(s) reported by the upgrader.
		 */
		public function error( $errors ) {
			if ( is_wp_error( $errors ) ) {
				$msg = $errors->get_error_message();
				if ( '' !== $msg ) {
					$this->errors_collected[] = $msg;
				}
			} elseif ( is_string( $errors ) && '' !== $errors ) {
				$this->errors_collected[] = $errors;
			}
		}
	}
	}
	return new Acfw_WpCli_Theme_Quiet_Skin();
}

if ( ! function_exists( 'wpcli_pack_theme_row' ) ) {

	/**
	 * Shape a WP_Theme into a clean array, including activation status and whether
	 * an update is pending (read from the update_themes site transient).
	 *
	 * @param WP_Theme $theme   Theme object.
	 * @param string   $current Active stylesheet slug.
	 * @param object|null $updates update_themes transient.
	 */
	function wpcli_pack_theme_row( WP_Theme $theme, string $current, $updates ): array {
		$slug   = $theme->get_stylesheet();
		$parent = $theme->parent();
		$has_up = is_object( $updates ) && isset( $updates->response[ $slug ] );
		return array(
			'name'             => $slug,
			'title'            => $theme->get( 'Name' ),
			'status'           => ( $slug === $current ) ? 'active' : 'inactive',
			'version'          => $theme->get( 'Version' ),
			'update_available' => (bool) $has_up,
			'author'           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'parent'           => $parent ? $parent->get_stylesheet() : '',
		);
	}

	/**
	 * Resolve the list of theme slugs from an input that may carry a single
	 * `$single_key` (string) and/or a `themes` array.
	 *
	 * @param array<string,mixed> $input      Raw input.
	 * @param string              $single_key Name of the single-slug key.
	 * @return array<int,string>
	 */
	function wpcli_pack_theme_slugs( array $input, string $single_key ): array {
		$slugs = array();
		if ( isset( $input[ $single_key ] ) && '' !== (string) $input[ $single_key ] ) {
			$slugs[] = (string) $input[ $single_key ];
		}
		if ( ! empty( $input['themes'] ) && is_array( $input['themes'] ) ) {
			foreach ( $input['themes'] as $t ) {
				$slugs[] = (string) $t;
			}
		}
		return array_values( array_unique( array_filter( $slugs, 'strlen' ) ) );
	}
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-list',
		'label'            => 'List Themes',
		'description'      => 'List installed themes (like `wp theme list`). Optionally filter by `status` (active/inactive/all), a `search` string matched against slug/name, and limit returned `fields`. `update_available` is read from the update_themes site transient (run a check first for fresh data).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array( 'type' => 'string', 'enum' => array( 'active', 'inactive', 'all' ), 'description' => 'Filter by status. Default all.' ),
				'search' => array( 'type' => 'string', 'description' => 'Case-insensitive substring matched against the theme slug and title.' ),
				'fields' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of columns to return per theme (e.g. ["name","status","version"]). Default all.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'  => array( 'type' => 'integer' ),
				'themes' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$current = (string) get_stylesheet();
			$updates = get_site_transient( 'update_themes' );
			$status  = isset( $input['status'] ) ? (string) $input['status'] : 'all';
			$search  = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
			$fields  = ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) ? array_map( 'strval', $input['fields'] ) : array();
			$out     = array();
			foreach ( wp_get_themes() as $slug => $theme ) {
				$row = wpcli_pack_theme_row( $theme, $current, $updates );
				if ( 'active' === $status && 'active' !== $row['status'] ) {
					continue;
				}
				if ( 'inactive' === $status && 'inactive' !== $row['status'] ) {
					continue;
				}
				if ( '' !== $search && false === strpos( strtolower( (string) $slug . ' ' . (string) $row['title'] ), $search ) ) {
					continue;
				}
				$out[] = wpcli_pack_pick( $row, $fields );
			}
			return array( 'count' => count( $out ), 'themes' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-get',
		'label'            => 'Get Theme',
		'description'      => 'Get detailed metadata for one installed theme (like `wp theme get <theme>`). The theme is identified by its stylesheet/folder slug.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet/folder slug, e.g. "twentytwentyfive".' ),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'name'         => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'version'      => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'author'       => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'template'     => array( 'type' => 'string' ),
				'stylesheet'   => array( 'type' => 'string' ),
				'parent'       => array( 'type' => 'string' ),
				'requires_wp'  => array( 'type' => 'string' ),
				'requires_php' => array( 'type' => 'string' ),
				'tags'         => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slug  = (string) $input['theme'];
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return wpcli_pack_err( 'theme_not_found', sprintf( 'Theme "%s" is not installed.', $slug ), 404 );
			}
			$parent = $theme->parent();
			$tags   = $theme->get( 'Tags' );
			return array(
				'name'         => $theme->get_stylesheet(),
				'title'        => $theme->get( 'Name' ),
				'version'      => $theme->get( 'Version' ),
				'status'       => ( $slug === (string) get_stylesheet() ) ? 'active' : 'inactive',
				'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'description'  => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'template'     => $theme->get_template(),
				'stylesheet'   => $theme->get_stylesheet(),
				'parent'       => $parent ? $parent->get_stylesheet() : '',
				'requires_wp'  => (string) $theme->get( 'RequiresWP' ),
				'requires_php' => (string) $theme->get( 'RequiresPHP' ),
				'tags'         => is_array( $tags ) ? array_values( $tags ) : array(),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-is-active',
		'label'            => 'Theme Is Active',
		'description'      => 'Check whether a theme is the active theme (like `wp theme is-active <theme>`).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet/folder slug.' ),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'active' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slug = (string) $input['theme'];
			return array( 'active' => ( $slug === (string) get_stylesheet() ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-is-installed',
		'label'            => 'Theme Is Installed',
		'description'      => 'Check whether a theme is installed (like `wp theme is-installed <theme>`).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet/folder slug.' ),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'installed' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'installed' => wp_get_theme( (string) $input['theme'] )->exists() );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-status',
		'label'            => 'Theme Status',
		'description'      => 'Summarize theme status (like `wp theme status`). With `theme`, reports that one theme; without it, returns a summary of all installed themes plus the active stylesheet and count with pending updates.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Optional theme slug. Omit for a site-wide summary.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'active'         => array( 'type' => 'string' ),
				'total'          => array( 'type' => 'integer' ),
				'update_count'   => array( 'type' => 'integer' ),
				'themes'         => array( 'type' => 'array' ),
				'theme'          => array( 'type' => 'object' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$current = (string) get_stylesheet();
			$updates = get_site_transient( 'update_themes' );
			if ( isset( $input['theme'] ) && '' !== (string) $input['theme'] ) {
				$slug  = (string) $input['theme'];
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() ) {
					return wpcli_pack_err( 'theme_not_found', sprintf( 'Theme "%s" is not installed.', $slug ), 404 );
				}
				return array( 'active' => $current, 'theme' => wpcli_pack_theme_row( $theme, $current, $updates ) );
			}
			$rows         = array();
			$update_count = 0;
			foreach ( wp_get_themes() as $theme ) {
				$row = wpcli_pack_theme_row( $theme, $current, $updates );
				if ( $row['update_available'] ) {
					++$update_count;
				}
				$rows[] = $row;
			}
			return array(
				'active'       => $current,
				'total'        => count( $rows ),
				'update_count' => $update_count,
				'themes'       => $rows,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-path',
		'label'            => 'Theme Path',
		'description'      => 'Get the absolute filesystem path to a theme directory, or to the themes root when no theme is given (like `wp theme path`).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Optional theme slug. Omit for the themes root directory.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'path' => array( 'type' => 'string' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( isset( $input['theme'] ) && '' !== (string) $input['theme'] ) {
				$slug  = (string) $input['theme'];
				$theme = wp_get_theme( $slug );
				if ( ! $theme->exists() ) {
					return wpcli_pack_err( 'theme_not_found', sprintf( 'Theme "%s" is not installed.', $slug ), 404 );
				}
				return array( 'path' => $theme->get_stylesheet_directory() );
			}
			return array( 'path' => get_theme_root() );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-activate',
		'label'            => 'Activate Theme',
		'description'      => 'Activate (switch to) an installed theme (like `wp theme activate <theme>`). Fails if the theme is not installed or has load errors.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet/folder slug to activate.' ),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'activated'  => array( 'type' => 'boolean' ),
				'stylesheet' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slug  = (string) $input['theme'];
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return wpcli_pack_err( 'theme_not_found', sprintf( 'Theme "%s" is not installed.', $slug ), 404 );
			}
			$errors = $theme->errors();
			if ( $errors ) {
				return wpcli_pack_err( 'theme_broken', sprintf( 'Theme "%s" cannot be activated: %s', $slug, $errors->get_error_message() ) );
			}
			switch_theme( $theme->get_stylesheet() );
			$now = (string) get_stylesheet();
			if ( $now !== $theme->get_stylesheet() ) {
				return wpcli_pack_err( 'activate_failed', sprintf( 'Theme "%s" did not become active.', $slug ) );
			}
			return array( 'activated' => true, 'stylesheet' => $now );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-delete',
		'label'            => 'Delete Theme',
		'description'      => 'Delete one or more installed themes from disk (like `wp theme delete <theme>...`). Provide `theme` (single slug) and/or `themes` (array). The active theme is never deleted (an error is returned for it even with `force`). Returns per-theme results.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme'  => array( 'type' => 'string', 'description' => 'A single theme slug to delete.' ),
				'themes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple theme slugs to delete.' ),
				'force'  => array( 'type' => 'boolean', 'description' => 'Reserved flag; even when true the active theme is never deleted.' ),
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
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			$slugs = wpcli_pack_theme_slugs( $input, 'theme' );
			if ( empty( $slugs ) ) {
				return wpcli_pack_err( 'no_theme', 'Provide "theme" or a non-empty "themes" array.' );
			}
			$current = (string) get_stylesheet();
			$deleted = array();
			$errors  = array();
			foreach ( $slugs as $slug ) {
				if ( $slug === $current ) {
					$errors[] = array( 'theme' => $slug, 'message' => 'Cannot delete the currently active theme.' );
					continue;
				}
				if ( ! wp_get_theme( $slug )->exists() ) {
					$errors[] = array( 'theme' => $slug, 'message' => 'Theme is not installed.' );
					continue;
				}
				$result = delete_theme( $slug );
				if ( is_wp_error( $result ) ) {
					$errors[] = array( 'theme' => $slug, 'message' => $result->get_error_message() );
				} elseif ( false === $result || null === $result ) {
					$errors[] = array( 'theme' => $slug, 'message' => 'Deletion failed (filesystem credentials or permissions).' );
				} else {
					$deleted[] = $slug;
				}
			}
			return array( 'deleted' => $deleted, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-install',
		'label'            => 'Install Theme',
		'description'      => 'Install one or more themes from the WordPress.org theme directory by slug (like `wp theme install <slug>...`). Requires outbound network access to api.wordpress.org. Provide `slug` (single) and/or `themes` (array). `activate` activates the (first) theme after install; `force` reinstalls/overwrites if already present. Returns per-theme results; upgrader errors are reported, not thrown.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'     => array( 'type' => 'string', 'description' => 'A single WordPress.org theme slug to install.' ),
				'themes'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple WordPress.org theme slugs to install.' ),
				'activate' => array( 'type' => 'boolean', 'description' => 'Activate the theme after installing. With multiple, the first installed is activated. Default false.' ),
				'force'    => array( 'type' => 'boolean', 'description' => 'Overwrite an existing install of the same theme. Default false.' ),
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
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$slugs = wpcli_pack_theme_slugs( $input, 'slug' );
			if ( empty( $slugs ) ) {
				return wpcli_pack_err( 'no_theme', 'Provide "slug" or a non-empty "themes" array.' );
			}
			$force     = ! empty( $input['force'] );
			$activate  = ! empty( $input['activate'] );
			$installed = array();
			$errors    = array();
			$first_ok  = '';

			foreach ( $slugs as $slug ) {
				if ( ! $force && wp_get_theme( $slug )->exists() ) {
					$installed[] = $slug;
					if ( '' === $first_ok ) {
						$first_ok = $slug;
					}
					continue;
				}
				$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
				if ( is_wp_error( $api ) ) {
					$errors[] = array( 'theme' => $slug, 'message' => $api->get_error_message() );
					continue;
				}
				if ( empty( $api->download_link ) ) {
					$errors[] = array( 'theme' => $slug, 'message' => 'No download link returned by themes API.' );
					continue;
				}
				$skin     = wpcli_pack_theme_skin();
				$upgrader = new Theme_Upgrader( $skin );
				$result   = $upgrader->install( $api->download_link, array( 'overwrite_package' => $force ) );
				if ( is_wp_error( $result ) ) {
					$errors[] = array( 'theme' => $slug, 'message' => $result->get_error_message() );
				} elseif ( false === $result || null === $result ) {
					$msg      = $skin->errors_collected ? implode( '; ', $skin->errors_collected ) : 'Install failed (network or filesystem credentials).';
					$errors[] = array( 'theme' => $slug, 'message' => $msg );
				} else {
					$installed[] = $slug;
					if ( '' === $first_ok ) {
						$first_ok = $slug;
					}
				}
			}

			$out = array( 'installed' => $installed, 'errors' => $errors );
			if ( $activate && '' !== $first_ok ) {
				$theme = wp_get_theme( $first_ok );
				if ( $theme->exists() && ! $theme->errors() ) {
					switch_theme( $theme->get_stylesheet() );
					$out['activated'] = $theme->get_stylesheet();
				}
			}
			return $out;
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-update',
		'label'            => 'Update Theme',
		'description'      => 'Update installed themes to their latest WordPress.org version (like `wp theme update <theme>... | --all`). Requires outbound network access. Provide `theme` (single), `themes` (array), or `all=true`. Returns per-theme results; upgrader errors are reported, not thrown.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme'  => array( 'type' => 'string', 'description' => 'A single theme slug to update.' ),
				'themes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple theme slugs to update.' ),
				'all'    => array( 'type' => 'boolean', 'description' => 'Update every theme that has a pending update.' ),
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
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			wp_update_themes();

			if ( ! empty( $input['all'] ) ) {
				$updates = get_site_transient( 'update_themes' );
				$slugs   = ( is_object( $updates ) && ! empty( $updates->response ) ) ? array_keys( $updates->response ) : array();
			} else {
				$slugs = wpcli_pack_theme_slugs( $input, 'theme' );
			}
			if ( empty( $slugs ) ) {
				if ( ! empty( $input['all'] ) ) {
					return array( 'updated' => array(), 'errors' => array() );
				}
				return wpcli_pack_err( 'no_theme', 'Provide "theme", a non-empty "themes" array, or "all".' );
			}

			$updated = array();
			$errors  = array();
			foreach ( $slugs as $slug ) {
				if ( ! wp_get_theme( $slug )->exists() ) {
					$errors[] = array( 'theme' => $slug, 'message' => 'Theme is not installed.' );
					continue;
				}
				$skin     = wpcli_pack_theme_skin();
				$upgrader = new Theme_Upgrader( $skin );
				$result   = $upgrader->upgrade( $slug );
				if ( is_wp_error( $result ) ) {
					$errors[] = array( 'theme' => $slug, 'message' => $result->get_error_message() );
				} elseif ( false === $result || null === $result ) {
					$msg      = $skin->errors_collected ? implode( '; ', $skin->errors_collected ) : 'Update failed or no update available.';
					$errors[] = array( 'theme' => $slug, 'message' => $msg );
				} else {
					$updated[] = $slug;
				}
			}
			return array( 'updated' => $updated, 'errors' => $errors );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-mod-get',
		'label'            => 'Get Theme Mod',
		'description'      => 'Get a single theme modification value (like `wp theme mod get <key>`). Theme mods are per-theme; defaults to the active theme, or pass `theme` to read another theme\'s stored mods. `found` is false when the key is not set.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array( 'type' => 'string', 'description' => 'Theme mod key, e.g. "custom_logo", "background_color".' ),
				'theme' => array( 'type' => 'string', 'description' => 'Theme slug. Default: the active theme.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The theme mod value (native type). Null when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key   = (string) $input['key'];
			$theme = isset( $input['theme'] ) ? (string) $input['theme'] : '';
			if ( '' === $theme || $theme === (string) get_stylesheet() ) {
				$sentinel = '__wpcli_pack_missing__';
				$value    = get_theme_mod( $key, $sentinel );
				if ( $sentinel === $value ) {
					return array( 'found' => false, 'value' => null );
				}
				return array( 'found' => true, 'value' => $value );
			}
			$mods = get_option( 'theme_mods_' . $theme );
			if ( is_array( $mods ) && array_key_exists( $key, $mods ) ) {
				return array( 'found' => true, 'value' => $mods[ $key ] );
			}
			return array( 'found' => false, 'value' => null );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-mod-get-all',
		'label'            => 'Get All Theme Mods',
		'description'      => 'Get every theme modification for a theme (like `wp theme mod get --all`). Defaults to the active theme; pass `theme` for another theme.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme slug. Default: the active theme.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'mods' => array( 'type' => 'object' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$theme = isset( $input['theme'] ) ? (string) $input['theme'] : '';
			if ( '' === $theme || $theme === (string) get_stylesheet() ) {
				$mods = get_theme_mods();
			} else {
				$mods = get_option( 'theme_mods_' . $theme );
			}
			if ( ! is_array( $mods ) ) {
				$mods = array();
			}
			return array( 'mods' => $mods );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-mod-set',
		'label'            => 'Set Theme Mod',
		'description'      => 'Set a single theme modification (like `wp theme mod set <key> <value>`). `value` may be any JSON type. Theme mods are per-theme: defaults to the active theme (via set_theme_mod), or pass `theme` to write into another theme\'s theme_mods_<slug> option.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array( 'type' => 'string', 'description' => 'Theme mod key.' ),
				'value' => array( 'description' => 'New value (any JSON type). Required.' ),
				'theme' => array( 'type' => 'string', 'description' => 'Theme slug. Default: the active theme.' ),
			),
			'required'             => array( 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key   = (string) $input['key'];
			$value = wpcli_pack_maybe_json( $input['value'] );
			$theme = isset( $input['theme'] ) ? (string) $input['theme'] : '';
			if ( '' === $theme || $theme === (string) get_stylesheet() ) {
				set_theme_mod( $key, $value );
				return array( 'success' => true );
			}
			$mods         = get_option( 'theme_mods_' . $theme );
			$mods         = is_array( $mods ) ? $mods : array();
			$mods[ $key ] = $value;
			update_option( 'theme_mods_' . $theme, $mods );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-mod-remove',
		'label'            => 'Remove Theme Mod',
		'description'      => 'Remove one theme modification by `key`, or all of them with `all=true` (like `wp theme mod remove <key>` / `--all`). Defaults to the active theme; pass `theme` for another theme.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array( 'type' => 'string', 'description' => 'Theme mod key to remove. Omit when using "all".' ),
				'all'   => array( 'type' => 'boolean', 'description' => 'Remove every theme mod for the theme.' ),
				'theme' => array( 'type' => 'string', 'description' => 'Theme slug. Default: the active theme.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$all   = ! empty( $input['all'] );
			$key   = isset( $input['key'] ) ? (string) $input['key'] : '';
			$theme = isset( $input['theme'] ) ? (string) $input['theme'] : '';
			if ( ! $all && '' === $key ) {
				return wpcli_pack_err( 'no_key', 'Provide "key" or set "all" to true.' );
			}
			$is_current = ( '' === $theme || $theme === (string) get_stylesheet() );
			if ( $all ) {
				if ( $is_current ) {
					remove_theme_mods();
				} else {
					delete_option( 'theme_mods_' . $theme );
				}
				return array( 'success' => true );
			}
			if ( $is_current ) {
				remove_theme_mod( $key );
			} else {
				$mods = get_option( 'theme_mods_' . $theme );
				if ( is_array( $mods ) && array_key_exists( $key, $mods ) ) {
					unset( $mods[ $key ] );
					update_option( 'theme_mods_' . $theme, $mods );
				}
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-auto-updates-status',
		'label'            => 'Theme Auto-Updates Status',
		'description'      => 'Report whether automatic updates are enabled for a theme (like `wp theme auto-updates status <theme>`). Reads the "auto_update_themes" option.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet/folder slug.' ),
			),
			'required'             => array( 'theme' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'enabled' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slug    = (string) $input['theme'];
			$enabled = (array) get_option( 'auto_update_themes', array() );
			return array( 'enabled' => in_array( $slug, $enabled, true ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-auto-updates-enable',
		'label'            => 'Enable Theme Auto-Updates',
		'description'      => 'Enable automatic updates for one or more themes (like `wp theme auto-updates enable <theme>...`). Provide `theme` (single) and/or `themes` (array). Updates the "auto_update_themes" option.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme'  => array( 'type' => 'string', 'description' => 'A single theme slug.' ),
				'themes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple theme slugs.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'enabled' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slugs = wpcli_pack_theme_slugs( $input, 'theme' );
			if ( empty( $slugs ) ) {
				return wpcli_pack_err( 'no_theme', 'Provide "theme" or a non-empty "themes" array.' );
			}
			$enabled = (array) get_option( 'auto_update_themes', array() );
			$enabled = array_values( array_unique( array_merge( $enabled, $slugs ) ) );
			update_option( 'auto_update_themes', $enabled );
			return array( 'success' => true, 'enabled' => $enabled );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/theme-auto-updates-disable',
		'label'            => 'Disable Theme Auto-Updates',
		'description'      => 'Disable automatic updates for one or more themes (like `wp theme auto-updates disable <theme>...`). Provide `theme` (single) and/or `themes` (array). Updates the "auto_update_themes" option.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'theme'  => array( 'type' => 'string', 'description' => 'A single theme slug.' ),
				'themes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Multiple theme slugs.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'enabled' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$slugs = wpcli_pack_theme_slugs( $input, 'theme' );
			if ( empty( $slugs ) ) {
				return wpcli_pack_err( 'no_theme', 'Provide "theme" or a non-empty "themes" array.' );
			}
			$enabled = (array) get_option( 'auto_update_themes', array() );
			$enabled = array_values( array_diff( $enabled, $slugs ) );
			update_option( 'auto_update_themes', $enabled );
			return array( 'success' => true, 'enabled' => $enabled );
		},
	)
);
