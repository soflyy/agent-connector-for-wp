<?php
/**
 * `wp config` — read and edit wp-config.php constants and $table_prefix natively.
 *
 * Reads happen via defined()/constant(); writes parse and rewrite wp-config.php
 * as a plain file with regex. Secret values are redacted on read.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The well-known WordPress configuration constants surfaced by `wp config list`.
 *
 * @return array<int,string>
 */
function wpcli_pack_config_constants(): array {
	return array(
		'DB_NAME',
		'DB_USER',
		'DB_PASSWORD',
		'DB_HOST',
		'DB_CHARSET',
		'DB_COLLATE',
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
		'WP_DEBUG',
		'WP_DEBUG_LOG',
		'WP_DEBUG_DISPLAY',
		'SCRIPT_DEBUG',
		'WP_CACHE',
		'WP_AUTO_UPDATE_CORE',
		'AUTOMATIC_UPDATER_DISABLED',
		'DISALLOW_FILE_EDIT',
		'DISALLOW_FILE_MODS',
		'FS_METHOD',
		'WP_HOME',
		'WP_SITEURL',
		'WP_CONTENT_DIR',
		'WP_CONTENT_URL',
		'WP_PLUGIN_DIR',
		'WPLANG',
		'WP_MEMORY_LIMIT',
		'WP_MAX_MEMORY_LIMIT',
		'WP_POST_REVISIONS',
		'AUTOSAVE_INTERVAL',
		'EMPTY_TRASH_DAYS',
		'WP_ENVIRONMENT_TYPE',
		'MULTISITE',
		'FORCE_SSL_ADMIN',
	);
}

/**
 * Whether a constant name holds a secret that must be redacted on read.
 */
function wpcli_pack_config_is_secret( string $name ): bool {
	if ( 'DB_PASSWORD' === $name ) {
		return true;
	}
	return (bool) preg_match( '/_(KEY|SALT)$/', $name );
}

/**
 * Locate wp-config.php (ABSPATH, then one level up, matching WordPress loader).
 *
 * @return string|null Absolute path, or null if not found.
 */
function wpcli_pack_config_path(): ?string {
	$candidates = array(
		ABSPATH . 'wp-config.php',
		dirname( ABSPATH ) . '/wp-config.php',
	);
	foreach ( $candidates as $path ) {
		if ( is_file( $path ) ) {
			return $path;
		}
	}
	return null;
}

/**
 * Describe a value's JSON-ish type for reporting.
 *
 * @param mixed $value Value.
 */
