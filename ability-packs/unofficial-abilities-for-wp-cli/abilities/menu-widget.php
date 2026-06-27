<?php
/**
 * `wp menu`, `wp sidebar`, and `wp widget` — manage nav menus, menu items,
 * menu locations, sidebars, and widgets natively (no WP-CLI binary).
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_resolve_menu' ) ) {
	/**
	 * Resolve a nav menu reference (numeric term_id, slug, or name) to a WP_Term.
	 *
	 * @param mixed $ref Menu reference: id, slug, or name.
	 * @return WP_Term|null The nav_menu term, or null when not found.
	 */
	function wpcli_pack_resolve_menu( $ref ) {
		if ( is_numeric( $ref ) ) {
			$term = get_term( (int) $ref, 'nav_menu' );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}
		// wp_get_nav_menu_object accepts id, slug, or name.
		$menu = wp_get_nav_menu_object( $ref );
		return ( $menu instanceof WP_Term ) ? $menu : null;
	}
}

if ( ! function_exists( 'wpcli_pack_split_widget_id' ) ) {
	/**
	 * Split a widget id like "text-3" into array( base, index ).
	 *
	 * @param string $widget_id Widget id, e.g. "text-3".
	 * @return array{0:string,1:int}|null array( base, index ) or null when malformed.
	 */
	function wpcli_pack_split_widget_id( string $widget_id ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
			return null;
		}
		return array( $m[1], (int) $m[2] );
	}
}

