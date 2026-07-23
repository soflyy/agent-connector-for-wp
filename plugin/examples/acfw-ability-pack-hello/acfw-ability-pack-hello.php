<?php
/**
 * Plugin Name:       ACFW Ability Pack: Hello
 * Description:        Example companion "ability pack" for Agent Connector for WP. Registers one ability (hello/greet) over the public registration API. Reference only — not wired into production.
 * Version:           1.0.0
 * Requires Plugins:  agent-connector
 * Requires PHP:      8.1
 * Author:            Soflyy
 * License:           GPL-2.0-or-later
 *
 * @package AcfwAbilityPackHello
 *
 * This file is a copy-pasteable template for a companion plugin. It writes NO
 * permission / auth / domain-lock / audit code: Agent Connector for WP injects
 * all of that (admin/super-admin gate, domain lock, audit log) for every ability
 * registered through agent_connector_for_wp_register_ability().
 *
 * See plugin/docs/registering-abilities.md for the full guide.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register an ability category for this pack.
 *
 * Categories must be registered on wp_abilities_api_categories_init, before the
 * abilities that reference them.
 */
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'hello',
			array(
				'label'       => 'Hello Pack',
				'description' => 'Example abilities contributed by the Hello ability pack.',
			)
		);
	}
);

/**
 * Register the ability itself.
 *
 * Guarded by function_exists() so the pack degrades gracefully (registers
 * nothing) when Agent Connector for WP is inactive or the public API is absent.
 */
add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
			return;
		}

		agent_connector_for_wp_register_ability(
			'hello/greet',
			array(
				'label'         => 'Greet',
				'description'   => 'Return a friendly greeting for the given name.',
				'category'      => 'hello',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Who to greet.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'message' => array( 'type' => 'string' ),
					),
				),
				'annotations'   => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
				// fn(array $input): array|WP_Error
				'execute_callback' => static function ( array $input ): array {
					$name = isset( $input['name'] ) ? (string) $input['name'] : 'world';
					return array( 'message' => sprintf( 'Hello, %s!', $name ) );
				},
				// Optional: redact the audit-log summary. Here the name is safe,
				// so we log it verbatim; redact secrets/large blobs in real packs.
				'summarize'        => static function ( array $input ): array {
					return array( 'name' => isset( $input['name'] ) ? (string) $input['name'] : '' );
				},
			)
		);
	}
);
