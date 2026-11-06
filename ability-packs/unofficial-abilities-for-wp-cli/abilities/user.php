<?php
/**
 * `wp user` and `wp user-meta` — manage users, roles, capabilities, meta, and sessions.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_resolve_user' ) ) {

	/**
	 * Resolve a user reference (numeric ID, login, or email) to a WP_User.
	 * Tries ID first, then login, then email. Returns null when not found.
	 *
	 * @param int|string $ref User ID, login, or email.
	 * @return WP_User|null
	 */
	function wpcli_pack_resolve_user( $ref ) {
		if ( $ref instanceof WP_User ) {
			return $ref->exists() ? $ref : null;
		}
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$by_id = get_user_by( 'id', (int) $ref );
			if ( $by_id instanceof WP_User ) {
				return $by_id;
			}
		}
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return null;
		}
		$user = get_user_by( 'login', $ref );
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$user = get_user_by( 'email', $ref );
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$user = get_user_by( 'slug', $ref );
		return $user instanceof WP_User ? $user : null;
	}
}

/**
 * wp user get
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-get',
		'label'            => 'Get User',
		'description'      => 'Get a single user by ID, login, or email (like `wp user get <user>`). The `user` ref is resolved by ID, then login, then email. Pass `field` to return just one column value. `found` is false when no user matches.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'  => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'field' => array( 'type' => 'string', 'description' => 'Optional. Return only this field from the user row (e.g. "user_email", "roles").' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'user'  => array( 'type' => 'object', 'description' => 'The user row, or null when not found.' ),
				'value' => array( 'description' => 'Present only when `field` was given.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return array( 'found' => false, 'user' => null );
			}
			$row = wpcli_pack_user_row( $user );
			if ( ! empty( $input['field'] ) ) {
				$field = (string) $input['field'];
				if ( ! array_key_exists( $field, $row ) ) {
					return wpcli_pack_err( 'invalid_field', sprintf( 'Unknown field "%s".', $field ), 400 );
				}
				return array( 'found' => true, 'value' => $row[ $field ], 'user' => $row );
			}
			return array( 'found' => true, 'user' => $row );
		},
	)
);

/**
 * wp user list
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-list',
		'label'            => 'List Users',
		'description'      => 'List users (like `wp user list`). Optional filters: `role`, free-text `search`, `orderby`/`order`, `number` (default 50, -1 for all), `offset`, and `fields` to restrict the returned columns. Returns {count, users}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'    => array( 'type' => 'string', 'description' => 'Only users with this role (e.g. "administrator", "subscriber").' ),
				'search'  => array( 'type' => 'string', 'description' => 'Search string; * may be used as a wildcard. Matches login, email, URL, nicename, display name.' ),
				'orderby' => array( 'type' => 'string', 'description' => 'Sort column: ID, login, nicename, email, registered, display_name. Default ID.' ),
				'order'   => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Sort direction. Default ASC.' ),
				'number'  => array( 'type' => 'integer', 'description' => 'Max users to return. Default 50. Use -1 for all.' ),
				'offset'  => array( 'type' => 'integer', 'description' => 'Number of users to skip.' ),
				'fields'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of user-row fields to return (e.g. ["ID","user_login","roles"]).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'users' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$args = array();
			if ( ! empty( $input['role'] ) ) {
				$args['role'] = (string) $input['role'];
			}
			if ( ! empty( $input['search'] ) ) {
				$args['search'] = (string) $input['search'];
			}
			$args['orderby'] = ! empty( $input['orderby'] ) ? (string) $input['orderby'] : 'ID';
			$args['order']   = ( ! empty( $input['order'] ) && 'DESC' === strtoupper( (string) $input['order'] ) ) ? 'DESC' : 'ASC';
			$number          = array_key_exists( 'number', $input ) ? (int) $input['number'] : 50;
			$args['number']  = ( $number < 0 ) ? -1 : $number;
			if ( isset( $input['offset'] ) ) {
				$args['offset'] = (int) $input['offset'];
			}
			$fields = array();
			if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
				$fields = array_map( 'strval', $input['fields'] );
			}
			$users = get_users( $args );
			$out   = array();
			foreach ( $users as $user ) {
				$row   = wpcli_pack_user_row( $user );
				$out[] = wpcli_pack_pick( $row, $fields );
			}
			return array( 'count' => count( $out ), 'users' => $out );
		},
	)
);

/**
 * wp user create
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-create',
		'label'            => 'Create User',
		'description'      => 'Create a new user (like `wp user create <login> <email>`). `user_login` and `user_email` are required. If `user_pass` is omitted, a strong password is generated and returned in `password`. Optional: role, display_name, first_name, last_name, user_url, send_email (notify the user), and a `meta` object of extra user-meta to set.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_login'   => array( 'type' => 'string', 'description' => 'The login name for the new user.' ),
				'user_email'   => array( 'type' => 'string', 'description' => 'Email address for the new user.' ),
				'user_pass'    => array( 'type' => 'string', 'description' => 'Plaintext password. If omitted, one is generated and returned.' ),
				'role'         => array( 'type' => 'string', 'description' => 'Role to assign (e.g. "editor"). Defaults to the site default role.' ),
				'display_name' => array( 'type' => 'string', 'description' => 'Public display name.' ),
				'first_name'   => array( 'type' => 'string', 'description' => 'First name.' ),
				'last_name'    => array( 'type' => 'string', 'description' => 'Last name.' ),
				'user_url'     => array( 'type' => 'string', 'description' => 'User website URL.' ),
				'send_email'   => array( 'type' => 'boolean', 'description' => 'Email the new user their account details. Default false.' ),
				'meta'         => array( 'type' => 'object', 'description' => 'Extra user-meta key/value pairs to set on creation.' ),
			),
			'required'             => array( 'user_login', 'user_email' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'password' => array( 'type' => 'string', 'description' => 'Only present when a password was generated.' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$login = (string) $input['user_login'];
			$email = (string) $input['user_email'];
			if ( '' === $login ) {
				return wpcli_pack_err( 'missing_login', 'user_login is required.' );
			}
			$generated = false;
			$pass      = isset( $input['user_pass'] ) ? (string) $input['user_pass'] : '';
			if ( '' === $pass ) {
				$pass      = wp_generate_password( 24, true, false );
				$generated = true;
			}
			$args = array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => $pass,
			);
			foreach ( array( 'role', 'display_name', 'first_name', 'last_name', 'user_url' ) as $key ) {
				if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
					$args[ $key ] = (string) $input[ $key ];
				}
			}
			$user_id = wp_insert_user( $args );
			if ( is_wp_error( $user_id ) ) {
				return wpcli_pack_err( 'user_create_failed', $user_id->get_error_message(), 400 );
			}
			$user_id = (int) $user_id;
			if ( ! empty( $input['meta'] ) ) {
				$meta = wpcli_pack_maybe_json( $input['meta'] );
				if ( is_array( $meta ) ) {
					foreach ( $meta as $mkey => $mval ) {
						update_user_meta( $user_id, (string) $mkey, $mval );
					}
				}
			}
			if ( ! empty( $input['send_email'] ) && function_exists( 'wp_send_new_user_notifications' ) ) {
				wp_send_new_user_notifications( $user_id, 'user' );
			}
			$result = array( 'id' => $user_id );
			if ( $generated ) {
				$result['password'] = $pass;
			}
			return $result;
		},
	)
);

/**
 * wp user update
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-update',
		'label'            => 'Update User',
		'description'      => 'Update fields on an existing user (like `wp user update <user> --<field>=<value>`). Resolve the user by ID/login/email, then pass any of: user_email, user_pass, display_name, first_name, last_name, user_url, role. Only the supplied fields are changed. Returns {id, updated} (the list of changed fields).',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'         => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'user_email'   => array( 'type' => 'string', 'description' => 'New email address.' ),
				'user_pass'    => array( 'type' => 'string', 'description' => 'New plaintext password.' ),
				'display_name' => array( 'type' => 'string', 'description' => 'New display name.' ),
				'first_name'   => array( 'type' => 'string', 'description' => 'New first name.' ),
				'last_name'    => array( 'type' => 'string', 'description' => 'New last name.' ),
				'user_url'     => array( 'type' => 'string', 'description' => 'New website URL.' ),
				'role'         => array( 'type' => 'string', 'description' => 'Replace all roles with this single role.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'updated' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$args    = array( 'ID' => (int) $user->ID );
			$updated = array();
			foreach ( array( 'user_email', 'user_pass', 'display_name', 'first_name', 'last_name', 'user_url', 'role' ) as $key ) {
				if ( array_key_exists( $key, $input ) ) {
					$args[ $key ] = (string) $input[ $key ];
					$updated[]    = $key;
				}
			}
			if ( empty( $updated ) ) {
				return array( 'id' => (int) $user->ID, 'updated' => array() );
			}
			$res = wp_update_user( $args );
			if ( is_wp_error( $res ) ) {
				return wpcli_pack_err( 'user_update_failed', $res->get_error_message(), 400 );
			}
			return array( 'id' => (int) $user->ID, 'updated' => $updated );
		},
	)
);

/**
 * wp user delete
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-delete',
		'label'            => 'Delete User',
		'description'      => 'Delete a user (like `wp user delete <user>`). Resolve by ID/login/email. Pass `reassign` (a user ID) to reassign the deleted user\'s posts/links to that user; otherwise their content is deleted. Returns {deleted}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'     => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'reassign' => array( 'type' => 'integer', 'description' => 'User ID to reassign the deleted user\'s content to. Omit to delete their content.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$reassign = isset( $input['reassign'] ) ? (int) $input['reassign'] : null;
			$deleted  = wp_delete_user( (int) $user->ID, $reassign );
			if ( ! $deleted ) {
				return wpcli_pack_err( 'user_delete_failed', sprintf( 'Could not delete user %d.', (int) $user->ID ) );
			}
			return array( 'deleted' => true );
		},
	)
);

/**
 * wp user exists
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-exists',
		'label'            => 'User Exists',
		'description'      => 'Check whether a user exists (like `wp user exists <id>`, generalized to accept login/email). Returns {exists, id}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'exists' => array( 'type' => 'boolean' ),
				'id'     => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return array( 'exists' => false, 'id' => 0 );
			}
			return array( 'exists' => true, 'id' => (int) $user->ID );
		},
	)
);

/**
 * wp user set-role
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-set-role',
		'label'            => 'Set User Role',
		'description'      => 'Replace all of a user\'s roles with a single role (like `wp user set-role <user> <role>`). Returns {success, roles} (the user\'s roles after the change).',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'role' => array( 'type' => 'string', 'description' => 'The role to set (e.g. "editor").' ),
			),
			'required'             => array( 'user', 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'roles'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$role = (string) $input['role'];
			if ( ! get_role( $role ) ) {
				return wpcli_pack_err( 'invalid_role', sprintf( 'Role "%s" does not exist.', $role ), 400 );
			}
			$user->set_role( $role );
			$fresh = new WP_User( (int) $user->ID );
			return array( 'success' => true, 'roles' => array_values( (array) $fresh->roles ) );
		},
	)
);

/**
 * wp user add-role
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-add-role',
		'label'            => 'Add User Role',
		'description'      => 'Add a role to a user without removing existing roles (like `wp user add-role <user> <role>`). Returns {success, roles}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'role' => array( 'type' => 'string', 'description' => 'The role to add.' ),
			),
			'required'             => array( 'user', 'role' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'roles'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$role = (string) $input['role'];
			if ( ! get_role( $role ) ) {
				return wpcli_pack_err( 'invalid_role', sprintf( 'Role "%s" does not exist.', $role ), 400 );
			}
			$user->add_role( $role );
			$fresh = new WP_User( (int) $user->ID );
			return array( 'success' => true, 'roles' => array_values( (array) $fresh->roles ) );
		},
	)
);

/**
 * wp user remove-role
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-remove-role',
		'label'            => 'Remove User Role',
		'description'      => 'Remove a role from a user (like `wp user remove-role <user> [<role>]`). If `role` is omitted, all roles are removed. Returns {success, roles}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'role' => array( 'type' => 'string', 'description' => 'The role to remove. Omit to remove all roles.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'roles'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			if ( empty( $input['role'] ) ) {
				foreach ( array_values( (array) $user->roles ) as $existing ) {
					$user->remove_role( $existing );
				}
			} else {
				$user->remove_role( (string) $input['role'] );
			}
			$fresh = new WP_User( (int) $user->ID );
			return array( 'success' => true, 'roles' => array_values( (array) $fresh->roles ) );
		},
	)
);

/**
 * wp user list-caps
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-list-caps',
		'label'            => 'List User Capabilities',
		'description'      => 'List a user\'s effective allowed capabilities (like `wp user list-caps <user>`), aggregated from their roles plus any directly granted caps. Returns {capabilities}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'capabilities' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$caps = array();
			foreach ( (array) $user->allcaps as $cap => $granted ) {
				if ( $granted ) {
					$caps[] = (string) $cap;
				}
			}
			return array( 'capabilities' => array_values( $caps ) );
		},
	)
);

/**
 * wp user add-cap
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-add-cap',
		'label'            => 'Add User Capability',
		'description'      => 'Grant a capability directly to a user, independent of their roles (like `wp user add-cap <user> <cap>`). Returns {success}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'cap'  => array( 'type' => 'string', 'description' => 'Capability slug to grant (e.g. "edit_posts").' ),
			),
			'required'             => array( 'user', 'cap' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$user->add_cap( (string) $input['cap'] );
			return array( 'success' => true );
		},
	)
);

/**
 * wp user remove-cap
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-remove-cap',
		'label'            => 'Remove User Capability',
		'description'      => 'Remove a capability that was granted directly to a user (like `wp user remove-cap <user> <cap>`). Does not affect caps inherited from roles. Returns {success}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'cap'  => array( 'type' => 'string', 'description' => 'Capability slug to remove.' ),
			),
			'required'             => array( 'user', 'cap' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$user->remove_cap( (string) $input['cap'] );
			return array( 'success' => true );
		},
	)
);

/**
 * wp user reset-password
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-reset-password',
		'label'            => 'Reset User Password',
		'description'      => 'Set a new password for a user (like `wp user reset-password <user>`). If `password` is omitted, a strong random one is generated. The new password is echoed back in `password` so the agent can use it. Set `send_email` to notify the user.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'       => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'password'   => array( 'type' => 'string', 'description' => 'New plaintext password. Omit to generate one.' ),
				'send_email' => array( 'type' => 'boolean', 'description' => 'Email the user a password-changed notification. Default false.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'password' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$pass = isset( $input['password'] ) ? (string) $input['password'] : '';
			if ( '' === $pass ) {
				$pass = wp_generate_password( 24, true, false );
			}
			wp_set_password( $pass, (int) $user->ID );
			if ( ! empty( $input['send_email'] ) && function_exists( 'wp_password_change_notification' ) ) {
				wp_password_change_notification( $user );
			}
			return array( 'password' => $pass );
		},
	)
);

/**
 * wp user meta get
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-meta-get',
		'label'            => 'Get User Meta',
		'description'      => 'Get a user-meta value (like `wp user meta get <user> <key>`). With `single` true (default) returns the single value; with false returns the array of all values for that key. `found` is false when the key has no stored value.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'   => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'single' => array( 'type' => 'boolean', 'description' => 'Return a single value (true, default) or all values (false).' ),
			),
			'required'             => array( 'user', 'key' ),
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
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$key    = (string) $input['key'];
			$single = array_key_exists( 'single', $input ) ? (bool) $input['single'] : true;
			$exists = metadata_exists( 'user', (int) $user->ID, $key );
			$value  = get_user_meta( (int) $user->ID, $key, $single );
			return array( 'found' => (bool) $exists, 'value' => $value );
		},
	)
);

/**
 * wp user meta list
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-meta-list',
		'label'            => 'List User Meta',
		'description'      => 'List all user-meta for a user (like `wp user meta list <user>`). Returns {count, meta} where meta is an array of {key, value} (values unserialized).',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'meta'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$all  = get_user_meta( (int) $user->ID );
			$meta = array();
			if ( is_array( $all ) ) {
				foreach ( $all as $key => $values ) {
					foreach ( (array) $values as $raw ) {
						$meta[] = array( 'key' => (string) $key, 'value' => maybe_unserialize( $raw ) );
					}
				}
			}
			return array( 'count' => count( $meta ), 'meta' => $meta );
		},
	)
);

/**
 * wp user meta add
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-meta-add',
		'label'            => 'Add User Meta',
		'description'      => 'Add a user-meta value (like `wp user meta add <user> <key> <value>`). Adds a new row even if the key already exists, unless `unique` is true (then it only adds when the key has no existing value). `value` may be any JSON type. Returns {meta_id}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'   => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value'  => array( 'description' => 'Meta value (any JSON type).' ),
				'unique' => array( 'type' => 'boolean', 'description' => 'Only add if the key does not already exist. Default false.' ),
			),
			'required'             => array( 'user', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'meta_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$value   = wpcli_pack_maybe_json( $input['value'] );
			$unique  = ! empty( $input['unique'] );
			$meta_id = add_user_meta( (int) $user->ID, (string) $input['key'], $value, $unique );
			if ( false === $meta_id ) {
				return wpcli_pack_err( 'meta_add_failed', 'Could not add user meta (it may already exist with unique=true).' );
			}
			return array( 'meta_id' => (int) $meta_id );
		},
	)
);

/**
 * wp user meta update
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-meta-update',
		'label'            => 'Update User Meta',
		'description'      => 'Create or update a user-meta value (like `wp user meta update <user> <key> <value>`). Sets the key to `value`, replacing any existing value(s). `value` may be any JSON type. Returns {success}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'  => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'New meta value (any JSON type).' ),
			),
			'required'             => array( 'user', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$value = wpcli_pack_maybe_json( $input['value'] );
			$ok    = update_user_meta( (int) $user->ID, (string) $input['key'], $value );
			return array( 'success' => ( false !== $ok ) );
		},
	)
);

/**
 * wp user meta delete
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-meta-delete',
		'label'            => 'Delete User Meta',
		'description'      => 'Delete user-meta (like `wp user meta delete <user> <key> [<value>]`). Deletes all rows for the key, or only rows matching `value` when provided. Returns {deleted}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user'  => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'Optional. Only delete rows whose value matches this (any JSON type).' ),
			),
			'required'             => array( 'user', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$value = array_key_exists( 'value', $input ) ? wpcli_pack_maybe_json( $input['value'] ) : '';
			$ok    = delete_user_meta( (int) $user->ID, (string) $input['key'], $value );
			return array( 'deleted' => (bool) $ok );
		},
	)
);

/**
 * wp user session list
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-session-list',
		'label'            => 'List User Sessions',
		'description'      => 'List a user\'s active login sessions (like `wp user session list <user>`). Returns {count, sessions} where each session includes login (timestamp), expiration (timestamp), and ip/ua when recorded.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'    => array( 'type' => 'integer' ),
				'sessions' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$manager  = WP_Session_Tokens::get_instance( (int) $user->ID );
			$sessions = $manager->get_all();
			$out      = array();
			foreach ( (array) $sessions as $session ) {
				$row = array(
					'login'      => isset( $session['login'] ) ? (int) $session['login'] : null,
					'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : null,
				);
				if ( isset( $session['ip'] ) ) {
					$row['ip'] = (string) $session['ip'];
				}
				if ( isset( $session['ua'] ) ) {
					$row['ua'] = (string) $session['ua'];
				}
				$out[] = $row;
			}
			return array( 'count' => count( $out ), 'sessions' => $out );
		},
	)
);

/**
 * wp user session destroy
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-session-destroy',
		'label'            => 'Destroy User Sessions',
		'description'      => 'Destroy a user\'s login sessions (like `wp user session destroy <user> --all`). With `all` true (default) destroys every session, logging the user out everywhere. Returns {success}.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'user' => array( 'type' => array( 'string', 'integer' ), 'description' => 'User ID, login, or email.' ),
				'all'  => array( 'type' => 'boolean', 'description' => 'Destroy all sessions. Default true.' ),
			),
			'required'             => array( 'user' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$user = wpcli_pack_resolve_user( $input['user'] );
			if ( ! $user ) {
				return wpcli_pack_err( 'user_not_found', 'No user matched the given ref.', 404 );
			}
			$manager = WP_Session_Tokens::get_instance( (int) $user->ID );
			$manager->destroy_all();
			return array( 'success' => true );
		},
	)
);
