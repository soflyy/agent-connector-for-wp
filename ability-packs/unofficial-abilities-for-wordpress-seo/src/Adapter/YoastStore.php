<?php
/**
 * YoastStore — load / read / persist helpers for Yoast SEO (free).
 *
 * Every mutation routes through Yoast's own canonical save paths so its
 * sanitization, validation, and indexable rebuilds run:
 *   - Post SEO meta  -> WPSEO_Meta::get_value() / WPSEO_Meta::set_value()
 *                       (the _yoast_wpseo_* watcher rebuilds the post indexable
 *                       at request shutdown).
 *   - Term SEO meta  -> WPSEO_Taxonomy_Meta::get_term_meta() / ::set_values()
 *                       (we force a term indexable rebuild after, because the
 *                       option write does not fire edited_term).
 *   - Settings       -> WPSEO_Options::get_option() / ::save_option()
 *                       (the sanitize_option_* validate filter runs on save).
 *
 * The agent-ergonomic verbs in abilities/ load current state through these
 * readers, apply only the caller's delta, and persist through these writers —
 * so a partial "change the meta description" never wipes the focus keyphrase.
 *
 * @package AcfwAbilityPackYoast
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** True when Yoast SEO core classes are loaded. */
function yoast_pack_active(): bool {
	return class_exists( 'WPSEO_Meta' ) && class_exists( 'WPSEO_Options' );
}

/** Standard "Yoast inactive" error. */
function yoast_pack_inactive_error(): WP_Error {
	return new WP_Error( 'yoast_inactive', 'Yoast SEO is not active.', array( 'status' => 500 ) );
}

/* -------------------------------------------------------------------------
 * Field maps — agent-friendly names <-> Yoast storage keys.
 * ---------------------------------------------------------------------- */

/**
 * Post SEO fields: agent_key => array( yoast_meta_key (no prefix), type ).
 *
 * type drives read/write coercion: string|bool|noindex|advanced|int.
 */
function yoast_pack_post_fields(): array {
	return array(
		'focus_keyphrase'     => array( 'focuskw', 'string' ),
		'seo_title'           => array( 'title', 'string' ),
		'meta_description'    => array( 'metadesc', 'string' ),
		'cornerstone'         => array( 'is_cornerstone', 'bool' ),
		'robots_noindex'      => array( 'meta-robots-noindex', 'noindex_post' ),
		'robots_nofollow'     => array( 'meta-robots-nofollow', 'bool01' ),
		'robots_advanced'     => array( 'meta-robots-adv', 'advanced' ),
		'canonical'           => array( 'canonical', 'string' ),
		'breadcrumb_title'    => array( 'bctitle', 'string' ),
		'og_title'            => array( 'opengraph-title', 'string' ),
		'og_description'      => array( 'opengraph-description', 'string' ),
		'og_image'            => array( 'opengraph-image', 'string' ),
		'og_image_id'         => array( 'opengraph-image-id', 'int' ),
		'twitter_title'       => array( 'twitter-title', 'string' ),
		'twitter_description' => array( 'twitter-description', 'string' ),
		'twitter_image'       => array( 'twitter-image', 'string' ),
		'twitter_image_id'    => array( 'twitter-image-id', 'int' ),
		'schema_page_type'    => array( 'schema_page_type', 'string' ),
		'schema_article_type' => array( 'schema_article_type', 'string' ),
	);
}

/**
 * Term SEO fields: agent_key => yoast taxonomy-meta key (WITH the wpseo_ prefix).
 * type lives in the second slot for the few that need coercion.
 */
function yoast_pack_term_fields(): array {
	return array(
		'seo_title'           => array( 'wpseo_title', 'string' ),
		'meta_description'    => array( 'wpseo_desc', 'string' ),
		'focus_keyphrase'     => array( 'wpseo_focuskw', 'string' ),
		'canonical'           => array( 'wpseo_canonical', 'string' ),
		'breadcrumb_title'    => array( 'wpseo_bctitle', 'string' ),
		'robots_noindex'      => array( 'wpseo_noindex', 'noindex_term' ),
		'cornerstone'         => array( 'wpseo_is_cornerstone', 'bool01' ),
		'og_title'            => array( 'wpseo_opengraph-title', 'string' ),
		'og_description'      => array( 'wpseo_opengraph-description', 'string' ),
		'og_image'            => array( 'wpseo_opengraph-image', 'string' ),
		'og_image_id'         => array( 'wpseo_opengraph-image-id', 'string' ),
		'twitter_title'       => array( 'wpseo_twitter-title', 'string' ),
		'twitter_description' => array( 'wpseo_twitter-description', 'string' ),
		'twitter_image'       => array( 'wpseo_twitter-image', 'string' ),
		'twitter_image_id'    => array( 'wpseo_twitter-image-id', 'string' ),
	);
}

