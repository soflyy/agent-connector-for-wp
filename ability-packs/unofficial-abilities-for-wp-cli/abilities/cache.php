<?php
/**
 * `wp cache` and `wp transient` — the object cache and the transient API.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-get',
		'label'            => 'Get Cache Value',
		'description'      => 'Get a value from the object cache (like `wp cache get <key> [<group>]`). `found` is false when the key is not present in the cache.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'group' => array( 'type' => 'string', 'description' => 'Cache group. Default empty (the default group).' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The cached value (native type). Null when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key   = (string) $input['key'];
			$group = isset( $input['group'] ) ? (string) $input['group'] : '';
			$found = false;
			$value = wp_cache_get( $key, $group, false, $found );
			if ( ! $found ) {
				return array( 'found' => false, 'value' => null );
			}
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-set',
		'label'            => 'Set Cache Value',
		'description'      => 'Set (create or overwrite) a value in the object cache (like `wp cache set <key> <value> [<group>] [<expiration>]`). `value` may be any JSON value. Without a persistent object cache this only lasts for the current request.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'        => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'value'      => array( 'description' => 'Value to cache (any JSON type). Required.' ),
				'group'      => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
				'expiration' => array( 'type' => 'integer', 'description' => 'Time-to-live in seconds. Default 0 (no expiry).' ),
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
			$key        = (string) $input['key'];
			$group      = isset( $input['group'] ) ? (string) $input['group'] : '';
			$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : 0;
			$value      = wpcli_pack_maybe_json( $input['value'] );
			return array( 'success' => (bool) wp_cache_set( $key, $value, $group, $expiration ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-add',
		'label'            => 'Add Cache Value',
		'description'      => 'Add a value to the object cache only if the key does not already exist (like `wp cache add <key> <value> [<group>] [<expiration>]`). `success` is false when the key already exists.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'        => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'value'      => array( 'description' => 'Value to cache (any JSON type). Required.' ),
				'group'      => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
				'expiration' => array( 'type' => 'integer', 'description' => 'Time-to-live in seconds. Default 0 (no expiry).' ),
			),
			'required'             => array( 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$key        = (string) $input['key'];
			$group      = isset( $input['group'] ) ? (string) $input['group'] : '';
			$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : 0;
			$value      = wpcli_pack_maybe_json( $input['value'] );
			return array( 'success' => (bool) wp_cache_add( $key, $value, $group, $expiration ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-replace',
		'label'            => 'Replace Cache Value',
		'description'      => 'Replace a value in the object cache only if the key already exists (like `wp cache replace <key> <value> [<group>] [<expiration>]`). `success` is false when the key is not present.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'        => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'value'      => array( 'description' => 'Replacement value (any JSON type). Required.' ),
				'group'      => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
				'expiration' => array( 'type' => 'integer', 'description' => 'Time-to-live in seconds. Default 0 (no expiry).' ),
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
			$key        = (string) $input['key'];
			$group      = isset( $input['group'] ) ? (string) $input['group'] : '';
			$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : 0;
			$value      = wpcli_pack_maybe_json( $input['value'] );
			return array( 'success' => (bool) wp_cache_replace( $key, $value, $group, $expiration ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-delete',
		'label'            => 'Delete Cache Value',
		'description'      => 'Delete a value from the object cache (like `wp cache delete <key> [<group>]`). `success` is false when the key was not present.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'   => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'group' => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key   = (string) $input['key'];
			$group = isset( $input['group'] ) ? (string) $input['group'] : '';
			return array( 'success' => (bool) wp_cache_delete( $key, $group ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-incr',
		'label'            => 'Increment Cache Value',
		'description'      => 'Increment a numeric value in the object cache (like `wp cache incr <key> [<offset>] [<group>]`). Returns the new value, or an error if the key is missing or not numeric.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'    => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'offset' => array( 'type' => 'integer', 'description' => 'Amount to add. Default 1.' ),
				'group'  => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'value' => array( 'description' => 'The new value after incrementing.' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$key    = (string) $input['key'];
			$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 1;
			$group  = isset( $input['group'] ) ? (string) $input['group'] : '';
			$value  = wp_cache_incr( $key, $offset, $group );
			if ( false === $value ) {
				return wpcli_pack_err( 'cache_incr_failed', sprintf( 'Could not increment cache key "%s" (missing or not numeric).', $key ) );
			}
			return array( 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-decr',
		'label'            => 'Decrement Cache Value',
		'description'      => 'Decrement a numeric value in the object cache (like `wp cache decr <key> [<offset>] [<group>]`). Returns the new value, or an error if the key is missing or not numeric.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'    => array( 'type' => 'string', 'description' => 'Cache key.' ),
				'offset' => array( 'type' => 'integer', 'description' => 'Amount to subtract. Default 1.' ),
				'group'  => array( 'type' => 'string', 'description' => 'Cache group. Default empty.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'value' => array( 'description' => 'The new value after decrementing.' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$key    = (string) $input['key'];
			$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 1;
			$group  = isset( $input['group'] ) ? (string) $input['group'] : '';
			$value  = wp_cache_decr( $key, $offset, $group );
			if ( false === $value ) {
				return wpcli_pack_err( 'cache_decr_failed', sprintf( 'Could not decrement cache key "%s" (missing or not numeric).', $key ) );
			}
			return array( 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-flush',
		'label'            => 'Flush Object Cache',
		'description'      => 'Flush the entire object cache (like `wp cache flush`). Removes every key in every group. With a persistent object cache this clears the shared store for the whole site.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			unset( $input );
			return array( 'success' => (bool) wp_cache_flush() );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-flush-group',
		'label'            => 'Flush Cache Group',
		'description'      => 'Flush all keys in a single cache group (like `wp cache flush-group <group>`). Not all object cache backends support this; `supported` reports whether the backend implements `flush_group` (checked via wp_cache_supports).',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'group' => array( 'type' => 'string', 'description' => 'Cache group to flush.' ),
			),
			'required'             => array( 'group' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'supported' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$group     = (string) $input['group'];
			$supported = function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' );
			if ( ! $supported ) {
				return array( 'success' => false, 'supported' => false );
			}
			return array( 'success' => (bool) wp_cache_flush_group( $group ), 'supported' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-type',
		'label'            => 'Object Cache Type',
		'description'      => 'Report which object cache implementation is in use (like `wp cache type`). `external` is true when a persistent/drop-in object cache is active (wp_using_ext_object_cache); `type` is the cache class name, or "default" for the built-in non-persistent cache.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'type'     => array( 'type' => 'string' ),
				'external' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			unset( $input );
			$external = wp_using_ext_object_cache();
			$type     = 'default';
			if ( isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
				$type = get_class( $GLOBALS['wp_object_cache'] );
			}
			return array( 'type' => $type, 'external' => (bool) $external );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cache-supports',
		'label'            => 'Cache Feature Support',
		'description'      => 'Check whether the active object cache backend supports a feature (like `wp cache supports <feature>`). Common features: flush_group, flush_runtime, get_multiple, set_multiple, add_multiple, delete_multiple.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'feature' => array( 'type' => 'string', 'description' => 'Feature name, e.g. "flush_group", "flush_runtime", "get_multiple".' ),
			),
			'required'             => array( 'feature' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'supported' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$feature   = (string) $input['feature'];
			$supported = function_exists( 'wp_cache_supports' ) && wp_cache_supports( $feature );
			return array( 'supported' => (bool) $supported );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-get',
		'label'            => 'Get Transient',
		'description'      => 'Get the value of a transient (like `wp transient get <key>`). Set `network` true to read a site/network transient (get_site_transient). `found` is false when the transient is missing or has expired.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array( 'type' => 'string', 'description' => 'Transient name.' ),
				'network' => array( 'type' => 'boolean', 'description' => 'Use the network/site transient store. Default false.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The transient value (native type). Null when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key     = (string) $input['key'];
			$network = ! empty( $input['network'] );
			$value   = $network ? get_site_transient( $key ) : get_transient( $key );
			if ( false === $value ) {
				return array( 'found' => false, 'value' => null );
			}
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-set',
		'label'            => 'Set Transient',
		'description'      => 'Set (create or overwrite) a transient (like `wp transient set <key> <value> [<expiration>]`). `value` may be any JSON value. Set `network` true to write a site/network transient. With no expiration the transient never expires on its own.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'        => array( 'type' => 'string', 'description' => 'Transient name.' ),
				'value'      => array( 'description' => 'Value to store (any JSON type). Required.' ),
				'expiration' => array( 'type' => 'integer', 'description' => 'Time-to-live in seconds. Default 0 (no expiry).' ),
				'network'    => array( 'type' => 'boolean', 'description' => 'Use the network/site transient store. Default false.' ),
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
			$key        = (string) $input['key'];
			$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : 0;
			$network    = ! empty( $input['network'] );
			$value      = wpcli_pack_maybe_json( $input['value'] );
			$ok         = $network
				? set_site_transient( $key, $value, $expiration )
				: set_transient( $key, $value, $expiration );
			return array( 'success' => (bool) $ok );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-delete',
		'label'            => 'Delete Transient',
		'description'      => 'Delete a transient (like `wp transient delete <key>`). Set `network` true to delete a site/network transient. `success` is false when the transient did not exist.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array( 'type' => 'string', 'description' => 'Transient name.' ),
				'network' => array( 'type' => 'boolean', 'description' => 'Use the network/site transient store. Default false.' ),
			),
			'required'             => array( 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key     = (string) $input['key'];
			$network = ! empty( $input['network'] );
			$ok      = $network ? delete_site_transient( $key ) : delete_transient( $key );
			return array( 'success' => (bool) $ok );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-delete-all',
		'label'            => 'Delete All Transients',
		'description'      => 'Delete every transient (like `wp transient delete --all`). Removes both regular and (when `network` is true) site/network transients, including their timeout rows, from the database. Note: with an external/persistent object cache active, transients are stored in the cache rather than the DB, so this only removes DB-stored transients — use wp-cli/cache-flush for those.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'network' => array( 'type' => 'boolean', 'description' => 'Also delete site/network transients (_site_transient_*). Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted_count' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$network = ! empty( $input['network'] );
			if ( $network ) {
				$deleted = (int) $wpdb->query(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%' OR option_name LIKE '\\_transient\\_%'"
				); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			} else {
				$deleted = (int) $wpdb->query(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'"
				); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			}
			return array( 'deleted_count' => $deleted );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-delete-expired',
		'label'            => 'Delete Expired Transients',
		'description'      => 'Delete only expired transients (like `wp transient delete --expired`). Finds timeout rows whose expiry is in the past and removes both the timeout and its companion value row, for regular and site/network transients. Has no effect when transients live in an external object cache.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted_count' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			unset( $input );
			global $wpdb;
			$now     = time();
			$deleted = 0;

			// Regular transients: _transient_timeout_<name> holds the expiry timestamp.
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
					WHERE a.option_name LIKE '\\_transient\\_%'
					AND a.option_name NOT LIKE '\\_transient\\_timeout\\_%'
					AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					AND b.option_value < %d",
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

			// Site/network transients (single-site storage in wp_options).
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
					WHERE a.option_name LIKE '\\_site\\_transient\\_%'
					AND a.option_name NOT LIKE '\\_site\\_transient\\_timeout\\_%'
					AND b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
					AND b.option_value < %d",
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

			return array( 'deleted_count' => $deleted );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/transient-type',
		'label'            => 'Transient Storage Type',
		'description'      => 'Report where transients are stored (like `wp transient type`). Returns "object-cache" when a persistent object cache is active (transients are kept there), otherwise "database" (they live in wp_options).',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'type' => array( 'type' => 'string' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			unset( $input );
			return array( 'type' => wp_using_ext_object_cache() ? 'object-cache' : 'database' );
		},
	)
);
