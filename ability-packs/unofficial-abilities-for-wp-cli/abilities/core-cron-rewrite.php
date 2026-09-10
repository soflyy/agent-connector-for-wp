<?php
/**
 * `wp core`, `wp cron`, and `wp rewrite` — site/system management in native PHP.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * CORE (`wp core`)
 * -----------------------------------------------------------------------------
 */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-version',
		'label'            => 'Core Version',
		'description'      => 'Report the installed WordPress version (like `wp core version`). With `extra` true, also returns the database schema version, PHP version, and the MySQL/MariaDB server version.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'extra' => array( 'type' => 'boolean', 'description' => 'Include db_version, php_version, and mysql_version. Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'version'        => array( 'type' => 'string' ),
				'db_version'     => array( 'type' => 'string' ),
				'php_version'    => array( 'type' => 'string' ),
				'mysql_version'  => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb, $wp_version;
			$version = isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );
			$out     = array( 'version' => $version );
			if ( ! empty( $input['extra'] ) ) {
				$out['db_version']    = (string) get_option( 'db_version' );
				$out['php_version']   = PHP_VERSION;
				$out['mysql_version'] = ( $wpdb && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : '';
			}
			return $out;
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-check-update',
		'label'            => 'Core Check Update',
		'description'      => 'Check whether a newer WordPress core release is available (like `wp core check-update`). Forces a fresh check via wp_version_check() and reads the update_core transient. Requires outbound network access to WordPress.org.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'current_version'  => array( 'type' => 'string' ),
				'updates'          => array( 'type' => 'array' ),
				'update_available' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_version;
			if ( ! function_exists( 'wp_version_check' ) ) {
				require_once ABSPATH . WPINC . '/update.php';
			}
			wp_version_check( array(), true );
			$current   = isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' );
			$transient = get_site_transient( 'update_core' );
			$updates   = array();
			if ( is_object( $transient ) && ! empty( $transient->updates ) && is_array( $transient->updates ) ) {
				foreach ( $transient->updates as $update ) {
					if ( isset( $update->response ) && 'latest' === $update->response ) {
						continue;
					}
					$updates[] = array(
						'version' => isset( $update->version ) ? (string) $update->version : '',
						'locale'  => isset( $update->locale ) ? (string) $update->locale : '',
						'package' => isset( $update->package ) ? (string) $update->package : '',
					);
				}
			}
			return array(
				'current_version'  => $current,
				'updates'          => $updates,
				'update_available' => ! empty( $updates ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-is-installed',
		'label'            => 'Core Is Installed',
		'description'      => 'Report whether WordPress is installed and whether this is a multisite network (like `wp core is-installed`).',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'installed' => array( 'type' => 'boolean' ),
				'multisite' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array(
				'installed' => (bool) is_blog_installed(),
				'multisite' => (bool) is_multisite(),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-update-db',
		'label'            => 'Core Update DB',
		'description'      => 'Run the WordPress database upgrade routine (like `wp core update-db`). Runs wp_upgrade() to bring the schema up to date with the installed core version. Idempotent — no-op when the DB is already current.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'         => array( 'type' => 'boolean' ),
				'from_db_version' => array( 'type' => 'string' ),
				'to_db_version'   => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$from = (string) get_option( 'db_version' );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! function_exists( 'wp_upgrade' ) ) {
				return wpcli_pack_err( 'upgrade_unavailable', 'wp_upgrade() is not available in this environment.', 500 );
			}
			wp_upgrade();
			// wp_upgrade() may cache the new db_version; read it fresh.
			wp_cache_delete( 'alloptions', 'options' );
			$to = (string) get_option( 'db_version' );
			return array(
				'success'         => true,
				'from_db_version' => $from,
				'to_db_version'   => $to,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-check-extensions',
		'label'            => 'Core Check Extensions',
		'description'      => 'Report the loaded PHP extensions and key environment versions. Useful for diagnosing missing requirements (e.g. mysqli, curl, gd, mbstring). No direct WP-CLI equivalent; complements `wp core version --extra`.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'php_version' => array( 'type' => 'string' ),
				'extensions'  => array( 'type' => 'array' ),
				'wp_version'  => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_version;
			$extensions = get_loaded_extensions();
			sort( $extensions );
			return array(
				'php_version' => PHP_VERSION,
				'extensions'  => array_values( $extensions ),
				'wp_version'  => isset( $wp_version ) ? (string) $wp_version : (string) get_bloginfo( 'version' ),
			);
		},
	)
);

/*
 * -----------------------------------------------------------------------------
 * CRON (`wp cron`)
 * -----------------------------------------------------------------------------
 */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-event-list',
		'label'            => 'Cron Event List',
		'description'      => 'List all scheduled WP-Cron events (like `wp cron event list`). Each event reports its hook, next run time (GMT and human-relative), recurrence schedule (or "one-time"), and arguments.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'  => array( 'type' => 'integer' ),
				'events' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$crons  = _get_cron_array();
			$events = array();
			if ( is_array( $crons ) ) {
				foreach ( $crons as $timestamp => $hooks ) {
					if ( ! is_array( $hooks ) ) {
						continue;
					}
					foreach ( $hooks as $hook => $instances ) {
						if ( ! is_array( $instances ) ) {
							continue;
						}
						foreach ( $instances as $instance ) {
							$schedule = ( isset( $instance['schedule'] ) && $instance['schedule'] ) ? (string) $instance['schedule'] : 'one-time';
							$events[] = array(
								'hook'             => (string) $hook,
								'next_run_gmt'     => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
								'next_run_relative' => human_time_diff( time(), (int) $timestamp ),
								'schedule'         => $schedule,
								'args'             => isset( $instance['args'] ) ? array_values( (array) $instance['args'] ) : array(),
							);
						}
					}
				}
			}
			return array( 'count' => count( $events ), 'events' => $events );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-event-run',
		'label'            => 'Cron Event Run',
		'description'      => 'Run WP-Cron events now (like `wp cron event run`). Provide `hooks` to run specific hooks regardless of due time, set `due_now` to run all currently-due events, or `all` to run every scheduled event. One-time events are unscheduled after running.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'hooks'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Hook names to run regardless of due time.' ),
				'due_now' => array( 'type' => 'boolean', 'description' => 'Run all events whose next run time has passed.' ),
				'all'     => array( 'type' => 'boolean', 'description' => 'Run every scheduled event regardless of due time.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'ran' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$crons      = _get_cron_array();
			$hook_names = isset( $input['hooks'] ) ? array_map( 'strval', (array) $input['hooks'] ) : array();
			$due_now    = ! empty( $input['due_now'] );
			$all        = ! empty( $input['all'] );
			$now        = time();
			$ran        = array();

			if ( empty( $hook_names ) && ! $due_now && ! $all ) {
				return wpcli_pack_err( 'no_selection', 'Specify hooks, due_now, or all to choose which events to run.' );
			}
			if ( ! is_array( $crons ) ) {
				return array( 'ran' => array() );
			}

			foreach ( $crons as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				$timestamp = (int) $timestamp;
				foreach ( $hooks as $hook => $instances ) {
					$hook = (string) $hook;
					if ( ! is_array( $instances ) ) {
						continue;
					}
					$selected = $all
						|| ( $due_now && $timestamp <= $now )
						|| in_array( $hook, $hook_names, true );
					if ( ! $selected ) {
						continue;
					}
					foreach ( $instances as $instance ) {
						$args = isset( $instance['args'] ) ? (array) $instance['args'] : array();
						$is_recurring = ! empty( $instance['schedule'] );
						$status = 'ran';
						try {
							// One-time events must be unscheduled before running, mirroring WP-Cron.
							if ( ! $is_recurring ) {
								wp_unschedule_event( $timestamp, $hook, $args );
							}
							do_action_ref_array( $hook, $args );
						} catch ( \Throwable $e ) {
							$status = 'error: ' . $e->getMessage();
						}
						$ran[] = array( 'hook' => $hook, 'status' => $status );
					}
				}
			}
			return array( 'ran' => $ran );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-event-schedule',
		'label'            => 'Cron Event Schedule',
		'description'      => 'Schedule a new WP-Cron event (like `wp cron event schedule`). Provide `recurrence` (a registered schedule name like "hourly" or "daily") for a recurring event, or omit it for a one-time event. `next_run` accepts a UNIX timestamp or a strtotime() string (default: now).',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'hook'       => array( 'type' => 'string', 'description' => 'Action hook name to fire.' ),
				'recurrence' => array( 'type' => 'string', 'description' => 'Schedule name (e.g. "hourly", "twicedaily", "daily"). Omit for a one-time event.' ),
				'next_run'   => array( 'description' => 'First run time: UNIX timestamp (integer) or strtotime string. Default now.' ),
				'args'       => array( 'type' => 'array', 'description' => 'Positional arguments passed to the hook.' ),
			),
			'required'             => array( 'hook' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'scheduled'    => array( 'type' => 'boolean' ),
				'next_run_gmt' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$hook = (string) $input['hook'];
			if ( '' === $hook ) {
				return wpcli_pack_err( 'bad_hook', 'hook is required.' );
			}
			$args = isset( $input['args'] ) ? array_values( (array) $input['args'] ) : array();

			// Resolve next_run to a timestamp.
			if ( ! isset( $input['next_run'] ) || '' === $input['next_run'] ) {
				$timestamp = time();
			} elseif ( is_numeric( $input['next_run'] ) ) {
				$timestamp = (int) $input['next_run'];
			} else {
				$timestamp = strtotime( (string) $input['next_run'] );
				if ( false === $timestamp ) {
					return wpcli_pack_err( 'bad_next_run', 'Could not parse next_run as a timestamp or date string.' );
				}
			}

			if ( ! empty( $input['recurrence'] ) ) {
				$recurrence = (string) $input['recurrence'];
				$schedules  = wp_get_schedules();
				if ( ! isset( $schedules[ $recurrence ] ) ) {
					return wpcli_pack_err( 'bad_recurrence', sprintf( 'Unknown recurrence "%s". Use wp-cli/cron-schedule-list to see available schedules.', $recurrence ) );
				}
				$result = wp_schedule_event( $timestamp, $recurrence, $hook, $args );
			} else {
				$result = wp_schedule_single_event( $timestamp, $hook, $args );
			}

			if ( false === $result || is_wp_error( $result ) ) {
				return wpcli_pack_err( 'schedule_failed', 'Could not schedule the event (it may be a duplicate or blocked by a filter).' );
			}
			return array(
				'scheduled'    => true,
				'next_run_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-event-delete',
		'label'            => 'Cron Event Delete',
		'description'      => 'Delete all scheduled instances of a hook (like `wp cron event delete`). Clears every queued occurrence of the given hook via wp_clear_scheduled_hook().',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'hook' => array( 'type' => 'string', 'description' => 'Hook name whose scheduled events should all be removed.' ),
			),
			'required'             => array( 'hook' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deleted_count' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$hook  = (string) $input['hook'];
			$count = wp_clear_scheduled_hook( $hook );
			// wp_clear_scheduled_hook returns the number cleared, or false on failure.
			return array( 'deleted_count' => is_int( $count ) ? $count : 0 );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-event-unschedule',
		'label'            => 'Cron Event Unschedule',
		'description'      => 'Unschedule the next occurrence of a hook (like `wp cron event unschedule`). Targets the upcoming run only; supply `args` to match an event scheduled with specific arguments.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'hook' => array( 'type' => 'string', 'description' => 'Hook name to unschedule.' ),
				'args' => array( 'type' => 'array', 'description' => 'Arguments the event was scheduled with (to match the exact instance).' ),
			),
			'required'             => array( 'hook' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$hook = (string) $input['hook'];
			$args = isset( $input['args'] ) ? array_values( (array) $input['args'] ) : array();
			$next = wp_next_scheduled( $hook, $args );
			if ( false === $next ) {
				return array( 'success' => false );
			}
			$result = wp_unschedule_event( $next, $hook, $args );
			return array( 'success' => ( false !== $result && ! is_wp_error( $result ) ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-schedule-list',
		'label'            => 'Cron Schedule List',
		'description'      => 'List the registered WP-Cron recurrence schedules (like `wp cron schedule list`). Returns each schedule name, its interval in seconds, and its display label.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'     => array( 'type' => 'integer' ),
				'schedules' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$schedules = wp_get_schedules();
			$out       = array();
			if ( is_array( $schedules ) ) {
				foreach ( $schedules as $name => $schedule ) {
					$out[] = array(
						'name'             => (string) $name,
						'interval_seconds' => isset( $schedule['interval'] ) ? (int) $schedule['interval'] : 0,
						'display'          => isset( $schedule['display'] ) ? (string) $schedule['display'] : '',
					);
				}
			}
			return array( 'count' => count( $out ), 'schedules' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/cron-test',
		'label'            => 'Cron Test',
		'description'      => 'Verify that WP-Cron can run (like `wp cron test`). Reports whether WP-Cron is enabled (DISABLE_WP_CRON), whether alternate cron (ALTERNATE_WP_CRON) is active, and attempts to spawn the cron runner.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'wp_cron_enabled'   => array( 'type' => 'boolean' ),
				'alternate_wp_cron' => array( 'type' => 'boolean' ),
				'message'           => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$disabled  = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
			$alternate = ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON );

			if ( $disabled ) {
				return array(
					'wp_cron_enabled'   => false,
					'alternate_wp_cron' => $alternate,
					'message'           => 'WP-Cron is disabled (DISABLE_WP_CRON is true). Events run only when triggered externally.',
				);
			}

			$spawn = spawn_cron();
			if ( is_wp_error( $spawn ) ) {
				return array(
					'wp_cron_enabled'   => true,
					'alternate_wp_cron' => $alternate,
					'message'           => 'WP-Cron is enabled but the spawn attempt failed: ' . $spawn->get_error_message(),
				);
			}
			return array(
				'wp_cron_enabled'   => true,
				'alternate_wp_cron' => $alternate,
				'message'           => false === $spawn
					? 'WP-Cron is enabled; no spawn was needed at this time (it may have run recently).'
					: 'WP-Cron is enabled and a cron run was spawned successfully.',
			);
		},
	)
);

/*
 * -----------------------------------------------------------------------------
 * REWRITE (`wp rewrite`)
 * -----------------------------------------------------------------------------
 */

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/rewrite-flush',
		'label'            => 'Rewrite Flush',
		'description'      => 'Flush (regenerate) the rewrite rules (like `wp rewrite flush`). Run after changing the permalink structure or registering new post types/taxonomies. `hard` true (default) also updates the web-server rewrite config where supported.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'hard' => array( 'type' => 'boolean', 'description' => 'Perform a hard flush (regenerate .htaccess / server rules). Default true.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$hard = array_key_exists( 'hard', $input ) ? (bool) $input['hard'] : true;
			flush_rewrite_rules( $hard );
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/rewrite-list',
		'label'            => 'Rewrite List',
		'description'      => 'List the active rewrite rules (like `wp rewrite list`). Each entry maps a URL `pattern` (regex) to the `query` it resolves to.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'rules' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_rewrite;
			if ( ! ( $wp_rewrite instanceof WP_Rewrite ) ) {
				return wpcli_pack_err( 'no_rewrite', 'The rewrite subsystem is not available.', 500 );
			}
			$rules = $wp_rewrite->wp_rewrite_rules();
			$out   = array();
			if ( is_array( $rules ) ) {
				foreach ( $rules as $pattern => $query ) {
					$out[] = array(
						'pattern' => (string) $pattern,
						'query'   => (string) $query,
					);
				}
			}
			return array( 'count' => count( $out ), 'rules' => $out );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/rewrite-structure',
		'label'            => 'Rewrite Structure',
		'description'      => 'Get or set the permalink structure (like `wp rewrite structure`). Omit `structure` to read the current value; provide it to set a new structure (e.g. "/%postname%/") which is saved and the rules flushed.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'structure' => array( 'type' => 'string', 'description' => 'New permalink structure tag string (e.g. "/%postname%/"). Omit to just read the current value.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'structure' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_rewrite;
			if ( array_key_exists( 'structure', $input ) ) {
				if ( ! ( $wp_rewrite instanceof WP_Rewrite ) ) {
					return wpcli_pack_err( 'no_rewrite', 'The rewrite subsystem is not available.', 500 );
				}
				$wp_rewrite->set_permalink_structure( (string) $input['structure'] );
				flush_rewrite_rules();
			}
			return array( 'structure' => (string) get_option( 'permalink_structure' ) );
		},
	)
);