/* -------------------------------------------------------------------------
 * Value coercion (storage <-> agent).
 * ---------------------------------------------------------------------- */

/** Decode a stored value into the agent-facing representation. */
function yoast_pack_decode( string $type, $stored ) {
	switch ( $type ) {
		case 'bool': // post is_cornerstone stored as '1' / ''.
			return ( '1' === (string) $stored || 'true' === (string) $stored );
		case 'bool01': // '1' / '0'.
			return ( '1' === (string) $stored );
		case 'int':
			return ( '' === (string) $stored ) ? null : (int) $stored;
		case 'noindex_post': // '0' default, '1' noindex, '2' index.
			$map = array( '0' => 'default', '1' => 'noindex', '2' => 'index' );
			return $map[ (string) $stored ] ?? 'default';
		case 'noindex_term': // stored literally: default|index|noindex.
			$v = (string) $stored;
			return ( '' === $v ) ? 'default' : $v;
		case 'advanced': // CSV of noimageindex/noarchive/nosnippet.
			$v = trim( (string) $stored );
			return ( '' === $v ) ? array() : array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
		default:
			return (string) $stored;
	}
}

/** Encode an agent-supplied value into the stored representation. */
function yoast_pack_encode( string $type, $value ) {
	switch ( $type ) {
		case 'bool':
			return yoast_pack_truthy( $value ) ? '1' : '';
		case 'bool01':
			return yoast_pack_truthy( $value ) ? '1' : '0';
		case 'int':
			return ( null === $value || '' === $value ) ? '' : (string) (int) $value;
		case 'noindex_post':
			$map = array( 'default' => '0', 'noindex' => '1', 'index' => '2' );
			return $map[ (string) $value ] ?? '0';
		case 'noindex_term':
			$v = (string) $value;
			return in_array( $v, array( 'default', 'index', 'noindex' ), true ) ? $v : 'default';
		case 'advanced':
			$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
			$valid = array( 'noimageindex', 'noarchive', 'nosnippet' );
			$items = array_values( array_intersect( array_map( 'strval', (array) $items ), $valid ) );
			return implode( ',', $items );
		default:
			return is_scalar( $value ) ? (string) $value : '';
	}
}

/** Loose truthiness for agent-supplied booleans. */
function yoast_pack_truthy( $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}
	return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
}

/* -------------------------------------------------------------------------
 * Post SEO read / write.
 * ---------------------------------------------------------------------- */

/**
 * Read all Yoast SEO meta for a post into the agent-facing shape.
 *
 * @return array|WP_Error
 */
function yoast_pack_get_post_seo( int $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'yoast_post_not_found', sprintf( 'No post found for id %d.', $post_id ), array( 'status' => 404 ) );
	}

	$seo = array();
	foreach ( yoast_pack_post_fields() as $agent_key => $def ) {
		list( $meta_key, $type ) = $def;
		$seo[ $agent_key ]       = yoast_pack_decode( $type, WPSEO_Meta::get_value( $meta_key, $post_id ) );
	}

	// Primary terms (one per hierarchical taxonomy with terms assigned).
	$seo['primary_terms'] = yoast_pack_get_primary_terms( $post_id, $post->post_type );

	return array(
		'type'      => 'post',
		'id'        => $post_id,
		'post_type' => $post->post_type,
		'title'     => $post->post_title,
		'permalink' => (string) get_permalink( $post_id ),
		'seo'       => $seo,
	);
}

