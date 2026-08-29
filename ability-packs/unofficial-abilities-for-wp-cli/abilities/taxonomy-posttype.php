<?php
/**
 * `wp taxonomy` + `wp post-type` — introspect registered taxonomies and post types.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/taxonomy-list',
		'label'            => 'List Taxonomies',
		'description'      => 'List registered taxonomies (like `wp taxonomy list`). Optionally filter to those attached to a given `object_type` (post type), e.g. "post". Each entry reports name, label, public/hierarchical flags, and the object types it applies to.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'object_type' => array( 'type' => 'string', 'description' => 'Optional. Only taxonomies registered for this object type (post type), e.g. "post".' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'      => array( 'type' => 'integer' ),
				'taxonomies' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomies = get_taxonomies( array(), 'objects' );
			$filter     = ! empty( $input['object_type'] ) ? (string) $input['object_type'] : '';
			$out        = array();
			foreach ( $taxonomies as $tax ) {
				$object_type = array_values( (array) $tax->object_type );
				if ( '' !== $filter && ! in_array( $filter, $object_type, true ) ) {
					continue;
				}
				$out[] = array(
					'name'         => $tax->name,
					'label'        => $tax->label,
					'public'       => (bool) $tax->public,
					'hierarchical' => (bool) $tax->hierarchical,
					'object_type'  => $object_type,
					'show_ui'      => (bool) $tax->show_ui,
					'show_in_rest' => (bool) $tax->show_in_rest,
				);
			}
			return array( 'count' => count( $out ), 'taxonomies' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/taxonomy-get',
		'label'            => 'Get Taxonomy',
		'description'      => 'Get the details of a single registered taxonomy (like `wp taxonomy get <taxonomy>`). Returns its key properties: labels, public/hierarchical flags, the object types it applies to, and REST settings.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category".' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'name'         => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
				'public'       => array( 'type' => 'boolean' ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'object_type'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax instanceof WP_Taxonomy ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			return array(
				'name'              => $tax->name,
				'label'            => $tax->label,
				'labels'           => (array) $tax->labels,
				'description'      => $tax->description,
				'public'           => (bool) $tax->public,
				'hierarchical'     => (bool) $tax->hierarchical,
				'object_type'      => array_values( (array) $tax->object_type ),
				'show_ui'          => (bool) $tax->show_ui,
				'show_in_menu'     => (bool) $tax->show_in_menu,
				'show_in_rest'     => (bool) $tax->show_in_rest,
				'rest_base'        => $tax->rest_base,
				'rewrite'          => $tax->rewrite,
				'cap'              => (array) $tax->cap,
				'_builtin'         => (bool) $tax->_builtin,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-type-list',
		'label'            => 'List Post Types',
		'description'      => 'List registered post types (like `wp post-type list`). Each entry reports name, label, public/hierarchical flags, and whether it is a built-in type.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'public_only' => array( 'type' => 'boolean', 'description' => 'Optional. Only return public post types.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'      => array( 'type' => 'integer' ),
				'post_types' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$args       = ! empty( $input['public_only'] ) ? array( 'public' => true ) : array();
			$post_types = get_post_types( $args, 'objects' );
			$out        = array();
			foreach ( $post_types as $pt ) {
				$out[] = array(
					'name'         => $pt->name,
					'label'        => $pt->label,
					'public'       => (bool) $pt->public,
					'hierarchical' => (bool) $pt->hierarchical,
					'show_ui'      => (bool) $pt->show_ui,
					'show_in_rest' => (bool) $pt->show_in_rest,
					'_builtin'     => (bool) $pt->_builtin,
				);
			}
			return array( 'count' => count( $out ), 'post_types' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-type-get',
		'label'            => 'Get Post Type',
		'description'      => 'Get the details of a single registered post type (like `wp post-type get <post-type>`). Returns its key properties: labels, supports, attached taxonomies, capability type, and REST settings.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array( 'type' => 'string', 'description' => 'Post type name, e.g. "post", "page".' ),
			),
			'required'             => array( 'post_type' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'name'           => array( 'type' => 'string' ),
				'label'          => array( 'type' => 'string' ),
				'public'         => array( 'type' => 'boolean' ),
				'hierarchical'   => array( 'type' => 'boolean' ),
				'supports'       => array( 'type' => 'array' ),
				'taxonomies'     => array( 'type' => 'array' ),
				'capability_type' => array(),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$post_type = (string) $input['post_type'];
			if ( ! post_type_exists( $post_type ) ) {
				return wpcli_pack_err( 'invalid_post_type', sprintf( 'Post type "%s" does not exist.', $post_type ), 404 );
			}
			$pt = get_post_type_object( $post_type );
			if ( ! $pt instanceof WP_Post_Type ) {
				return wpcli_pack_err( 'invalid_post_type', sprintf( 'Post type "%s" does not exist.', $post_type ), 404 );
			}
			$supports = get_all_post_type_supports( $post_type );
			return array(
				'name'            => $pt->name,
				'label'           => $pt->label,
				'labels'          => (array) $pt->labels,
				'description'     => $pt->description,
				'public'          => (bool) $pt->public,
				'hierarchical'    => (bool) $pt->hierarchical,
				'supports'        => array_keys( is_array( $supports ) ? $supports : array() ),
				'taxonomies'      => array_values( (array) get_object_taxonomies( $post_type ) ),
				'capability_type' => $pt->capability_type,
				'cap'             => (array) $pt->cap,
				'menu_position'   => $pt->menu_position,
				'menu_icon'       => $pt->menu_icon,
				'has_archive'     => $pt->has_archive,
				'show_ui'         => (bool) $pt->show_ui,
				'show_in_menu'    => $pt->show_in_menu,
				'show_in_rest'    => (bool) $pt->show_in_rest,
				'rest_base'       => $pt->rest_base,
				'rewrite'         => $pt->rewrite,
				'_builtin'        => (bool) $pt->_builtin,
			);
		},
	)
);
