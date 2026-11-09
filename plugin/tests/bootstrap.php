<?php
/** Minimal WordPress boundary doubles for the offline directory tests. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// Report warnings (including undefined version keys) as test failures.
set_error_handler( static function ( int $severity, string $message, string $file, int $line ): void {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

class WP_REST_Controller {}

function apply_filters( $name, $value ) {
	return isset( $GLOBALS['filters'][ $name ] ) ? $GLOBALS['filters'][ $name ]( $value ) : $value;
}
function get_transient( $key ) {
	return $GLOBALS['transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['transients'][ $key ] = $value;
}
function delete_transient( $key ) {
	unset( $GLOBALS['transients'][ $key ] );
}
function get_plugins() {
	return $GLOBALS['plugins'];
}
function is_plugin_active( $file ) {
	return in_array( $file, $GLOBALS['active_plugins'], true );
}
function is_admin() {
	return false;
}
function wp_remote_get( $url, $args ) {
	return array( 'body' => $GLOBALS['manifest'] );
}
function wp_remote_retrieve_response_code( $response ) {
	return 200;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_parse_url( $url ) {
	return parse_url( $url );
}
function current_user_can( $cap ) {
	return true;
}
function __( $text, $domain ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $text ) {
	return esc_html( $text );
}
function esc_url( $text ) {
	return esc_html( $text );
}
function esc_html__( $text, $domain ) {
	return esc_html( $text );
}
function esc_html_e( $text, $domain ) {
	echo esc_html( $text );
}
function admin_url( $path ) {
	return '/wp-admin/' . $path;
}
function wp_nonce_field( $action ) {
	echo '<input name="_wpnonce" value="test" />';
}
function submit_button( $label, $type, $name, $wrap ) {
	echo '<button>' . esc_html( $label ) . '</button>';
}

require_once __DIR__ . '/../src/Services/PluginDirectory.php';
require_once __DIR__ . '/../src/Services/PackUpdater.php';
require_once __DIR__ . '/../src/Admin/DirectoryPage.php';
require_once __DIR__ . '/../src/Rest/SettingsController.php';