/** Read the primary term for each hierarchical taxonomy of the post type. */
function yoast_pack_get_primary_terms( int $post_id, string $post_type ): array {
	$out = array();
	foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
		if ( ! $tax->hierarchical ) {
			continue;
		}
		$value = get_post_meta( $post_id, '_yoast_wpseo_primary_' . $tax->name, true );
		if ( '' !== (string) $value ) {
			$out[ $tax->name ] = (int) $value;
		}
	}
	return $out;
}

/**
 * Apply a delta of SEO fields to a post via Yoast's own save path.
 *
 * @param array $delta agent_key => value (only keys present are changed).
 * @return array|WP_Error The reloaded post SEO, or WP_Error.
 */
function yoast_pack_set_post_seo( int $post_id, array $delta ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'yoast_post_not_found', sprintf( 'No post found for id %d.', $post_id ), array( 'status' => 404 ) );
	}

	$fields  = yoast_pack_post_fields();
	$changed = array();

	foreach ( $delta as $agent_key => $value ) {
		if ( 'primary_terms' === $agent_key ) {
			continue; // handled below
		}
		if ( ! isset( $fields[ $agent_key ] ) ) {
			return new WP_Error(
				'yoast_unknown_field',
				sprintf( 'Unknown post SEO field "%s". Valid fields: %s.', $agent_key, implode( ', ', array_keys( $fields ) ) ),
				array( 'status' => 400 )
			);
		}
		list( $meta_key, $type ) = $fields[ $agent_key ];
		WPSEO_Meta::set_value( $meta_key, yoast_pack_encode( $type, $value ), $post_id );
		$changed[] = $agent_key;
	}

	// Primary terms: { taxonomy => term_id }.
	if ( isset( $delta['primary_terms'] ) && is_array( $delta['primary_terms'] ) ) {
		foreach ( $delta['primary_terms'] as $taxonomy => $term_id ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'yoast_bad_taxonomy', sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ), array( 'status' => 400 ) );
			}
			update_post_meta( $post_id, '_yoast_wpseo_primary_' . $taxonomy, (int) $term_id );
		}
		$changed[] = 'primary_terms';
	}

	if ( ! $changed ) {
		return new WP_Error( 'yoast_no_change', 'Provide at least one SEO field to change.', array( 'status' => 400 ) );
	}

	$result            = yoast_pack_get_post_seo( $post_id );
	$result['changed'] = $changed;
	return $result;
}

/* -------------------------------------------------------------------------
 * Term SEO read / write.
 * ---------------------------------------------------------------------- */

/**
 * Read all Yoast SEO meta for a taxonomy term into the agent-facing shape.
 *
 * @return array|WP_Error
 */
function yoast_pack_get_term_seo( int $term_id, string $taxonomy ) {
	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'yoast_term_not_found', sprintf( 'No term %d found in taxonomy "%s".', $term_id, $taxonomy ), array( 'status' => 404 ) );
	}

	$seo = array();
	foreach ( yoast_pack_term_fields() as $agent_key => $def ) {
		list( $prefixed_key, $type ) = $def;
		$stored                      = WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, substr( $prefixed_key, strlen( 'wpseo_' ) ) );
		$seo[ $agent_key ]           = yoast_pack_decode( $type, ( false === $stored ) ? '' : $stored );
	}

	return array(
		'type'     => 'term',
		'id'       => $term_id,
		'taxonomy' => $taxonomy,
		'name'     => $term->name,
		'link'     => (string) get_term_link( $term ),
		'seo'      => $seo,
	);
}

/**
 * Apply a delta of SEO fields to a term via Yoast's own save path, then force
 * a term indexable rebuild (the option write does not fire edited_term).
 *
 * @return array|WP_Error
 */
