<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

\defined( 'ABSPATH' ) || exit();

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
	 * Creates (or reactivates) a pending subscription row.
	 *
	 * Behaviour by existing-row status:
	 *
	 * - No existing row → fresh insert, returns the new ID.
	 * - Existing PENDING → reissues a new token and timestamp, returns the
	 *   existing ID (so the caller resends the confirmation email and the
	 *   stale token stops working).
	 * - Existing UNSUBSCRIBED → reactivates the row back to PENDING with a
	 *   new token, returns the existing ID. Lets a former subscriber sign
	 *   up again without manual cleanup.
	 * - Existing CONFIRMED → returns 0; subscriber is already active and
	 *   the caller should report this as a duplicate.
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier (or 0).
	 * @param string $target_meta Secondary target qualifier or empty string.
	 * @param string $email       Subscriber email (will be normalized).
	 *
	 * @return int Subscription ID (new or reactivated), 0 when the email is already confirmed for this target.
	 */
	public static function create_pending(
		string $target_type,
		int $target_id,
		string $target_meta,
		string $email,
	): int {
		$email     = Token::normalize_email( $email );
		$token     = Token::generate();
		$timestamp = current_time( 'mysql', true );

		$new_id = self::insert_pending( $target_type, $target_id, $target_meta, $email, $token, $timestamp );
		if ( $new_id > 0 ) {
			return $new_id;
		}

		$existing = self::find_by_target_email( $target_type, $target_id, $target_meta, $email );
		if ( $existing === null || $existing->status === Subscription::STATUS_CONFIRMED ) {
			return $existing === null ? 0 : 0;
		}

		return self::reset_to_pending( (int) $existing->id, $token, $timestamp );
	}

	/**
	 * Inserts a brand new pending row.
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier.
	 * @param string $target_meta Secondary qualifier.
	 * @param string $email       Normalized email.
	 * @param string $token       Generated token.
	 * @param string $timestamp   MySQL DATETIME (UTC).
	 *
	 * @return int New ID, or 0 if the insert failed (typically a unique-key collision).
	 */
	private static function insert_pending(
		string $target_type,
		int $target_id,
		string $target_meta,
		string $email,
		string $token,
		string $timestamp,
	): int {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'target_type' => $target_type,
				'target_id'   => $target_id,
				'target_meta' => $target_meta,
				'email'       => $email,
				'token'       => $token,
				'status'      => Subscription::STATUS_PENDING,
				'created_at'  => $timestamp,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s' ],
		);

		return ( \is_int( $inserted ) && $inserted > 0 ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finds a single subscription matching the target/email tuple, regardless of status.
	 *
	 * @param string $target_type Target type slug.
	 * @param int    $target_id   Target identifier.
	 * @param string $target_meta Secondary qualifier.
	 * @param string $email       Normalized email.
	 *
	 * @return Subscription|null
	 */
	private static function find_by_target_email(
		string $target_type,
		int $target_id,
		string $target_meta,
		string $email,
	): ?Subscription {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d AND target_meta = %s AND email = %s LIMIT 1',
				self::table(),
				$target_type,
				$target_id,
				$target_meta,
				$email,
			),
			\ARRAY_A,
		);

		return \is_array( $row ) ? Subscription::from_row( $row ) : null;
	}

	/**
	 * Resets an existing row back to PENDING with a fresh token and timestamp.
	 *
	 * @param int    $id        Existing subscription ID.
	 * @param string $token     New token to write.
	 * @param string $timestamp New `created_at` value.
	 *
	 * @return int The same ID.
	 */
	private static function reset_to_pending( int $id, string $token, string $timestamp ): int {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'status'           => Subscription::STATUS_PENDING,
				'token'            => $token,
				'created_at'       => $timestamp,
				'confirmed_at'     => null,
				'last_notified_at' => null,
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ],
		);

		return $id;
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
