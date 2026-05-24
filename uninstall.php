<?php
/**
 * Removes all plugin-owned data on uninstall.
 *
 * Composer-dependent code lives behind an autoload guard. If `vendor/`
 * is missing (corrupted install, plugin folder copied without running
 * `composer install`), we still want uninstall to do the minimum: clear
 * the cron hook and drop the option, plus the known custom tables
 * directly through `$wpdb`. Anything that requires our classes —
 * `Activation::drop_all()` etc. — is skipped in that fallback path.
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit();

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\Apermo\Notify\Cron\Scheduler::unschedule();
	\Apermo\Notify\Activation::drop_all();
	delete_option( 'apermo_notify_settings' );
	return;
}

// Fallback: vendor/ is gone. Hand-roll the cleanup using only WP core APIs.
wp_clear_scheduled_hook( 'apermo_notify_prune' );

global $wpdb;

// Mirrors `Activation::SUBSCRIPTIONS_TABLE` / `SENT_LOG_TABLE`. Keep these
// constants and the table names in sync if either side changes.
foreach ( [ 'apermo_notify_subscriptions', 'apermo_notify_sent_log' ] as $apermo_notify_table_slug ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.DB.RestrictedKeywords, WordPress.WP.GlobalVariablesOverride, WordPress.DB.PreparedSQL.NotPrepared, Apermo.WordPress.NoHardcodedTableNames.Found -- DDL only runs on uninstall; %i quotes the table identifier; the "hardcoded table name" heuristic misreads `DROP TABLE IF EXISTS`.
	$apermo_notify_drop_sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $apermo_notify_table_slug );
	$wpdb->query( $apermo_notify_drop_sql );
	// phpcs:enable
}

delete_option( 'apermo_notify_settings' );
delete_option( 'apermo_notify_db_version' );
