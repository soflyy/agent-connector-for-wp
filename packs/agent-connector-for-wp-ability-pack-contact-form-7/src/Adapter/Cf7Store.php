<?php
/**
 * Cf7Store — load / read / persist helpers for Contact Form 7.
 *
 * Every mutation routes through Contact Form 7's own canonical save path
 * (`wpcf7_save_contact_form()`), which sanitizes input, runs the plugin's
 * validation, and fires its hooks. The agent-ergonomic verbs in abilities/
 * load the current state through these readers, apply only the caller's delta,
 * and hand the *whole* resulting property back to that save path — so a partial
 * "change the subject line" never wipes the rest of the mail template.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Loads a contact form by numeric ID or hash. Returns WP_Error if not found.
 *
 * @param int|string $id Numeric post ID or (7+ char) hash string.
 * @return WPCF7_ContactForm|WP_Error
 */
function cf7_pack_load( $id ) {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active.', array( 'status' => 500 ) );
	}

	$form = null;

	if ( is_string( $id ) && preg_match( '/^[0-9a-f]{7,}$/', $id ) ) {
		$form = wpcf7_get_contact_form_by_hash( $id );
	}

	if ( ! $form ) {
		$form = wpcf7_contact_form( (int) $id );
	}

	if ( ! $form ) {
		return new WP_Error(
			'cf7_not_found',
			sprintf( 'No contact form found for id "%s".', (string) $id ),
			array( 'status' => 404 )
		);
	}

	return $form;
}

/**
 * Persists a delta of properties through CF7's own save path.
 *
 * Only the keys present in $delta are touched; everything else on the form is
 * preserved (wpcf7_save_contact_form re-loads the form and only overwrites the
 * properties supplied). For array properties (mail/mail_2/messages) the caller
 * must pass the FULL merged array, because CF7's sanitizers fill unspecified
 * sub-keys with empty defaults.
 *
 * @param int   $id    Existing form ID.
 * @param array $delta Subset of: title, locale, form, mail, mail_2, messages, additional_settings.
 * @return WPCF7_ContactForm|WP_Error The saved form, reloaded.
 */
function cf7_pack_save( int $id, array $delta ) {
	$allowed = array( 'title', 'locale', 'form', 'mail', 'mail_2', 'messages', 'additional_settings' );
	$data    = array( 'id' => $id );

	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $delta ) ) {
			$data[ $key ] = $delta[ $key ];
		}
	}

	$saved = wpcf7_save_contact_form( $data, 'save' );

	if ( ! $saved ) {
		return new WP_Error( 'cf7_save_failed', 'Contact Form 7 rejected the save.', array( 'status' => 500 ) );
	}

	// Reload so subsequent reads reflect persisted, sanitized state.
	return cf7_pack_load( $saved->id() );
}

/**
 * Returns the full mail (or mail_2) array for a form, with all expected keys.
 *
 * @param WPCF7_ContactForm $form  Form object.
 * @param string            $which 'mail' or 'mail_2'.
 * @return array
 */
function cf7_pack_mail_array( WPCF7_ContactForm $form, string $which ): array {
	$defaults = array(
		'active'             => ( 'mail' === $which ), // primary mail is always active
		'subject'            => '',
		'sender'             => '',
		'recipient'          => '',
		'body'               => '',
		'additional_headers' => '',
		'attachments'        => '',
		'use_html'           => false,
		'exclude_blank'      => false,
	);

	$mail = (array) $form->prop( $which );

	return wp_parse_args( $mail, $defaults );
}

/**
 * Compact list-row representation of a form.
 */
function cf7_pack_summary( WPCF7_ContactForm $form ): array {
	return array(
		'id'        => (int) $form->id(),
		'title'     => (string) $form->title(),
		'slug'      => (string) $form->name(),
		'hash'      => (string) $form->hash(),
		'locale'    => (string) $form->locale(),
		'shortcode' => (string) $form->shortcode(),
		'fields'    => array_values( array_filter( array_map(
			static function ( $tag ) {
				return $tag->name ? (string) $tag->name : null;
			},
			(array) $form->scan_form_tags( array( 'feature' => 'name-attr' ) )
		) ) ),
	);
}

/**
 * Full configuration of a form: properties, parsed fields, mail, messages,
 * additional settings (raw + parsed), and any config-validator errors.
 */
function cf7_pack_detail( WPCF7_ContactForm $form ): array {
	$detail = cf7_pack_summary( $form );

	$detail['form'] = array(
		'raw'    => (string) $form->prop( 'form' ),
		'fields' => cf7_pack_parse_fields( $form ),
	);

	$detail['mail']   = cf7_pack_mail_array( $form, 'mail' );
	$detail['mail_2'] = cf7_pack_mail_array( $form, 'mail_2' );

	$detail['messages'] = (array) $form->prop( 'messages' );

	$detail['additional_settings'] = array(
		'raw'    => (string) $form->prop( 'additional_settings' ),
		'parsed' => cf7_pack_parse_settings( (string) $form->prop( 'additional_settings' ) ),
	);

	$detail['config_errors'] = cf7_pack_config_errors( $form );

	return $detail;
}

/**
 * Parses a form's template into structured field descriptors.
 */
function cf7_pack_parse_fields( WPCF7_ContactForm $form ): array {
	$raw    = (string) $form->prop( 'form' );
	$out    = array();

	foreach ( (array) $form->scan_form_tags() as $tag ) {
		if ( empty( $tag->type ) ) {
			continue;
		}

		$located = cf7_pack_locate_tag( $raw, (string) $tag->name );

		$out[] = array(
			'type'       => (string) $tag->type,
			'basetype'   => (string) $tag->basetype,
			'name'       => (string) $tag->name,
			'required'   => (bool) $tag->is_required(),
			'options'    => array_values( (array) $tag->options ),
			'values'     => array_values( (array) $tag->values ),
			'raw_values' => array_values( (array) $tag->raw_values ),
			'labels'     => array_values( (array) $tag->labels ),
			'raw_tag'    => $located ? $located['match'] : '',
		);
	}

	return $out;
}

/**
 * Parses the additional_settings string into key => [values...] pairs.
 */
function cf7_pack_parse_settings( string $raw ): array {
	$out     = array();
	$pattern = '/^([a-zA-Z0-9_]+)[\t ]*:(.*)$/';

	foreach ( explode( "\n", $raw ) as $line ) {
		if ( preg_match( $pattern, $line, $m ) ) {
			$key = $m[1];
			$out[ $key ][] = trim( $m[2] );
		}
	}

	return $out;
}

/**
 * Runs Contact Form 7's own configuration validator against a form and
 * returns a flat list of error descriptors.
 */
function cf7_pack_config_errors( WPCF7_ContactForm $form ): array {
	if ( ! class_exists( 'WPCF7_ConfigValidator' ) ) {
		return array();
	}

	$validator = new WPCF7_ConfigValidator( $form );
	$validator->validate();

	$errors = array();

	$messages = $validator->collect_error_messages( array( 'decodes_html_entities' => true ) );

	foreach ( (array) $messages as $section => $section_errors ) {
		foreach ( (array) $section_errors as $error ) {
			$errors[] = array(
				'section' => (string) $section,
				'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				'link'    => isset( $error['link'] ) ? (string) $error['link'] : '',
			);
		}
	}

	return $errors;
}
