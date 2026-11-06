<?php
/**
 * Ability-pack registry.
 *
 * Ability definition files (under abilities/) call ACFW_Pack::category() and
 * ACFW_Pack::ability() at require time. ACFW_Pack::boot() then registers them on
 * the two Abilities API hooks, guarded so the pack is inert when Agent Connector
 * is inactive. Centralizing registration here keeps every ability file tiny and
 * lets the coverage tooling enumerate what was registered (ACFW_Pack::definitions()).
 *
 * @package AcfwAbilityPack
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'ACFW_Pack' ) ) {
	return;
}

final class ACFW_Pack {

	/** @var array<string,array> ability name => $args (plus 'name'). */
	private static array $abilities = array();

	/** @var array<string,array> category slug => $args. */
	private static array $categories = array();

	/**
	 * Register an ability category. Reuse a slug across ability files freely;
	 * last definition wins.
	 */
	public static function category( string $slug, array $args ): void {
		self::$categories[ $slug ] = $args;
	}

	/**
	 * Queue an ability definition. $def must include 'name' (vendor/ability) plus
	 * the standard args: label, description, category, input_schema, output_schema,
	 * execute_callback, and optionally annotations / summarize. Do NOT pass a
	 * permission_callback — Agent Connector owns auth.
	 */
	public static function ability( array $def ): void {
		if ( empty( $def['name'] ) || ! is_string( $def['name'] ) ) {
			return;
		}
		self::$abilities[ $def['name'] ] = $def;
	}

	/** Enumerate queued ability definitions (used by coverage tooling). */
	public static function definitions(): array {
		return self::$abilities;
	}

	/** Wire the queued categories + abilities onto the Abilities API hooks. */
	public static function boot(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! function_exists( 'wp_register_ability_category' ) ) {
					return;
				}
				foreach ( self::$categories as $slug => $args ) {
					wp_register_ability_category( $slug, $args );
				}
			}
		);

		add_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
					return; // Host inactive — register nothing.
				}
				foreach ( self::$abilities as $name => $def ) {
					$args = $def;
					unset( $args['name'] );
					agent_connector_for_wp_register_ability( $name, $args );
				}
			}
		);
	}
}