function wpcli_pack_config_type( $value ): string {
	if ( is_bool( $value ) ) {
		return 'boolean';
	}
	if ( is_int( $value ) ) {
		return 'integer';
	}
	if ( is_float( $value ) ) {
		return 'double';
	}
	if ( is_array( $value ) ) {
		return 'array';
	}
	if ( null === $value ) {
		return 'null';
	}
	return 'string';
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-get',
		'label'            => 'Get Config Value',
		'description'      => 'Read a single wp-config.php constant or $table_prefix (like `wp config get <name>`). Reads the live value via defined()/constant(). Pass name="table_prefix" for the database table prefix. Omit `name` to return all well-known WordPress config constants currently defined. DB_PASSWORD and *_KEY/*_SALT secrets are redacted to "***".',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Constant name (e.g. "WP_DEBUG", "DB_NAME") or "table_prefix". Omit for all known constants.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found'     => array( 'type' => 'boolean' ),
				'value'     => array(),
				'type'      => array( 'type' => 'string' ),
				'constants' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			// No name -> dump all known defined constants (redacted).
			if ( empty( $input['name'] ) ) {
				$constants = array();
				foreach ( wpcli_pack_config_constants() as $name ) {
					if ( ! defined( $name ) ) {
						continue;
					}
					$value = constant( $name );
					$type  = wpcli_pack_config_type( $value );
					if ( wpcli_pack_config_is_secret( $name ) ) {
						$value = '***';
					}
					$constants[] = array( 'name' => $name, 'value' => $value, 'type' => $type );
				}
				return array( 'found' => true, 'constants' => $constants );
			}

			$name = (string) $input['name'];
			if ( 'table_prefix' === $name || '$table_prefix' === $name ) {
				$prefix = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : '';
				return array( 'found' => '' !== $prefix, 'value' => $prefix, 'type' => 'string' );
			}
			if ( ! defined( $name ) ) {
				return array( 'found' => false, 'value' => null, 'type' => 'null' );
			}
			$value = constant( $name );
			$type  = wpcli_pack_config_type( $value );
			if ( wpcli_pack_config_is_secret( $name ) ) {
				$value = '***';
			}
			return array( 'found' => true, 'value' => $value, 'type' => $type );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-list',
		'label'            => 'List Config Values',
		'description'      => 'List all well-known WordPress config constants currently defined (like `wp config list`). DB_PASSWORD and *_KEY/*_SALT secrets are redacted to "***".',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'constants' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$constants = array();
			foreach ( wpcli_pack_config_constants() as $name ) {
				if ( ! defined( $name ) ) {
					continue;
				}
				$value = constant( $name );
				$type  = wpcli_pack_config_type( $value );
				if ( wpcli_pack_config_is_secret( $name ) ) {
					$value = '***';
				}
				$constants[] = array( 'name' => $name, 'value' => $value, 'type' => $type );
			}
			return array( 'constants' => $constants );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-has',
		'label'            => 'Config Has Constant',
		'description'      => 'Check whether a wp-config.php constant is defined (like `wp config has <name>`). Returns has=true/false via defined().',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Constant name to check.' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'has' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'has' => defined( (string) $input['name'] ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-path',
		'label'            => 'Config File Path',
		'description'      => 'Locate the wp-config.php file (like `wp config path`). Checks ABSPATH then the parent directory, matching the WordPress loader.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'path'  => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$path = wpcli_pack_config_path();
			if ( null === $path ) {
				return array( 'found' => false, 'path' => '' );
			}
			return array( 'found' => true, 'path' => $path );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-set',
		'label'            => 'Set Config Value',
		'description'      => 'Add or update a wp-config.php constant or $table_prefix (like `wp config set <name> <value>`). Edits wp-config.php as a file: replaces an existing definition or inserts a new one before the "stop editing" marker. By default the value is written as a quoted string; pass `raw`=true to insert it unquoted (for true/false/integers/expressions). Use type="variable" for $table_prefix. EDITS A CRITICAL FILE.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'  => array( 'type' => 'string', 'description' => 'Constant name, or the variable name (without $) when type="variable", e.g. "WP_DEBUG" or "table_prefix".' ),
				'value' => array( 'type' => 'string', 'description' => 'Value to write. With raw=false it is quoted as a string; with raw=true it is inserted verbatim.' ),
				'type'  => array( 'type' => 'string', 'enum' => array( 'constant', 'variable' ), 'description' => 'constant (define) or variable ($table_prefix). Default constant.' ),
				'raw'   => array( 'type' => 'boolean', 'description' => 'Insert the value unquoted (e.g. true, false, 42). Default false.' ),
			),
			'required'             => array( 'name', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'summarize'        => static function ( array $input ) {
			$name = isset( $input['name'] ) ? (string) $input['name'] : '';
			return sprintf( 'Set wp-config.php %s = *** (value redacted).', $name );
		},
		'execute_callback' => static function ( array $input ) {
			$path = wpcli_pack_config_path();
			if ( null === $path ) {
				return wpcli_pack_err( 'config_not_found', 'Could not locate wp-config.php.', 404 );
			}
			if ( ! is_writable( $path ) ) {
				return wpcli_pack_err( 'config_not_writable', sprintf( 'wp-config.php is not writable: %s', $path ), 403 );
			}
			$name  = (string) $input['name'];
			$value = (string) $input['value'];
			$type  = isset( $input['type'] ) ? (string) $input['type'] : 'constant';
			$raw   = ! empty( $input['raw'] );

			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $contents ) {
				return wpcli_pack_err( 'read_failed', 'Could not read wp-config.php.' );
			}

			$literal = $raw ? $value : "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";

			if ( 'variable' === $type ) {
				$var     = '$' . ltrim( $name, '$' );
				$new_line = preg_quote( $var, '/' );
				$pattern = '/^[ \t]*' . $new_line . '\s*=\s*.*?;[ \t]*$/m';
				$replacement = $var . ' = ' . $literal . ';';
				if ( preg_match( $pattern, $contents ) ) {
					$contents = preg_replace( $pattern, $replacement, $contents, 1 );
				} else {
					$contents = wpcli_pack_config_insert( $contents, $replacement );
				}
			} else {
				$q       = preg_quote( $name, '/' );
				$pattern = '/^[ \t]*define\s*\(\s*([\'"])' . $q . '\1\s*,.*?\)\s*;[ \t]*$/m';
				$replacement = "define( '" . $name . "', " . $literal . ' );';
				if ( preg_match( $pattern, $contents ) ) {
					$contents = preg_replace( $pattern, $replacement, $contents, 1 );
				} else {
					$contents = wpcli_pack_config_insert( $contents, $replacement );
				}
			}

			if ( false === strpos( $contents, 'wp-settings.php' ) ) {
				return wpcli_pack_err( 'sanity_failed', 'Refusing to write: edited wp-config.php no longer references wp-settings.php.' );
			}
			if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return wpcli_pack_err( 'write_failed', 'Could not write wp-config.php.' );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-delete',
		'label'            => 'Delete Config Value',
		'description'      => 'Remove a constant definition from wp-config.php (like `wp config delete <name>`). Deletes the matching define() line from the file. EDITS A CRITICAL FILE.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Constant name to remove.' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$path = wpcli_pack_config_path();
			if ( null === $path ) {
				return wpcli_pack_err( 'config_not_found', 'Could not locate wp-config.php.', 404 );
			}
			if ( ! is_writable( $path ) ) {
				return wpcli_pack_err( 'config_not_writable', sprintf( 'wp-config.php is not writable: %s', $path ), 403 );
			}
			$name     = (string) $input['name'];
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $contents ) {
				return wpcli_pack_err( 'read_failed', 'Could not read wp-config.php.' );
			}
			$q       = preg_quote( $name, '/' );
			$pattern = '/^[ \t]*define\s*\(\s*([\'"])' . $q . '\1\s*,.*?\)\s*;[ \t]*\r?\n?/m';
			if ( ! preg_match( $pattern, $contents ) ) {
				return wpcli_pack_err( 'constant_not_found', sprintf( 'No define() for "%s" found in wp-config.php.', $name ), 404 );
			}
			$contents = preg_replace( $pattern, '', $contents, 1 );
			if ( false === strpos( $contents, 'wp-settings.php' ) ) {
				return wpcli_pack_err( 'sanity_failed', 'Refusing to write: edited wp-config.php no longer references wp-settings.php.' );
			}
			if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return wpcli_pack_err( 'write_failed', 'Could not write wp-config.php.' );
			}
			return array( 'success' => true );
		},
	)
);

