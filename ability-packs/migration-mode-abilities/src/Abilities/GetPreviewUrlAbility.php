<?php
/**
 * Ability: migration-mode/get-preview-url
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities\Abilities;

use MigrationMode\Abilities\Config;

defined( 'ABSPATH' ) || exit;

final class GetPreviewUrlAbility {

	public const NAME = 'migration-mode/get-preview-url';

	public static function definition(): array {
		return array(
			'label'               => 'Migration Mode: preview URL for a profile',
			'description'         => 'Return the exact, token-signed URL to view a path under a given profile — hand the "url" to a browser (e.g. Playwright browser_navigate) to render/screenshot that path as if only the profile\'s plugins were active. Also returns the equivalent request header and cookie for clients that prefer those. Pass profile "off" to get a URL that renders the full normal stack.',
			'category'            => 'migration-mode',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'profile' => array(
						'type'        => 'string',
						'description' => 'Profile id from set-profiles, or "off"/"default" for no filtering.',
					),
					'path'    => array(
						'type'        => 'string',
						'description' => 'Site-relative path or full same-site URL to view. Defaults to "/" (home).',
					),
				),
				'required'             => array( 'profile' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'url'     => array( 'type' => 'string' ),
					'profile' => array( 'type' => 'string' ),
					'valid'   => array( 'type' => 'boolean' ),
					'header'  => array( 'type' => 'object' ),
					'cookie'  => array( 'type' => 'object' ),
					'note'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'annotations'         => array( 'readonly' => true ),
		);
	}

	public static function execute( array $input ): array {
		$profile = isset( $input['profile'] ) ? (string) $input['profile'] : '';
		$path    = isset( $input['path'] ) && '' !== $input['path'] ? (string) $input['path'] : '/';
		$secret  = Config::secret();

		$reserved  = in_array( $profile, array( 'off', 'default' ), true );
		$valid     = $reserved || isset( Config::profiles()[ sanitize_key( $profile ) ] );

		// Resolve the base URL (accept a full same-site URL or a relative path).
		$base = ( 0 === strpos( $path, 'http://' ) || 0 === strpos( $path, 'https://' ) )
			? $path
			: home_url( '/' . ltrim( $path, '/' ) );

		$url = add_query_arg(
			array(
				'acm_profile' => $profile,
				'acm_token'   => $secret,
			),
			$base
		);

		return array(
			'url'     => $url,
			'profile' => $profile,
			'valid'   => $valid,
			'header'  => array(
				'X-ACM-Profile' => $profile,
				'X-ACM-Token'   => $secret,
			),
			'cookie'  => array(
				'acm_profile' => $profile,
				'acm_token'   => $secret,
			),
			'note'    => $valid
				? 'Navigate a browser to "url". The first hit also sets a sticky cookie so the page\'s assets/AJAX inherit the same profile. Append &acm_debug=1 to receive an X-ACM-Active-Plugins response header listing the plugins actually loaded.'
				: 'Profile not found — run set-profiles first, or use "off" for the normal stack.',
		);
	}
}