/* -------------------------------------------------------------------------
 * MENUS — wp menu
 * ---------------------------------------------------------------------- */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-list',
		'label'            => 'List Nav Menus',
		'description'      => 'List all navigation menus (like `wp menu list`). Each entry includes its term_id, name, slug, item count, and the theme locations it is assigned to.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'menus' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$menus     = wp_get_nav_menus();
			$locations = get_nav_menu_locations();
			$out       = array();
			foreach ( $menus as $menu ) {
				$assigned = array();
				foreach ( $locations as $loc => $menu_id ) {
					if ( (int) $menu_id === (int) $menu->term_id ) {
						$assigned[] = $loc;
					}
				}
				$out[] = array(
					'term_id'   => (int) $menu->term_id,
					'name'      => $menu->name,
					'slug'      => $menu->slug,
					'count'     => (int) $menu->count,
					'locations' => $assigned,
				);
			}
			return array( 'count' => count( $out ), 'menus' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-create',
		'label'            => 'Create Nav Menu',
		'description'      => 'Create a new navigation menu (like `wp menu create <name>`). Returns the new menu_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array( 'type' => 'string', 'description' => 'Display name for the new menu, e.g. "Primary Navigation".' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'menu_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$menu_id = wp_create_nav_menu( (string) $input['name'] );
			if ( is_wp_error( $menu_id ) ) {
				return wpcli_pack_err( 'menu_create_failed', $menu_id->get_error_message() );
			}
			return array( 'menu_id' => (int) $menu_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-delete',
		'label'            => 'Delete Nav Menu',
		'description'      => 'Delete a navigation menu and all of its items (like `wp menu delete <menu>`). The menu may be referenced by id, slug, or name.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu' => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
			),
			'required'             => array( 'menu' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$result = wp_delete_nav_menu( $menu->term_id );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'menu_delete_failed', $result->get_error_message() );
			}
			return array( 'deleted' => (bool) $result );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-item-list',
		'label'            => 'List Menu Items',
		'description'      => 'List the items in a navigation menu (like `wp menu item list <menu>`). The menu may be referenced by id, slug, or name. Each item includes db_id, type, title, link, parent, menu_order, object, and object_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu' => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
			),
			'required'             => array( 'menu' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'items' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$items = wp_get_nav_menu_items( $menu->term_id );
			$items = is_array( $items ) ? $items : array();
			$out   = array();
			foreach ( $items as $item ) {
				$out[] = array(
					'db_id'      => (int) $item->db_id,
					'type'       => $item->type,
					'title'      => $item->title,
					'link'       => $item->url,
					'parent'     => (int) $item->menu_item_parent,
					'menu_order' => (int) $item->menu_order,
					'object'     => $item->object,
					'object_id'  => (int) $item->object_id,
				);
			}
			return array( 'count' => count( $out ), 'items' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-item-add-post',
		'label'            => 'Add Post Menu Item',
		'description'      => 'Add an existing post/page (or any post type) to a navigation menu (like `wp menu item add-post <menu> <post_id>`). Optionally override the title, set a parent menu item (its db_id), and set a 1-based position. Returns the new menu item db_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu'     => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
				'post_id'  => array( 'type' => 'integer', 'description' => 'ID of the post/page to add.' ),
				'title'    => array( 'type' => 'string', 'description' => 'Optional title override; defaults to the post title.' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'Optional db_id of the parent menu item.' ),
				'position' => array( 'type' => 'integer', 'description' => 'Optional 1-based position (menu_order) within the menu.' ),
			),
			'required'             => array( 'menu', 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'db_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$post = get_post( (int) $input['post_id'] );
			if ( ! $post ) {
				return wpcli_pack_err( 'post_not_found', sprintf( 'Post %d not found.', (int) $input['post_id'] ), 404 );
			}
			$args = array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => get_post_type( $post ),
				'menu-item-object-id' => (int) $post->ID,
				'menu-item-status'    => 'publish',
			);
			if ( isset( $input['title'] ) ) {
				$args['menu-item-title'] = (string) $input['title'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['menu-item-parent-id'] = (int) $input['parent'];
			}
			if ( isset( $input['position'] ) ) {
				$args['menu-item-position'] = (int) $input['position'];
			}
			$db_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
			if ( is_wp_error( $db_id ) ) {
				return wpcli_pack_err( 'menu_item_add_failed', $db_id->get_error_message() );
			}
			return array( 'db_id' => (int) $db_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-item-add-term',
		'label'            => 'Add Term Menu Item',
		'description'      => 'Add a taxonomy term (category, tag, etc.) to a navigation menu (like `wp menu item add-term <menu> <taxonomy> <term_id>`). Optionally override title, set a parent menu item db_id, and a 1-based position. Returns the new menu item db_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu'     => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category" or "post_tag".' ),
				'term_id'  => array( 'type' => 'integer', 'description' => 'ID of the term to add.' ),
				'title'    => array( 'type' => 'string', 'description' => 'Optional title override; defaults to the term name.' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'Optional db_id of the parent menu item.' ),
				'position' => array( 'type' => 'integer', 'description' => 'Optional 1-based position (menu_order) within the menu.' ),
			),
			'required'             => array( 'menu', 'taxonomy', 'term_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'db_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$taxonomy = (string) $input['taxonomy'];
			$term     = get_term( (int) $input['term_id'], $taxonomy );
			if ( ! ( $term instanceof WP_Term ) ) {
				return wpcli_pack_err( 'term_not_found', sprintf( 'Term %d not found in taxonomy "%s".', (int) $input['term_id'], $taxonomy ), 404 );
			}
			$args = array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => $taxonomy,
				'menu-item-object-id' => (int) $term->term_id,
				'menu-item-status'    => 'publish',
			);
			if ( isset( $input['title'] ) ) {
				$args['menu-item-title'] = (string) $input['title'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['menu-item-parent-id'] = (int) $input['parent'];
			}
			if ( isset( $input['position'] ) ) {
				$args['menu-item-position'] = (int) $input['position'];
			}
			$db_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
			if ( is_wp_error( $db_id ) ) {
				return wpcli_pack_err( 'menu_item_add_failed', $db_id->get_error_message() );
			}
			return array( 'db_id' => (int) $db_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-item-add-custom',
		'label'            => 'Add Custom Menu Item',
		'description'      => 'Add a custom link to a navigation menu (like `wp menu item add-custom <menu> <title> <link>`). Optionally set a parent menu item db_id and a 1-based position. Returns the new menu item db_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu'     => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
				'title'    => array( 'type' => 'string', 'description' => 'Link text shown in the menu.' ),
				'link'     => array( 'type' => 'string', 'description' => 'Destination URL for the custom link.' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'Optional db_id of the parent menu item.' ),
				'position' => array( 'type' => 'integer', 'description' => 'Optional 1-based position (menu_order) within the menu.' ),
			),
			'required'             => array( 'menu', 'title', 'link' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'db_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$args = array(
				'menu-item-type'   => 'custom',
				'menu-item-title'  => (string) $input['title'],
				'menu-item-url'    => (string) $input['link'],
				'menu-item-status' => 'publish',
			);
			if ( isset( $input['parent'] ) ) {
				$args['menu-item-parent-id'] = (int) $input['parent'];
			}
			if ( isset( $input['position'] ) ) {
				$args['menu-item-position'] = (int) $input['position'];
			}
			$db_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
			if ( is_wp_error( $db_id ) ) {
				return wpcli_pack_err( 'menu_item_add_failed', $db_id->get_error_message() );
			}
			return array( 'db_id' => (int) $db_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-item-delete',
		'label'            => 'Delete Menu Item',
		'description'      => 'Remove a single item from a navigation menu by its db_id (like `wp menu item delete <db_id>`).',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'db_id' => array( 'type' => 'integer', 'description' => 'The menu item db_id (from menu-item-list).' ),
			),
			'required'             => array( 'db_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
			$db_id = (int) $input['db_id'];
			$item  = get_post( $db_id );
			if ( ! $item || 'nav_menu_item' !== get_post_type( $item ) ) {
				return wpcli_pack_err( 'menu_item_not_found', sprintf( 'Menu item %d not found.', $db_id ), 404 );
			}
			$result = wp_delete_post( $db_id, true );
			return array( 'deleted' => (bool) $result );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-location-list',
		'label'            => 'List Menu Locations',
		'description'      => 'List the navigation menu locations registered by the active theme (like `wp menu location list`). Each entry includes the location slug, its human description, and the menu currently assigned to it (0 if none).',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'     => array( 'type' => 'integer' ),
				'locations' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$registered = get_registered_nav_menus();
			$assigned   = get_nav_menu_locations();
			$out        = array();
			foreach ( $registered as $location => $description ) {
				$out[] = array(
					'location'      => $location,
					'description'   => $description,
					'assigned_menu' => isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0,
				);
			}
			return array( 'count' => count( $out ), 'locations' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-location-assign',
		'label'            => 'Assign Menu Location',
		'description'      => 'Assign a navigation menu to a theme location (like `wp menu location assign <menu> <location>`). The menu may be referenced by id, slug, or name.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'menu'     => array( 'type' => 'string', 'description' => 'Menu reference: numeric id, slug, or name.' ),
				'location' => array( 'type' => 'string', 'description' => 'Theme location slug (see menu-location-list).' ),
			),
			'required'             => array( 'menu', 'location' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$menu = wpcli_pack_resolve_menu( $input['menu'] );
			if ( ! $menu ) {
				return wpcli_pack_err( 'menu_not_found', sprintf( 'Menu "%s" not found.', (string) $input['menu'] ), 404 );
			}
			$location   = (string) $input['location'];
			$registered = get_registered_nav_menus();
			if ( ! isset( $registered[ $location ] ) ) {
				return wpcli_pack_err( 'location_not_found', sprintf( 'Location "%s" is not registered by the active theme.', $location ), 404 );
			}
			$locations              = get_nav_menu_locations();
			$locations[ $location ] = (int) $menu->term_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/menu-location-remove',
		'label'            => 'Remove Menu Location',
		'description'      => 'Remove the menu assigned to a theme location, leaving the location empty (like `wp menu location remove <menu> <location>`). Idempotent.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'location' => array( 'type' => 'string', 'description' => 'Theme location slug to clear.' ),
			),
			'required'             => array( 'location' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$location  = (string) $input['location'];
			$locations = get_nav_menu_locations();
			if ( isset( $locations[ $location ] ) ) {
				unset( $locations[ $location ] );
				set_theme_mod( 'nav_menu_locations', $locations );
			}
			return array( 'success' => true );
		},
	)
);

/* -------------------------------------------------------------------------
 * SIDEBARS — wp sidebar
 * ---------------------------------------------------------------------- */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/sidebar-list',
		'label'            => 'List Sidebars',
		'description'      => 'List the registered sidebars / widget areas (like `wp sidebar list`). Each entry includes the sidebar id, name, description, and how many widgets it currently holds.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'    => array( 'type' => 'integer' ),
				'sidebars' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_registered_sidebars;
			$registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
			$widgets    = wp_get_sidebars_widgets();
			$out        = array();
			foreach ( $registered as $id => $sidebar ) {
				$ids   = isset( $widgets[ $id ] ) && is_array( $widgets[ $id ] ) ? $widgets[ $id ] : array();
				$out[] = array(
					'id'           => (string) $id,
					'name'         => isset( $sidebar['name'] ) ? (string) $sidebar['name'] : (string) $id,
					'description'  => isset( $sidebar['description'] ) ? (string) $sidebar['description'] : '',
					'widget_count' => count( $ids ),
				);
			}
			return array( 'count' => count( $out ), 'sidebars' => $out );
		},
	)
);

