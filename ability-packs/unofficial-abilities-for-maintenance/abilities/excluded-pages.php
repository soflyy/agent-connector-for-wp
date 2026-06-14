<?php
/**
 * Excluded-pages abilities: list candidate pages and add/remove them from the
 * maintenance-mode exclusion list (a delta verb — no full list re-send).
 *
 * @package AcfwAbilityPackMaintenance
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/list-excludable-pages',
		'label'            => 'List Excludable Pages',
		'description'      => 'List published content (pages, posts, and other public post types) that can be excluded from maintenance mode, so you know which IDs to pass to maintenance/update-excluded-pages. Mirrors what the plugin offers in its admin UI (public post types, up to 200 entries each). Each entry includes whether it is currently excluded.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'search' => array( 'type' => 'string', 'description' => 'Optional title search filter.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'pages' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$excluded   = mtnc_pack_excluded_ids( mtnc_pack_load() );
			$search     = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
			$post_types = get_post_types( array( 'show_ui' => true, 'public' => true ), 'objects' );
			$skip       = array( 'attachment', 'revision', 'nav_menu_item' );

			$pages = array();
			foreach ( $post_types as $slug => $type ) {
				if ( in_array( $slug, $skip, true ) ) {
					continue;
				}
				$args = array(
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'post_type'      => $slug,
					'post_status'    => 'publish',
				);
				if ( '' !== $search ) {
					$args['s'] = $search;
				}
				foreach ( get_posts( $args ) as $p ) {
					$pages[] = array(
						'id'        => (int) $p->ID,
						'title'     => (string) $p->post_title,
						'post_type' => (string) $slug,
						'excluded'  => in_array( (int) $p->ID, $excluded, true ),
					);
				}
			}

			return array( 'pages' => $pages );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/update-excluded-pages',
		'label'            => 'Update Excluded Pages',
		'description'      => 'Change which pages/posts stay publicly visible while maintenance mode is on. Pass add (IDs to start excluding) and/or remove (IDs to stop excluding) — a delta; pages you don\'t mention are left as-is. Set clear=true to drop all exclusions first (applied before add). Post types are resolved automatically from each ID. Returns the resulting excluded pages. Use maintenance/list-excludable-pages to find IDs.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'add'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Post/page IDs to add to the exclusion list.',
				),
				'remove' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Post/page IDs to remove from the exclusion list.',
				),
				'clear'  => array( 'type' => 'boolean', 'description' => 'Remove all current exclusions before applying add.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'excluded_pages' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$add    = isset( $input['add'] ) ? array_map( 'intval', (array) $input['add'] ) : array();
			$remove = isset( $input['remove'] ) ? array_map( 'intval', (array) $input['remove'] ) : array();
			$clear  = ! empty( $input['clear'] );

			if ( ! $add && ! $remove && ! $clear ) {
				return new WP_Error( 'mtnc_no_change', 'Provide add, remove, and/or clear.', array( 'status' => 400 ) );
			}

			// Validate IDs being added actually exist.
			foreach ( $add as $id ) {
				if ( ! get_post( $id ) ) {
					return new WP_Error( 'mtnc_bad_page', sprintf( 'No post/page with ID %d.', $id ), array( 'status' => 400 ) );
				}
			}

			$current = $clear ? array() : mtnc_pack_excluded_ids( mtnc_pack_load() );
			$current = array_diff( $current, $remove );
			$current = array_merge( $current, $add );
			$current = array_values( array_unique( array_map( 'intval', $current ) ) );

			$grouped = mtnc_pack_group_excluded( $current );
			// Store the plugin's "empty" shape when nothing remains.
			$value = $grouped ? $grouped : '';

			$saved = mtnc_pack_save( array( 'exclude_pages' => $value ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array( 'excluded_pages' => mtnc_pack_resolved_excluded( $saved ) );
		},
	)
);
