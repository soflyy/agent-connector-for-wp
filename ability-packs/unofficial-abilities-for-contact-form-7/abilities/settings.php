<?php
/**
 * Additional-settings abilities: set/unset individual key:value settings (delta),
 * and list the well-known keys.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/update-additional-settings',
		'label'            => 'Update Additional Settings',
		'description'      => 'Set or remove entries in a form\'s "Additional Settings" (the key: value lines, e.g. demo_mode: on, skip_mail: on, subscribers_only: on). Pass set as a map of key => value to add/replace those keys, and/or unset as a list of keys to remove. Other settings lines are preserved. Returns the parsed settings. Use cf7/list-additional-settings-keys for well-known keys.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'set'   => array(
					'type'                 => 'object',
					'description'          => 'Map of setting key => value to add or replace, e.g. { "demo_mode": "on" }.',
					'additionalProperties' => array( 'type' => array( 'string', 'boolean', 'number' ) ),
				),
				'unset' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Setting keys to remove entirely.',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$set   = isset( $input['set'] ) ? (array) $input['set'] : array();
			$unset = isset( $input['unset'] ) ? array_map( 'strval', (array) $input['unset'] ) : array();

			if ( ! $set && ! $unset ) {
				return new WP_Error( 'cf7_no_change', 'Provide set and/or unset.', array( 'status' => 400 ) );
			}

			foreach ( array_keys( $set ) as $key ) {
				if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', (string) $key ) ) {
					return new WP_Error( 'cf7_bad_setting_key', sprintf( 'Invalid setting key "%s".', $key ), array( 'status' => 400 ) );
				}
			}

			// Rebuild the settings text line-by-line, preserving order and any lines
			// (comments, blanks) we don't recognize as key:value pairs.
			$raw     = (string) $form->prop( 'additional_settings' );
			$pattern = '/^([a-zA-Z0-9_]+)[\t ]*:(.*)$/';
			$out     = array();
			$seen    = array();

			foreach ( explode( "\n", $raw ) as $line ) {
				if ( preg_match( $pattern, $line, $m ) ) {
					$key = $m[1];
					if ( in_array( $key, $unset, true ) ) {
						continue; // drop
					}
					if ( array_key_exists( $key, $set ) ) {
						if ( in_array( $key, $seen, true ) ) {
							continue; // already wrote replacement; drop dup
						}
						$out[]  = $key . ': ' . cf7_pack_setting_value( $set[ $key ] );
						$seen[] = $key;
						continue;
					}
					$out[] = $line;
				} else {
					$out[] = $line;
				}
			}

			// Append any set keys that weren't already present.
			foreach ( $set as $key => $value ) {
				if ( ! in_array( $key, $seen, true ) ) {
					$out[] = $key . ': ' . cf7_pack_setting_value( $value );
				}
			}

			$new_raw = trim( implode( "\n", $out ) );

			$saved = cf7_pack_save( (int) $form->id(), array( 'additional_settings' => $new_raw ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'raw'    => (string) $saved->prop( 'additional_settings' ),
				'parsed' => cf7_pack_parse_settings( (string) $saved->prop( 'additional_settings' ) ),
			);
		},
	)
);

/**
 * Normalizes a setting value to its CF7 string form (booleans -> on/off).
 */
function cf7_pack_setting_value( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? 'on' : 'off';
	}
	return trim( (string) $value );
}

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/list-additional-settings-keys',
		'label'            => 'List Additional Settings Keys',
		'description'      => 'Introspection: return the well-known "Additional Settings" keys Contact Form 7 (and its core modules) recognize, with what each does and accepted values. Note: arbitrary keys are allowed too; this lists the documented ones.',
		'category'         => 'cf7',
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
			$settings = array(
				array( 'key' => 'demo_mode', 'values' => 'on/off', 'description' => 'Accept submissions but never actually send mail or store entries.' ),
				array( 'key' => 'skip_mail', 'values' => 'on/off', 'description' => 'Process and validate the submission but do not send the email.' ),
				array( 'key' => 'subscribers_only', 'values' => 'on/off', 'description' => 'Only logged-in users with the wpcf7_submit capability can submit; enables nonce checking.' ),
				array( 'key' => 'do_not_store', 'values' => 'on/off', 'description' => 'Prevent the submission from being stored (e.g. by Flamingo).' ),
				array( 'key' => 'flamingo_email', 'values' => 'a mail-tag', 'description' => 'Which field Flamingo treats as the sender email (default [your-email]).' ),
				array( 'key' => 'flamingo_name', 'values' => 'a mail-tag', 'description' => 'Which field Flamingo treats as the sender name (default [your-name]).' ),
				array( 'key' => 'flamingo_subject', 'values' => 'text/mail-tags', 'description' => 'Subject used for the stored Flamingo record.' ),
			);
			return array( 'settings' => $settings );
		},
	)
);
