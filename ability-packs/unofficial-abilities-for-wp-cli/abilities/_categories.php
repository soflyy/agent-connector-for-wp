<?php
/**
 * Ability categories for the WP-CLI pack. Loaded first (underscore sorts ahead
 * of the family files) so every ability has a registered category to attach to.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::category(
	'wp-cli-data',
	array(
		'label'       => 'WP-CLI: Options & Data',
		'description' => 'Native-PHP equivalents of `wp option`, `wp transient`, `wp cache`, `wp db`, and `wp search-replace`.',
	)
);

ACFW_Pack::category(
	'wp-cli-content',
	array(
		'label'       => 'WP-CLI: Content',
		'description' => 'Native-PHP equivalents of `wp post`, `wp post-type`, `wp post-meta`, `wp comment`, `wp term`, `wp taxonomy`, `wp media`, and `wp menu`.',
	)
);

ACFW_Pack::category(
	'wp-cli-users',
	array(
		'label'       => 'WP-CLI: Users & Roles',
		'description' => 'Native-PHP equivalents of `wp user`, `wp user-meta`, `wp role`, and `wp cap`.',
	)
);

ACFW_Pack::category(
	'wp-cli-extend',
	array(
		'label'       => 'WP-CLI: Plugins & Themes',
		'description' => 'Native-PHP equivalents of `wp plugin`, `wp theme`, `wp theme mod`, `wp language`, `wp widget`, and `wp sidebar`.',
	)
);

ACFW_Pack::category(
	'wp-cli-system',
	array(
		'label'       => 'WP-CLI: Site & System',
		'description' => 'Native-PHP equivalents of `wp core`, `wp cron`, `wp rewrite`, `wp maintenance-mode`, `wp config`, `wp site`, and `wp export`/`import`.',
	)
);