function yoast_pack_set_term_seo( int $term_id, string $taxonomy, array $delta ) {
	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'yoast_term_not_found', sprintf( 'No term %d found in taxonomy "%s".', $term_id, $taxonomy ), array( 'status' => 404 ) );
	}

	$fields  = yoast_pack_term_fields();
	$changed = array();

	// Start from the FULL current meta: WPSEO_Taxonomy_Meta::validate_term_meta_data
	// resets text/social fields to their default when they are absent from the
	// values passed to set_values, so a partial write would wipe them. Load the
	// complete set, overlay the delta, and persist the whole thing.
	$values = WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
	$values = is_array( $values ) ? $values : array();

	foreach ( $delta as $agent_key => $value ) {
		if ( ! isset( $fields[ $agent_key ] ) ) {
			return new WP_Error(
				'yoast_unknown_field',
				sprintf( 'Unknown term SEO field "%s". Valid fields: %s.', $agent_key, implode( ', ', array_keys( $fields ) ) ),
				array( 'status' => 400 )
			);
		}
		list( $prefixed_key, $type ) = $fields[ $agent_key ];
		$values[ $prefixed_key ]     = yoast_pack_encode( $type, $value );
		$changed[]                   = $agent_key;
	}

	if ( ! $changed ) {
		return new WP_Error( 'yoast_no_change', 'Provide at least one SEO field to change.', array( 'status' => 400 ) );
	}

	WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $values );
	yoast_pack_rebuild_term_indexable( $term_id );

	$result            = yoast_pack_get_term_seo( $term_id, $taxonomy );
	$result['changed'] = $changed;
	return $result;
}

/** Force a rebuild of a term's Yoast indexable so the change renders. */
function yoast_pack_rebuild_term_indexable( int $term_id ): void {
	if ( ! function_exists( 'YoastSEO' ) ) {
		return;
	}
	try {
		$watcher = YoastSEO()->classes->get( \Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher::class );
		if ( $watcher && method_exists( $watcher, 'build_indexable' ) ) {
			$watcher->build_indexable( $term_id );
		}
	} catch ( \Throwable $e ) {
		// Indexable table may be absent; the meta write still persists.
	}
}

/* -------------------------------------------------------------------------
 * Settings: search appearance (wpseo_titles), social (wpseo_social),
 * general (wpseo). Read via get_option(), write via save_option().
 * ---------------------------------------------------------------------- */

/** The three user-facing Yoast option groups. */
function yoast_pack_setting_groups(): array {
	return array( 'wpseo', 'wpseo_titles', 'wpseo_social' );
}

/** General-settings keys we expose (curated subset of the wpseo group). */
function yoast_pack_general_keys(): array {
	return array(
		'features'              => array(
			'enable_xml_sitemap',
			'enable_cornerstone_content',
			'enable_text_link_counter',
			'enable_admin_bar_menu',
			'enable_metabox_insights',
			'enable_link_suggestions',
			'enable_index_now',
			'enable_llms_txt',
			'enable_enhanced_slack_sharing',
			'enable_headless_rest_endpoints',
			'enable_schema',
			'keyword_analysis_active',
			'content_analysis_active',
			'inclusive_language_analysis_active',
			'disableadvanced_meta',
			'tracking',
		),
		'webmaster_verification' => array( 'baiduverify', 'googleverify', 'msverify', 'yandexverify' ),
		'site_representation'    => array( 'company_or_person', 'site_type', 'environment_type', 'has_multiple_authors' ),
		'crawl_cleanup'          => array(
			'remove_feed_global',
			'remove_feed_post_comments',
			'remove_shortlinks',
			'remove_rest_api_links',
			'remove_rsd_wlw_links',
			'remove_oembed_links',
			'remove_generator',
			'remove_emoji_scripts',
			'remove_powered_by_header',
			'remove_pingback_header',
			'clean_campaign_tracking_urls',
			'deny_adsbot_crawling',
			'deny_ccbot_crawling',
			'deny_google_extended_crawling',
			'deny_gptbot_crawling',
		),
	);
}

