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
	public const SCHEMA_VERSION = 1;

	/**
	 * Unprefixed name of the subscriptions table.
	 */
	public const SUBSCRIPTIONS_TABLE = 'apermo_notify_subscriptions';

	/**
	 * Unprefixed name of the sent-log table.
	 */
	public const SENT_LOG_TABLE = 'apermo_notify_sent_log';

	/**
	 * Runs activation: creates or migrates custom tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::tables()->create_and_update_tables();
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
				PRIMARY KEY  (id),
				KEY target (target_type, target_id),
				KEY email (email),
				KEY token (token),
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
