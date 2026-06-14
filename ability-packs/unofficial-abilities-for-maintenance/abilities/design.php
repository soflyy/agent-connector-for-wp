<?php
/**
 * Design abilities: colors/fonts/blur/logo deltas, font introspection, and
 * sideloading an image from a URL into an image slot.
 *
 * @package AcfwAbilityPackMaintenance
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Validates a #rgb / #rrggbb hex color.
 *
 * @param string $value Candidate.
 * @return true|WP_Error
 */
function mtnc_pack_validate_hex( string $value ) {
	if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
		return true;
	}
	return new WP_Error( 'mtnc_bad_color', sprintf( 'Invalid hex color "%s" (use #rgb or #rrggbb).', $value ), array( 'status' => 400 ) );
}

/**
 * Validates that an attachment ID refers to an existing image (0 clears).
 *
 * @param int $id Attachment ID.
 * @return true|WP_Error
 */
function mtnc_pack_validate_attachment( int $id ) {
	if ( $id <= 0 ) {
		return true; // clears the slot
	}
	if ( 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
		return true;
	}
	return new WP_Error( 'mtnc_bad_attachment', sprintf( 'Attachment %d is not an existing image. Use maintenance/set-image-from-url to add one.', $id ), array( 'status' => 400 ) );
}

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/update-design',
		'label'            => 'Update Page Design',
		'description'      => 'Update the look of the maintenance page: colors, fonts, background blur, logo sizing, and image slots (by attachment ID). Pass only the fields you want to change. Colors must be hex (#rgb / #rrggbb). Font family must be one returned by maintenance/list-fonts. Image fields take an existing attachment ID (or 0 to clear) — to fetch a new image from a URL use maintenance/set-image-from-url. Returns the updated design settings.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'body_bg_color'     => array( 'type' => 'string', 'description' => 'Background color, hex.' ),
				'font_color'        => array( 'type' => 'string', 'description' => 'Font color, hex.' ),
				'controls_bg_color' => array( 'type' => 'string', 'description' => 'Login-block background color, hex.' ),
				'body_font_family'  => array( 'type' => 'string', 'description' => 'Font family (see maintenance/list-fonts).' ),
				'body_font_subset'  => array( 'type' => 'string', 'description' => 'Font variant/subset for the chosen Bunny font (see maintenance/list-fonts).' ),
				'is_blur'           => array( 'type' => 'boolean', 'description' => 'Apply blur to the background image.' ),
				'blur_intensity'    => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Blur radius in px.' ),
				'logo_width'        => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Logo width in px.' ),
				'logo_height'       => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Logo height in px (omit for auto).' ),
				'logo'              => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Logo attachment ID (0 to clear).' ),
				'retina_logo'       => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Retina logo attachment ID (0 to clear).' ),
				'body_bg'           => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Background image attachment ID (0 to clear).' ),
				'bg_image_portrait' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Portrait background image attachment ID (0 to clear).' ),
				'preloader_img'     => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Preloader image attachment ID (0 to clear).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$allowed = array(
				'body_bg_color', 'font_color', 'controls_bg_color', 'body_font_family', 'body_font_subset',
				'is_blur', 'blur_intensity', 'logo_width', 'logo_height',
				'logo', 'retina_logo', 'body_bg', 'bg_image_portrait', 'preloader_img',
			);
			$delta = array_intersect_key( $input, array_flip( $allowed ) );
			if ( ! $delta ) {
				return new WP_Error( 'mtnc_no_change', 'Provide at least one design field to update.', array( 'status' => 400 ) );
			}

			foreach ( array( 'body_bg_color', 'font_color', 'controls_bg_color' ) as $ck ) {
				if ( isset( $delta[ $ck ] ) ) {
					$v = mtnc_pack_validate_hex( (string) $delta[ $ck ] );
					if ( is_wp_error( $v ) ) {
						return $v;
					}
				}
			}

			if ( isset( $delta['body_font_family'] ) ) {
				$v = mtnc_pack_validate_font_family( (string) $delta['body_font_family'] );
				if ( is_wp_error( $v ) ) {
					return $v;
				}
			}

			foreach ( mtnc_pack_image_keys() as $ik ) {
				if ( isset( $delta[ $ik ] ) ) {
					$v = mtnc_pack_validate_attachment( (int) $delta[ $ik ] );
					if ( is_wp_error( $v ) ) {
						return $v;
					}
				}
			}

			$saved = mtnc_pack_save( $delta );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return mtnc_pack_settings_view()['design'];
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/list-fonts',
		'label'            => 'List Available Fonts',
		'description'      => 'Introspection: list the font families valid for body_font_family — the built-in system stacks plus the Bunny web fonts, each with its available variants (the valid values for body_font_subset). Call this before maintenance/update-design when changing the font.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'standard' => array( 'type' => 'array' ),
				'bunny'    => array( 'type' => 'object' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			return mtnc_pack_fonts();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/set-image-from-url',
		'label'            => 'Set Image From URL',
		'description'      => 'Download an image from a URL into the WordPress media library and assign it to one of the maintenance page image slots: logo, retina_logo, body_bg (background), bg_image_portrait, or preloader_img. This is the easy way to set the logo/background without a pre-existing attachment. Returns the new attachment ID and the slot it was set on.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'slot' => array(
					'type'        => 'string',
					'enum'        => array( 'logo', 'retina_logo', 'body_bg', 'bg_image_portrait', 'preloader_img' ),
					'description' => 'Which image slot to assign the downloaded image to.',
				),
				'url'  => array( 'type' => 'string', 'description' => 'Publicly reachable image URL to download.' ),
			),
			'required'             => array( 'slot', 'url' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'slot'          => array( 'type' => 'string' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$slot = (string) $input['slot'];
			if ( ! in_array( $slot, mtnc_pack_image_keys(), true ) ) {
				return new WP_Error( 'mtnc_bad_slot', 'Unknown image slot.', array( 'status' => 400 ) );
			}
			$url = esc_url_raw( (string) $input['url'] );
			if ( '' === $url ) {
				return new WP_Error( 'mtnc_bad_url', 'A valid url is required.', array( 'status' => 400 ) );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = media_sideload_image( $url, 0, null, 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error( 'mtnc_sideload_failed', 'Could not download/import the image: ' . $attachment_id->get_error_message(), array( 'status' => 502 ) );
			}

			$saved = mtnc_pack_save( array( $slot => (int) $attachment_id ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'slot'          => $slot,
				'attachment_id' => (int) $attachment_id,
				'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
			);
		},
	)
);
