<?php
/**
 * Messages abilities: edit a form's response messages (delta), and list the
 * available message keys with their meaning and defaults.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/update-messages',
		'label'            => 'Update Response Messages',
		'description'      => 'Change one or more of a form\'s response messages (e.g. the "message sent" or validation texts). Pass a map of message-key => text for only the messages you want to change; the others are preserved. Use cf7/list-message-keys to see the keys.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'messages' => array(
					'type'                 => 'object',
					'description'          => 'Map of message key to new text, e.g. { "mail_sent_ok": "Thanks!" }.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'id', 'messages' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$delta = (array) $input['messages'];
			if ( ! $delta ) {
				return new WP_Error( 'cf7_no_change', 'Provide at least one message to change.', array( 'status' => 400 ) );
			}

			$known = array_keys( wpcf7_messages() );
			$bad   = array_diff( array_keys( $delta ), $known );
			if ( $bad ) {
				return new WP_Error(
					'cf7_unknown_message_key',
					sprintf( 'Unknown message key(s): %s. Call cf7/list-message-keys.', implode( ', ', $bad ) ),
					array( 'status' => 400 )
				);
			}

			// Merge onto the full current message set (sanitizer drops unknown keys
			// and would otherwise reset everything not supplied).
			$messages = (array) $form->prop( 'messages' );
			foreach ( $delta as $key => $text ) {
				$messages[ $key ] = (string) $text;
			}

			$saved = cf7_pack_save( (int) $form->id(), array( 'messages' => $messages ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array( 'messages' => (array) $saved->prop( 'messages' ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/list-message-keys',
		'label'            => 'List Message Keys',
		'description'      => 'Introspection: return every response-message key a form has, what each is shown for, and its default text. Use before cf7/update-messages.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'message_keys' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$keys = array();
			foreach ( wpcf7_messages() as $key => $arr ) {
				$keys[] = array(
					'key'         => (string) $key,
					'description' => isset( $arr['description'] ) ? wp_strip_all_tags( (string) $arr['description'] ) : '',
					'default'     => isset( $arr['default'] ) ? (string) $arr['default'] : '',
				);
			}
			return array( 'message_keys' => $keys );
		},
	)
);
