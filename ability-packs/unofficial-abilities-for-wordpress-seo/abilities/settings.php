<?php
/**
 * Site-wide Yoast settings abilities: read the Search Appearance, Social, and
 * General settings, and write any of them with one validated delta verb.
 *
 * @package AcfwAbilityPackYoast
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/get-search-appearance',
		'label'            => 'Get Search Appearance Settings',
		'description'      => 'Read Yoast\'s Search Appearance settings (the wpseo_titles option): the title separator (and the available separator keys), homepage title/metadesc templates, special-page (author/date/archive/search/404) titles and noindex toggles, breadcrumb settings, the Knowledge Graph / site representation (organization vs person), and per-content-type and per-taxonomy title templates, meta description templates, noindex flags, and schema types. The exact keys returned here are the keys you pass to yoast/update-settings (e.g. title-post, metadesc-page, noindex-tax-category, schema-page-type-post, separator, breadcrumbs-sep, company_name).',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			return yoast_pack_get_search_appearance();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/get-social-settings',
		'label'            => 'Get Social Settings',
		'description'      => 'Read Yoast\'s Social settings (the wpseo_social option): whether Open Graph and X/Twitter card output are enabled, the Twitter card type, linked social profile URLs (Facebook, X/Twitter, Instagram, LinkedIn, YouTube, Pinterest, Wikipedia, Mastodon, and any other URLs), the default social image, and the front-page Open Graph title/description/image. The returned keys are the keys you pass to yoast/update-settings (e.g. opengraph, twitter_card_type, facebook_site, og_default_image).',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			return yoast_pack_get_social();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/get-general-settings',
		'label'            => 'Get General Settings',
		'description'      => 'Read Yoast\'s curated General settings (the wpseo option), grouped: feature toggles (XML sitemap, cornerstone content, admin bar menu, link counter, IndexNow, llms.txt, SEO/readability/inclusive-language analysis, usage tracking, etc.), webmaster verification codes (Google/Bing/Baidu/Yandex), site representation (organization vs person, site type, environment type), and crawl-cleanup toggles. The returned keys are the keys you pass to yoast/update-settings (e.g. enable_xml_sitemap, googleverify, company_or_person).',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			return yoast_pack_get_general();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/update-settings',
		'label'            => 'Update Yoast Settings',
		'description'      => 'Update one or more site-wide Yoast settings in a single call. Pass a "settings" object of key => value pairs; each key is routed to its owning Yoast option group (wpseo, wpseo_titles, or wpseo_social) and saved through Yoast\'s validation. Pass only the keys you want to change. Use the get-* settings abilities to discover valid keys and current values first. Notes: booleans accept true/false; the title "separator" takes a key like "sc-dash"/"sc-pipe" (not the literal character) — see separator_options from yoast/get-search-appearance; title/metadesc templates may use %%replacement variables%% (see yoast/list-replacement-variables). Returns "applied" (key => stored value after validation) and "rejected" (unknown keys). A value that came back unchanged in "applied" was rejected by Yoast validation.',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type'        => 'object',
					'description' => 'Map of Yoast setting key => new value. E.g. {"separator":"sc-pipe","title-post":"%%title%% %%sep%% %%sitename%%","enable_xml_sitemap":true,"company_name":"Acme"}.',
				),
			),
			'required'             => array( 'settings' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'applied'  => array( 'type' => 'object' ),
				'rejected' => array( 'type' => 'object' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
			if ( ! $settings ) {
				return new WP_Error( 'yoast_no_change', 'Provide at least one setting to change.', array( 'status' => 400 ) );
			}
			return yoast_pack_update_settings( $settings );
		},
		'summarize'        => static function ( array $input ): array {
			return array(
				'keys' => isset( $input['settings'] ) && is_array( $input['settings'] ) ? implode( ',', array_keys( $input['settings'] ) ) : '',
			);
		},
	)
);
