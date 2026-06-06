<?php
/**
 * Form-level abilities: list, read, create, copy, delete, rename/locale.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::category(
	'cf7',
	array(
		'label'       => 'Contact Form 7',
		'description' => 'Create and manage Contact Form 7 forms, fields, mail, messages, and settings.',
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/list-forms',
		'label'            => 'List Contact Forms',
		'description'      => 'List every Contact Form 7 form with its id, title, slug, hash, locale, shortcode, and field names. Start here to discover forms to act on.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'search' => array( 'type' => 'string', 'description' => 'Optional title keyword filter.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'forms' => array( 'type' => 'array' ),
				'count' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
				return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active.', array( 'status' => 500 ) );
			}

			$args = array();

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = (string) $input['search'];
			}

			$forms = array_map( 'cf7_pack_summary', WPCF7_ContactForm::find( $args ) );

			return array(
				'forms' => $forms,
				'count' => count( $forms ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/get-form',
		'label'            => 'Get Contact Form',
		'description'      => 'Return the full configuration of one form: the form template (raw + parsed fields), both mail templates, all messages, additional settings, the shortcode, and any configuration-validator errors. Use this before editing so you know the current state.',
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
			return cf7_pack_detail( $form );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/create-form',
		'label'            => 'Create Contact Form',
		'description'      => 'Create a new contact form. By default it is pre-filled with Contact Form 7\'s standard template (name/email/subject/message/submit fields and working mail). Pass template:"blank" for an empty form template. Returns the new form id, slug, hash, and shortcode. Add or change fields afterwards with cf7/add-field etc.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array( 'type' => 'string', 'description' => 'Form title. Defaults to "Untitled".' ),
				'locale'   => array( 'type' => 'string', 'description' => 'WordPress locale code, e.g. en_US. Defaults to the site locale.' ),
				'template' => array( 'type' => 'string', 'enum' => array( 'default', 'blank' ), 'description' => 'default = standard CF7 starter form; blank = empty form template.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			if ( ! function_exists( 'wpcf7_save_contact_form' ) ) {
				return new WP_Error( 'cf7_inactive', 'Contact Form 7 is not active.', array( 'status' => 500 ) );
			}

			$data = array( 'id' => -1 );

			if ( isset( $input['title'] ) && '' !== $input['title'] ) {
				$data['title'] = (string) $input['title'];
			}
			if ( ! empty( $input['locale'] ) ) {
				$data['locale'] = (string) $input['locale'];
			}
			if ( isset( $input['template'] ) && 'blank' === $input['template'] ) {
				$data['form'] = '';
			}

			$form = wpcf7_save_contact_form( $data, 'save' );

			if ( ! $form || ! $form->id() ) {
				return new WP_Error( 'cf7_create_failed', 'Could not create the form.', array( 'status' => 500 ) );
			}

			return cf7_pack_summary( cf7_pack_load( $form->id() ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/copy-form',
		'label'            => 'Copy Contact Form',
		'description'      => 'Duplicate an existing form (all fields, mail, messages, and settings). Optionally give the copy a new title (otherwise "<original>_copy"). Returns the new form\'s id, slug, hash, and shortcode.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'description' => 'Numeric id of the form to copy.' ),
				'title' => array( 'type' => 'string', 'description' => 'Optional title for the copy.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$copy = $form->copy();

			if ( ! empty( $input['title'] ) ) {
				$copy->set_title( (string) $input['title'] );
			}

			$new_id = $copy->save();

			if ( ! $new_id ) {
				return new WP_Error( 'cf7_copy_failed', 'Could not save the copied form.', array( 'status' => 500 ) );
			}

			return cf7_pack_summary( cf7_pack_load( $new_id ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/delete-form',
		'label'            => 'Delete Contact Form',
		'description'      => 'Permanently delete a contact form. This cannot be undone. Any page still embedding its shortcode will show "Contact form not found".',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'description' => 'Numeric id of the form to delete.' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$id      = (int) $form->id();
			$deleted = $form->delete();

			if ( ! $deleted ) {
				return new WP_Error( 'cf7_delete_failed', 'Could not delete the form.', array( 'status' => 500 ) );
			}

			return array( 'deleted' => true, 'id' => $id );
		},
		'summarize'        => static function ( array $input ): array {
			return array( 'id' => isset( $input['id'] ) ? (string) $input['id'] : '' );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/update-form-properties',
		'label'            => 'Update Form Title / Locale',
		'description'      => 'Rename a form and/or change its locale. Only the fields you pass are changed; the form template, mail, messages, and settings are untouched.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'title'  => array( 'type' => 'string', 'description' => 'New title.' ),
				'locale' => array( 'type' => 'string', 'description' => 'New locale code, e.g. fr_FR.' ),
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

			$delta = array();
			if ( isset( $input['title'] ) ) {
				$delta['title'] = (string) $input['title'];
			}
			if ( isset( $input['locale'] ) ) {
				$delta['locale'] = (string) $input['locale'];
			}

			if ( ! $delta ) {
				return new WP_Error( 'cf7_no_change', 'Provide a title and/or locale to change.', array( 'status' => 400 ) );
			}

			$saved = cf7_pack_save( (int) $form->id(), $delta );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return cf7_pack_summary( $saved );
		},
	)
);
