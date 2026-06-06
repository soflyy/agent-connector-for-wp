<?php
/**
 * EXAMPLE ability definitions — delete this file once you have real ones.
 *
 * It exists only to show the two shapes you'll write most:
 *   1. An ergonomic ACTION VERB that takes only the delta (not a whole structure).
 *   2. An INTROSPECTION ability so the agent can discover valid shapes at runtime.
 *
 * Replace {{VENDOR}} with your pack's vendor namespace (e.g. the target slug).
 *
 * @package AcfwAbilityPack
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::category(
	'{{VENDOR}}',
	array(
		'label'       => '{{TARGET_LABEL}}',
		'description' => 'Agent abilities for controlling {{TARGET_LABEL}}.',
	)
);

/*
 * ACTION VERB — note how the input is the *delta only*. The agent says "add a
 * field to this group" and passes just the field; it never re-sends the group's
 * other fields. Inside, you read current state via the plugin's own loader,
 * apply the change, and persist via the plugin's own save path (so its
 * validation + hooks run). That logic belongs in src/Adapter, called from here.
 */
ACFW_Pack::ability(
	array(
		'name'          => '{{VENDOR}}/example-add-thing',
		'label'         => 'Add Thing To Group',
		'description'   => 'Add a single thing to an existing group, identified by id. Only the new thing is supplied; existing things in the group are untouched. Returns the id of the created thing.',
		'category'      => '{{VENDOR}}',
		'input_schema'  => array(
			'type'                 => 'object',
			'properties'           => array(
				'group_id' => array(
					'type'        => 'string',
					'description' => 'Id of the group to add to.',
				),
				'thing'    => array(
					'type'        => 'object',
					'description' => 'The single thing to add (only its own properties — see {{VENDOR}}/example-describe-shapes for valid keys).',
				),
			),
			'required'             => array( 'group_id', 'thing' ),
			'additionalProperties' => false,
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'string' ),
			),
		),
		'annotations'   => array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		),
		'execute_callback' => static function ( array $input ) {
			// Delegate to your adapter (src/Adapter/...): load via plugin loader,
			// mutate, save via plugin save path. Return array|WP_Error.
			return new WP_Error( 'not_implemented', 'Replace the example abilities with real ones.' );
		},
	)
);

/*
 * INTROSPECTION — lets the agent learn valid shapes (types, allowed keys, enums)
 * instead of guessing. Cheap to provide, hugely improves agent success rate.
 */
ACFW_Pack::ability(
	array(
		'name'          => '{{VENDOR}}/example-describe-shapes',
		'label'         => 'Describe Available Shapes',
		'description'   => 'Return the kinds of things this plugin supports and the properties each accepts, so a caller can construct valid input for the action abilities.',
		'category'      => '{{VENDOR}}',
		'input_schema'  => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'shapes' => array( 'type' => 'array' ),
			),
		),
		'annotations'   => array(
			'readonly'   => true,
			'idempotent' => true,
		),
		'execute_callback' => static function ( array $input ) {
			return array( 'shapes' => array() );
		},
	)
);