/* -------------------------------------------------------------------------
 * WIDGETS — wp widget
 * ---------------------------------------------------------------------- */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-list',
		'label'            => 'List Widgets',
		'description'      => 'List widgets in a sidebar, or all sidebars (like `wp widget list <sidebar>`). Each entry includes the widget id (e.g. "text-3"), its base type name, the sidebar it lives in, its 0-based position, and its saved settings.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'sidebar' => array( 'type' => 'string', 'description' => 'Optional sidebar id to filter by (e.g. "sidebar-1"). Omit to list all sidebars.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'   => array( 'type' => 'integer' ),
				'widgets' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$sidebars = wp_get_sidebars_widgets();
			$filter   = isset( $input['sidebar'] ) ? (string) $input['sidebar'] : null;
			$out      = array();
			foreach ( $sidebars as $sidebar_id => $widget_ids ) {
				if ( 'array_version' === $sidebar_id || ! is_array( $widget_ids ) ) {
					continue;
				}
				if ( null !== $filter && $sidebar_id !== $filter ) {
					continue;
				}
				$position = 0;
				foreach ( $widget_ids as $widget_id ) {
					$parsed = wpcli_pack_split_widget_id( (string) $widget_id );
					$settings = null;
					$base     = '';
					if ( $parsed ) {
						list( $base, $index ) = $parsed;
						$opt = get_option( 'widget_' . $base, array() );
						if ( is_array( $opt ) && isset( $opt[ $index ] ) ) {
							$settings = $opt[ $index ];
						}
					}
					$out[] = array(
						'id'       => (string) $widget_id,
						'name'     => $base,
						'sidebar'  => (string) $sidebar_id,
						'position' => $position,
						'settings' => $settings,
					);
					$position++;
				}
			}
			return array( 'count' => count( $out ), 'widgets' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-add',
		'label'            => 'Add Widget',
		'description'      => 'Add a new widget to a sidebar (like `wp widget add <name> <sidebar> [position] [--field=value]`). `name` is the widget base type (e.g. "text", "nav_menu"). Allocates the next index for that type, stores `settings`, and inserts it at the optional 1-based `position`. Returns the new widget_id (e.g. "text-4").',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'     => array( 'type' => 'string', 'description' => 'Widget base type, e.g. "text", "categories", "nav_menu".' ),
				'sidebar'  => array( 'type' => 'string', 'description' => 'Target sidebar id, e.g. "sidebar-1".' ),
				'position' => array( 'type' => 'integer', 'description' => 'Optional 1-based insertion position within the sidebar. Defaults to the end.' ),
				'settings' => array( 'type' => 'object', 'description' => 'Widget option fields (varies per widget type), e.g. {"title":"Hi","text":"..."}.' ),
			),
			'required'             => array( 'name', 'sidebar' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'widget_id' => array( 'type' => 'string' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/widgets.php';
			global $wp_registered_sidebars;
			$base       = (string) $input['name'];
			$sidebar_id = (string) $input['sidebar'];

			$registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
			if ( 'wp_inactive_widgets' !== $sidebar_id && ! isset( $registered[ $sidebar_id ] ) ) {
				return wpcli_pack_err( 'sidebar_not_found', sprintf( 'Sidebar "%s" is not registered.', $sidebar_id ), 404 );
			}

			$opt = get_option( 'widget_' . $base, array() );
			if ( ! is_array( $opt ) ) {
				$opt = array();
			}
			// Determine next index (ignore the _multiwidget flag).
			$index = 1;
			foreach ( $opt as $k => $v ) {
				if ( is_numeric( $k ) && (int) $k >= $index ) {
					$index = (int) $k + 1;
				}
			}
			$settings           = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
			$opt[ $index ]      = $settings;
			$opt['_multiwidget'] = 1;
			update_option( 'widget_' . $base, $opt );

			$widget_id = $base . '-' . $index;
			$sidebars  = wp_get_sidebars_widgets();
			if ( ! isset( $sidebars[ $sidebar_id ] ) || ! is_array( $sidebars[ $sidebar_id ] ) ) {
				$sidebars[ $sidebar_id ] = array();
			}
			$list = $sidebars[ $sidebar_id ];
			if ( isset( $input['position'] ) ) {
				$pos = max( 0, (int) $input['position'] - 1 );
				array_splice( $list, $pos, 0, array( $widget_id ) );
			} else {
				$list[] = $widget_id;
			}
			$sidebars[ $sidebar_id ] = $list;
			wp_set_sidebars_widgets( $sidebars );

			return array( 'widget_id' => $widget_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-update',
		'label'            => 'Update Widget',
		'description'      => 'Update the settings of an existing widget (like `wp widget update <widget_id> --field=value`). The provided `settings` are merged into the widget\'s existing options. Identify the widget by id, e.g. "text-3".',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_id' => array( 'type' => 'string', 'description' => 'Widget id, e.g. "text-3".' ),
				'settings'  => array( 'type' => 'object', 'description' => 'Option fields to merge into the widget\'s current settings.' ),
			),
			'required'             => array( 'widget_id', 'settings' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$parsed = wpcli_pack_split_widget_id( (string) $input['widget_id'] );
			if ( ! $parsed ) {
				return wpcli_pack_err( 'bad_widget_id', sprintf( 'Malformed widget id "%s".', (string) $input['widget_id'] ) );
			}
			list( $base, $index ) = $parsed;
			$opt = get_option( 'widget_' . $base, array() );
			if ( ! is_array( $opt ) || ! isset( $opt[ $index ] ) ) {
				return wpcli_pack_err( 'widget_not_found', sprintf( 'Widget "%s" not found.', (string) $input['widget_id'] ), 404 );
			}
			$settings      = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
			$current       = is_array( $opt[ $index ] ) ? $opt[ $index ] : array();
			$opt[ $index ] = array_merge( $current, $settings );
			update_option( 'widget_' . $base, $opt );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-delete',
		'label'            => 'Delete Widget',
		'description'      => 'Permanently delete a widget (like `wp widget delete <widget_id>`). Removes it from its sidebar and discards its saved settings. Identify the widget by id, e.g. "text-3".',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_id' => array( 'type' => 'string', 'description' => 'Widget id, e.g. "text-3".' ),
			),
			'required'             => array( 'widget_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$widget_id = (string) $input['widget_id'];
			$parsed    = wpcli_pack_split_widget_id( $widget_id );
			if ( ! $parsed ) {
				return wpcli_pack_err( 'bad_widget_id', sprintf( 'Malformed widget id "%s".', $widget_id ) );
			}
			list( $base, $index ) = $parsed;

			// Remove from all sidebars.
			$sidebars = wp_get_sidebars_widgets();
			foreach ( $sidebars as $sid => $ids ) {
				if ( ! is_array( $ids ) ) {
					continue;
				}
				$key = array_search( $widget_id, $ids, true );
				if ( false !== $key ) {
					unset( $sidebars[ $sid ][ $key ] );
					$sidebars[ $sid ] = array_values( $sidebars[ $sid ] );
				}
			}
			wp_set_sidebars_widgets( $sidebars );

			// Remove the stored settings.
			$opt = get_option( 'widget_' . $base, array() );
			if ( is_array( $opt ) && isset( $opt[ $index ] ) ) {
				unset( $opt[ $index ] );
				update_option( 'widget_' . $base, $opt );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-move',
		'label'            => 'Move Widget',
		'description'      => 'Move a widget to another sidebar and/or a new position (like `wp widget move <widget_id> --sidebar-id=... --position=...`). Identify the widget by id, e.g. "text-3".',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_id' => array( 'type' => 'string', 'description' => 'Widget id to move, e.g. "text-3".' ),
				'sidebar'   => array( 'type' => 'string', 'description' => 'Target sidebar id, e.g. "sidebar-2".' ),
				'position'  => array( 'type' => 'integer', 'description' => 'Optional 1-based position within the target sidebar. Defaults to the end.' ),
			),
			'required'             => array( 'widget_id', 'sidebar' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_registered_sidebars;
			$widget_id = (string) $input['widget_id'];
			$target    = (string) $input['sidebar'];

			$registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
			if ( 'wp_inactive_widgets' !== $target && ! isset( $registered[ $target ] ) ) {
				return wpcli_pack_err( 'sidebar_not_found', sprintf( 'Sidebar "%s" is not registered.', $target ), 404 );
			}

			$sidebars = wp_get_sidebars_widgets();
			$found    = false;
			foreach ( $sidebars as $sid => $ids ) {
				if ( ! is_array( $ids ) ) {
					continue;
				}
				$key = array_search( $widget_id, $ids, true );
				if ( false !== $key ) {
					unset( $sidebars[ $sid ][ $key ] );
					$sidebars[ $sid ] = array_values( $sidebars[ $sid ] );
					$found            = true;
				}
			}
			if ( ! $found ) {
				return wpcli_pack_err( 'widget_not_found', sprintf( 'Widget "%s" not found in any sidebar.', $widget_id ), 404 );
			}
			if ( ! isset( $sidebars[ $target ] ) || ! is_array( $sidebars[ $target ] ) ) {
				$sidebars[ $target ] = array();
			}
			$list = $sidebars[ $target ];
			if ( isset( $input['position'] ) ) {
				$pos = max( 0, (int) $input['position'] - 1 );
				array_splice( $list, $pos, 0, array( $widget_id ) );
			} else {
				$list[] = $widget_id;
			}
			$sidebars[ $target ] = $list;
			wp_set_sidebars_widgets( $sidebars );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-deactivate',
		'label'            => 'Deactivate Widget',
		'description'      => 'Deactivate a widget by moving it to the inactive widgets store (like `wp widget deactivate <widget_id>`). The widget keeps its settings and can be re-added later. Identify it by id, e.g. "text-3".',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_id' => array( 'type' => 'string', 'description' => 'Widget id to deactivate, e.g. "text-3".' ),
			),
			'required'             => array( 'widget_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$widget_id = (string) $input['widget_id'];
			$sidebars  = wp_get_sidebars_widgets();
			$found     = false;
			foreach ( $sidebars as $sid => $ids ) {
				if ( 'wp_inactive_widgets' === $sid || ! is_array( $ids ) ) {
					continue;
				}
				$key = array_search( $widget_id, $ids, true );
				if ( false !== $key ) {
					unset( $sidebars[ $sid ][ $key ] );
					$sidebars[ $sid ] = array_values( $sidebars[ $sid ] );
					$found            = true;
				}
			}
			if ( ! $found ) {
				return wpcli_pack_err( 'widget_not_found', sprintf( 'Active widget "%s" not found.', $widget_id ), 404 );
			}
			if ( ! isset( $sidebars['wp_inactive_widgets'] ) || ! is_array( $sidebars['wp_inactive_widgets'] ) ) {
				$sidebars['wp_inactive_widgets'] = array();
			}
			$sidebars['wp_inactive_widgets'][] = $widget_id;
			wp_set_sidebars_widgets( $sidebars );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/widget-reset',
		'label'            => 'Reset Sidebar Widgets',
		'description'      => 'Clear a sidebar by moving all of its widgets to the inactive store (like `wp widget reset <sidebar>` / `wp widget reset --all`). Provide either a `sidebar` id or set `all` to true to reset every active sidebar.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'sidebar' => array( 'type' => 'string', 'description' => 'Sidebar id to reset, e.g. "sidebar-1". Ignored when `all` is true.' ),
				'all'     => array( 'type' => 'boolean', 'description' => 'Reset every active sidebar.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$all = ! empty( $input['all'] );
			if ( ! $all && empty( $input['sidebar'] ) ) {
				return wpcli_pack_err( 'sidebar_required', 'Provide a "sidebar" id or set "all" to true.' );
			}
			$sidebars = wp_get_sidebars_widgets();
			if ( ! isset( $sidebars['wp_inactive_widgets'] ) || ! is_array( $sidebars['wp_inactive_widgets'] ) ) {
				$sidebars['wp_inactive_widgets'] = array();
			}
			$targets = array();
			if ( $all ) {
				foreach ( $sidebars as $sid => $ids ) {
					if ( 'wp_inactive_widgets' !== $sid && 'array_version' !== $sid && is_array( $ids ) ) {
						$targets[] = $sid;
					}
				}
			} else {
				$sid = (string) $input['sidebar'];
				if ( ! isset( $sidebars[ $sid ] ) ) {
					return wpcli_pack_err( 'sidebar_not_found', sprintf( 'Sidebar "%s" not found.', $sid ), 404 );
				}
				$targets[] = $sid;
			}
			foreach ( $targets as $sid ) {
				$ids = is_array( $sidebars[ $sid ] ) ? $sidebars[ $sid ] : array();
				foreach ( $ids as $widget_id ) {
					$sidebars['wp_inactive_widgets'][] = $widget_id;
				}
				$sidebars[ $sid ] = array();
			}
			wp_set_sidebars_widgets( $sidebars );
			return array( 'success' => true );
		},
	)
);