/** Structured, agent-readable view of the Search Appearance (wpseo_titles) settings. */
function yoast_pack_get_search_appearance(): array {
	$o = WPSEO_Options::get_option( 'wpseo_titles' );

	$content_types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
		$n                     = $pt->name;
		$content_types[ $n ] = array(
			'title_template'      => $o[ "title-$n" ] ?? '',
			'metadesc_template'   => $o[ "metadesc-$n" ] ?? '',
			'noindex'             => ! empty( $o[ "noindex-$n" ] ),
			'schema_page_type'    => $o[ "schema-page-type-$n" ] ?? '',
			'schema_article_type' => $o[ "schema-article-type-$n" ] ?? '',
			'social_title'        => $o[ "social-title-$n" ] ?? '',
			'social_description'  => $o[ "social-description-$n" ] ?? '',
		);
	}

	$taxonomies = array();
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
		$n                = $tax->name;
		$taxonomies[ $n ] = array(
			'title_template'    => $o[ "title-tax-$n" ] ?? '',
			'metadesc_template' => $o[ "metadesc-tax-$n" ] ?? '',
			'noindex'           => ! empty( $o[ "noindex-tax-$n" ] ),
		);
	}

	$inst       = WPSEO_Options::get_option_instance( 'wpseo_titles' );
	$separators = ( $inst && method_exists( $inst, 'get_separator_options_for_display' ) )
		? $inst->get_separator_options_for_display()
		: array();

	return array(
		'separator'         => $o['separator'] ?? 'sc-dash',
		'separator_options' => $separators,
		'homepage'          => array(
			'title_template'    => $o['title-home-wpseo'] ?? '',
			'metadesc_template' => $o['metadesc-home-wpseo'] ?? '',
		),
		'special_pages'     => array(
			'author_title'      => $o['title-author-wpseo'] ?? '',
			'author_noindex'    => ! empty( $o['noindex-author-wpseo'] ),
			'archive_title'     => $o['title-archive-wpseo'] ?? '',
			'archive_noindex'   => ! empty( $o['noindex-archive-wpseo'] ),
			'search_title'      => $o['title-search-wpseo'] ?? '',
			'error404_title'    => $o['title-404-wpseo'] ?? '',
			'disable_author'    => ! empty( $o['disable-author'] ),
			'disable_date'      => ! empty( $o['disable-date'] ),
			'disable_post_format' => ! empty( $o['disable-post_format'] ),
		),
		'breadcrumbs'       => array(
			'enable'    => ! empty( $o['breadcrumbs-enable'] ),
			'separator' => $o['breadcrumbs-sep'] ?? '',
			'home_text' => $o['breadcrumbs-home'] ?? '',
			'prefix'    => $o['breadcrumbs-prefix'] ?? '',
		),
		'knowledge_graph'   => array(
			'company_or_person' => $o['company_or_person'] ?? '',
			'company_name'      => $o['company_name'] ?? '',
			'company_logo'      => $o['company_logo'] ?? '',
			'person_name'       => $o['person_name'] ?? '',
			'website_name'      => $o['website_name'] ?? '',
		),
		'content_types'     => $content_types,
		'taxonomies'        => $taxonomies,
	);
}

/** Read the wpseo_social option group. */
function yoast_pack_get_social(): array {
	$o    = WPSEO_Options::get_option( 'wpseo_social' );
	$keys = array(
		'opengraph',
		'twitter',
		'twitter_card_type',
		'facebook_site',
		'twitter_site',
		'instagram_url',
		'linkedin_url',
		'youtube_url',
		'pinterest_url',
		'wikipedia_url',
		'mastodon_url',
		'other_social_urls',
		'og_default_image',
		'og_default_image_id',
		'og_frontpage_title',
		'og_frontpage_desc',
		'og_frontpage_image',
		'og_frontpage_image_id',
		'pinterestverify',
	);
	$out  = array();
	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $o ) ) {
			$out[ $k ] = $o[ $k ];
		}
	}
	return $out;
}

/** Read the curated general (wpseo) settings, grouped. */
function yoast_pack_get_general(): array {
	$o   = WPSEO_Options::get_option( 'wpseo' );
	$out = array();
	foreach ( yoast_pack_general_keys() as $group => $keys ) {
		$out[ $group ] = array();
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $o ) ) {
				$out[ $group ][ $k ] = $o[ $k ];
			}
		}
	}
	return $out;
}

/**
 * Map a setting key to the option group that owns it, or '' if unknown.
 *
 * First by exact membership in the enriched group array, then by the group's
 * variable-key pattern table (e.g. "title-<cpt>", "noindex-tax-<tax>") so that
 * dynamic keys still route correctly without relying on WPSEO_Options::set()
 * (which silently caches unknown keys, defeating rejection).
 */
