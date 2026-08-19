<?php
/**
 * Application-passwords availability, with reason detection.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

use WP_Application_Passwords;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_is_application_passwords_available()/_for_user() with reason
 * detection, so the admin UI and the REST error can say *why* passwords
 * are unavailable — the HTTPS/environment-type gate core applies, vs. a
 * plugin actively filtering the feature off — instead of always pointing
 * the operator at a wp-config.php setting that isn't the actual problem.
 */
final class ApplicationPasswords {

	public static function available( ?\WP_User $user ): bool {
		return null === self::unavailable_reason( $user );
	}

	/**
	 * Why Application Passwords aren't available for $user right now, or
	 * null when they are.
	 *
	 * @return array{type:string,plugin:?string}|null
	 */
	public static function unavailable_reason( ?\WP_User $user ): ?array {
		if ( ! class_exists( WP_Application_Passwords::class ) || ! function_exists( 'wp_is_application_passwords_available' ) ) {
			return array(
				'type'   => 'unsupported',
				'plugin' => null,
			);
		}

		if ( ! Config::is_secure_transport() ) {
			return array(
				'type'   => 'transport',
				'plugin' => null,
			);
		}

		if ( ! wp_is_application_passwords_available() ) {
			return array(
				'type'   => 'disabled',
				'plugin' => self::find_disabling_plugin( 'wp_is_application_passwords_available' ),
			);
		}

		if (
			$user instanceof \WP_User
			&& function_exists( 'wp_is_application_passwords_available_for_user' )
			&& ! wp_is_application_passwords_available_for_user( $user )
		) {
			return array(
				'type'   => 'disabled_for_user',
				'plugin' => self::find_disabling_plugin( 'wp_is_application_passwords_available_for_user' ),
			);
		}

		return null;
	}

	/**
	 * Human-readable explanation for a reason from unavailable_reason(), for
	 * contexts (REST error messages) that need prose rather than a UI branch
	 * on the `type`/`plugin` fields.
	 *
	 * @param array{type:string,plugin:?string} $reason
	 */
	public static function unavailable_message( array $reason ): string {
		if ( 'transport' === $reason['type'] || 'unsupported' === $reason['type'] ) {
			return __(
				"Application passwords require HTTPS and aren't available on this site. On a production environment WordPress only allows them over HTTPS; over plain HTTP they work only when the site's environment type is local (set WP_ENVIRONMENT_TYPE to local in wp-config.php).",
				'agent-connector-for-wp'
			);
		}

		if ( ! empty( $reason['plugin'] ) ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( 'Application passwords are disabled by the "%s" plugin.', 'agent-connector-for-wp' ),
				$reason['plugin']
			);
		}

		return __( 'Application passwords are disabled on this site by a plugin or theme.', 'agent-connector-for-wp' );
	}

	/**
	 * Best-effort: which installed plugin (if any) hooked $hook to force
	 * Application Passwords off. Core registers no callbacks on either
	 * availability filter itself, so any callback found here came from a
	 * plugin — we don't try to name themes or must-use code, only ordinary
	 * plugins living under WP_PLUGIN_DIR.
	 */
	private static function find_disabling_plugin( string $hook ): ?string {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = (array) get_plugins();

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$file = self::callback_file( $callback['function'] );
				if ( null === $file ) {
					continue;
				}
				foreach ( $plugins as $plugin_file => $data ) {
					$plugin_dir = trailingslashit( dirname( WP_PLUGIN_DIR . '/' . $plugin_file ) );
					if ( 0 === strpos( $file, $plugin_dir ) ) {
						return (string) $data['Name'];
					}
				}
			}
		}

		return null;
	}

	/**
	 * Source file of a hook callback, or null when it can't be resolved (a
	 * core/internal function, or reflection fails).
	 *
	 * @param callable $function
	 */
	private static function callback_file( $function ): ?string {
		try {
			if ( is_array( $function ) && 2 === count( $function ) ) {
				$reflection = new \ReflectionMethod( $function[0], $function[1] );
			} elseif ( $function instanceof \Closure || ( is_string( $function ) && function_exists( $function ) ) ) {
				$reflection = new \ReflectionFunction( $function );
			} elseif ( is_object( $function ) && method_exists( $function, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $function, '__invoke' );
			} else {
				return null;
			}
		} catch ( \ReflectionException $e ) {
			return null;
		}

		$file = $reflection->getFileName();
		return is_string( $file ) ? $file : null;
	}
}
