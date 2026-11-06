<?php
/**
 * `wp post` and `wp post-meta` — create, read, update, delete posts, their meta, and their terms.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-get',
		'label'            => 'Get Post',
		'description'      => 'Get a single post by ID (like `wp post get <id>`). Returns the full post row. Pass `field` to return only that one field. `found` is false when the post does not exist.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'field' => array( 'type' => 'string', 'description' => 'Return only this single field (e.g. "post_title"). Omit to return the whole row.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'post'  => array( 'type' => 'object', 'description' => 'The post row (omitted when field is given or not found).' ),
				'value' => array( 'description' => 'The requested single field value when `field` is given.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$post = get_post( (int) $input['id'] );
			if ( ! $post instanceof WP_Post ) {
				return array( 'found' => false, 'post' => null, 'value' => null );
			}
			$row = wpcli_pack_post_row( $post );
			if ( ! empty( $input['field'] ) ) {
				$field = (string) $input['field'];
				if ( ! array_key_exists( $field, $row ) ) {
					return wpcli_pack_err( 'invalid_field', sprintf( 'Unknown field "%s".', $field ) );
				}
				return array( 'found' => true, 'value' => $row[ $field ] );
			}
			return array( 'found' => true, 'post' => $row );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-list',
		'label'            => 'List Posts',
		'description'      => 'List posts with filters (like `wp post list`). All inputs optional: post_type (default "post", "any" for all), post_status (default "any"), author, search, name (slug), parent, orderby, order, number (default 20, -1 for all), offset, meta_key, meta_value. Use `fields` to subset columns. Returns {count, posts}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'   => array( 'type' => 'string', 'description' => 'Post type, e.g. "post", "page", or "any". Default "post".' ),
				'post_status' => array( 'type' => 'string', 'description' => 'Post status, e.g. "publish", "draft", or "any". Default "any".' ),
				'author'      => array( 'type' => 'integer', 'description' => 'Limit to posts by this author ID.' ),
				'search'      => array( 'type' => 'string', 'description' => 'Free-text search across title/content.' ),
				'name'        => array( 'type' => 'string', 'description' => 'Match by exact post slug (post_name).' ),
				'parent'      => array( 'type' => 'integer', 'description' => 'Limit to children of this post ID.' ),
				'orderby'     => array( 'type' => 'string', 'description' => 'Order field, e.g. "date", "ID", "title", "menu_order". Default "date".' ),
				'order'       => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Sort direction. Default "DESC".' ),
				'number'      => array( 'type' => 'integer', 'description' => 'Max posts to return. Default 20. Use -1 for all.' ),
				'offset'      => array( 'type' => 'integer', 'description' => 'Skip this many posts.' ),
				'fields'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of columns to return per post (e.g. ["ID","post_title"]).' ),
				'meta_key'    => array( 'type' => 'string', 'description' => 'Filter by this meta key.' ),
				'meta_value'  => array( 'description' => 'Filter by this meta value (used with meta_key).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'posts' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$args = array(
				'post_type'        => isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post',
				'post_status'      => isset( $input['post_status'] ) ? (string) $input['post_status'] : 'any',
				'posts_per_page'   => isset( $input['number'] ) ? (int) $input['number'] : 20,
				'orderby'          => isset( $input['orderby'] ) ? (string) $input['orderby'] : 'date',
				'order'            => isset( $input['order'] ) ? (string) $input['order'] : 'DESC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			);
			if ( isset( $input['offset'] ) ) {
				$args['offset'] = (int) $input['offset'];
			}
			if ( isset( $input['author'] ) ) {
				$args['author'] = (int) $input['author'];
			}
			if ( ! empty( $input['search'] ) ) {
				$args['s'] = (string) $input['search'];
			}
			if ( ! empty( $input['name'] ) ) {
				$args['name'] = (string) $input['name'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['post_parent'] = (int) $input['parent'];
			}
			if ( ! empty( $input['meta_key'] ) ) {
				$args['meta_key'] = (string) $input['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				if ( array_key_exists( 'meta_value', $input ) ) {
					$args['meta_value'] = wpcli_pack_maybe_json( $input['meta_value'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				}
			}
			$query  = new WP_Query( $args );
			$fields = isset( $input['fields'] ) ? array_map( 'strval', (array) $input['fields'] ) : array();
			$posts  = array();
			foreach ( $query->posts as $post ) {
				$posts[] = wpcli_pack_pick( wpcli_pack_post_row( $post ), $fields );
			}
			return array( 'count' => count( $posts ), 'posts' => $posts );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-create',
		'label'            => 'Create Post',
		'description'      => 'Create a new post (like `wp post create`). Maps to wp_insert_post fields. Defaults: post_status "draft", post_type "post". `meta` is an object of meta_key => value stored on the new post. `post_category`/`tags_input` assign terms. Returns {id}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_title'     => array( 'type' => 'string', 'description' => 'Post title.' ),
				'post_content'   => array( 'type' => 'string', 'description' => 'Post body content.' ),
				'post_excerpt'   => array( 'type' => 'string', 'description' => 'Post excerpt.' ),
				'post_status'    => array( 'type' => 'string', 'description' => 'Status, e.g. "draft", "publish", "pending". Default "draft".' ),
				'post_type'      => array( 'type' => 'string', 'description' => 'Post type. Default "post".' ),
				'post_author'    => array( 'type' => 'integer', 'description' => 'Author user ID.' ),
				'post_date'      => array( 'type' => 'string', 'description' => 'Post date in "YYYY-MM-DD HH:MM:SS" (local time).' ),
				'post_name'      => array( 'type' => 'string', 'description' => 'Post slug.' ),
				'post_parent'    => array( 'type' => 'integer', 'description' => 'Parent post ID.' ),
				'menu_order'     => array( 'type' => 'integer', 'description' => 'Menu order.' ),
				'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ), 'description' => 'Whether comments are allowed.' ),
				'ping_status'    => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ), 'description' => 'Whether pingbacks/trackbacks are allowed.' ),
				'post_category'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category term IDs.' ),
				'tags_input'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names or slugs.' ),
				'meta'           => array( 'type' => 'object', 'description' => 'Map of meta_key => value to set on the new post.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$args = array(
				'post_status' => isset( $input['post_status'] ) ? (string) $input['post_status'] : 'draft',
				'post_type'   => isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post',
			);
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_date', 'post_name', 'comment_status', 'ping_status' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$args[ $key ] = (string) $input[ $key ];
				}
			}
			foreach ( array( 'post_author', 'post_parent', 'menu_order' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$args[ $key ] = (int) $input[ $key ];
				}
			}
			if ( isset( $input['post_category'] ) ) {
				$args['post_category'] = array_map( 'intval', (array) wpcli_pack_maybe_json( $input['post_category'] ) );
			}
			if ( isset( $input['tags_input'] ) ) {
				$args['tags_input'] = (array) wpcli_pack_maybe_json( $input['tags_input'] );
			}
			if ( isset( $input['meta'] ) ) {
				$meta = wpcli_pack_maybe_json( $input['meta'] );
				if ( is_array( $meta ) ) {
					$args['meta_input'] = $meta;
				}
			}
			$id = wp_insert_post( $args, true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			return array( 'id' => (int) $id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-update',
		'label'            => 'Update Post',
		'description'      => 'Update fields of an existing post (like `wp post update <id>`). Delta only — only the fields you pass change. Accepts the same fields as wp-cli/post-create. Returns {id, updated}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array( 'type' => 'integer', 'description' => 'Post ID to update.' ),
				'post_title'     => array( 'type' => 'string', 'description' => 'New post title.' ),
				'post_content'   => array( 'type' => 'string', 'description' => 'New post body content.' ),
				'post_excerpt'   => array( 'type' => 'string', 'description' => 'New post excerpt.' ),
				'post_status'    => array( 'type' => 'string', 'description' => 'New status, e.g. "draft", "publish".' ),
				'post_type'      => array( 'type' => 'string', 'description' => 'New post type.' ),
				'post_author'    => array( 'type' => 'integer', 'description' => 'New author user ID.' ),
				'post_date'      => array( 'type' => 'string', 'description' => 'New post date "YYYY-MM-DD HH:MM:SS".' ),
				'post_name'      => array( 'type' => 'string', 'description' => 'New post slug.' ),
				'post_parent'    => array( 'type' => 'integer', 'description' => 'New parent post ID.' ),
				'menu_order'     => array( 'type' => 'integer', 'description' => 'New menu order.' ),
				'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ), 'description' => 'Whether comments are allowed.' ),
				'ping_status'    => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ), 'description' => 'Whether pingbacks are allowed.' ),
				'post_category'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category term IDs (replaces existing).' ),
				'tags_input'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag names/slugs (replaces existing).' ),
				'meta'           => array( 'type' => 'object', 'description' => 'Map of meta_key => value to set/overwrite.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'updated' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$id = (int) $input['id'];
			if ( ! get_post( $id ) instanceof WP_Post ) {
				return wpcli_pack_err( 'post_not_found', sprintf( 'Post %d does not exist.', $id ), 404 );
			}
			$args = array( 'ID' => $id );
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_date', 'post_name', 'comment_status', 'ping_status' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$args[ $key ] = (string) $input[ $key ];
				}
			}
			foreach ( array( 'post_author', 'post_parent', 'menu_order' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$args[ $key ] = (int) $input[ $key ];
				}
			}
			if ( isset( $input['post_category'] ) ) {
				$args['post_category'] = array_map( 'intval', (array) wpcli_pack_maybe_json( $input['post_category'] ) );
			}
			if ( isset( $input['tags_input'] ) ) {
				$args['tags_input'] = (array) wpcli_pack_maybe_json( $input['tags_input'] );
			}
			if ( isset( $input['meta'] ) ) {
				$meta = wpcli_pack_maybe_json( $input['meta'] );
				if ( is_array( $meta ) ) {
					$args['meta_input'] = $meta;
				}
			}
			$result = wp_update_post( $args, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'id' => $id, 'updated' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-delete',
		'label'            => 'Delete Post',
		'description'      => 'Delete a post (like `wp post delete <id>`). By default the post is moved to trash; pass force=true to permanently delete it (also required for post types without trash support). Returns {deleted, trashed}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Post ID to delete.' ),
				'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing. Default false.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'trashed' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$id    = (int) $input['id'];
			$force = ! empty( $input['force'] );
			if ( ! get_post( $id ) instanceof WP_Post ) {
				return wpcli_pack_err( 'post_not_found', sprintf( 'Post %d does not exist.', $id ), 404 );
			}
			$result = wp_delete_post( $id, $force );
			if ( ! $result ) {
				return wpcli_pack_err( 'post_delete_failed', sprintf( 'Could not delete post %d.', $id ) );
			}
			return array( 'deleted' => true, 'trashed' => ! $force );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-exists',
		'label'            => 'Post Exists',
		'description'      => 'Check whether a post ID exists (like `wp post exists <id>`). Returns {exists, post_type}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Post ID to check.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'exists'    => array( 'type' => 'boolean' ),
				'post_type' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$post = get_post( (int) $input['id'] );
			if ( ! $post instanceof WP_Post ) {
				return array( 'exists' => false, 'post_type' => '' );
			}
			return array( 'exists' => true, 'post_type' => $post->post_type );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-meta-get',
		'label'            => 'Get Post Meta',
		'description'      => 'Get a post meta value (like `wp post meta get <id> <key>`). `single` (default true) returns one value; false returns the array of all values for the key. `found` is false when the key has no rows.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'single' => array( 'type' => 'boolean', 'description' => 'Return a single value (true, default) or all values for the key (false).' ),
			),
			'required'             => array( 'id', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found' => array( 'type' => 'boolean' ),
				'value' => array( 'description' => 'The meta value (native type). Null when not found.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$id  = (int) $input['id'];
			$key = (string) $input['key'];
			if ( ! metadata_exists( 'post', $id, $key ) ) {
				return array( 'found' => false, 'value' => null );
			}
			$single = ! isset( $input['single'] ) || (bool) $input['single'];
			return array( 'found' => true, 'value' => get_post_meta( $id, $key, $single ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-meta-list',
		'label'            => 'List Post Meta',
		'description'      => 'List all meta for a post (like `wp post meta list <id>`). Returns {count, meta:[{key, value}]} with values unserialized into native types.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
			),
			'required'             => array( 'id' ),
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
			$all  = get_post_meta( (int) $input['id'] );
			$all  = is_array( $all ) ? $all : array();
			$meta = array();
			foreach ( $all as $key => $values ) {
				foreach ( (array) $values as $value ) {
					$meta[] = array( 'key' => $key, 'value' => maybe_unserialize( $value ) );
				}
			}
			return array( 'count' => count( $meta ), 'meta' => $meta );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-meta-add',
		'label'            => 'Add Post Meta',
		'description'      => 'Add a post meta row (like `wp post meta add <id> <key> <value>`). Allows duplicate keys unless `unique` is true. `value` may be any JSON type and is stored as-is. Returns {meta_id}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value'  => array( 'description' => 'Meta value (any JSON type).' ),
				'unique' => array( 'type' => 'boolean', 'description' => 'Fail if the key already exists. Default false (duplicates allowed).' ),
			),
			'required'             => array( 'id', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'meta_id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$value   = wpcli_pack_maybe_json( $input['value'] );
			$unique  = ! empty( $input['unique'] );
			$meta_id = add_post_meta( (int) $input['id'], (string) $input['key'], $value, $unique );
			if ( false === $meta_id ) {
				return wpcli_pack_err( 'post_meta_add_failed', 'Could not add post meta (unique constraint or invalid post).' );
			}
			return array( 'meta_id' => (int) $meta_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-meta-update',
		'label'            => 'Update Post Meta',
		'description'      => 'Update (upsert) a post meta value (like `wp post meta update <id> <key> <value>`). Sets the key, creating it if missing. `value` may be any JSON type, stored as-is. Returns {success}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'New meta value (any JSON type).' ),
			),
			'required'             => array( 'id', 'key', 'value' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$value = wpcli_pack_maybe_json( $input['value'] );
			$ok    = update_post_meta( (int) $input['id'], (string) $input['key'], $value );
			if ( false === $ok ) {
				return wpcli_pack_err( 'post_meta_update_failed', 'Could not update post meta (invalid post).' );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-meta-delete',
		'label'            => 'Delete Post Meta',
		'description'      => 'Delete post meta (like `wp post meta delete <id> <key>`). Without `value` removes all rows for the key; with `value` removes only rows matching that value. Returns {deleted}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'Only delete rows matching this value (any JSON type). Omit to delete all rows for the key.' ),
			),
			'required'             => array( 'id', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$id    = (int) $input['id'];
			$key   = (string) $input['key'];
			$value = array_key_exists( 'value', $input ) ? wpcli_pack_maybe_json( $input['value'] ) : '';
			return array( 'deleted' => (bool) delete_post_meta( $id, $key, $value ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-term-list',
		'label'            => 'List Post Terms',
		'description'      => 'List the terms attached to a post in a taxonomy (like `wp post term list <id> <taxonomy>`). Returns {count, terms:[{term_id,name,slug}]}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy, e.g. "category", "post_tag".' ),
			),
			'required'             => array( 'id', 'taxonomy' ),
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
			$terms = wp_get_object_terms( (int) $input['id'], (string) $input['taxonomy'] );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$out = array();
			foreach ( $terms as $term ) {
				$out[] = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
				);
			}
			return array( 'count' => count( $out ), 'terms' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-term-set',
		'label'            => 'Set Post Terms',
		'description'      => 'Replace a post\'s terms in a taxonomy (like `wp post term set <id> <taxonomy> <terms>...`). `terms` is an array of term IDs or slugs; existing terms in the taxonomy are overwritten. Returns {success}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy, e.g. "category", "post_tag".' ),
				'terms'    => array( 'type' => 'array', 'description' => 'Term IDs (integers) or slugs (strings) to set.' ),
			),
			'required'             => array( 'id', 'taxonomy', 'terms' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$terms  = (array) wpcli_pack_maybe_json( $input['terms'] );
			$result = wp_set_object_terms( (int) $input['id'], $terms, (string) $input['taxonomy'], false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-term-add',
		'label'            => 'Add Post Terms',
		'description'      => 'Append terms to a post in a taxonomy without removing existing ones (like `wp post term add <id> <taxonomy> <terms>...`). `terms` is an array of term IDs or slugs. Returns {success}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy, e.g. "category", "post_tag".' ),
				'terms'    => array( 'type' => 'array', 'description' => 'Term IDs (integers) or slugs (strings) to append.' ),
			),
			'required'             => array( 'id', 'taxonomy', 'terms' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$terms  = (array) wpcli_pack_maybe_json( $input['terms'] );
			$result = wp_set_object_terms( (int) $input['id'], $terms, (string) $input['taxonomy'], true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-term-remove',
		'label'            => 'Remove Post Terms',
		'description'      => 'Remove specific terms from a post in a taxonomy (like `wp post term remove <id> <taxonomy> <terms>...`). `terms` is an array of term IDs or slugs. Returns {success}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Post ID.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy, e.g. "category", "post_tag".' ),
				'terms'    => array( 'type' => 'array', 'description' => 'Term IDs (integers) or slugs (strings) to remove.' ),
			),
			'required'             => array( 'id', 'taxonomy', 'terms' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$terms  = (array) wpcli_pack_maybe_json( $input['terms'] );
			$result = wp_remove_object_terms( (int) $input['id'], $terms, (string) $input['taxonomy'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'success' => (bool) $result );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/post-generate',
		'label'            => 'Generate Posts',
		'description'      => 'Create N dummy posts for testing (like `wp post generate --count=<n>`). Inputs: count (default 5), post_type (default "post"), post_status (default "publish"), post_title_prefix. Returns {created:[ids]}.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'count'             => array( 'type' => 'integer', 'description' => 'How many posts to create. Default 5.' ),
				'post_type'         => array( 'type' => 'string', 'description' => 'Post type. Default "post".' ),
				'post_status'       => array( 'type' => 'string', 'description' => 'Post status. Default "publish".' ),
				'post_title_prefix' => array( 'type' => 'string', 'description' => 'Prefix for generated titles. Default "Post".' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'created' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$count  = isset( $input['count'] ) ? max( 1, (int) $input['count'] ) : 5;
			$type   = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
			$status = isset( $input['post_status'] ) ? (string) $input['post_status'] : 'publish';
			$prefix = isset( $input['post_title_prefix'] ) ? (string) $input['post_title_prefix'] : 'Post';
			$created = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$id = wp_insert_post(
					array(
						'post_title'   => sprintf( '%s %d', $prefix, $i ),
						'post_content' => sprintf( 'Generated content for %s %d.', $prefix, $i ),
						'post_status'  => $status,
						'post_type'    => $type,
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$created[] = (int) $id;
			}
			return array( 'created' => $created );
		},
	)
);
