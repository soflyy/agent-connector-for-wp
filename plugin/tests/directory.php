<?php
/** Offline regression tests; run with `composer test` from plugin/. */

declare( strict_types=1 );

require __DIR__ . '/bootstrap.php';

use AgentConnectorForWp\Admin\DirectoryPage;
use AgentConnectorForWp\Rest\SettingsController;
use AgentConnectorForWp\Services\PackUpdater;
use AgentConnectorForWp\Services\PluginDirectory;

$assertions = 0;
function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$GLOBALS['assertions'];
}
function call_private( $class, string $method, ...$args ) {
	return ( new ReflectionMethod( $class, $method ) )->invoke( is_object( $class ) ? $class : null, ...$args );
}
function render_cell( array $row ): string {
	ob_start();
	try {
		call_private( new DirectoryPage(), 'render_pack_cell', $row );
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

$external = json_decode( file_get_contents( __DIR__ . '/../../ability-packs/external.json' ), true, 512, JSON_THROW_ON_ERROR );
check( 2 === count( $external ), 'There must be two external listings.' );
$expected_names = array(
	'elevated-product-search' => 'Elevated Product Search',
	'elevated-discount-rules' => 'Elevated Discount Rules',
);
foreach ( $external as $entry ) {
	$slug = $entry['ability_pack_slug'];
	check( isset( $expected_names[ $slug ] ) && $expected_names[ $slug ] === $entry['ability_pack_name'], 'External slug and name must match the product.' );
	check( "https://elevatedextensions.com/latest/{$slug}.zip" === $entry['download_url'], 'External download must use the latest ZIP endpoint.' );
	check( "https://elevatedextensions.com/extensions/{$slug}/" === $entry['source_url'], 'External source must use the product page.' );
}
$GLOBALS['manifest'] = json_encode( array( 'entries' => $external ), JSON_THROW_ON_ERROR );
$entries = PluginDirectory::get( true )['entries'];
check( 2 === count( $entries ), 'Versionless entries must survive remote normalization.' );
foreach ( $entries as $i => $entry ) {
	check( ! array_key_exists( 'version', $external[ $i ] ), 'External source must omit version.' );
	check( '' === $entry['version'], 'Missing version must normalize to an empty string.' );
	check( 'woocommerce' === $entry['target_plugin'], 'External entries must target WooCommerce.' );
	check( $external[ $i ]['description'] === $entry['description'], 'Descriptions must survive normalization.' );
}

// Product Search deliberately has a main file different from its folder name.
$GLOBALS['plugins'] = array(
	'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '10.0' ),
	'elevated-product-search/plugin.php' => array( 'Name' => 'Elevated Product Search', 'Version' => '1.0' ),
	'elevated-discount-rules/elevated-discount-rules.php' => array( 'Name' => 'Elevated Discount Rules', 'Version' => '1.0' ),
	'hello.php' => array( 'Name' => 'Hello', 'Version' => '1.0' ),
);
$GLOBALS['active_plugins'] = array_keys( $GLOBALS['plugins'] );
check( 'elevated-product-search/plugin.php' === PluginDirectory::installed_file_for_slug( 'elevated-product-search' ), 'Folder must resolve to plugin.php.' );
check( 'hello.php' === PluginDirectory::installed_file_for_slug( 'hello' ), 'Single-file plugins must still resolve.' );
check( null === PluginDirectory::installed_file_for_slug( 'missing' ), 'Absent plugin must not match.' );

$matches = PluginDirectory::match_installed( $entries );
check( 2 === count( $matches ), 'Both entries must match WooCommerce.' );
foreach ( $matches as $match ) {
	check( $match['pack_installed'] && $match['pack_active'], 'Both installed main files must be detected as active.' );
	$match['has_pack'] = true;
	$html = render_cell( $match );
	check( str_contains( $html, 'Installed &amp; active' ), 'Active status must render for versionless plugins.' );
	check( ! str_contains( $html, '<span class="description">v' ), 'Missing version must not render a version label.' );
}

// Exercise both missing-key and normalized-empty-string rendering.
$row = array( 'has_pack' => true, 'entry' => $external[0], 'pack_installed' => false, 'pack_active' => false );
$html = render_cell( $row );
check( str_contains( $html, 'Install &amp; Activate' ), 'A raw versionless listing must remain installable.' );
check( ! str_contains( $html, '<span class="description">v' ), 'A raw missing version must not render a label.' );
$row['entry']['version'] = '2.0.0';
check( str_contains( render_cell( $row ), 'v2.0.0' ), 'Versioned listings must retain their labels.' );

// A generated WooCommerce pack must not be overwritten by the external entries.
$generated = array_merge( $entries[0], array( 'ability_pack_slug' => 'unofficial-abilities-for-woocommerce', 'ability_pack_name' => 'Generated WooCommerce pack', 'version' => '2.0.0' ) );
set_transient( PluginDirectory::CACHE_KEY, array( 'entries' => array_merge( array( $generated ), $entries ) ), 3600 );
$rows = PluginDirectory::installed_overview()['rows'];
$woo_rows = array_values( array_filter( $rows, static fn( $row ) => 'woocommerce/woocommerce.php' === $row['plugin_file'] ) );
check( 3 === count( $woo_rows ), 'All three WooCommerce listings must survive the overview join.' );
check( array_column( array_column( $woo_rows, 'entry' ), 'ability_pack_slug' ) === array_column( array_merge( array( $generated ), $entries ), 'ability_pack_slug' ), 'Each WooCommerce row must retain its own entry.' );
$hello_rows = array_values( array_filter( $rows, static fn( $row ) => 'hello.php' === $row['plugin_file'] ) );
check( 1 === count( $hello_rows ) && ! $hello_rows[0]['has_pack'], 'Plugins without packs must still get one row.' );
$GLOBALS['active_plugins'] = array( 'woocommerce/woocommerce.php' );
foreach ( PluginDirectory::match_installed( $entries ) as $match ) {
	check( $match['pack_installed'] && ! $match['pack_active'], 'Inactive plugins must be detected separately from absent plugins.' );
}
$GLOBALS['active_plugins'] = array();
check( array() === PluginDirectory::installed_overview()['rows'], 'Inactive target plugins must not appear in the overview.' );

// Both installers must allow the vendor while retaining HTTPS and host boundaries.
$url_cases = array(
	'https://elevatedextensions.com/latest/elevated-product-search.zip' => true,
	'https://elevatedextensions.com/latest/elevated-discount-rules.zip' => true,
	'https://ELEVATEDEXTENSIONS.COM/latest/pack.zip' => true,
	'https://cdn.elevatedextensions.com/pack.zip' => true,
	'https://github.com/org/repo/releases/download/v1/pack.zip' => true,
	'https://objects.githubusercontent.com/pack.zip' => true,
	'https://codeload.github.com/org/repo/zip/v1' => true,
	'http://elevatedextensions.com/pack.zip' => false,
	'ftp://elevatedextensions.com/pack.zip' => false,
	'https://elevatedextensions.com.evil.example/pack.zip' => false,
	'https://notelevatedextensions.com/pack.zip' => false,
	'https://elevatedextensions.com@evil.example/pack.zip' => false,
	'/latest/pack.zip' => false,
);
foreach ( array( array( DirectoryPage::class, 'is_allowed_download' ), array( SettingsController::class, 'is_pack_download_allowed' ) ) as [ $class, $method ] ) {
	foreach ( $url_cases as $url => $expected ) {
		check( $expected === call_private( $class, $method, $url ), $class . ' download policy failed for ' . $url );
	}
	$GLOBALS['filters']['agent_connector_for_wp_pack_download_hosts'] = static fn( $hosts ) => array( 'custom.example' );
	check( call_private( $class, $method, 'https://custom.example/pack.zip' ), 'The host filter must support a custom host.' );
	check( ! call_private( $class, $method, 'https://elevatedextensions.com/pack.zip' ), 'The host filter must be able to remove defaults.' );
	unset( $GLOBALS['filters']['agent_connector_for_wp_pack_download_hosts'] );
}

// Include a real versioned pack so the updater cannot pass by simply doing nothing.
$managed_file = 'unofficial-abilities-for-woocommerce/main.php';
$GLOBALS['plugins'][ $managed_file ] = array( 'Name' => 'Generated pack', 'Version' => '1.0.0', 'Agent Connector' => 'Ability Pack' );
set_transient( PluginDirectory::UNIVERSAL_ABILITIES_FAIL_KEY, 1, 3600 );
foreach ( array( false, true ) as $with_marker ) {
	foreach ( array( false, true ) as $normalized ) {
		foreach ( array( 'elevated-product-search/plugin.php', 'elevated-discount-rules/elevated-discount-rules.php' ) as $file ) {
			$GLOBALS['plugins'][ $file ]['Agent Connector'] = $with_marker ? 'Ability Pack' : '';
		}
		$listings = $normalized ? $entries : $external;
		set_transient( PluginDirectory::CACHE_KEY, array( 'entries' => array_merge( array( $generated ), $listings ) ), 3600 );
		$transient = (object) array( 'response' => array(), 'no_update' => array() );
		$updated = ( new PackUpdater() )->inject_updates( $transient );
		check( isset( $updated->response[ $managed_file ] ) && '2.0.0' === $updated->response[ $managed_file ]->new_version, 'Versioned marked packs must still offer updates.' );
		foreach ( array( 'elevated-product-search/plugin.php', 'elevated-discount-rules/elevated-discount-rules.php' ) as $file ) {
			check( ! isset( $updated->response[ $file ] ) && ! isset( $updated->no_update[ $file ] ), 'Versionless entries must never be managed, even with the marker header.' );
		}
	}
}
// Header exclusion is independent of version exclusion.
$versioned_external = array_map( static fn( $entry ) => array_merge( $entry, array( 'version' => '999.0.0' ) ), $external );
foreach ( array( 'elevated-product-search/plugin.php', 'elevated-discount-rules/elevated-discount-rules.php' ) as $file ) {
	unset( $GLOBALS['plugins'][ $file ]['Agent Connector'] );
}
set_transient( PluginDirectory::CACHE_KEY, array( 'entries' => $versioned_external ), 3600 );
$updated = ( new PackUpdater() )->inject_updates( (object) array( 'response' => array(), 'no_update' => array() ) );
check( array() === $updated->response && array() === $updated->no_update, 'Unmarked external plugins must never be managed, even with a version.' );

echo "Directory regression tests: {$assertions} assertions passed.\n";
