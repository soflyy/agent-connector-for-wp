<?php
/**
 * Cf7TagBuilder — construct, locate, and merge Contact Form 7 form-tags.
 *
 * A CF7 form's fields live as `[type name options... "values"...]` tokens inside
 * the `form` template string. There is no structured field store to save through,
 * so the ergonomic field verbs (add/update/remove-field) manipulate that string
 * here, then persist the whole template through `wpcf7_save_contact_form()` so the
 * plugin re-parses and renders it. Building a tag from a small spec is what lets
 * the agent say "add a required email named your-email" instead of authoring CF7
 * tag syntax by hand.
 *
 * @package AcfwAbilityPackCF7
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of supported field types: what each accepts and an example. Drives
 * both the build_tag() logic and the cf7/list-field-types introspection ability.
 */
function cf7_pack_field_catalog(): array {
	return array(
		'text'       => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'Single-line text input.', 'example' => '[text* your-name maxlength:100]' ),
		'email'      => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'Email input, validated as an email address.', 'example' => '[email* your-email]' ),
		'url'        => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'URL input, validated as a URL.', 'example' => '[url website]' ),
		'tel'        => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'Telephone-number input.', 'example' => '[tel phone]' ),
		'textarea'   => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'Multi-line text. Use cols/rows.', 'example' => '[textarea your-message 40x10]' ),
		'number'     => array( 'required' => true,  'choices' => false, 'placeholder' => true,  'desc' => 'Numeric spinbox. Use min/max/step.', 'example' => '[number quantity min:1 max:10]' ),
		'range'      => array( 'required' => true,  'choices' => false, 'placeholder' => false, 'desc' => 'Slider. Use min/max/step.', 'example' => '[range rating min:0 max:100]' ),
		'date'       => array( 'required' => true,  'choices' => false, 'placeholder' => false, 'desc' => 'Date picker. min/max are YYYY-MM-DD.', 'example' => '[date appointment min:2026-01-01]' ),
		'select'     => array( 'required' => true,  'choices' => true,  'placeholder' => false, 'desc' => 'Drop-down menu. Supports multiple, include_blank.', 'example' => '[select country "USA" "Canada"]' ),
		'checkbox'   => array( 'required' => true,  'choices' => true,  'placeholder' => false, 'desc' => 'Checkbox group. Supports exclusive, label_first, use_label_element.', 'example' => '[checkbox interests "Sports" "Music"]' ),
		'radio'      => array( 'required' => false, 'choices' => true,  'placeholder' => false, 'desc' => 'Radio-button group (always required by nature).', 'example' => '[radio gender "Male" "Female"]' ),
		'acceptance' => array( 'required' => false, 'choices' => false, 'placeholder' => false, 'desc' => 'Consent checkbox. Use optional, invert, default_checked.', 'example' => '[acceptance accept-terms "I agree"]' ),
		'file'       => array( 'required' => true,  'choices' => false, 'placeholder' => false, 'desc' => 'File upload. Use filetypes, limit.', 'example' => '[file upload filetypes:pdf|jpg limit:2mb]' ),
		'quiz'       => array( 'required' => false, 'choices' => true,  'placeholder' => false, 'desc' => 'Anti-spam question. choices are "question|answer".', 'example' => '[quiz human-check "1+1=?|2"]' ),
		'hidden'     => array( 'required' => false, 'choices' => false, 'placeholder' => false, 'desc' => 'Hidden field. Use value.', 'example' => '[hidden ref "campaign-x"]' ),
		'submit'     => array( 'required' => false, 'choices' => false, 'placeholder' => false, 'desc' => 'Submit button. value is the button label; no name.', 'example' => '[submit "Send"]' ),
		'reset'      => array( 'required' => false, 'choices' => false, 'placeholder' => false, 'desc' => 'Reset button. value is the button label; no name.', 'example' => '[reset "Clear"]' ),
	);
}

/**
 * Escapes a value for inclusion inside a double-quoted CF7 value token.
 * CF7 tag values cannot contain a double quote, so collapse them to single.
 */
function cf7_pack_quote( $value ): string {
	return str_replace( array( '"', "\n", "\r" ), array( "'", ' ', ' ' ), (string) $value );
}

