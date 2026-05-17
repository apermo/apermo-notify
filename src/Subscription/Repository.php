<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

use Apermo\Notify\Activation;

/**
 * Persists and queries subscriptions against the custom DB tables.
 */
final class Repository {

	/**
	 * Returns the fully prefixed name of the subscriptions table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activation::SUBSCRIPTIONS_TABLE;
	}

	/**
	 * Creates a pending subscription row.
	 *
	 * Returns 0 if the row already exists for this (target, email) combination.
	 * Caller is responsible for handling that case (e.g. by re-sending the
	 * confirm email for an existing pending row).
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier (or 0).
	 * @param string $target_meta Secondary target qualifier or empty string.
	 * @param string $email       Subscriber email (will be normalized).
	 *
	 * @return int Newly inserted ID, or 0 if a duplicate row prevented insertion.
	 */
	public static function create_pending(
		string $target_type,
		int $target_id,
		string $target_meta,
		string $email,
	): int {
		global $wpdb;

		$token = Token::generate();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'target_type' => $target_type,
				'target_id'   => $target_id,
				'target_meta' => $target_meta,
				'email'       => Token::normalize_email( $email ),
				'token'       => $token,
				'status'      => Subscription::STATUS_PENDING,
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s' ],
		);

		if ( $inserted === false || $inserted === 0 ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Marks a pending subscription as confirmed and returns it.
	 *
	 * @param string $token Token from the confirmation URL.
	 *
	 * @return Subscription|null Confirmed subscription, or null if no matching pending row was found.
	 */
	public static function confirm( string $token ): ?Subscription {
		global $wpdb;

		$subscription = self::find_by_token( $token );
		if ( $subscription === null || $subscription->status !== Subscription::STATUS_PENDING ) {
			return null;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'status'       => Subscription::STATUS_CONFIRMED,
				'confirmed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $subscription->id ],
			[ '%d', '%s' ],
			[ '%d' ],
		);

		return self::find_by_token( $token );
	}

	/**
	 * Marks a subscription as unsubscribed.
	 *
	 * @param string $token Token from the unsubscribe URL.
	 *
	 * @return bool Whether a row was updated.
	 */
	public static function unsubscribe( string $token ): bool {
		global $wpdb;

		$subscription = self::find_by_token( $token );
		if ( $subscription === null ) {
			return false;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[ 'status' => Subscription::STATUS_UNSUBSCRIBED ],
			[ 'id' => $subscription->id ],
			[ '%d' ],
			[ '%d' ],
		);

		return \is_int( $updated ) && $updated > 0;
	}

	/**
	 * Finds a subscription by its token.
	 *
	 * @param string $token Token to look up.
	 *
	 * @return Subscription|null
	 */
	public static function find_by_token( string $token ): ?Subscription {
		global $wpdb;

		if ( $token === '' ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token = %s LIMIT 1',
				self::table(),
				$token,
			),
			\ARRAY_A,
		);

		return \is_array( $row ) ? Subscription::from_row( $row ) : null;
	}

	/**
	 * Returns confirmed subscriptions for a given target.
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier.
	 * @param string $target_meta Secondary qualifier (defaults to '').
	 *
	 * @return array<int, Subscription>
	 */
	public static function find_confirmed_for_target(
		string $target_type,
		int $target_id,
		string $target_meta = '',
	): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d AND target_meta = %s AND status = %d',
				self::table(),
				$target_type,
				$target_id,
				$target_meta,
				Subscription::STATUS_CONFIRMED,
			),
			\ARRAY_A,
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ Subscription::class, 'from_row' ], $rows );
	}

	/**
	 * Counts confirmed subscriptions for a given target.
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier.
	 * @param string $target_meta Secondary qualifier (defaults to '').
	 *
	 * @return int
	 */
	public static function count_confirmed_for_target(
		string $target_type,
		int $target_id,
		string $target_meta = '',
	): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE target_type = %s AND target_id = %d AND target_meta = %s AND status = %d',
				self::table(),
				$target_type,
				$target_id,
				$target_meta,
				Subscription::STATUS_CONFIRMED,
			),
		);
	}

	/**
	 * Deletes pending (unconfirmed) subscriptions older than a given MySQL datetime.
	 *
	 * @param string $datetime Cutoff in MySQL DATETIME format (UTC).
	 *
	 * @return int Number of rows deleted.
	 */
	public static function purge_unconfirmed_older_than( string $datetime ): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %d AND created_at < %s',
				self::table(),
				Subscription::STATUS_PENDING,
				$datetime,
			),
		);

		return \is_int( $deleted ) ? $deleted : 0;
	}
}
