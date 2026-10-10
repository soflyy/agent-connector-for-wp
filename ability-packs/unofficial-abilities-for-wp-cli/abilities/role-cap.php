<?php
/**
 * `wp role` and `wp cap` — manage roles and their capabilities natively.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a capabilities input that may be a single string or an array.
 *
 * @param mixed $value Raw input value.
 * @return array<int,string>
 */
function wpcli_pack_caps_arg( $value ): array {
	if ( is_string( $value ) ) {
		$value = '' === trim( $value ) ? array() : array( $value );
	}
	$out = array();
	foreach ( (array) $value as $cap ) {
		$cap = (string) $cap;
		if ( '' !== $cap ) {
			$out[] = $cap;
		}
	}
	return array_values( array_unique( $out ) );
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-list',
		'label'            => 'List Roles',
		'description'      => 'List all registered roles (like `wp role list`). Returns each role key, its display name, and how many capabilities it grants.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'roles' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$wp_roles = wp_roles();
			$names    = $wp_roles->get_names();
			$roles    = array();
			foreach ( $names as $key => $display ) {
				$role    = $wp_roles->get_role( $key );
				$caps    = ( $role && is_array( $role->capabilities ) ) ? $role->capabilities : array();
				$granted = array_filter( $caps );
				$roles[] = array(
					'role'               => $key,
					'name'               => $display,
					'capabilities_count' => count( $granted ),
				);
			}
			return array( 'count' => count( $roles ), 'roles' => $roles );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-get',
		'label'            => 'Get Role',
		'description'      => 'Show a single role and its granted capabilities (like `wp role list` filtered to one role / `wp cap list <role>`). Returns the role key, display name, and the list of granted capability names.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role' => array( 'type' => 'string', 'description' => 'Role key, e.g. "editor".' ),
			),
			'required'             => array( 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'role'         => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'capabilities' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key  = (string) $input['role'];
			$role = get_role( $key );
			if ( null === $role ) {
				return wpcli_pack_err( 'role_not_found', sprintf( 'Role "%s" does not exist.', $key ), 404 );
			}
			$names   = wp_roles()->get_names();
			$caps    = is_array( $role->capabilities ) ? $role->capabilities : array();
			$granted = array_keys( array_filter( $caps ) );
			sort( $granted );
			return array(
				'role'         => $key,
				'name'         => isset( $names[ $key ] ) ? $names[ $key ] : $key,
				'capabilities' => $granted,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-create',
		'label'            => 'Create Role',
		'description'      => 'Create a new role (like `wp role create <role> <name>`). Optionally clone the capabilities of an existing role with `clone_role`. Fails if the role already exists.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'       => array( 'type' => 'string', 'description' => 'New role key (machine name), e.g. "shop_manager".' ),
				'name'       => array( 'type' => 'string', 'description' => 'Human-readable display name, e.g. "Shop Manager".' ),
				'clone_role' => array( 'type' => 'string', 'description' => 'Optional existing role key to copy capabilities from.' ),
			),
			'required'             => array( 'role', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$key = (string) $input['role'];
			if ( null !== get_role( $key ) ) {
				return wpcli_pack_err( 'role_exists', sprintf( 'Role "%s" already exists.', $key ), 409 );
			}
			$caps = array();
			if ( ! empty( $input['clone_role'] ) ) {
				$source = get_role( (string) $input['clone_role'] );
				if ( null === $source ) {
					return wpcli_pack_err( 'clone_role_not_found', sprintf( 'Role to clone "%s" does not exist.', (string) $input['clone_role'] ), 404 );
				}
				$caps = is_array( $source->capabilities ) ? $source->capabilities : array();
			}
			$role = add_role( $key, (string) $input['name'], $caps );
			if ( null === $role ) {
				return wpcli_pack_err( 'role_create_failed', sprintf( 'Could not create role "%s".', $key ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-delete',
		'label'            => 'Delete Role',
		'description'      => 'Delete a role (like `wp role delete <role>`). Users assigned only to this role lose it.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role' => array( 'type' => 'string', 'description' => 'Role key to delete.' ),
			),
			'required'             => array( 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key = (string) $input['role'];
			if ( null === get_role( $key ) ) {
				return wpcli_pack_err( 'role_not_found', sprintf( 'Role "%s" does not exist.', $key ), 404 );
			}
			remove_role( $key );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-exists',
		'label'            => 'Role Exists',
		'description'      => 'Check whether a role is registered (like `wp role exists <role>`). Returns exists=true/false.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role' => array( 'type' => 'string', 'description' => 'Role key to check.' ),
			),
			'required'             => array( 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'exists' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'exists' => null !== get_role( (string) $input['role'] ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/role-reset',
		'label'            => 'Reset Role',
		'description'      => 'Reset one role (pass `role`) or all default roles (pass `all`=true) to their WordPress defaults (like `wp role reset`). This restores the default capabilities for the built-in roles (administrator, editor, author, contributor, subscriber) by re-running WordPress\'s populate_roles(). Custom-only capabilities added later are not preserved.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role' => array( 'type' => 'string', 'description' => 'Role key to reset to defaults. Omit when using all=true.' ),
				'all'  => array( 'type' => 'boolean', 'description' => 'Reset all default roles to WordPress defaults.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'reset' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$all = ! empty( $input['all'] );
			if ( ! $all && empty( $input['role'] ) ) {
				return wpcli_pack_err( 'role_required', 'Provide a role key or set all=true.' );
			}
			$default_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
			$targets       = $all ? $default_roles : array( (string) $input['role'] );
			if ( ! $all && ! in_array( $targets[0], $default_roles, true ) ) {
				return wpcli_pack_err( 'not_default_role', sprintf( 'Role "%s" is not a default WordPress role and cannot be reset.', $targets[0] ) );
			}
			// Remove the targeted roles so populate_roles() re-creates them with default caps.
			$wp_roles = wp_roles();
			foreach ( $targets as $key ) {
				if ( null !== $wp_roles->get_role( $key ) ) {
					$wp_roles->remove_role( $key );
				}
			}
			require_once ABSPATH . 'wp-admin/includes/schema.php';
			populate_roles();
			return array( 'reset' => $targets );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cap-list',
		'label'            => 'List Capabilities',
		'description'      => 'List the capabilities granted to a role (like `wp cap list <role>`). Returns the granted capability names and a count.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role' => array( 'type' => 'string', 'description' => 'Role key, e.g. "author".' ),
			),
			'required'             => array( 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'        => array( 'type' => 'integer' ),
				'capabilities' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key  = (string) $input['role'];
			$role = get_role( $key );
			if ( null === $role ) {
				return wpcli_pack_err( 'role_not_found', sprintf( 'Role "%s" does not exist.', $key ), 404 );
			}
			$caps    = is_array( $role->capabilities ) ? $role->capabilities : array();
			$granted = array_keys( array_filter( $caps ) );
			sort( $granted );
			return array( 'count' => count( $granted ), 'capabilities' => $granted );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cap-add',
		'label'            => 'Add Capabilities',
		'description'      => 'Grant one or more capabilities to a role (like `wp cap add <role> <cap>...`). `capabilities` may be a single capability string or an array. Set `grant`=false to add the capability explicitly denied. Idempotent per capability.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'         => array( 'type' => 'string', 'description' => 'Role key to modify.' ),
				'capabilities' => array( 'description' => 'Capability name or array of names, e.g. "edit_posts" or ["edit_posts","publish_posts"].' ),
				'grant'        => array( 'type' => 'boolean', 'description' => 'Whether the capability is granted (true) or explicitly denied (false). Default true.' ),
			),
			'required'             => array( 'role', 'capabilities' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'added'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key  = (string) $input['role'];
			$role = get_role( $key );
			if ( null === $role ) {
				return wpcli_pack_err( 'role_not_found', sprintf( 'Role "%s" does not exist.', $key ), 404 );
			}
			$caps = wpcli_pack_caps_arg( $input['capabilities'] );
			if ( empty( $caps ) ) {
				return wpcli_pack_err( 'caps_required', 'Provide at least one capability.' );
			}
			$grant = ! array_key_exists( 'grant', $input ) || (bool) $input['grant'];
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap, $grant );
			}
			return array( 'success' => true, 'added' => $caps );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cap-remove',
		'label'            => 'Remove Capabilities',
		'description'      => 'Remove one or more capabilities from a role (like `wp cap remove <role> <cap>...`). `capabilities` may be a single capability string or an array. Idempotent per capability.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'         => array( 'type' => 'string', 'description' => 'Role key to modify.' ),
				'capabilities' => array( 'description' => 'Capability name or array of names to remove.' ),
			),
			'required'             => array( 'role', 'capabilities' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'removed' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$key  = (string) $input['role'];
			$role = get_role( $key );
			if ( null === $role ) {
				return wpcli_pack_err( 'role_not_found', sprintf( 'Role "%s" does not exist.', $key ), 404 );
			}
			$caps = wpcli_pack_caps_arg( $input['capabilities'] );
			if ( empty( $caps ) ) {
				return wpcli_pack_err( 'caps_required', 'Provide at least one capability.' );
			}
			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
			return array( 'success' => true, 'removed' => $caps );
		},
	)
);
