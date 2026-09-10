<?php
/**
 * Shared helpers for the WP-CLI ability pack.
 *
 * Every ability returns plain associative arrays (serialized to JSON for the
 * agent), never formatted CLI text. These helpers shape WordPress objects into
 * clean arrays and standardize errors so each ability file stays tiny.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_err' ) ) {

	/**
	 * Build a WP_Error with an HTTP-ish status, mirroring WP-CLI's exit-on-error
	 * behavior in a way the agent can read.
	 */
	function wpcli_pack_err( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Normalize a value that may arrive as a JSON string, an array, or a scalar.
	 * WP-CLI agents frequently pass `--<key>=<json>`; here a property may be sent
	 * as an object/array directly OR as a JSON-encoded string. Accept both.
	 *
	 * @param mixed $value Raw input value.
	 * @return mixed Decoded value (array) when $value was a JSON object/array string, else $value unchanged.
	 */
	function wpcli_pack_maybe_json( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$trimmed = trim( $value );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return $value;
		}
		$decoded = json_decode( $trimmed, true );
		return ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) ? $value : $decoded;
	}

	/**
	 * Reduce an associative row to only the requested fields, preserving order.
	 * When $fields is empty, the row is returned unchanged.
	 *
	 * @param array<string,mixed> $row    Full row.
	 * @param array<int,string>   $fields Field names to keep.
	 * @return array<string,mixed>
	 */
	function wpcli_pack_pick( array $row, array $fields ): array {
		if ( empty( $fields ) ) {
			return $row;
		}
		$out = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$out[ $field ] = $row[ $field ];
			}
		}
		return $out;
	}

	/**
	 * Shape a WP_Post into a clean array (the columns WP-CLI's `post` command
	 * exposes, plus a couple of convenience fields).
	 */
	function wpcli_pack_post_row( WP_Post $post ): array {
		return array(
			'ID'             => (int) $post->ID,
			'post_title'     => $post->post_title,
			'post_name'      => $post->post_name,
			'post_type'      => $post->post_type,
			'post_status'    => $post->post_status,
			'post_author'    => (int) $post->post_author,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'post_modified'  => $post->post_modified,
			'post_parent'    => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'post_excerpt'   => $post->post_excerpt,
			'post_content'   => $post->post_content,
			'guid'           => $post->guid,
			'url'            => (string) get_permalink( $post ),
		);
	}

	/**
	 * Shape a WP_User into a clean array (WP-CLI `user` columns).
	 */
	function wpcli_pack_user_row( WP_User $user ): array {
		return array(
			'ID'                => (int) $user->ID,
			'user_login'        => $user->user_login,
			'display_name'      => $user->display_name,
			'user_email'        => $user->user_email,
			'user_registered'   => $user->user_registered,
			'user_nicename'     => $user->user_nicename,
			'user_url'          => $user->user_url,
			'roles'             => array_values( (array) $user->roles ),
		);
	}

	/**
	 * Shape a WP_Term into a clean array (WP-CLI `term` columns).
	 */
	function wpcli_pack_term_row( WP_Term $term ): array {
		return array(
			'term_id'          => (int) $term->term_id,
			'term_taxonomy_id' => (int) $term->term_taxonomy_id,
			'name'             => $term->name,
			'slug'             => $term->slug,
			'taxonomy'         => $term->taxonomy,
			'description'      => $term->description,
			'parent'           => (int) $term->parent,
			'count'            => (int) $term->count,
		);
	}

	/**
	 * Shape a comment into a clean array (WP-CLI `comment` columns).
	 */
	function wpcli_pack_comment_row( WP_Comment $comment ): array {
		return array(
			'comment_ID'           => (int) $comment->comment_ID,
			'comment_post_ID'      => (int) $comment->comment_post_ID,
			'comment_author'       => $comment->comment_author,
			'comment_author_email' => $comment->comment_author_email,
			'comment_author_url'   => $comment->comment_author_url,
			'comment_date'         => $comment->comment_date,
			'comment_approved'     => $comment->comment_approved,
			'comment_parent'       => (int) $comment->comment_parent,
			'user_id'              => (int) $comment->user_id,
			'comment_content'      => $comment->comment_content,
		);
	}

	/**
	 * Resolve the meta object-type ("post" | "user" | "term" | "comment") to the
	 * arguments WordPress's generic meta functions expect. Centralizes the meta
	 * abilities so post-meta / user-meta / term-meta / comment-meta share code.
	 *
	 * @return string The meta type accepted by get_metadata()/update_metadata().
	 */
	function wpcli_pack_meta_type( string $object_type ): string {
		$map = array(
			'post'    => 'post',
			'user'    => 'user',
			'term'    => 'term',
			'comment' => 'comment',
		);
		return $map[ $object_type ] ?? $object_type;
	}
}
