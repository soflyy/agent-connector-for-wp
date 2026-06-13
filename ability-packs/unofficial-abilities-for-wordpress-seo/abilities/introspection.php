<?php
/**
 * Introspection abilities: let the agent discover valid shapes at runtime
 * instead of guessing (Yoast replacement variables for title/metadesc templates).
 *
 * @package AcfwAbilityPackYoast
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'yoast/list-replacement-variables',
		'label'            => 'List Replacement Variables',
		'description'      => 'List the Yoast replacement variables (e.g. %%title%%, %%sitename%%, %%sep%%, %%page%%, %%category%%, %%primary_category%%, custom-field cf_*, custom-taxonomy ct_*) that can be used in SEO title and meta description templates and in the title/metadesc templates set via yoast/update-settings. Each entry has the variable token and a human-readable label.',
		'category'         => 'yoast',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'variables' => array( 'type' => 'array' ),
				'count'     => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( ! yoast_pack_active() ) {
				return yoast_pack_inactive_error();
			}
			$vars = yoast_pack_replacement_variables();
			return array(
				'variables' => $vars,
				'count'     => count( $vars ),
			);
		},
	)
);
