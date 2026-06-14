<?php
/**
 * MaintenanceStore — load / read / persist helpers for the Maintenance plugin.
 *
 * The Maintenance plugin keeps ALL of its configuration in a single option,
 * `maintenance_options`. It is loaded through `mtnc_get_plugin_options()` and the
 * only write path is the admin settings handler (`mtnc_register_settings()`),
 * which rebuilds the whole array from $_POST and would DROP any key it does not
 * know about (including PRO keys). That makes it unusable for partial updates.
 *
 * So the ergonomic verbs here follow load-modify-save: read the current option
 * through the plugin's own loader, overlay only the caller's delta, re-apply the
 * exact same per-field sanitization the plugin's save handler uses, write the
 * full merged array back, then clear caches the same way the plugin does after a
 * save (`MTNC::mtnc_clear_cache()`). Every other key — including ones we never
 * expose — is preserved untouched.
 *
 * @package AcfwAbilityPackMaintenance
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const MTNC_PACK_OPTION = 'maintenance_options';

/**
 * Image-attachment option keys (stored as numeric attachment IDs; '' = unset).
 *
 * @return string[]
 */
function mtnc_pack_image_keys(): array {
	return array( 'logo', 'retina_logo', 'body_bg', 'bg_image_portrait', 'preloader_img' );
}

/**
 * Confirms the Maintenance plugin is loaded so its loader/defaults are available.
 *
 * @return true|WP_Error
 */
function mtnc_pack_ready() {
	if ( ! function_exists( 'mtnc_get_plugin_options' ) ) {
		return new WP_Error( 'mtnc_inactive', 'The Maintenance plugin is not active.', array( 'status' => 500 ) );
	}
	return true;
}

/**
 * Reads the current configuration, parsed with the plugin's own defaults so
 * every known key is present. This is the plugin's canonical loader.
 *
 * @return array
 */
function mtnc_pack_load(): array {
	return (array) mtnc_get_plugin_options( false );
}

/**
 * Applies the plugin's per-field sanitization to a single value, mirroring
 * exactly what `mtnc_register_settings()` does on a real form save.
 *
 * @param string $key   Option key.
 * @param mixed  $value Raw value.
 * @return mixed Sanitized value.
 */
function mtnc_pack_sanitize_value( string $key, $value ) {
	switch ( $key ) {
		case 'description':
		case 'custom_css':
			return wp_kses_post( (string) $value );

		case 'state':
			return $value ? 1 : 0;

		case 'show_some_love':
		case 'is_login':
		case '503_enabled':
		case 'is_blur':
			return (bool) $value;

		case 'logo_width':
		case 'blur_intensity':
			return (int) $value;

		case 'logo_height':
			return ( '' === $value || null === $value ) ? '' : (int) $value;

		case 'logo':
		case 'retina_logo':
		case 'body_bg':
		case 'bg_image_portrait':
		case 'preloader_img':
			return ( '' === $value || null === $value || 0 === (int) $value ) ? '' : (int) $value;

		case 'exclude_pages':
			return $value; // Already structured by the caller.

		default:
			return sanitize_text_field( (string) $value );
	}
}

/**
 * Persists a delta of option keys through load-modify-save.
 *
 * @param array $delta Map of option key => new value (already schema-typed).
 * @return array|WP_Error The reloaded configuration, or WP_Error.
 */
function mtnc_pack_save( array $delta ) {
	$ready = mtnc_pack_ready();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}

	$current = mtnc_pack_load();

	foreach ( $delta as $key => $value ) {
		$current[ (string) $key ] = mtnc_pack_sanitize_value( (string) $key, $value );
	}

	// Mark settings as customized, exactly like the plugin's save handler.
	$current['default_settings'] = false;

	update_option( MTNC_PACK_OPTION, $current );

	// Flush page caches the same way the plugin does after saving.
	if ( class_exists( 'MTNC' ) && method_exists( 'MTNC', 'mtnc_clear_cache' ) ) {
		MTNC::mtnc_clear_cache();
	}

	return mtnc_pack_load();
}

/* -------------------------------------------------------------------------
 * Read views
 * ---------------------------------------------------------------------- */

/**
 * Compact status of maintenance mode.
 *
 * @return array
 */
function mtnc_pack_status_view(): array {
	$o = mtnc_pack_load();
	return array(
		'enabled'                => (bool) $o['state'],
		'preview_url'            => home_url( '?maintenance-preview' ),
		'site_url'               => home_url( '/' ),
		'page_title'             => (string) $o['page_title'],
		'heading'                => (string) $o['heading'],
		'http_503_enabled'       => ! empty( $o['503_enabled'] ),
		'frontend_login_enabled' => ! empty( $o['is_login'] ),
		'excluded_page_count'    => count( mtnc_pack_excluded_ids( $o ) ),
	);
}

/**
 * Flat, unique list of excluded post IDs from an options array.
 *
 * @param array $o Options.
 * @return int[]
 */
