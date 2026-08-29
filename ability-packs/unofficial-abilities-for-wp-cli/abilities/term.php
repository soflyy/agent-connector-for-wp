<?php
/**
 * `wp term` + `wp term meta` — read and write taxonomy terms and their metadata.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-get',
		'label'            => 'Get Term',
		'description'      => 'Get a single taxonomy term by ID or slug (like `wp term get <taxonomy> <term>`). `term` accepts a numeric term ID or a slug. When `field` is given, only that column is returned in `value`. `found` is false when the term does not exist.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category", "post_tag".' ),
				'term'     => array( 'type' => array( 'string', 'integer' ), 'description' => 'Term ID (numeric) or slug. Accepts a number or a string.' ),
				'field'    => array( 'type' => 'string', 'description' => 'Optional. Return only this field (e.g. "name", "slug", "count").' ),
			),
			'required'             => array( 'taxonomy', 'term' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'term'  => array( 'type' => 'object', 'description' => 'The term row. Null when not found.' ),
				'value' => array( 'description' => 'Single field value when `field` is given.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$term_ref = (string) $input['term'];
			if ( is_numeric( $term_ref ) ) {
				$term = get_term( (int) $term_ref, $taxonomy );
			} else {
				$term = get_term_by( 'slug', $term_ref, $taxonomy );
			}
			if ( ! $term instanceof WP_Term ) {
				return array( 'found' => false, 'term' => null, 'value' => null );
			}
			$row = wpcli_pack_term_row( $term );
			if ( ! empty( $input['field'] ) ) {
				$field = (string) $input['field'];
				if ( ! array_key_exists( $field, $row ) ) {
					return wpcli_pack_err( 'invalid_field', sprintf( 'Unknown field "%s".', $field ) );
				}
				return array( 'found' => true, 'term' => $row, 'value' => $row[ $field ] );
			}
			return array( 'found' => true, 'term' => $row, 'value' => null );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-list',
		'label'            => 'List Terms',
		'description'      => 'List terms in a taxonomy (like `wp term list <taxonomy>`). Optional `search`, `hide_empty` (default false), `parent`, `orderby`, `order`, `number` (default 100; 0 returns all), and `offset`. `fields` limits the columns of each returned term.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'   => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category".' ),
				'search'     => array( 'type' => 'string', 'description' => 'Match terms whose name contains this string.' ),
				'hide_empty' => array( 'type' => 'boolean', 'description' => 'Hide terms with no assigned objects. Default false.' ),
				'parent'     => array( 'type' => 'integer', 'description' => 'Only direct children of this parent term ID.' ),
				'orderby'    => array( 'type' => 'string', 'description' => 'Order by column (name, slug, term_id, count, ...). Default name.' ),
				'order'      => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Sort direction. Default ASC.' ),
				'number'     => array( 'type' => 'integer', 'description' => 'Max terms to return. Default 100; 0 returns all.' ),
				'offset'     => array( 'type' => 'integer', 'description' => 'Number of terms to skip.' ),
				'fields'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of columns to keep for each term.' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'terms' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! empty( $input['hide_empty'] ),
			);
			if ( ! empty( $input['search'] ) ) {
				$args['search'] = (string) $input['search'];
			}
			if ( array_key_exists( 'parent', $input ) && null !== $input['parent'] ) {
				$args['parent'] = (int) $input['parent'];
			}
			if ( ! empty( $input['orderby'] ) ) {
				$args['orderby'] = (string) $input['orderby'];
			}
			if ( ! empty( $input['order'] ) ) {
				$args['order'] = ( 'DESC' === strtoupper( (string) $input['order'] ) ) ? 'DESC' : 'ASC';
			}
			$number = array_key_exists( 'number', $input ) ? (int) $input['number'] : 100;
			if ( $number > 0 ) {
				$args['number'] = $number;
			}
			if ( ! empty( $input['offset'] ) ) {
				$args['offset'] = (int) $input['offset'];
			}
			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) {
				return wpcli_pack_err( 'term_list_failed', $terms->get_error_message() );
			}
			$fields = isset( $input['fields'] ) ? array_values( (array) $input['fields'] ) : array();
			$out    = array();
			foreach ( (array) $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$out[] = wpcli_pack_pick( wpcli_pack_term_row( $term ), $fields );
			}
			return array( 'count' => count( $out ), 'terms' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-create',
		'label'            => 'Create Term',
		'description'      => 'Create a new term in a taxonomy (like `wp term create <taxonomy> <name>`). Optional `slug`, `description`, and `parent` (term ID, hierarchical taxonomies only). Fails if a matching term already exists.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category".' ),
				'name'        => array( 'type' => 'string', 'description' => 'Human-readable term name.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'Optional URL-friendly slug.' ),
				'description' => array( 'type' => 'string', 'description' => 'Optional term description.' ),
				'parent'     => array( 'type' => 'integer', 'description' => 'Optional parent term ID (hierarchical taxonomies).' ),
			),
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'term_id'          => array( 'type' => 'integer' ),
				'term_taxonomy_id' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$args = array();
			if ( isset( $input['slug'] ) ) {
				$args['slug'] = (string) $input['slug'];
			}
			if ( isset( $input['description'] ) ) {
				$args['description'] = (string) $input['description'];
			}
			if ( array_key_exists( 'parent', $input ) && null !== $input['parent'] ) {
				$args['parent'] = (int) $input['parent'];
			}
			$result = wp_insert_term( (string) $input['name'], $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'term_create_failed', $result->get_error_message() );
			}
			return array(
				'term_id'          => (int) $result['term_id'],
				'term_taxonomy_id' => (int) $result['term_taxonomy_id'],
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-update',
		'label'            => 'Update Term',
		'description'      => 'Update an existing term by ID (like `wp term update <taxonomy> <term-id>`). Only the supplied fields (`name`, `slug`, `description`, `parent`) are changed.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy name.' ),
				'term'        => array( 'type' => 'integer', 'description' => 'Term ID to update.' ),
				'name'        => array( 'type' => 'string', 'description' => 'New name.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'New slug.' ),
				'description' => array( 'type' => 'string', 'description' => 'New description.' ),
				'parent'     => array( 'type' => 'integer', 'description' => 'New parent term ID (0 for top level).' ),
			),
			'required'             => array( 'taxonomy', 'term' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'term_id' => array( 'type' => 'integer' ),
				'updated' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$term_id = (int) $input['term'];
			$term    = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof WP_Term ) {
				return wpcli_pack_err( 'term_not_found', sprintf( 'Term %d does not exist in taxonomy "%s".', $term_id, $taxonomy ), 404 );
			}
			$args = array();
			if ( isset( $input['name'] ) ) {
				$args['name'] = (string) $input['name'];
			}
			if ( isset( $input['slug'] ) ) {
				$args['slug'] = (string) $input['slug'];
			}
			if ( isset( $input['description'] ) ) {
				$args['description'] = (string) $input['description'];
			}
			if ( array_key_exists( 'parent', $input ) && null !== $input['parent'] ) {
				$args['parent'] = (int) $input['parent'];
			}
			if ( empty( $args ) ) {
				return array( 'term_id' => $term_id, 'updated' => false );
			}
			$result = wp_update_term( $term_id, $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'term_update_failed', $result->get_error_message() );
			}
			return array( 'term_id' => (int) $result['term_id'], 'updated' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-delete',
		'label'            => 'Delete Term',
		'description'      => 'Delete a term by ID (like `wp term delete <taxonomy> <term-id>`). Returns deleted=false if the term did not exist.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name.' ),
				'term'     => array( 'type' => 'integer', 'description' => 'Term ID to delete.' ),
			),
			'required'             => array( 'taxonomy', 'term' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$term_id = (int) $input['term'];
			$result  = wp_delete_term( $term_id, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'term_delete_failed', $result->get_error_message() );
			}
			return array( 'deleted' => (bool) $result );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-exists',
		'label'            => 'Term Exists',
		'description'      => 'Check whether a term exists in a taxonomy by ID or slug (like `wp term get` used as a check). Returns exists and the resolved term_id.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name.' ),
				'term'     => array( 'type' => 'string', 'description' => 'Term ID (numeric) or slug.' ),
			),
			'required'             => array( 'taxonomy', 'term' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'exists'  => array( 'type' => 'boolean' ),
				'term_id' => array( 'type' => 'integer', 'description' => '0 when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$term_ref = (string) $input['term'];
			if ( is_numeric( $term_ref ) ) {
				$term = get_term( (int) $term_ref, $taxonomy );
				if ( $term instanceof WP_Term ) {
					return array( 'exists' => true, 'term_id' => (int) $term->term_id );
				}
				return array( 'exists' => false, 'term_id' => 0 );
			}
			$result = term_exists( $term_ref, $taxonomy );
			if ( empty( $result ) ) {
				return array( 'exists' => false, 'term_id' => 0 );
			}
			$term_id = is_array( $result ) ? (int) $result['term_id'] : (int) $result;
			return array( 'exists' => true, 'term_id' => $term_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-recount',
		'label'            => 'Recount Terms',
		'description'      => 'Recalculate the object count for every term in a taxonomy (like `wp term recount <taxonomy>`). Useful after bulk imports or direct DB edits.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy whose term counts to recalculate.' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'   => array( 'type' => 'boolean' ),
				'recounted' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$tt_ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'tt_ids',
				)
			);
			if ( is_wp_error( $tt_ids ) ) {
				return wpcli_pack_err( 'term_recount_failed', $tt_ids->get_error_message() );
			}
			$tt_ids = array_map( 'intval', (array) $tt_ids );
			if ( $tt_ids ) {
				wp_update_term_count_now( $tt_ids, $taxonomy );
			}
			return array( 'success' => true, 'recounted' => count( $tt_ids ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-generate',
		'label'            => 'Generate Terms',
		'description'      => 'Create several placeholder terms at once for testing (like `wp term generate <taxonomy> --count=N`). Names are "<name_prefix> <n>". Returns the created term IDs.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy to populate.' ),
				'count'       => array( 'type' => 'integer', 'description' => 'How many terms to create. Default 5.' ),
				'name_prefix' => array( 'type' => 'string', 'description' => 'Prefix for generated term names. Default "Term".' ),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'created' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$taxonomy = (string) $input['taxonomy'];
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return wpcli_pack_err( 'invalid_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), 404 );
			}
			$count  = isset( $input['count'] ) ? max( 0, (int) $input['count'] ) : 5;
			$prefix = isset( $input['name_prefix'] ) ? (string) $input['name_prefix'] : 'Term';
			$created = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$name   = sprintf( '%s %d', $prefix, $i );
				$result = wp_insert_term( $name, $taxonomy );
				if ( is_wp_error( $result ) ) {
					continue;
				}
				$created[] = (int) $result['term_id'];
			}
			return array( 'created' => $created );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-meta-get',
		'label'            => 'Get Term Meta',
		'description'      => 'Get a term meta value (like `wp term meta get <term-id> <key>`). With single=true (default) returns the single value; single=false returns all values for the key as an array. `found` is false when no meta exists.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'term'   => array( 'type' => 'integer', 'description' => 'Term ID.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'single' => array( 'type' => 'boolean', 'description' => 'Return one value (true, default) or all values (false).' ),
			),
			'required'             => array( 'term', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The meta value (native type).' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$term_id = (int) $input['term'];
			$key     = (string) $input['key'];
			$single  = array_key_exists( 'single', $input ) ? (bool) $input['single'] : true;
			$meta_type = wpcli_pack_meta_type( 'term' );
			if ( ! metadata_exists( $meta_type, $term_id, $key ) ) {
				return array( 'found' => false, 'value' => null );
			}
			$value = get_metadata( $meta_type, $term_id, $key, $single );
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-meta-list',
		'label'            => 'List Term Meta',
		'description'      => 'List all meta key/value pairs for a term (like `wp term meta list <term-id>`). Values are returned unserialized.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'term' => array( 'type' => 'integer', 'description' => 'Term ID.' ),
			),
			'required'             => array( 'term' ),
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
			$term_id = (int) $input['term'];
			$all     = get_metadata( wpcli_pack_meta_type( 'term' ), $term_id );
			$all     = is_array( $all ) ? $all : array();
			$out     = array();
			foreach ( $all as $key => $values ) {
				foreach ( (array) $values as $value ) {
					$out[] = array( 'key' => (string) $key, 'value' => maybe_unserialize( $value ) );
				}
			}
			return array( 'count' => count( $out ), 'meta' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-meta-add',
		'label'            => 'Add Term Meta',
		'description'      => 'Add a term meta value (like `wp term meta add <term-id> <key> <value>`). `value` may be any JSON type. With unique=true the add fails if the key already has a value.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'term'   => array( 'type' => 'integer', 'description' => 'Term ID.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value'  => array( 'description' => 'Meta value (any JSON type).' ),
				'unique' => array( 'type' => 'boolean', 'description' => 'Only add if the key has no value yet. Default false.' ),
			),
			'required'             => array( 'term', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'meta_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$term_id = (int) $input['term'];
			$key     = (string) $input['key'];
			$value   = wpcli_pack_maybe_json( $input['value'] );
			$unique  = ! empty( $input['unique'] );
			$meta_id = add_term_meta( $term_id, $key, $value, $unique );
			if ( ! $meta_id || is_wp_error( $meta_id ) ) {
				$msg = is_wp_error( $meta_id ) ? $meta_id->get_error_message() : sprintf( 'Could not add meta "%s" to term %d.', $key, $term_id );
				return wpcli_pack_err( 'term_meta_add_failed', $msg );
			}
			return array( 'meta_id' => (int) $meta_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-meta-update',
		'label'            => 'Update Term Meta',
		'description'      => 'Create or update a term meta value (like `wp term meta update <term-id> <key> <value>`). `value` may be any JSON type. Idempotent.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'term'  => array( 'type' => 'integer', 'description' => 'Term ID.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'New meta value (any JSON type).' ),
			),
			'required'             => array( 'term', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$term_id = (int) $input['term'];
			$key     = (string) $input['key'];
			$value   = wpcli_pack_maybe_json( $input['value'] );
			$result  = update_term_meta( $term_id, $key, $value );
			if ( is_wp_error( $result ) ) {
				return wpcli_pack_err( 'term_meta_update_failed', $result->get_error_message() );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/term-meta-delete',
		'label'            => 'Delete Term Meta',
		'description'      => 'Delete a term meta key (like `wp term meta delete <term-id> <key>`). When `value` is given, only the matching value is removed; otherwise all values for the key are deleted.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'term'  => array( 'type' => 'integer', 'description' => 'Term ID.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'Optional. Only delete the entry with this exact value.' ),
			),
			'required'             => array( 'term', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$term_id = (int) $input['term'];
			$key     = (string) $input['key'];
			$value   = array_key_exists( 'value', $input ) ? wpcli_pack_maybe_json( $input['value'] ) : '';
			$deleted = delete_term_meta( $term_id, $key, $value );
			return array( 'deleted' => (bool) $deleted );
		},
	)
);
