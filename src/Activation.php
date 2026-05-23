<?php

declare(strict_types=1);

namespace Apermo\Notify;

\defined( 'ABSPATH' ) || exit();

use apermo\WPTools\Custom_Tables;

/**
 * Creates and maintains the plugin's custom DB tables.
 */
class Activation {

	/**
	 * Option key that tracks the current schema version.
	 */
	public const VERSION_OPTION = 'apermo_notify_db_version';

	/**
	 * Current schema version. Increment when the SQL below changes.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Unprefixed name of the subscriptions table.
	 */
	public const SUBSCRIPTIONS_TABLE = 'apermo_notify_subscriptions';

	/**
	 * Unprefixed name of the sent-log table.
	 */
	public const SENT_LOG_TABLE = 'apermo_notify_sent_log';

	/**
	 * Runs activation: creates or migrates custom tables and backfills any
	 * schema upgrade that needs data movement (dbDelta only adds columns).
	 *
	 * @return void
	 */
	public static function activate(): void {
		$previous_version = (int) get_option( self::VERSION_OPTION, 0 );

		self::tables()->create_and_update_tables();

		if ( $previous_version > 0 && $previous_version < 2 ) {
			self::backfill_v2();
		}
	}

	/**
	 * Backfills the v2 columns for rows created under v1 so the prune cron
	 * doesn't immediately mark legacy data stale.
	 *
	 * @return void
	 */
	private static function backfill_v2(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIPTIONS_TABLE;

		// Existing confirmed rows: their last "interaction" was confirmation.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET kept_alive_at = confirmed_at WHERE confirmed_at IS NOT NULL AND kept_alive_at IS NULL',
				$table,
			),
		);

		// Any row without recorded consent: assume implicit consent at creation
		// time. New rows from v2 onwards record explicit consent in FormHandler.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET consent_at = created_at WHERE consent_at IS NULL',
				$table,
			),
		);
	}

	/**
	 * Drops all plugin-owned tables and deletes the schema-version option.
	 *
	 * Uses a freshly constructed Custom_Tables helper without registering any
	 * tables via add(); drop_table() works off `$wpdb` directly and does not
	 * need the registered set.
	 *
	 * @return void
	 */
	public static function drop_all(): void {
		$tables = new Custom_Tables( self::VERSION_OPTION, self::SCHEMA_VERSION );
		$tables->drop_table( self::SUBSCRIPTIONS_TABLE );
		$tables->drop_table( self::SENT_LOG_TABLE );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Builds a Custom_Tables helper preloaded with the plugin's tables.
	 *
	 * @return Custom_Tables
	 */
	public static function tables(): Custom_Tables {
		$tables = new Custom_Tables( self::VERSION_OPTION, self::SCHEMA_VERSION );

		$tables->add(
			self::SUBSCRIPTIONS_TABLE,
			"CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				target_type varchar(32) NOT NULL,
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_meta varchar(64) NOT NULL DEFAULT '',
				filter_json text NULL,
				email varchar(254) NOT NULL,
				token char(64) NOT NULL,
				status tinyint(3) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				confirmed_at datetime NULL,
				last_notified_at datetime NULL,
				consent_at datetime NULL,
				kept_alive_at datetime NULL,
				stale_email_sent_at datetime NULL,
				PRIMARY KEY  (id),
				KEY target (target_type, target_id),
				KEY email (email),
				KEY token (token),
				KEY kept_alive (kept_alive_at),
				UNIQUE KEY target_email (target_type, target_id, target_meta, email)
			) %s",
		);

		$tables->add(
			self::SENT_LOG_TABLE,
			'CREATE TABLE %s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				event varchar(16) NOT NULL,
				sent_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY subscription (subscription_id),
				KEY post_event (post_id, event)
			) %s',
		);

		return $tables;
	}
}