function mtnc_pack_excluded_ids( array $o ): array {
	$ids = array();
	if ( ! empty( $o['exclude_pages'] ) && is_array( $o['exclude_pages'] ) ) {
		foreach ( $o['exclude_pages'] as $list ) {
			foreach ( (array) $list as $id ) {
				if ( '' !== $id && null !== $id ) {
					$ids[] = (int) $id;
				}
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Resolves excluded IDs to {id, title, post_type} descriptors.
 *
 * @param array $o Options.
 * @return array<int,array>
 */
function mtnc_pack_resolved_excluded( array $o ): array {
	$out = array();
	foreach ( mtnc_pack_excluded_ids( $o ) as $id ) {
		$p = get_post( $id );
		$out[] = array(
			'id'        => $id,
			'title'     => $p ? (string) $p->post_title : '',
			'post_type' => $p ? (string) $p->post_type : '',
			'exists'    => (bool) $p,
		);
	}
	return $out;
}

/**
 * Groups a flat ID list into the plugin's exclude_pages shape
 * (post_type => [ "id", ... ] with string IDs, as the form stores them).
 *
 * @param int[] $ids Post IDs.
 * @return array
 */
function mtnc_pack_group_excluded( array $ids ): array {
	$grouped = array();
	foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			continue;
		}
		$grouped[ $p->post_type ][] = (string) $id;
	}
	return $grouped;
}

/**
 * Full, grouped settings view for reads.
 *
 * @return array
 */
function mtnc_pack_settings_view(): array {
	$o = mtnc_pack_load();
	return array(
		'maintenance_enabled' => (bool) $o['state'],
		'preview_url'         => home_url( '?maintenance-preview' ),
		'content'             => array(
			'page_title'     => (string) $o['page_title'],
			'heading'        => (string) $o['heading'],
			'description'    => (string) $o['description'],
			'footer_text'    => (string) $o['footer_text'],
			'show_some_love' => ! empty( $o['show_some_love'] ),
			'is_login'       => ! empty( $o['is_login'] ),
		),
		'design'              => array(
			'logo'              => (string) $o['logo'],
			'retina_logo'       => (string) $o['retina_logo'],
			'logo_width'        => $o['logo_width'],
			'logo_height'       => $o['logo_height'],
			'body_bg'           => (string) $o['body_bg'],
			'bg_image_portrait' => (string) $o['bg_image_portrait'],
			'preloader_img'     => (string) $o['preloader_img'],
			'body_bg_color'     => (string) $o['body_bg_color'],
			'font_color'        => (string) $o['font_color'],
			'controls_bg_color' => (string) $o['controls_bg_color'],
			'body_font_family'  => (string) $o['body_font_family'],
			'body_font_subset'  => (string) $o['body_font_subset'],
			'is_blur'           => ! empty( $o['is_blur'] ),
			'blur_intensity'    => (int) $o['blur_intensity'],
		),
		'advanced'            => array(
			'gg_analytics_id' => (string) $o['gg_analytics_id'],
			'503_enabled'     => ! empty( $o['503_enabled'] ),
			'custom_css'      => (string) $o['custom_css'],
		),
		'excluded_pages'      => mtnc_pack_resolved_excluded( $o ),
	);
}

/* -------------------------------------------------------------------------
 * Fonts
 * ---------------------------------------------------------------------- */

/**
 * Available font families: built-in stacks + Bunny web-font families with their
 * variants (the values valid for body_font_subset).
 *
 * @return array{standard:string[],bunny:array<string,string[]>}
 */
function mtnc_pack_fonts(): array {
	global $standart_fonts;

	$standard = ( isset( $standart_fonts ) && is_array( $standart_fonts ) )
		? array_keys( $standart_fonts )
		: array();

	$bunny = array();
	if ( function_exists( 'mtnc_get_bunny_fonts' ) ) {
		$json = json_decode( (string) mtnc_get_bunny_fonts(), true );
		if ( is_array( $json ) ) {
			foreach ( $json as $family => $info ) {
				$bunny[ (string) $family ] = isset( $info['variants'] )
					? array_values( array_map( 'strval', (array) $info['variants'] ) )
					: array();
			}
		}
	}

	return array(
		'standard' => $standard,
		'bunny'    => $bunny,
	);
}

/**
 * Validates a font family against the known standard + Bunny families.
 *
 * @param string $family Family value.
 * @return true|WP_Error
 */
function mtnc_pack_validate_font_family( string $family ) {
	$fonts = mtnc_pack_fonts();
	if ( in_array( $family, $fonts['standard'], true ) || isset( $fonts['bunny'][ $family ] ) ) {
		return true;
	}
	return new WP_Error(
		'mtnc_bad_font',
		sprintf( 'Unknown font family "%s". Call maintenance/list-fonts for valid families.', $family ),
		array( 'status' => 400 )
	);
}
