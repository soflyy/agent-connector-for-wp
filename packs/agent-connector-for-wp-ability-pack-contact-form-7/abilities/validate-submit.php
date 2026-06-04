<?php
/**
 * Validation & test-submission abilities.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/validate-form',
		'label'            => 'Validate Form Configuration',
		'description'      => 'Run Contact Form 7\'s own configuration validator against a form and return any problems it finds (bad mail-tag references, invalid recipient syntax, deprecated settings, etc.). Use this after editing to confirm the form is still well-formed.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'valid'  => array( 'type' => 'boolean' ),
				'errors' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$errors = cf7_pack_config_errors( $form );

			return array(
				'valid'  => empty( $errors ),
				'errors' => $errors,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/submit-form',
		'label'            => 'Test-Submit Form',
		'description'      => 'Submit a form programmatically with the given field values, exactly as a visitor would, and return the result (status such as mail_sent / validation_failed / acceptance_missing, the response message, and any invalid fields). By default it does NOT send a real email (skip_mail) so you can safely test validation and flow; pass send_mail:true to actually deliver. The anti-spam nonce/user-agent heuristics are bypassed for this server-side test. data is a map of field name to value (a value may be an array for checkbox/select).',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'data'      => array(
					'type'        => 'object',
					'description' => 'Map of field name => value. Value may be a string or an array (checkbox/select). Omit a field to leave it empty.',
				),
				'send_mail' => array( 'type' => 'boolean', 'description' => 'Actually send the email(s). Default false (validation/flow only).' ),
			),
			'required'             => array( 'id', 'data' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$send_mail = ! empty( $input['send_mail'] );
			$data      = isset( $input['data'] ) ? (array) $input['data'] : array();

			// Stage the submission in the request superglobals, the way the real
			// front-end POST would arrive, then drive CF7's own submit() pipeline.
			$saved_post = $_POST;

			$unit_tag = 'wpcf7-f' . $form->id() . '-o1';

			$_POST = array(
				'_wpcf7'                 => $form->id(),
				'_wpcf7_version'         => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
				'_wpcf7_locale'          => $form->locale(),
				'_wpcf7_unit_tag'        => $unit_tag,
				'_wpcf7_container_post'  => 0,
				'_wpcf7_posted_data_hash' => '',
			);

			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && '' !== $key && '_' !== $key[0] ) {
					$_POST[ $key ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
				}
			}

			// This is a trusted server-side test, not a browser POST, so suppress
			// the nonce / user-agent spam heuristics (they would otherwise flag it).
			$skip_spam = static function () {
				return true;
			};
			add_filter( 'wpcf7_skip_spam_check', $skip_spam );

			$result = $form->submit( array( 'skip_mail' => ! $send_mail ) );

			remove_filter( 'wpcf7_skip_spam_check', $skip_spam );
			$_POST = $saved_post;

			$out = array(
				'status'       => isset( $result['status'] ) ? (string) $result['status'] : '',
				'message'      => isset( $result['message'] ) ? (string) $result['message'] : '',
				'mail_sent'    => isset( $result['status'] ) && 'mail_sent' === $result['status'] && $send_mail,
				'invalid_fields' => array(),
			);

			if ( ! empty( $result['invalid_fields'] ) ) {
				foreach ( (array) $result['invalid_fields'] as $name => $field ) {
					$out['invalid_fields'][] = array(
						'field'  => (string) $name,
						'reason' => isset( $field['reason'] ) ? (string) $field['reason'] : '',
					);
				}
			}

			return $out;
		},
		'summarize'        => static function ( array $input ): array {
			return array(
				'id'        => isset( $input['id'] ) ? (string) $input['id'] : '',
				'fields'    => isset( $input['data'] ) && is_array( $input['data'] ) ? array_keys( $input['data'] ) : array(),
				'send_mail' => ! empty( $input['send_mail'] ),
			);
		},
	)
);
