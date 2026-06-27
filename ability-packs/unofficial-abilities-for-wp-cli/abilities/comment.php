<?php
/**
 * `wp comment` and `wp comment-meta` — read and write comments and their metadata.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-get',
		'label'            => 'Get Comment',
		'description'      => 'Get a single comment by ID (like `wp comment get <id>`). Returns the comment columns. When `field` is given, only that one field is returned under `value`. `found` is false when no comment has that ID.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
				'field' => array( 'type' => 'string', 'description' => 'Return only this single field (e.g. "comment_content", "comment_approved"). Omit to return the whole row.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'found'   => array( 'type' => 'boolean' ),
				'comment' => array( 'type' => 'object' ),
				'value'   => array( 'description' => 'Single field value when `field` is given.' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$comment = get_comment( (int) $input['id'] );
			if ( ! $comment instanceof WP_Comment ) {
				return array( 'found' => false, 'comment' => null );
			}
			$row = wpcli_pack_comment_row( $comment );
			if ( ! empty( $input['field'] ) ) {
				$field = (string) $input['field'];
				if ( ! array_key_exists( $field, $row ) ) {
					return wpcli_pack_err( 'invalid_field', sprintf( 'Unknown comment field "%s".', $field ) );
				}
				return array( 'found' => true, 'value' => $row[ $field ] );
			}
			return array( 'found' => true, 'comment' => $row );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-list',
		'label'            => 'List Comments',
		'description'      => 'List comments (like `wp comment list`). Filter by `post_id`, `status` (approve/hold/spam/trash/all), `author_email`, or a `search` term. Sort with `orderby`/`order`. `number` caps results (default 25, 0 for all) with `offset`. `fields` limits returned columns.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'      => array( 'type' => 'integer', 'description' => 'Only comments on this post ID.' ),
				'status'       => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ), 'description' => 'Comment status filter. "all" returns every status. Default lists approved.' ),
				'author_email' => array( 'type' => 'string', 'description' => 'Only comments from this author email.' ),
				'search'       => array( 'type' => 'string', 'description' => 'Search term matched against comment content and author fields.' ),
				'orderby'      => array( 'type' => 'string', 'description' => 'Column to order by, e.g. "comment_date_gmt", "comment_ID". Default comment_date_gmt.' ),
				'order'        => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Sort direction. Default DESC.' ),
				'number'       => array( 'type' => 'integer', 'description' => 'Max comments to return. Default 25. Use 0 for all.' ),
				'offset'       => array( 'type' => 'integer', 'description' => 'Number of comments to skip (for paging).' ),
				'fields'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Subset of columns to return per comment. Omit for all.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'    => array( 'type' => 'integer' ),
				'comments' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$args = array();

			if ( isset( $input['post_id'] ) ) {
				$args['post_id'] = (int) $input['post_id'];
			}
			if ( ! empty( $input['status'] ) && 'all' !== $input['status'] ) {
				$args['status'] = (string) $input['status'];
			} elseif ( ! empty( $input['status'] ) && 'all' === $input['status'] ) {
				$args['status'] = 'all';
			}
			if ( ! empty( $input['author_email'] ) ) {
				$args['author_email'] = (string) $input['author_email'];
			}
			if ( ! empty( $input['search'] ) ) {
				$args['search'] = (string) $input['search'];
			}
			$args['orderby'] = ! empty( $input['orderby'] ) ? (string) $input['orderby'] : 'comment_date_gmt';
			$args['order']   = ! empty( $input['order'] ) ? strtoupper( (string) $input['order'] ) : 'DESC';

			$number = array_key_exists( 'number', $input ) ? (int) $input['number'] : 25;
			if ( $number > 0 ) {
				$args['number'] = $number;
			}
			if ( ! empty( $input['offset'] ) ) {
				$args['offset'] = (int) $input['offset'];
			}

			$fields   = array();
			if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
				$fields = array_map( 'strval', $input['fields'] );
			}

			$comments = get_comments( $args );
			$out      = array();
			foreach ( (array) $comments as $comment ) {
				if ( ! $comment instanceof WP_Comment ) {
					continue;
				}
				$out[] = wpcli_pack_pick( wpcli_pack_comment_row( $comment ), $fields );
			}
			return array( 'count' => count( $out ), 'comments' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-create',
		'label'            => 'Create Comment',
		'description'      => 'Create a new comment (like `wp comment create`). `comment_post_ID` and `comment_content` are required. `comment_approved` defaults to 1 (approved); pass "0" or "hold"/"spam" to moderate. Returns the new comment `id`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'comment_post_ID'      => array( 'type' => 'integer', 'description' => 'ID of the post the comment is attached to. Required.' ),
				'comment_content'      => array( 'type' => 'string', 'description' => 'Comment body text. Required.' ),
				'comment_author'       => array( 'type' => 'string', 'description' => 'Display name of the comment author.' ),
				'comment_author_email' => array( 'type' => 'string', 'description' => 'Author email address.' ),
				'comment_author_url'   => array( 'type' => 'string', 'description' => 'Author website URL.' ),
				'comment_parent'       => array( 'type' => 'integer', 'description' => 'Parent comment ID for threaded replies. Default 0.' ),
				'user_id'              => array( 'type' => 'integer', 'description' => 'Registered user ID who authored the comment.' ),
				'comment_approved'     => array( 'description' => 'Approval state: 1/"approve" approved, 0/"hold" pending, "spam". Default 1.' ),
			),
			'required'             => array( 'comment_post_ID', 'comment_content' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$data = array(
				'comment_post_ID' => (int) $input['comment_post_ID'],
				'comment_content' => (string) $input['comment_content'],
			);
			foreach ( array( 'comment_author', 'comment_author_email', 'comment_author_url' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$data[ $key ] = (string) $input[ $key ];
				}
			}
			if ( isset( $input['comment_parent'] ) ) {
				$data['comment_parent'] = (int) $input['comment_parent'];
			}
			if ( isset( $input['user_id'] ) ) {
				$data['user_id'] = (int) $input['user_id'];
			}
			$data['comment_approved'] = array_key_exists( 'comment_approved', $input ) ? $input['comment_approved'] : 1;

			$id = wp_insert_comment( $data );
			if ( ! $id ) {
				return wpcli_pack_err( 'comment_create_failed', 'Could not create the comment.' );
			}
			return array( 'id' => (int) $id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-update',
		'label'            => 'Update Comment',
		'description'      => 'Update fields on an existing comment (like `wp comment update <id> --<field>=<value>`). Only the fields you pass are changed (delta). Returns the `id` and whether anything was `updated`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                   => array( 'type' => 'integer', 'description' => 'Comment ID. Required.' ),
				'comment_content'      => array( 'type' => 'string', 'description' => 'New comment body text.' ),
				'comment_author'       => array( 'type' => 'string', 'description' => 'New author display name.' ),
				'comment_author_email' => array( 'type' => 'string', 'description' => 'New author email.' ),
				'comment_author_url'   => array( 'type' => 'string', 'description' => 'New author website URL.' ),
				'comment_approved'     => array( 'description' => 'New approval state: 1/"approve", 0/"hold", "spam".' ),
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
			$id      = (int) $input['id'];
			$comment = get_comment( $id );
			if ( ! $comment instanceof WP_Comment ) {
				return wpcli_pack_err( 'comment_not_found', sprintf( 'Comment %d does not exist.', $id ), 404 );
			}
			$data = array( 'comment_ID' => $id );
			foreach ( array( 'comment_content', 'comment_author', 'comment_author_email', 'comment_author_url' ) as $key ) {
				if ( array_key_exists( $key, $input ) ) {
					$data[ $key ] = (string) $input[ $key ];
				}
			}
			if ( array_key_exists( 'comment_approved', $input ) ) {
				$data['comment_approved'] = $input['comment_approved'];
			}
			if ( count( $data ) <= 1 ) {
				return wpcli_pack_err( 'nothing_to_update', 'Provide at least one field to update.' );
			}
			$ok = wp_update_comment( $data );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			return array( 'id' => $id, 'updated' => (bool) $ok );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-delete',
		'label'            => 'Delete Comment',
		'description'      => 'Delete a comment (like `wp comment delete <id>`). By default the comment is moved to trash; pass `force` true to delete permanently. Returns `deleted`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
				'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing. Default false.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$force = ! empty( $input['force'] );
			$ok    = wp_delete_comment( (int) $input['id'], $force );
			return array( 'deleted' => (bool) $ok );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-exists',
		'label'            => 'Comment Exists',
		'description'      => 'Check whether a comment with the given ID exists (like `wp comment exists <id>`). Returns `exists`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'exists' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'exists' => get_comment( (int) $input['id'] ) instanceof WP_Comment );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-approve',
		'label'            => 'Approve Comment',
		'description'      => 'Approve a comment (like `wp comment approve <id>`). Sets its status to "approve". Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_set_comment_status( (int) $input['id'], 'approve' );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_approve_failed', sprintf( 'Could not approve comment %d.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-unapprove',
		'label'            => 'Unapprove Comment',
		'description'      => 'Unapprove a comment, moving it back to moderation (like `wp comment unapprove <id>`). Sets its status to "hold". Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_set_comment_status( (int) $input['id'], 'hold' );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_unapprove_failed', sprintf( 'Could not unapprove comment %d.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-spam',
		'label'            => 'Mark Comment as Spam',
		'description'      => 'Mark a comment as spam (like `wp comment spam <id>`). Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_spam_comment( (int) $input['id'] );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_spam_failed', sprintf( 'Could not mark comment %d as spam.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-unspam',
		'label'            => 'Unmark Comment as Spam',
		'description'      => 'Restore a comment from spam (like `wp comment unspam <id>`). Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_unspam_comment( (int) $input['id'] );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_unspam_failed', sprintf( 'Could not unspam comment %d.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-trash',
		'label'            => 'Trash Comment',
		'description'      => 'Move a comment to the trash (like `wp comment trash <id>`). Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_trash_comment( (int) $input['id'] );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_trash_failed', sprintf( 'Could not trash comment %d.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-untrash',
		'label'            => 'Untrash Comment',
		'description'      => 'Restore a comment from the trash (like `wp comment untrash <id>`). Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ok = wp_untrash_comment( (int) $input['id'] );
			if ( ! $ok ) {
				return wpcli_pack_err( 'comment_untrash_failed', sprintf( 'Could not untrash comment %d.', (int) $input['id'] ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-status',
		'label'            => 'Get Comment Status',
		'description'      => 'Get the status of a comment (like `wp comment status <id>`). Returns the status string: "approved", "unapproved", "spam", "trash", or "deleted".',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'status' => array( 'type' => 'string' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$id     = (int) $input['id'];
			$status = wp_get_comment_status( $id );
			if ( false === $status ) {
				return wpcli_pack_err( 'comment_not_found', sprintf( 'Comment %d does not exist.', $id ), 404 );
			}
			return array( 'status' => (string) $status );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-count',
		'label'            => 'Count Comments',
		'description'      => 'Count comments by status (like `wp comment count`). Pass `post_id` to count for a single post, or omit / 0 for the whole site. Returns counts: approved, moderated, spam, trash, post-trashed, total_comments, all.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'Post ID to count comments for. 0 or omitted counts the whole site.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'approved'       => array( 'type' => 'integer' ),
				'moderated'      => array( 'type' => 'integer' ),
				'spam'           => array( 'type' => 'integer' ),
				'trash'          => array( 'type' => 'integer' ),
				'total_comments' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			$counts  = wp_count_comments( $post_id );
			return (array) $counts;
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-recount',
		'label'            => 'Recount Post Comments',
		'description'      => 'Recalculate the cached comment count for a post (like `wp comment recount <post-id>`). Useful after bulk comment changes. Returns `success` and the recalculated `count`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'Post ID whose comment count should be recalculated.' ),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'count'   => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$post_id = (int) $input['post_id'];
			wp_update_comment_count( $post_id );
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return wpcli_pack_err( 'post_not_found', sprintf( 'Post %d does not exist.', $post_id ), 404 );
			}
			return array( 'success' => true, 'count' => (int) $post->comment_count );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-meta-get',
		'label'            => 'Get Comment Meta',
		'description'      => 'Get a comment meta value (like `wp comment meta get <id> <key>`). With `single` true (default) returns the single value; false returns an array of all values for the key. `found` is false when the key has no value.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
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
			if ( ! metadata_exists( 'comment', $id, $key ) ) {
				return array( 'found' => false, 'value' => null );
			}
			$single = array_key_exists( 'single', $input ) ? (bool) $input['single'] : true;
			$value  = get_comment_meta( $id, $key, $single );
			return array( 'found' => true, 'value' => $value );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-meta-list',
		'label'            => 'List Comment Meta',
		'description'      => 'List all meta key/value pairs for a comment (like `wp comment meta list <id>`). Values are returned unserialized. A key appears once per stored row.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
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
			$id   = (int) $input['id'];
			$all  = get_comment_meta( $id );
			$out  = array();
			if ( is_array( $all ) ) {
				foreach ( $all as $key => $values ) {
					foreach ( (array) $values as $value ) {
						$out[] = array( 'key' => (string) $key, 'value' => maybe_unserialize( $value ) );
					}
				}
			}
			return array( 'count' => count( $out ), 'meta' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-meta-add',
		'label'            => 'Add Comment Meta',
		'description'      => 'Add a comment meta value (like `wp comment meta add <id> <key> <value>`). `value` may be any JSON type. With `unique` true the key is only added if it does not already exist. Returns the new `meta_id`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
				'key'    => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value'  => array( 'description' => 'Meta value (any JSON type).' ),
				'unique' => array( 'type' => 'boolean', 'description' => 'Only add if the key does not already exist. Default false.' ),
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
			$unique  = ! empty( $input['unique'] );
			$value   = wpcli_pack_maybe_json( $input['value'] );
			$meta_id = add_comment_meta( (int) $input['id'], (string) $input['key'], $value, $unique );
			if ( ! $meta_id ) {
				return wpcli_pack_err( 'comment_meta_add_failed', 'Could not add comment meta (it may already exist when unique=true).' );
			}
			return array( 'meta_id' => (int) $meta_id );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-meta-update',
		'label'            => 'Update Comment Meta',
		'description'      => 'Update (or create) a comment meta value (like `wp comment meta update <id> <key> <value>`). `value` may be any JSON type. Returns `success`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
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
			$ok    = update_comment_meta( (int) $input['id'], (string) $input['key'], $value );
			if ( false === $ok ) {
				return wpcli_pack_err( 'comment_meta_update_failed', 'Could not update comment meta.' );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-meta-delete',
		'label'            => 'Delete Comment Meta',
		'description'      => 'Delete a comment meta key (like `wp comment meta delete <id> <key>`). If `value` is given, only rows matching that value are removed; otherwise all rows for the key are deleted. Returns `deleted`.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Comment ID.' ),
				'key'   => array( 'type' => 'string', 'description' => 'Meta key.' ),
				'value' => array( 'description' => 'Only delete rows whose value matches this (any JSON type). Omit to delete all rows for the key.' ),
			),
			'required'             => array( 'id', 'key' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$value = array_key_exists( 'value', $input ) ? wpcli_pack_maybe_json( $input['value'] ) : '';
			$ok    = delete_comment_meta( (int) $input['id'], (string) $input['key'], $value );
			return array( 'deleted' => (bool) $ok );
		},
	)
);
