<?php
/**
 * Maintenance-mode status + the core on/off verbs.
 *
 * @package AcfwAbilityPackMaintenance
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::category(
	'maintenance',
	array(
		'label'       => 'Maintenance',
		'description' => 'Control the Maintenance plugin: toggle maintenance mode and customize the maintenance / coming-soon page.',
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/get-status',
		'label'            => 'Get Maintenance Status',
		'description'      => 'Report whether maintenance mode is currently ON (the site is closed to logged-out visitors) or OFF, plus the preview URL and a few headline settings. Read this first to know the current state.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'enabled'                => array( 'type' => 'boolean' ),
				'preview_url'            => array( 'type' => 'string' ),
				'site_url'               => array( 'type' => 'string' ),
				'page_title'             => array( 'type' => 'string' ),
				'heading'                => array( 'type' => 'string' ),
				'http_503_enabled'       => array( 'type' => 'boolean' ),
				'frontend_login_enabled' => array( 'type' => 'boolean' ),
				'excluded_page_count'    => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$ready = mtnc_pack_ready();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			return mtnc_pack_status_view();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/enable',
		'label'            => 'Enable Maintenance Mode',
		'description'      => 'Turn maintenance mode ON. The maintenance/coming-soon page is then shown to all visitors who are not logged in; logged-in users still see the real site. Returns the new status. Idempotent.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$saved = mtnc_pack_save( array( 'state' => 1 ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return mtnc_pack_status_view();
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'maintenance/disable',
		'label'            => 'Disable Maintenance Mode',
		'description'      => 'Turn maintenance mode OFF so the normal site is visible to everyone again. Settings are preserved and can be re-enabled later. Returns the new status. Idempotent.',
		'category'         => 'maintenance',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array( 'type' => 'object' ),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$saved = mtnc_pack_save( array( 'state' => 0 ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return mtnc_pack_status_view();
		},
	)
);
