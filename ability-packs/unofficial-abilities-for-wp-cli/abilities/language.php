<?php
/**
 * `wp language core` — list, install, and activate WordPress core translations,
 * all in native PHP (no shell, no `wp` binary).
 *
 * Listing reads the available-translations API plus the locally installed
 * language files. Installing a language pack downloads it from the
 * WordPress.org translations service and therefore needs outbound network
 * access. Activation flips the `WPLANG` option (the empty string means en_US).
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-core-list',
		'label'            => 'List Core Languages',
		'description'      => 'List available and installed WordPress core translations (like `wp language core list`). Each entry reports its locale, native name, and status: "active" (the current site language), "installed" (downloaded but not active), or "available" (offered by WordPress.org but not yet installed). Fetching the available list needs outbound network access; installed languages are read locally.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'     => array( 'type' => 'integer' ),
				'languages' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';

			$current   = get_locale();
			$installed = get_available_languages(); // Locales with installed .mo files (en_US never listed).
			$installed = is_array( $installed ) ? $installed : array();

			$available = wp_get_available_translations();
			$available = is_array( $available ) ? $available : array();

			$languages = array();
			$seen      = array();

			// en_US is always present and is the built-in default.
			$languages[] = array(
				'language'    => 'en_US',
				'native_name' => 'English (United States)',
				'status'      => ( 'en_US' === $current || '' === get_option( 'WPLANG', '' ) ) ? 'active' : 'installed',
				'updated'     => '',
			);
			$seen['en_US'] = true;

			foreach ( $available as $locale => $info ) {
				$is_installed = in_array( $locale, $installed, true );
				if ( $locale === $current ) {
					$status = 'active';
				} elseif ( $is_installed ) {
					$status = 'installed';
				} else {
					$status = 'available';
				}
				$languages[]    = array(
					'language'    => (string) $locale,
					'native_name' => isset( $info['native_name'] ) ? (string) $info['native_name'] : (string) $locale,
					'status'      => $status,
					'updated'     => isset( $info['updated'] ) ? (string) $info['updated'] : '',
				);
				$seen[ $locale ] = true;
			}

			// Any installed locale the API didn't return (offline / custom packs).
			foreach ( $installed as $locale ) {
				if ( isset( $seen[ $locale ] ) ) {
					continue;
				}
				$languages[] = array(
					'language'    => (string) $locale,
					'native_name' => (string) $locale,
					'status'      => ( $locale === $current ) ? 'active' : 'installed',
					'updated'     => '',
				);
			}

			return array( 'count' => count( $languages ), 'languages' => $languages );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-core-install',
		'label'            => 'Install Core Language',
		'description'      => 'Download and install a WordPress core language pack (like `wp language core install <locale>`). `language` is a locale such as "fr_FR" or "de_DE". With `activate` true, the site language is switched to it after installation. Needs outbound network access to the WordPress.org translations service.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array( 'type' => 'string', 'description' => 'Locale to install, e.g. "fr_FR", "es_ES", "de_DE".' ),
				'activate' => array( 'type' => 'boolean', 'description' => 'Activate this language as the site language after installing. Default false.' ),
			),
			'required'             => array( 'language' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'installed' => array( 'type' => 'boolean' ),
				'activated' => array( 'type' => 'boolean' ),
				'locale'    => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			// wp_download_language_pack() drives the upgrader + filesystem API,
			// which live in admin includes not loaded on a REST request.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$locale = (string) $input['language'];

			// Already installed? Treat as success (idempotent).
			$existing = get_available_languages();
			$existing = is_array( $existing ) ? $existing : array();
			$result   = true;
			if ( ! in_array( $locale, $existing, true ) ) {
				$result = wp_download_language_pack( $locale );
				if ( false === $result ) {
					return wpcli_pack_err( 'install_failed', sprintf( 'Could not install language pack for "%s". Check the locale and outbound network access.', $locale ) );
				}
			}

			$activated = false;
			if ( ! empty( $input['activate'] ) ) {
				update_option( 'WPLANG', $locale );
				if ( function_exists( 'switch_to_locale' ) ) {
					switch_to_locale( $locale );
				}
				$activated = true;
			}

			return array(
				'installed' => true,
				'activated' => $activated,
				'locale'    => $locale,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-core-activate',
		'label'            => 'Activate Core Language',
		'description'      => 'Set the active site language (like `wp language core activate <locale>`). Pass a locale such as "fr_FR", or "en_US" / empty string to revert to the built-in English. The locale must already be installed (use wp-cli/language-core-install first for anything but en_US).',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array( 'type' => 'string', 'description' => 'Locale to activate, e.g. "fr_FR". Use "en_US" or "" for the default English.' ),
			),
			'required'             => array( 'language' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'locale'  => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$locale = (string) $input['language'];

			// en_US is represented by an empty WPLANG.
			$wplang = ( 'en_US' === $locale ) ? '' : $locale;

			if ( '' !== $wplang ) {
				$installed = get_available_languages();
				$installed = is_array( $installed ) ? $installed : array();
				if ( ! in_array( $wplang, $installed, true ) ) {
					return wpcli_pack_err( 'not_installed', sprintf( 'Language "%s" is not installed. Install it first.', $locale ), 404 );
				}
			}

			update_option( 'WPLANG', $wplang );
			if ( '' !== $wplang && function_exists( 'switch_to_locale' ) ) {
				switch_to_locale( $wplang );
			}

			return array( 'success' => true, 'locale' => '' === $wplang ? 'en_US' : $wplang );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-list',
		'label'            => 'List Installed Languages',
		'description'      => 'List the languages currently installed on the site and the active locale (a convenience alias over get_available_languages / get_locale). Reads locally; no network access required. Note en_US is the built-in default and is not listed under available.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'locale'    => array( 'type' => 'string' ),
				'available' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$available = get_available_languages();
			$available = is_array( $available ) ? array_values( $available ) : array();
			return array(
				'locale'    => (string) get_locale(),
				'available' => $available,
			);
		},
	)
);
