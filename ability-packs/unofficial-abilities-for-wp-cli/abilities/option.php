<?php
/**
 * `wp option` — read and write the wp_options table.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-get',
		'label'            => 'Get Option',
		'description'      => 'Get the value of a single option from wp_options (like `wp option get <name>`). Returns the value already unserialized into native JSON (arrays/objects come back as objects). `found` is false when the option does not exist.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Option name, e.g. "siteurl", "blogname", "active_plugins".' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The option value (native type). Null when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$name     = (string) $input['name'];
			$sentinel = '__wpcli_pack_missing__';
			$value    = get_option( $name, $sentinel );
			if ( $sentinel === $value ) {
				return array( 'found' => false, 'value' => null );
			}
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-add',
		'label'            => 'Add Option',
		'description'      => 'Create a new option (like `wp option add <name> <value>`). Fails if the option already exists — use wp-cli/option-update to overwrite. `value` may be any JSON value; objects/arrays are stored serialized. `autoload` controls whether WordPress loads it on every request.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array( 'type' => 'string', 'description' => 'Option name.' ),
				'value'    => array( 'description' => 'Option value (any JSON type). Required.' ),
				'autoload' => array( 'type' => 'boolean', 'description' => 'Autoload this option on every page load. Default true.' ),
			),
			'required'             => array( 'name', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$name = (string) $input['name'];
			if ( false !== get_option( $name, false ) || null !== get_option( $name, null ) ) {
				// Distinguish a real existing option (even if its value is falsey).
				$probe = get_option( $name, '__wpcli_pack_missing__' );
				if ( '__wpcli_pack_missing__' !== $probe ) {
					return wpcli_pack_err( 'option_exists', sprintf( 'Option "%s" already exists.', $name ), 409 );
				}
			}
			$autoload = isset( $input['autoload'] ) ? (bool) $input['autoload'] : true;
			$ok       = add_option( $name, $input['value'], '', $autoload );
			if ( ! $ok ) {
				return wpcli_pack_err( 'option_add_failed', sprintf( 'Could not add option "%s".', $name ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-update',
		'label'            => 'Update Option',
		'description'      => 'Create or overwrite an option (like `wp option update <name> <value>`, alias `option set`). `value` may be any JSON value. Optionally change `autoload`. Idempotent.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array( 'type' => 'string', 'description' => 'Option name.' ),
				'value'    => array( 'description' => 'New option value (any JSON type). Required.' ),
				'autoload' => array( 'type' => 'boolean', 'description' => 'Set autoload behavior. Omit to leave unchanged.' ),
			),
			'required'             => array( 'name', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$name     = (string) $input['name'];
			$autoload = array_key_exists( 'autoload', $input ) ? (bool) $input['autoload'] : null;
			$ok       = update_option( $name, $input['value'], $autoload );
			// update_option returns false when the value is unchanged; treat that as success.
			return array( 'success' => true, 'changed' => (bool) $ok );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-delete',
		'label'            => 'Delete Option',
		'description'      => 'Delete an option (like `wp option delete <name>`). Returns success even if the option did not exist.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Option name.' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'deleted' => (bool) delete_option( (string) $input['name'] ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-list',
		'label'            => 'List Options',
		'description'      => 'List options from wp_options (like `wp option list`). Optionally filter by a name `search` pattern (use * as wildcard) and/or `autoload` state. Values are returned unserialized. Use `total_only` to just count.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'search'     => array( 'type' => 'string', 'description' => 'Filter option names by this pattern. * matches any sequence (e.g. "theme_mods_*").' ),
				'exclude'    => array( 'type' => 'string', 'description' => 'Exclude option names matching this pattern.' ),
				'autoload'   => array( 'type' => 'string', 'enum' => array( 'on', 'off' ), 'description' => 'Only options with this autoload state.' ),
				'total_only' => array( 'type' => 'boolean', 'description' => 'Return only the count, not the values.' ),
				'limit'      => array( 'type' => 'integer', 'description' => 'Max rows to return. Default 500.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'   => array( 'type' => 'integer' ),
				'options' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$where  = array( '1=1' );
			$params = array();
			if ( ! empty( $input['search'] ) ) {
				$where[]  = 'option_name LIKE %s';
				$params[] = str_replace( '*', '%', (string) $input['search'] );
			}
			if ( ! empty( $input['exclude'] ) ) {
				$where[]  = 'option_name NOT LIKE %s';
				$params[] = str_replace( '*', '%', (string) $input['exclude'] );
			}
			if ( ! empty( $input['autoload'] ) ) {
				// WP 6.6+ uses 'on'/'off'/'auto-on'/... ; older uses 'yes'/'no'. Match both.
				if ( 'on' === $input['autoload'] ) {
					$where[] = "autoload IN ('yes','on','auto-on','auto')";
				} else {
					$where[] = "autoload IN ('no','off','auto-off')";
				}
			}
			$sql = 'SELECT option_name, option_value, autoload FROM ' . $wpdb->options . ' WHERE ' . implode( ' AND ', $where );
			if ( $params ) {
				$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$rows = is_array( $rows ) ? $rows : array();
			if ( ! empty( $input['total_only'] ) ) {
				return array( 'count' => count( $rows ), 'options' => array() );
			}
			$limit = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 500;
			$out   = array();
			foreach ( array_slice( $rows, 0, $limit ) as $row ) {
				$out[] = array(
					'option_name'  => $row['option_name'],
					'option_value' => maybe_unserialize( $row['option_value'] ),
					'autoload'     => $row['autoload'],
				);
			}
			return array( 'count' => count( $rows ), 'options' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-pluck',
		'label'            => 'Pluck Nested Option Value',
		'description'      => 'Read a nested value inside a serialized (array/object) option (like `wp option pluck <name> <key>...`). Provide `key_path` as the ordered list of keys to descend. Returns the value found or found=false.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array( 'type' => 'string', 'description' => 'Option name.' ),
				'key_path' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Ordered keys to descend into the nested value, e.g. ["foo","bar"].' ),
			),
			'required'             => array( 'name', 'key_path' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array(),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$value = get_option( (string) $input['name'], null );
			foreach ( (array) $input['key_path'] as $key ) {
				if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
					$value = $value[ $key ];
				} elseif ( is_object( $value ) && isset( $value->$key ) ) {
					$value = $value->$key;
				} else {
					return array( 'found' => false, 'value' => null );
				}
			}
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-patch',
		'label'            => 'Patch Nested Option Value',
		'description'      => 'Insert, update, or delete a nested value inside a serialized (array) option (like `wp option patch <action> <name> <key>...`). `action` is insert|update|delete. `key_path` locates the slot; `value` is required for insert/update. Only the targeted slot changes; the rest of the option is preserved.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'action'   => array( 'type' => 'string', 'enum' => array( 'insert', 'update', 'delete' ), 'description' => 'insert adds a new key, update changes an existing key, delete removes it.' ),
				'name'     => array( 'type' => 'string', 'description' => 'Option name.' ),
				'key_path' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Ordered keys locating the slot to change.' ),
				'value'    => array( 'description' => 'New value (any JSON type). Required for insert/update.' ),
			),
			'required'             => array( 'action', 'name', 'key_path' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$action   = (string) $input['action'];
			$name     = (string) $input['name'];
			$key_path = array_values( (array) $input['key_path'] );
			if ( empty( $key_path ) ) {
				return wpcli_pack_err( 'bad_key_path', 'key_path must have at least one key.' );
			}
			if ( in_array( $action, array( 'insert', 'update' ), true ) && ! array_key_exists( 'value', $input ) ) {
				return wpcli_pack_err( 'value_required', 'value is required for insert/update.' );
			}
			$root = get_option( $name, array() );
			if ( ! is_array( $root ) ) {
				return wpcli_pack_err( 'not_array_option', sprintf( 'Option "%s" is not an array; cannot patch nested keys.', $name ) );
			}
			$ref      = &$root;
			$last_key = array_pop( $key_path );
			foreach ( $key_path as $key ) {
				if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
					if ( 'delete' === $action ) {
						return wpcli_pack_err( 'key_not_found', 'Nested key path does not exist.' );
					}
					$ref[ $key ] = array();
				}
				$ref = &$ref[ $key ];
			}
			if ( 'delete' === $action ) {
				if ( ! array_key_exists( $last_key, $ref ) ) {
					return wpcli_pack_err( 'key_not_found', 'Key to delete does not exist.' );
				}
				unset( $ref[ $last_key ] );
			} elseif ( 'insert' === $action ) {
				if ( array_key_exists( $last_key, $ref ) ) {
					return wpcli_pack_err( 'key_exists', 'Key already exists; use action=update.' );
				}
				$ref[ $last_key ] = $input['value'];
			} else { // update
				$ref[ $last_key ] = $input['value'];
			}
			update_option( $name, $root );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-get-autoload',
		'label'            => 'Get Option Autoload',
		'description'      => 'Report whether an option is autoloaded (like `wp option get-autoload <name>`). Returns "on" or "off", or found=false.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Option name.' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found'    => array( 'type' => 'boolean' ),
				'autoload' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$row = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM ' . $wpdb->options . ' WHERE option_name = %s', (string) $input['name'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( null === $row ) {
				return array( 'found' => false, 'autoload' => '' );
			}
			$on = in_array( $row, array( 'yes', 'on', 'auto-on', 'auto' ), true );
			return array( 'found' => true, 'autoload' => $on ? 'on' : 'off' );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/option-set-autoload',
		'label'            => 'Set Option Autoload',
		'description'      => 'Change whether an option is autoloaded (like `wp option set-autoload <name> <on|off>`). Idempotent.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array( 'type' => 'string', 'description' => 'Option name.' ),
				'autoload' => array( 'type' => 'string', 'enum' => array( 'on', 'off' ), 'description' => 'Desired autoload state.' ),
			),
			'required'             => array( 'name', 'autoload' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$name     = (string) $input['name'];
			$probe    = get_option( $name, '__wpcli_pack_missing__' );
			if ( '__wpcli_pack_missing__' === $probe ) {
				return wpcli_pack_err( 'option_not_found', sprintf( 'Option "%s" does not exist.', $name ), 404 );
			}
			$autoload = 'on' === $input['autoload'];
			// update_option() short-circuits when the value is unchanged and then
			// never touches the autoload column, so use the dedicated API (WP 6.6+)
			// and fall back to a direct column write on older cores.
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				$changed = wp_set_option_autoload( $name, $autoload );
			} else {
				global $wpdb;
				$changed = (bool) $wpdb->update( $wpdb->options, array( 'autoload' => $autoload ? 'yes' : 'no' ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
			return array( 'success' => true, 'changed' => (bool) $changed );
		},
	)
);