if ( ! function_exists( 'wpcli_pack_config_insert' ) ) {
	/**
	 * Insert a new line into wp-config.php contents before the "stop editing"
	 * marker, falling back to before the wp-settings.php require.
	 *
	 * @param string $contents Current file contents.
	 * @param string $line     Line to insert (no trailing newline).
	 * @return string Updated contents.
	 */
	function wpcli_pack_config_insert( string $contents, string $line ): string {
		$marker = "/* That's all, stop editing! Happy publishing. */";
		if ( false !== strpos( $contents, $marker ) ) {
			return str_replace( $marker, $line . "\n\n" . $marker, $contents );
		}
		// Older marker wording.
		if ( preg_match( "#/\\* That's all, stop editing.*?\\*/#", $contents, $m ) ) {
			return str_replace( $m[0], $line . "\n\n" . $m[0], $contents );
		}
		// Fall back to before the wp-settings.php require.
		$pattern = '/^[ \t]*require_once[^\n]*wp-settings\.php[^\n]*;[ \t]*$/m';
		if ( preg_match( $pattern, $contents, $m ) ) {
			return str_replace( $m[0], $line . "\n\n" . $m[0], $contents );
		}
		// Last resort: append.
		return rtrim( $contents ) . "\n" . $line . "\n";
	}
}
