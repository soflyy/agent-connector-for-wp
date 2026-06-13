<?php
/**
 * Per-content SEO abilities: read, update, and discover the Yoast SEO metadata
 * for individual posts/pages and taxonomy terms.
 *
 * @package AcfwAbilityPackYoast
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::category(
	'yoast',
	array(
		'label'       => 'Yoast SEO (Unofficial)',
		'description' => 'Control Yoast SEO: per-post/term SEO metadata, search appearance & title templates, social defaults, and general settings.',
	)
);

/* Shared schema fragment describing the editable SEO fields. */
$yoast_seo_fields_schema = array(
	'focus_keyphrase'     => array( 'type' => 'string', 'description' => 'Focus keyphrase the content should rank for.' ),
	'seo_title'           => array( 'type' => 'string', 'description' => 'SEO title. May contain Yoast replacement variables like %%title%% %%sep%% %%sitename%% (see yoast/list-replacement-variables).' ),
	'meta_description'    => array( 'type' => 'string', 'description' => 'Meta description. May contain replacement variables.' ),
	'cornerstone'         => array( 'type' => 'boolean', 'description' => 'Mark as cornerstone content.' ),
	'robots_noindex'      => array( 'type' => 'string', 'enum' => array( 'default', 'index', 'noindex' ), 'description' => 'Search-engine indexing. "default" follows the content-type setting.' ),
	'robots_nofollow'     => array( 'type' => 'boolean', 'description' => 'Add nofollow to links. (Posts only.)' ),
	'robots_advanced'     => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'noimageindex', 'noarchive', 'nosnippet' ) ), 'description' => 'Advanced robots directives. (Posts only.)' ),
	'canonical'           => array( 'type' => 'string', 'description' => 'Canonical URL override.' ),
	'breadcrumb_title'    => array( 'type' => 'string', 'description' => 'Title used for this content in breadcrumbs.' ),
	'og_title'            => array( 'type' => 'string', 'description' => 'Facebook/Open Graph title.' ),
	'og_description'      => array( 'type' => 'string', 'description' => 'Facebook/Open Graph description.' ),
	'og_image'            => array( 'type' => 'string', 'description' => 'Facebook/Open Graph image URL.' ),
	'og_image_id'         => array( 'type' => 'integer', 'description' => 'Attachment ID for the Open Graph image.' ),
	'twitter_title'       => array( 'type' => 'string', 'description' => 'X/Twitter title.' ),
	'twitter_description' => array( 'type' => 'string', 'description' => 'X/Twitter description.' ),
	'twitter_image'       => array( 'type' => 'string', 'description' => 'X/Twitter image URL.' ),
	'twitter_image_id'    => array( 'type' => 'integer', 'description' => 'Attachment ID for the X/Twitter image.' ),
	'schema_page_type'    => array( 'type' => 'string', 'description' => 'Schema.org page type, e.g. WebPage, AboutPage, FAQPage. (Posts only.)' ),
	'schema_article_type' => array( 'type' => 'string', 'description' => 'Schema.org article type, e.g. Article, NewsArticle, None. (Posts only.)' ),
	'primary_terms'       => array( 'type' => 'object', 'description' => 'Primary term per hierarchical taxonomy, e.g. {"category": 5}. (Posts only.)' ),
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/get-content-seo',
		'label'            => 'Get Content SEO',
		'description'      => 'Read the full Yoast SEO metadata for one post/page (type:"post") or taxonomy term (type:"term"). Returns the focus keyphrase, SEO title, meta description, robots/indexing settings, canonical, breadcrumb title, social (Open Graph + X/Twitter) overrides, schema types, and (for posts) primary terms. Call this before editing so you know current state. Discover ids with yoast/find-content.',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => 'Whether the id refers to a post or a taxonomy term.' ),
				'id'       => array( 'type' => 'integer', 'description' => 'Post id or term id.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Required when type is "term" (e.g. category, post_tag).' ),
			),
			'required'             => array( 'type', 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			if ( 'term' === ( $input['type'] ?? '' ) ) {
				if ( empty( $input['taxonomy'] ) ) {
					return new WP_Error( 'yoast_missing_taxonomy', 'taxonomy is required when type is "term".', array( 'status' => 400 ) );
				}
				return yoast_pack_get_term_seo( (int) $input['id'], (string) $input['taxonomy'] );
			}
			return yoast_pack_get_post_seo( (int) $input['id'] );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/update-content-seo',
		'label'            => 'Update Content SEO',
		'description'      => 'Update the Yoast SEO metadata of one post/page (type:"post") or taxonomy term (type:"term"). Pass only the fields in "seo" you want to change — everything else is preserved. Writes through Yoast\'s own save path so validation runs and the indexable is rebuilt. Post-only fields (robots_nofollow, robots_advanced, schema_page_type, schema_article_type, primary_terms) are rejected for terms. Returns the reloaded SEO state and the list of changed fields.',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => 'Whether the id refers to a post or a taxonomy term.' ),
				'id'       => array( 'type' => 'integer', 'description' => 'Post id or term id.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Required when type is "term".' ),
				'seo'      => array(
					'type'                 => 'object',
					'description'          => 'The fields to change (delta only).',
					'properties'           => $yoast_seo_fields_schema,
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'type', 'id', 'seo' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			$seo = isset( $input['seo'] ) && is_array( $input['seo'] ) ? $input['seo'] : array();
			if ( 'term' === ( $input['type'] ?? '' ) ) {
				if ( empty( $input['taxonomy'] ) ) {
					return new WP_Error( 'yoast_missing_taxonomy', 'taxonomy is required when type is "term".', array( 'status' => 400 ) );
				}
				$post_only = array_intersect( array_keys( $seo ), array( 'robots_nofollow', 'robots_advanced', 'schema_page_type', 'schema_article_type', 'primary_terms' ) );
				if ( $post_only ) {
					return new WP_Error( 'yoast_post_only_field', 'These fields are not supported for terms: ' . implode( ', ', $post_only ) . '.', array( 'status' => 400 ) );
				}
				return yoast_pack_set_term_seo( (int) $input['id'], (string) $input['taxonomy'], $seo );
			}
			return yoast_pack_set_post_seo( (int) $input['id'], $seo );
		},
		'summarize'        => static function ( array $input ): array {
			return array(
				'type'   => isset( $input['type'] ) ? (string) $input['type'] : '',
				'id'     => isset( $input['id'] ) ? (int) $input['id'] : 0,
				'fields' => isset( $input['seo'] ) && is_array( $input['seo'] ) ? implode( ',', array_keys( $input['seo'] ) ) : '',
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/find-content',
		'label'            => 'Find Content',
		'description'      => 'Discover posts/pages (kind:"post") or taxonomy terms (kind:"term") together with a summary of their Yoast SEO state (SEO title, meta description, focus keyphrase, and for posts the cornerstone flag). Use this to locate the id to pass to yoast/get-content-seo and yoast/update-content-seo. Supports a free-text search and a post_type/taxonomy filter.',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'      => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => 'List posts or taxonomy terms. Defaults to "post".' ),
				'post_type' => array( 'type' => 'string', 'description' => 'Filter posts by post type (e.g. post, page). Defaults to any.' ),
				'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomy to list when kind is "term". Defaults to category.' ),
				'search'    => array( 'type' => 'string', 'description' => 'Optional keyword filter on the title/name.' ),
				'per_page'  => array( 'type' => 'integer', 'description' => 'Max results (1-100). Defaults to 20.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			return yoast_pack_find_content( $input );
		},
	)
);
