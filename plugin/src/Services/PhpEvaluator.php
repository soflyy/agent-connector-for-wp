<?php
/**
 * Arbitrary PHP evaluation inside the loaded WordPress runtime.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a PHP snippet with output buffering, returning printed output, the
 * returned value (JSON-normalized), and any thrown Throwable. Fatal errors are
 * best-effort captured via a shutdown handler.
 */
final class PhpEvaluator {

	/**
	 * @param string $code     PHP code. May `return` a value.
	 * @param ?int   $timeout_ms Soft time limit applied via set_time_limit().
	 *
	 * @return array{output:string,output_encoding:string,result:mixed,result_type:string,exception:?array}
	 */
	public function evaluate( string $code, ?int $timeout_ms = null ): array {
		$timeout_ms = null === $timeout_ms ? Config::default_timeout_ms() : max( 1000, $timeout_ms );
		// set_time_limit takes whole seconds; round up.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) ceil( $timeout_ms / 1000 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$exception = null;
		$result    = null;
		$result_ty = 'null';

		ob_start();

		// Capture an otherwise-uncatchable fatal so the caller gets *something*
		// back instead of a dropped connection. The flag lets us tell a clean
		// finish from a fatal during shutdown.
		$completed = false;
		$shutdown  = static function () use ( &$completed ) {
			if ( $completed ) {
				return;
			}
			$err = error_get_last();
			if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
				$buffer = ob_get_clean();
				$out    = Helpers::encode_output( is_string( $buffer ) ? $buffer : '' );
				$payload = array(
					'output'          => $out['data'],
					'output_encoding' => $out['encoding'],
					'result'          => null,
					'result_type'     => 'null',
					'exception'       => array(
						'type'    => 'FatalError',
						'message' => (string) $err['message'],
						'file'    => (string) $err['file'],
						'line'    => (int) $err['line'],
					),
				);
				// We are in shutdown; emit and stop.
				echo wp_json_encode( array( 'agent_connector_for_wp_fatal' => $payload ) );
			}
		};
		register_shutdown_function( $shutdown );

		try {
			// eval() returns whatever the code `return`s, or null.
			$result    = eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged, WordPress.PHP.Eval.Eval
			$result_ty = gettype( $result );
		} catch ( \Throwable $t ) {
			$exception = array(
				'type'    => get_class( $t ),
				'message' => $t->getMessage(),
				'file'    => $t->getFile(),
				'line'    => $t->getLine(),
			);
		}

		$completed = true;
		$buffer    = ob_get_clean();
		$out       = Helpers::encode_output( is_string( $buffer ) ? $buffer : '' );

		return array(
			'output'          => $out['data'],
			'output_encoding' => $out['encoding'],
			'result'          => $this->normalize( $result ),
			'result_type'     => $result_ty,
			'exception'       => $exception,
		);
	}

	/**
	 * Make an arbitrary return value JSON-safe. Objects without a natural JSON
	 * form fall back to print_r so the agent at least sees their shape.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed JSON-encodable value.
	 */
	private function normalize( $value ) {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		// Probe whether it encodes cleanly as-is.
		$encoded = wp_json_encode( $value );
		if ( false !== $encoded ) {
			return json_decode( $encoded, true );
		}
		return array(
			'__unserializable' => true,
			'preview'          => print_r( $value, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.RestrictedFunctions
		);
	}
}
