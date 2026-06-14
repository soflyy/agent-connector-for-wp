<?php
/**
 * Read-all settings, content/advanced delta updates, and settings introspection.
 *
 * @package AcfwAbilityPackMaintenance
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/get-settings',
		'label'            => 'Get All Maintenance Settings',
		'description'      => 'Return the complete current configuration of the maintenance page, grouped into content, design, advanced, and the list of excluded pages (resolved to id/title/type). Use this to inspect before making targeted updates.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			return mtnc_pack_settings_view();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/update-content',
		'label'            => 'Update Page Content',
		'description'      => 'Update the text content of the maintenance page. Pass only the fields you want to change; the rest are left as-is. "description" accepts safe HTML (it is run through wp_kses_post). Returns the updated content settings.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'page_title'     => array( 'type' => 'string', 'description' => 'Browser/SEO <title> of the maintenance page.' ),
				'heading'        => array( 'type' => 'string', 'description' => 'Large headline shown on the page.' ),
				'description'    => array( 'type' => 'string', 'description' => 'Body text under the headline. Safe HTML allowed.' ),
				'footer_text'    => array( 'type' => 'string', 'description' => 'Footer text (e.g. a copyright line).' ),
				'show_some_love' => array( 'type' => 'boolean', 'description' => 'Show a small "powered by WP Maintenance" credit link in the footer.' ),
				'is_login'       => array( 'type' => 'boolean', 'description' => 'Show the built-in front-end login form on the maintenance page.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$allowed = array( 'page_title', 'heading', 'description', 'footer_text', 'show_some_love', 'is_login' );
			$delta   = array_intersect_key( $input, array_flip( $allowed ) );
			if ( ! $delta ) {
				return new WP_Error( 'mtnc_no_change', 'Provide at least one content field to update.', array( 'status' => 400 ) );
			}
			$saved = mtnc_pack_save( $delta );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return mtnc_pack_settings_view()['content'];
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/update-advanced',
		'label'            => 'Update Advanced Settings',
		'description'      => 'Update advanced options: Google Analytics tracking ID, the 503 "Service Temporarily Unavailable" HTTP response (recommended for SEO so the site stays out of the index while down), and custom CSS injected into the page. Pass only the fields you want to change. Returns the updated advanced settings.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'gg_analytics_id' => array( 'type' => 'string', 'description' => 'Google Analytics / GA4 measurement ID (e.g. G-XXXXXXX or UA-XXXXX-X). Empty string to remove. Ignored at render time if 503 is enabled.' ),
				'503_enabled'     => array( 'type' => 'boolean', 'description' => 'Send an HTTP 503 Service Unavailable status. Disables analytics output while on.' ),
				'custom_css'      => array( 'type' => 'string', 'description' => 'Raw CSS (no <style> tags) appended to the page styles.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$allowed = array( 'gg_analytics_id', '503_enabled', 'custom_css' );
			$delta   = array_intersect_key( $input, array_flip( $allowed ) );
			if ( ! $delta ) {
				return new WP_Error( 'mtnc_no_change', 'Provide at least one advanced field to update.', array( 'status' => 400 ) );
			}
			$saved = mtnc_pack_save( $delta );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return mtnc_pack_settings_view()['advanced'];
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/describe-settings',
		'label'            => 'Describe Settings',
		'description'      => 'Introspection: list every configurable setting of the maintenance page — its key, type, default, which update ability sets it, and notes. Use this to discover valid inputs before calling the update abilities.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'settings' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$s = static function ( $key, $type, $default, $verb, $notes ) {
				return compact( 'key', 'type', 'default', 'verb', 'notes' );
			};
			return array(
				'settings' => array(
					$s( 'state', 'boolean', true, 'maintenance/enable + maintenance/disable', 'Master on/off switch for maintenance mode.' ),
					$s( 'page_title', 'string', 'Site is undergoing maintenance', 'maintenance/update-content', 'Page <title>.' ),
					$s( 'heading', 'string', 'Maintenance mode is on', 'maintenance/update-content', 'Headline.' ),
					$s( 'description', 'html', 'Site will be available soon. Thank you for your patience!', 'maintenance/update-content', 'Body text; safe HTML allowed.' ),
					$s( 'footer_text', 'string', '© {site name} {year}', 'maintenance/update-content', 'Footer text.' ),
					$s( 'show_some_love', 'boolean', false, 'maintenance/update-content', 'Footer credit link.' ),
					$s( 'is_login', 'boolean', true, 'maintenance/update-content', 'Show front-end login form.' ),
					$s( 'logo', 'attachment_id', '', 'maintenance/update-design or maintenance/set-image-from-url', 'Logo image.' ),
					$s( 'retina_logo', 'attachment_id', '', 'maintenance/update-design or maintenance/set-image-from-url', 'Retina (2x) logo.' ),
					$s( 'logo_width', 'integer', 220, 'maintenance/update-design', 'Logo width in px.' ),
					$s( 'logo_height', 'integer', '', 'maintenance/update-design', 'Logo height in px (blank = auto).' ),
					$s( 'body_bg', 'attachment_id', '(sample image)', 'maintenance/update-design or maintenance/set-image-from-url', 'Full-screen background image.' ),
					$s( 'bg_image_portrait', 'attachment_id', '', 'maintenance/update-design or maintenance/set-image-from-url', 'Background image for portrait orientation.' ),
					$s( 'preloader_img', 'attachment_id', '', 'maintenance/update-design or maintenance/set-image-from-url', 'Page preloader image.' ),
					$s( 'body_bg_color', 'hex_color', '#111111', 'maintenance/update-design', 'Background color.' ),
					$s( 'font_color', 'hex_color', '#ffffff', 'maintenance/update-design', 'Font color.' ),
					$s( 'controls_bg_color', 'hex_color', '#111111', 'maintenance/update-design', 'Login block background color.' ),
					$s( 'body_font_family', 'string', 'Open Sans', 'maintenance/update-design', 'Font family; see maintenance/list-fonts.' ),
					$s( 'body_font_subset', 'string', 'Latin', 'maintenance/update-design', 'Font variant/subset; see maintenance/list-fonts.' ),
					$s( 'is_blur', 'boolean', false, 'maintenance/update-design', 'Apply blur to the background image.' ),
					$s( 'blur_intensity', 'integer', 5, 'maintenance/update-design', 'Blur radius in px.' ),
					$s( 'gg_analytics_id', 'string', '', 'maintenance/update-advanced', 'Google Analytics ID.' ),
					$s( '503_enabled', 'boolean', false, 'maintenance/update-advanced', 'Send HTTP 503 status.' ),
					$s( 'custom_css', 'css', '', 'maintenance/update-advanced', 'Custom CSS.' ),
					$s( 'exclude_pages', 'map<post_type,id[]>', '', 'maintenance/update-excluded-pages', 'Pages/posts shown normally even while in maintenance.' ),
				),
			);
		},
	)
);
