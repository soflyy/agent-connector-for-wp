<?php
/**
 * Mail abilities: edit the mail / mail_2 templates (delta), and discover the
 * mail-tags available to use inside them.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/update-mail',
		'label'            => 'Update Mail Template',
		'description'      => 'Edit a form\'s email template. which:"primary" (default) is the notification CF7 always sends to the site; which:"secondary" is the optional autoresponder (mail_2) — set active:true to enable it. You pass only the fields to change (recipient, subject, body, etc.); the rest of that mail template is preserved. Use mail-tags like [your-email] (see cf7/list-mail-tags) inside the values.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'which'              => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary' ), 'description' => 'primary = the always-on notification (mail); secondary = the autoresponder (mail_2). Default primary.' ),
				'active'             => array( 'type' => 'boolean', 'description' => 'Enable/disable this mail. The primary mail is always active; this mainly applies to the secondary mail.' ),
				'recipient'          => array( 'type' => 'string', 'description' => 'To: address(es).' ),
				'sender'             => array( 'type' => 'string', 'description' => 'From: header.' ),
				'subject'            => array( 'type' => 'string', 'description' => 'Subject line.' ),
				'body'               => array( 'type' => 'string', 'description' => 'Email body.' ),
				'additional_headers' => array( 'type' => 'string', 'description' => 'Extra headers, e.g. "Reply-To: [your-email]" (one per line).' ),
				'attachments'        => array( 'type' => 'string', 'description' => 'Mail-tags of file fields and/or file paths to attach, one per line.' ),
				'use_html'           => array( 'type' => 'boolean', 'description' => 'Send the body as HTML.' ),
				'exclude_blank'      => array( 'type' => 'boolean', 'description' => 'Omit lines that contain only empty mail-tags.' ),
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

			$which_in = isset( $input['which'] ) ? (string) $input['which'] : 'primary';
			$prop     = ( 'secondary' === $which_in ) ? 'mail_2' : 'mail';

			$mail = cf7_pack_mail_array( $form, $prop );

			$editable = array( 'active', 'recipient', 'sender', 'subject', 'body', 'additional_headers', 'attachments', 'use_html', 'exclude_blank' );
			$changed  = false;
			foreach ( $editable as $key ) {
				if ( array_key_exists( $key, $input ) ) {
					$mail[ $key ] = $input[ $key ];
					$changed      = true;
				}
			}

			if ( ! $changed ) {
				return new WP_Error( 'cf7_no_change', 'Provide at least one mail field to change.', array( 'status' => 400 ) );
			}

			// Primary mail is always active.
			if ( 'mail' === $prop ) {
				$mail['active'] = true;
			}

			$saved = cf7_pack_save( (int) $form->id(), array( $prop => $mail ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'which' => $which_in,
				'mail'  => cf7_pack_mail_array( $saved, $prop ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/list-mail-tags',
		'label'            => 'List Mail Tags',
		'description'      => 'Introspection: for a given form, return the mail-tags you can use in mail templates and messages. Includes the form\'s own field tags (e.g. [your-name]) and Contact Form 7\'s special tags (e.g. [_site_title], [_remote_ip], [_date], [_url]).',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$field_tags = array_map(
				static function ( $name ) {
					return '[' . $name . ']';
				},
				(array) $form->collect_mail_tags()
			);

			$special = array(
				'[_site_title]'           => 'Site title.',
				'[_site_description]'     => 'Site tagline.',
				'[_site_url]'             => 'Site URL.',
				'[_site_admin_email]'     => 'Site admin email.',
				'[_remote_ip]'            => 'Submitter IP address.',
				'[_user_agent]'           => 'Submitter browser user-agent.',
				'[_url]'                  => 'URL of the page the form was submitted from.',
				'[_date]'                 => 'Submission date.',
				'[_time]'                 => 'Submission time.',
				'[_invalid_fields]'       => 'Count of invalid fields.',
				'[_post_id]'              => 'ID of the embedding post.',
				'[_post_title]'           => 'Title of the embedding post.',
				'[_post_url]'             => 'Permalink of the embedding post.',
				'[_user_login]'           => 'Logged-in user login (if any).',
				'[_user_email]'           => 'Logged-in user email (if any).',
			);

			return array(
				'field_tags'   => array_values( $field_tags ),
				'special_tags' => $special,
			);
		},
	)
);