function yoast_pack_group_for_key( string $key ): string {
	foreach ( yoast_pack_setting_groups() as $group ) {
		$opt = WPSEO_Options::get_option( $group );
		if ( is_array( $opt ) && array_key_exists( $key, $opt ) ) {
			return $group;
		}
	}
	foreach ( yoast_pack_setting_groups() as $group ) {
		$inst = WPSEO_Options::get_option_instance( $group );
		if ( ! $inst || ! method_exists( $inst, 'get_patterns' ) ) {
			continue;
		}
		foreach ( (array) $inst->get_patterns() as $pattern ) {
			if ( '' !== $pattern && 0 === strpos( $key, $pattern ) ) {
				return $group;
			}
		}
	}
	return '';
}

/**
 * Persist a delta of settings across the three groups, validated. Each key is
 * routed to its owning group; the sanitize_option_* filter validates on save.
 *
 * @param array $settings key => value.
 * @return array { applied: {key:value}, rejected: {key:reason} }
 */
function yoast_pack_update_settings( array $settings ): array {
	$applied  = array();
	$rejected = array();

	foreach ( $settings as $key => $value ) {
		$key   = (string) $key;
		$group = yoast_pack_group_for_key( $key );

		if ( '' === $group ) {
			$rejected[ $key ] = 'unknown setting key';
			continue;
		}

		WPSEO_Options::save_option( $group, $key, $value );

		$stored          = WPSEO_Options::get_option( $group );
		$applied[ $key ] = $stored[ $key ] ?? null;
	}

	return array(
		'applied'  => $applied,
		'rejected' => $rejected,
	);
}

/* -------------------------------------------------------------------------
 * Introspection helpers.
 * ---------------------------------------------------------------------- */

/** The %%replacement variables%% available for title/metadesc templates. */
function yoast_pack_replacement_variables(): array {
	if ( ! class_exists( 'WPSEO_Replace_Vars' ) ) {
		return array();
	}
	$rv  = new WPSEO_Replace_Vars();
	$out = array();
	foreach ( $rv->get_replacement_variables_list() as $var ) {
		$out[] = array(
			'name'  => '%%' . ( $var['name'] ?? '' ) . '%%',
			'label' => $var['label'] ?? '',
		);
	}
	return $out;
}

/**
 * Discover posts/terms with their key SEO fields, for the agent to pick targets.
 *
 * @return array
 */
function yoast_pack_find_content( array $args ): array {
	$kind      = $args['kind'] ?? 'post';
	$search    = isset( $args['search'] ) ? (string) $args['search'] : '';
	$per_page  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;

	$items = array();

	if ( 'term' === $kind ) {
		$taxonomy = isset( $args['taxonomy'] ) && '' !== $args['taxonomy'] ? (string) $args['taxonomy'] : 'category';
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => $search,
				'number'     => $per_page,
				'hide_empty' => false,
			)
		);
		foreach ( ( is_array( $terms ) ? $terms : array() ) as $term ) {
			$items[] = array(
				'id'              => (int) $term->term_id,
				'taxonomy'        => $taxonomy,
				'name'            => $term->name,
				'seo_title'       => (string) WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, 'title' ),
				'meta_description' => (string) WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, 'desc' ),
				'focus_keyphrase' => (string) WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, 'focuskw' ),
			);
		}
		return array( 'kind' => 'term', 'taxonomy' => $taxonomy, 'items' => $items, 'count' => count( $items ) );
	}

	$post_type = isset( $args['post_type'] ) && '' !== $args['post_type'] ? (string) $args['post_type'] : 'any';
	$query     = new WP_Query(
		array(
			'post_type'           => $post_type,
			'post_status'         => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			's'                   => $search,
			'posts_per_page'      => $per_page,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);
	foreach ( $query->posts as $post ) {
		$items[] = array(
			'id'              => (int) $post->ID,
			'post_type'       => $post->post_type,
			'status'          => $post->post_status,
			'title'           => $post->post_title,
			'seo_title'       => (string) WPSEO_Meta::get_value( 'title', $post->ID ),
			'meta_description' => (string) WPSEO_Meta::get_value( 'metadesc', $post->ID ),
			'focus_keyphrase' => (string) WPSEO_Meta::get_value( 'focuskw', $post->ID ),
			'cornerstone'     => ( '1' === (string) WPSEO_Meta::get_value( 'is_cornerstone', $post->ID ) ),
		);
	}
	return array( 'kind' => 'post', 'post_type' => $post_type, 'items' => $items, 'count' => count( $items ) );
}
