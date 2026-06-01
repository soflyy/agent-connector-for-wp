<?php
/**
 * Marker wrapper around an ability execute callback.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

defined( 'ABSPATH' ) || exit;

/**
 * An invokable that wraps an ability's execute callback with the plugin's
 * domain-lock enforcement and audit logging (see AuditLogger::wrap()).
 *
 * It exists as a concrete class — rather than a plain closure — purely so the
 * governance layer can recognize an already-wrapped callback (`instanceof`) and
 * never wrap it a second time. Double-wrapping would double-log and run the
 * domain-lock check twice; the marker makes the chokepoint idempotent.
 *
 * Being invokable, it is itself `callable` and drops in anywhere a closure
 * execute_callback was used before.
 */
final class WrappedHandler {

	/**
	 * The ability name, used for the audit-log line.
	 *
	 * @var string
	 */
	private $ability;

	/**
	 * The underlying ability handler: fn(array $input): mixed|WP_Error.
	 *
	 * @var callable
	 */
	private $handler;

	/**
	 * Optional redaction callback for the audit log: fn(array $input): array.
	 *
	 * @var callable|null
	 */
	private $summarizer;

	/**
	 * @param string        $ability    Ability name, e.g. my-plugin/do-thing.
	 * @param callable      $handler    fn(array $input): mixed|WP_Error.
	 * @param callable|null $summarizer fn(array $input): array — a redacted summary for the log.
	 */
	public function __construct( string $ability, callable $handler, ?callable $summarizer = null ) {
		$this->ability    = $ability;
		$this->handler    = $handler;
		$this->summarizer = $summarizer;
	}

	/**
	 * Invoke the wrapped handler with domain-lock enforcement + audit logging.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return mixed|\WP_Error
	 */
	public function __invoke( $input = array() ) {
		return AuditLogger::run( $this->ability, $this->handler, $this->summarizer, $input );
	}
}