/**
 * Builds a CF7 form-tag string from an ergonomic spec.
 *
 * @param array $spec Recognized keys: type (required), name, required, id, class,
 *                    min, max, step, minlength, maxlength, cols, rows, autocomplete,
 *                    placeholder, default_value, default, value, choices[],
 *                    multiple, include_blank, label_first, use_label_element,
 *                    exclusive, optional, invert, default_checked, filetypes, limit,
 *                    raw_options[] (verbatim passthrough).
 * @return string|WP_Error
 */
function cf7_pack_build_tag( array $spec ) {
	$catalog = cf7_pack_field_catalog();
	$type    = isset( $spec['type'] ) ? (string) $spec['type'] : '';

	if ( ! isset( $catalog[ $type ] ) ) {
		return new WP_Error(
			'cf7_unknown_type',
			sprintf( 'Unknown field type "%s". Call cf7/list-field-types for valid types.', $type ),
			array( 'status' => 400 )
		);
	}

	$meta     = $catalog[ $type ];
	$required = ! empty( $spec['required'] ) && $meta['required'];
	$name     = isset( $spec['name'] ) ? trim( (string) $spec['name'] ) : '';

	$needs_name = ! in_array( $type, array( 'submit', 'reset' ), true );

	if ( $needs_name ) {
		if ( '' === $name ) {
			return new WP_Error( 'cf7_missing_name', sprintf( 'Field type "%s" requires a name.', $type ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $name ) ) {
			return new WP_Error( 'cf7_bad_name', 'Field name must start with a letter and contain only letters, digits, hyphens, underscores.', array( 'status' => 400 ) );
		}
	}

	// CF7's tag scanner requires every option/flag to appear BEFORE any quoted
	// value. We build the two groups separately and concatenate options-then-values
	// so the emitted tag always parses.
	$options = array();
	$values  = array();

	if ( ! empty( $spec['html_id'] ) ) {
		$options[] = 'id:' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $spec['html_id'] );
	}
	if ( ! empty( $spec['class'] ) ) {
		foreach ( preg_split( '/\s+/', (string) $spec['class'] ) as $cls ) {
			$cls = preg_replace( '/[^A-Za-z0-9_-]/', '', $cls );
			if ( '' !== $cls ) {
				$options[] = 'class:' . $cls;
			}
		}
	}

	// Numeric / length / date constraints.
	foreach ( array( 'min', 'max', 'step', 'minlength', 'maxlength' ) as $opt ) {
		if ( isset( $spec[ $opt ] ) && '' !== $spec[ $opt ] ) {
			$options[] = $opt . ':' . preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $spec[ $opt ] );
		}
	}

	// Textarea size (cols x rows).
	if ( 'textarea' === $type && ( ! empty( $spec['cols'] ) || ! empty( $spec['rows'] ) ) ) {
		$cols      = (int) ( $spec['cols'] ?? 40 );
		$rows      = (int) ( $spec['rows'] ?? 10 );
		$options[] = $cols . 'x' . $rows;
	}

	if ( ! empty( $spec['autocomplete'] ) ) {
		$options[] = 'autocomplete:' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $spec['autocomplete'] );
	}

	// File options.
	if ( ! empty( $spec['filetypes'] ) ) {
		$options[] = 'filetypes:' . preg_replace( '/[^A-Za-z0-9|.]/', '', (string) $spec['filetypes'] );
	}
	if ( ! empty( $spec['limit'] ) ) {
		$options[] = 'limit:' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $spec['limit'] );
	}

	// Boolean flag options.
	$flags = array(
		'multiple'          => 'multiple',
		'include_blank'     => 'include_blank',
		'label_first'       => 'label_first',
		'use_label_element' => 'use_label_element',
		'exclusive'         => 'exclusive',
		'optional'          => 'optional',
		'invert'            => 'invert',
	);
	foreach ( $flags as $key => $token ) {
		if ( ! empty( $spec[ $key ] ) ) {
			$options[] = $token;
		}
	}
	if ( 'acceptance' === $type && ! empty( $spec['default_checked'] ) ) {
		$options[] = 'default:on';
	}

	// The placeholder flag is an option; its text is a value (added below).
	$use_placeholder = $meta['placeholder'] && isset( $spec['placeholder'] ) && '' !== $spec['placeholder'];
	if ( $use_placeholder ) {
		$options[] = 'placeholder';
	} elseif ( ! empty( $spec['default'] ) ) {
		$options[] = 'default:' . preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $spec['default'] );
	}

	// Verbatim passthrough of any options the builder doesn't model.
	if ( ! empty( $spec['raw_options'] ) && is_array( $spec['raw_options'] ) ) {
		foreach ( $spec['raw_options'] as $opt ) {
			$opt = trim( (string) $opt );
			if ( '' !== $opt ) {
				$options[] = $opt;
			}
		}
	}

	// Choices (selectable values) — preserves "label|value" pipe syntax.
	if ( $meta['choices'] && ! empty( $spec['choices'] ) && is_array( $spec['choices'] ) ) {
		foreach ( $spec['choices'] as $choice ) {
			$values[] = '"' . cf7_pack_quote( $choice ) . '"';
		}
	}

	// Single literal value for hidden / submit / reset.
	if ( in_array( $type, array( 'hidden', 'submit', 'reset' ), true ) && isset( $spec['value'] ) && '' !== $spec['value'] ) {
		$values[] = '"' . cf7_pack_quote( $spec['value'] ) . '"';
	}

	// Placeholder text, or a literal pre-filled default value.
	if ( $use_placeholder ) {
		$values[] = '"' . cf7_pack_quote( $spec['placeholder'] ) . '"';
	} elseif ( isset( $spec['default_value'] ) && '' !== $spec['default_value'] && ! $meta['choices'] ) {
		$values[] = '"' . cf7_pack_quote( $spec['default_value'] ) . '"';
	}

	$parts = array( $type . ( $required ? '*' : '' ) );
	if ( $needs_name ) {
		$parts[] = $name;
	}
	$parts = array_merge( $parts, $options, $values );

	return '[' . implode( ' ', $parts ) . ']';
}

