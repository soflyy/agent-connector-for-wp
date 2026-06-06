<?php
/**
 * Field abilities: add / update / remove fields, set the raw template, and
 * introspect available field types.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Shared JSON-schema fragment describing a field spec for add/update-field.
 */
function cf7_pack_field_spec_schema(): array {
	return array(
		'type'              => array( 'type' => 'string', 'description' => 'Field type, e.g. text, email, textarea, select, checkbox, radio, acceptance, file, number, date, hidden, submit. See cf7/list-field-types.' ),
		'name'             => array( 'type' => 'string', 'description' => 'Field name (the key used in mail tags and posted data). Required for all types except submit/reset.' ),
		'required'         => array( 'type' => 'boolean', 'description' => 'Make the field required (adds the * to its type). Ignored for types that cannot be required.' ),
		'label'            => array( 'type' => 'string', 'description' => 'Visible label. When given, the field is wrapped in a <label> element in the template.' ),
		'placeholder'      => array( 'type' => 'string', 'description' => 'Placeholder text (text-like fields only).' ),
		'default_value'    => array( 'type' => 'string', 'description' => 'Literal pre-filled value (text-like fields).' ),
		'default'          => array( 'type' => 'string', 'description' => 'Dynamic default keyword, e.g. get, post, user_email.' ),
		'value'            => array( 'type' => 'string', 'description' => 'Literal value for hidden fields, or the button label for submit/reset.' ),
		'choices'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Options for select/checkbox/radio/quiz. Use "Label|value" to set a separate stored value; for quiz use "question|answer".' ),
		'html_id'          => array( 'type' => 'string', 'description' => 'HTML id attribute for the field element.' ),
		'class'            => array( 'type' => 'string', 'description' => 'Space-separated CSS class names.' ),
		'min'              => array( 'type' => array( 'string', 'number' ), 'description' => 'Minimum (number/range/date: date is YYYY-MM-DD).' ),
		'max'              => array( 'type' => array( 'string', 'number' ), 'description' => 'Maximum (number/range/date).' ),
		'step'             => array( 'type' => array( 'string', 'number' ), 'description' => 'Step (number/range/date).' ),
		'minlength'        => array( 'type' => 'integer', 'description' => 'Minimum length (text/textarea).' ),
		'maxlength'        => array( 'type' => 'integer', 'description' => 'Maximum length (text/textarea).' ),
		'cols'             => array( 'type' => 'integer', 'description' => 'Textarea columns.' ),
		'rows'             => array( 'type' => 'integer', 'description' => 'Textarea rows.' ),
		'autocomplete'     => array( 'type' => 'string', 'description' => 'autocomplete attribute, e.g. name, email, tel.' ),
		'multiple'         => array( 'type' => 'boolean', 'description' => 'select: allow multiple selection.' ),
		'include_blank'    => array( 'type' => 'boolean', 'description' => 'select: include an empty first option.' ),
		'exclusive'        => array( 'type' => 'boolean', 'description' => 'checkbox: allow only one selection.' ),
		'label_first'      => array( 'type' => 'boolean', 'description' => 'checkbox/radio: put the label before the box.' ),
		'use_label_element' => array( 'type' => 'boolean', 'description' => 'checkbox/radio: wrap each option in a <label>.' ),
		'optional'         => array( 'type' => 'boolean', 'description' => 'acceptance: make consent optional.' ),
		'invert'           => array( 'type' => 'boolean', 'description' => 'acceptance: invert the meaning (checking blocks submission).' ),
		'default_checked'  => array( 'type' => 'boolean', 'description' => 'acceptance: checked by default.' ),
		'filetypes'        => array( 'type' => 'string', 'description' => 'file: allowed types, pipe-separated, e.g. pdf|jpg|png.' ),
		'limit'            => array( 'type' => 'string', 'description' => 'file: max size, e.g. 2mb.' ),
		'raw_options'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Escape hatch: extra CF7 option tokens to append verbatim.' ),
	);
}

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/add-field',
		'label'            => 'Add Field',
		'description'      => 'Add one field to a form\'s template. You pass only the new field (type, name, and its attributes) — existing fields are untouched. By default the new field is inserted just before the submit button; pass position:"append" to put it at the very end. Optionally pass a label to wrap it in a <label>. Returns the generated tag and the updated parsed fields.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'id'       => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
					'position' => array( 'type' => 'string', 'enum' => array( 'before_submit', 'append' ), 'description' => 'Where to insert. Default before_submit.' ),
					'raw_tag'  => array( 'type' => 'string', 'description' => 'Escape hatch: a complete CF7 tag like [text* your-name] to insert verbatim instead of building from the spec.' ),
				),
				cf7_pack_field_spec_schema()
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

			if ( ! empty( $input['raw_tag'] ) ) {
				$tag = trim( (string) $input['raw_tag'] );
			} else {
				$tag = cf7_pack_build_tag( $input );
				if ( is_wp_error( $tag ) ) {
					return $tag;
				}
			}

			$position = ( isset( $input['position'] ) && 'append' === $input['position'] ) ? 'append' : 'before_submit';
			$label    = isset( $input['label'] ) ? (string) $input['label'] : null;

			$new_form = cf7_pack_form_insert( (string) $form->prop( 'form' ), $tag, $label, $position );

			$saved = cf7_pack_save( (int) $form->id(), array( 'form' => $new_form ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'tag'    => $tag,
				'fields' => cf7_pack_parse_fields( $saved ),
				'form'   => (string) $saved->prop( 'form' ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/update-field',
		'label'            => 'Update Field',
		'description'      => 'Change an existing field, located by its current name. You pass only the attributes to change; the field\'s other attributes are preserved (its definition is read, your delta is merged onto it, and it is rewritten). To rename a field, pass new_name. Returns the new tag and updated fields.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'id'       => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
					'name'     => array( 'type' => 'string', 'description' => 'Current name of the field to update.' ),
					'new_name' => array( 'type' => 'string', 'description' => 'Optional: rename the field to this.' ),
				),
				cf7_pack_field_spec_schema()
			),
			'required'             => array( 'id', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$target = null;
			foreach ( (array) $form->scan_form_tags() as $tag ) {
				if ( (string) $tag->name === (string) $input['name'] ) {
					$target = $tag;
					break;
				}
			}

			if ( null === $target ) {
				return new WP_Error( 'cf7_field_not_found', sprintf( 'No field named "%s" in this form.', (string) $input['name'] ), array( 'status' => 404 ) );
			}

			// Merge: existing field spec <- caller's delta.
			$spec = cf7_pack_tag_to_spec( $target );

			$delta_keys = array_keys( cf7_pack_field_spec_schema() );
			foreach ( $delta_keys as $key ) {
				if ( array_key_exists( $key, $input ) ) {
					$spec[ $key ] = $input[ $key ];
				}
			}

			if ( ! empty( $input['new_name'] ) ) {
				$spec['name'] = (string) $input['new_name'];
			}

			// label is a wrapper concern, not part of the tag.
			unset( $spec['label'] );

			$new_tag = cf7_pack_build_tag( $spec );
			if ( is_wp_error( $new_tag ) ) {
				return $new_tag;
			}

			$result = cf7_pack_form_replace( (string) $form->prop( 'form' ), (string) $input['name'], $new_tag );
			if ( ! $result['replaced'] ) {
				return new WP_Error( 'cf7_replace_failed', 'Could not locate the field tag in the template.', array( 'status' => 500 ) );
			}

			$saved = cf7_pack_save( (int) $form->id(), array( 'form' => $result['form'] ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'tag'    => $new_tag,
				'fields' => cf7_pack_parse_fields( $saved ),
				'form'   => (string) $saved->prop( 'form' ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/remove-field',
		'label'            => 'Remove Field',
		'description'      => 'Remove the field with the given name from a form\'s template (and tidy up an empty surrounding <label>). Other fields are untouched. Returns the updated fields.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'   => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'name' => array( 'type' => 'string', 'description' => 'Name of the field to remove.' ),
			),
			'required'             => array( 'id', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$result = cf7_pack_form_remove( (string) $form->prop( 'form' ), (string) $input['name'] );
			if ( ! $result['removed'] ) {
				return new WP_Error( 'cf7_field_not_found', sprintf( 'No field named "%s" in this form.', (string) $input['name'] ), array( 'status' => 404 ) );
			}

			$saved = cf7_pack_save( (int) $form->id(), array( 'form' => $result['form'] ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'removed' => true,
				'fields'  => cf7_pack_parse_fields( $saved ),
				'form'    => (string) $saved->prop( 'form' ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/set-form-template',
		'label'            => 'Set Raw Form Template',
		'description'      => 'Replace a form\'s entire template string at once (the [tag] markup, labels, and HTML). Use this for wholesale layout changes; for single-field edits prefer cf7/add-field, cf7/update-field, cf7/remove-field. Returns the parsed fields and any config errors.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'   => array( 'type' => 'integer', 'description' => 'Numeric form id (from cf7/list-forms).' ),
				'form' => array( 'type' => 'string', 'description' => 'The complete new form template.' ),
			),
			'required'             => array( 'id', 'form' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$form = cf7_pack_load( $input['id'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}

			$saved = cf7_pack_save( (int) $form->id(), array( 'form' => (string) $input['form'] ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return array(
				'fields'        => cf7_pack_parse_fields( $saved ),
				'form'          => (string) $saved->prop( 'form' ),
				'config_errors' => cf7_pack_config_errors( $saved ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'cf7/list-field-types',
		'label'            => 'List Field Types',
		'description'      => 'Introspection: return every field type cf7/add-field and cf7/update-field support, whether each can be required or take choices/placeholder, and a syntax example. Call this to learn valid inputs instead of guessing.',
		'category'         => 'cf7',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'field_types' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$types = array();
			foreach ( cf7_pack_field_catalog() as $type => $meta ) {
				$types[] = array(
					'type'                => $type,
					'can_be_required'     => (bool) $meta['required'],
					'takes_choices'       => (bool) $meta['choices'],
					'takes_placeholder'   => (bool) $meta['placeholder'],
					'description'         => (string) $meta['desc'],
					'example'             => (string) $meta['example'],
				);
			}
			return array( 'field_types' => $types );
		},
	)
);