/**
 * Locates the raw `[...]` span of the field with the given name in a form
 * template. The name is always the first token after the tag type in CF7 syntax.
 *
 * @return array{match:string,start:int,length:int,type:string}|null
 */
function cf7_pack_locate_tag( string $form, string $name ): ?array {
	if ( '' === $name ) {
		return null;
	}

	if ( ! preg_match_all( '/\[([A-Za-z][A-Za-z0-9_]*\*?)((?:[ \t\r\n]+[^\]]*)?)\]/', $form, $matches, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	foreach ( $matches[0] as $i => $whole ) {
		$rest   = trim( $matches[2][ $i ][0] );
		$tokens = preg_split( '/[ \t\r\n]+/', $rest );
		$first  = $tokens[0] ?? '';

		if ( $first === $name ) {
			return array(
				'match'  => $whole[0],
				'start'  => (int) $whole[1],
				'length' => strlen( $whole[0] ),
				'type'   => $matches[1][ $i ][0],
			);
		}
	}

	return null;
}

/**
 * Converts a scanned WPCF7_FormTag back into a build_tag() spec, so update-field
 * can merge a caller's delta onto the field's existing definition rather than
 * forcing a full redefinition. Unrecognized option tokens are preserved verbatim
 * via raw_options.
 */
function cf7_pack_tag_to_spec( WPCF7_FormTag $tag ): array {
	$spec = array(
		'type'     => (string) $tag->basetype,
		'name'     => (string) $tag->name,
		'required' => (bool) $tag->is_required(),
	);

	$raw_options = array();

	foreach ( (array) $tag->options as $opt ) {
		$opt = (string) $opt;

		if ( preg_match( '/^(id|class|min|max|step|minlength|maxlength|autocomplete|filetypes|limit|default):(.+)$/', $opt, $m ) ) {
			$key = $m[1];
			if ( 'class' === $key ) {
				$spec['class'] = isset( $spec['class'] ) ? trim( $spec['class'] . ' ' . $m[2] ) : $m[2];
			} elseif ( 'id' === $key ) {
				$spec['html_id'] = $m[2];
			} else {
				$spec[ $key ] = $m[2];
			}
		} elseif ( preg_match( '/^(\d+)x(\d+)$/', $opt, $m ) ) {
			$spec['cols'] = (int) $m[1];
			$spec['rows'] = (int) $m[2];
		} elseif ( in_array( $opt, array( 'multiple', 'include_blank', 'label_first', 'use_label_element', 'exclusive', 'optional', 'invert', 'placeholder', 'watermark' ), true ) ) {
			if ( 'placeholder' === $opt || 'watermark' === $opt ) {
				$spec['_has_placeholder'] = true;
			} else {
				$spec[ $opt ] = true;
			}
		} else {
			$raw_options[] = $opt;
		}
	}

	if ( $raw_options ) {
		$spec['raw_options'] = $raw_options;
	}

	// Values: choices for selectable types, else placeholder/default for single.
	$values = array_values( (array) $tag->raw_values );

	$selectable = function_exists( 'wpcf7_form_tag_supports' )
		? wpcf7_form_tag_supports( $tag->type, 'selectable-values' )
		: in_array( $tag->basetype, array( 'select', 'checkbox', 'radio', 'quiz' ), true );

	if ( $selectable ) {
		if ( $values ) {
			$spec['choices'] = $values;
		}
	} elseif ( $values ) {
		if ( ! empty( $spec['_has_placeholder'] ) ) {
			$spec['placeholder'] = $values[0];
		} elseif ( in_array( $tag->basetype, array( 'hidden', 'submit', 'reset' ), true ) ) {
			$spec['value'] = $values[0];
		} else {
			$spec['default_value'] = $values[0];
		}
	}

	unset( $spec['_has_placeholder'] );

	return $spec;
}

/**
 * Inserts a field tag into a form template, optionally wrapped in a <label>.
 *
 * @param string      $form     Current template.
 * @param string      $tag      The `[...]` tag to insert.
 * @param string|null $label    Optional visible label; wraps the tag in <label>.
 * @param string      $position 'append' (default) or 'before_submit'.
 * @return string New template.
 */
function cf7_pack_form_insert( string $form, string $tag, ?string $label, string $position = 'append' ): string {
	if ( null !== $label && '' !== trim( $label ) ) {
		$block = sprintf( "<label> %s\n    %s </label>", trim( $label ), $tag );
	} else {
		$block = $tag;
	}

	if ( 'before_submit' === $position && preg_match( '/\[submit\b[^\]]*\]/', $form, $m, PREG_OFFSET_CAPTURE ) ) {
		$offset  = (int) $m[0][1];
		$before  = rtrim( substr( $form, 0, $offset ) );
		$after   = substr( $form, $offset );
		$wrapped = '' === $before ? $block : $before . "\n\n" . $block;
		return $wrapped . "\n\n" . $after;
	}

	return '' === trim( $form ) ? $block : rtrim( $form ) . "\n\n" . $block;
}

/**
 * Removes the field with the given name from a form template. Collapses an empty
 * enclosing <label> and excess blank lines left behind.
 *
 * @return array{form:string,removed:bool}
 */
function cf7_pack_form_remove( string $form, string $name ): array {
	$located = cf7_pack_locate_tag( $form, $name );

	if ( null === $located ) {
		return array( 'form' => $form, 'removed' => false );
	}

	$new = substr( $form, 0, $located['start'] )
		. substr( $form, $located['start'] + $located['length'] );

	// Tidy up: drop any <label> block that no longer wraps a form-tag (an
	// orphaned label left behind by removing its control), then collapse the
	// whitespace/blank-line gaps left behind.
	$new = preg_replace_callback(
		'/<label\b[^>]*>(.*?)<\/label>/su',
		static function ( $m ) {
			return ( false !== strpos( $m[1], '[' ) ) ? $m[0] : '';
		},
		$new
	);
	$new = preg_replace( '/[ \t]+\n/', "\n", $new );
	$new = preg_replace( '/\n{3,}/', "\n\n", $new );

	return array( 'form' => trim( (string) $new ), 'removed' => true );
}

/**
 * Replaces the field with the given name in a form template with a new tag.
 *
 * @return array{form:string,replaced:bool}
 */
function cf7_pack_form_replace( string $form, string $name, string $new_tag ): array {
	$located = cf7_pack_locate_tag( $form, $name );

	if ( null === $located ) {
		return array( 'form' => $form, 'replaced' => false );
	}

	$new = substr( $form, 0, $located['start'] )
		. $new_tag
		. substr( $form, $located['start'] + $located['length'] );

	return array( 'form' => $new, 'replaced' => true );
}
