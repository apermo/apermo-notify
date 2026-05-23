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
	 * @param bool   $has_consent Whether the visitor ticked the consent checkbox.
	 *
	 * @return int Subscription ID (new or reactivated), 0 when the email is already confirmed for this target.
	 */
	public static function create_pending(
		string $target_type,
		int $target_id,
		string $target_meta,
		string $email,
		bool $has_consent = false,
	): int {
		$email      = Token::normalize_email( $email );
		$token      = Token::generate();
		$timestamp  = current_time( 'mysql', true );
		$consent_at = $has_consent ? $timestamp : null;

		$new_id = self::insert_pending( $target_type, $target_id, $target_meta, $email, $token, $timestamp, $consent_at );
		if ( $new_id > 0 ) {
			return $new_id;
		}

		$existing = self::find_by_target_email( $target_type, $target_id, $target_meta, $email );
		if ( $existing === null || $existing->status === Subscription::STATUS_CONFIRMED ) {
			return $existing === null ? 0 : 0;
		}

		return self::reset_to_pending( (int) $existing->id, $token, $timestamp, $consent_at );
	}

	// phpcs:disable Apermo.CodeQuality.ExcessiveParameterCount.TooMany -- Inserts mirror DB columns; bundling into an array buys nothing.

	/**
	 * Inserts a brand new pending row.
	 *
	 * @param string      $target_type Target type slug.
	 * @param int         $target_id   Target identifier.
	 * @param string      $target_meta Secondary qualifier.
	 * @param string      $email       Normalized email.
	 * @param string      $token       Generated token.
	 * @param string      $timestamp   MySQL DATETIME (UTC).
	 * @param string|null $consent_at  Timestamp the consent box was ticked, or null when missing.
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
		?string $consent_at,
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
				'consent_at'  => $consent_at,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ],
		);

		return ( \is_int( $inserted ) && $inserted > 0 ) ? (int) $wpdb->insert_id : 0;
	}

	// phpcs:enable Apermo.CodeQuality.ExcessiveParameterCount.TooMany

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
	public static function find_by_target_email(
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
	 * @param int         $id         Existing subscription ID.
	 * @param string      $token      New token to write.
	 * @param string      $timestamp  New `created_at` value.
	 * @param string|null $consent_at Timestamp consent was ticked on the resubmit, or null when missing.
	 *
	 * @return int The same ID.
	 */
	private static function reset_to_pending( int $id, string $token, string $timestamp, ?string $consent_at ): int {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'status'              => Subscription::STATUS_PENDING,
				'token'               => $token,
				'created_at'          => $timestamp,
				'confirmed_at'        => null,
				'last_notified_at'    => null,
				'consent_at'          => $consent_at,
				'kept_alive_at'       => null,
				'stale_email_sent_at' => null,
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
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

		$now_utc = current_time( 'mysql', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'status'        => Subscription::STATUS_CONFIRMED,
				'confirmed_at'  => $now_utc,
				'kept_alive_at' => $now_utc,
			],
			[ 'id' => $subscription->id ],
			[ '%d', '%s', '%s' ],
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

	/**
	 * Returns every confirmed subscription for a given email address.
	 *
	 * @param string $email Subscriber email (will be normalized).
	 *
	 * @return array<int, Subscription>
	 */
	public static function find_confirmed_by_email( string $email ): array {
		global $wpdb;

		$email = Token::normalize_email( $email );
		if ( $email === '' ) {
			return [];
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s AND status = %d ORDER BY confirmed_at DESC',
				self::table(),
				$email,
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
	 * Returns confirmed rows whose kept_alive timestamp is older than the
	 * cutoff and that haven't yet received a stale-warning email.
	 *
	 * @param string $cutoff_datetime MySQL DATETIME (UTC). Rows with `kept_alive_at < cutoff` qualify.
	 *
	 * @return array<int, Subscription>
	 */
	public static function find_stale_for_warning( string $cutoff_datetime ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %d AND kept_alive_at IS NOT NULL AND kept_alive_at < %s AND stale_email_sent_at IS NULL',
				self::table(),
				Subscription::STATUS_CONFIRMED,
				$cutoff_datetime,
			),
			\ARRAY_A,
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ Subscription::class, 'from_row' ], $rows );
	}

	/**
	 * Returns IDs of rows that should now be deleted.
	 *
	 * In `delete` mode the caller passes `$grace_datetime = null` and we
	 * delete by `kept_alive_at < $stale_datetime`. In `keep_alive` mode the
	 * caller passes both; we delete rows whose stale warning was sent at
	 * least the grace window ago and that haven't been kept-alive since.
	 *
	 * @param string      $stale_datetime  Cutoff for kept_alive_at when in delete mode.
	 * @param string|null $grace_datetime  Cutoff for stale_email_sent_at in keep-alive mode; null for delete mode.
	 *
	 * @return array<int, int>
	 */
	public static function find_stale_for_purge( string $stale_datetime, ?string $grace_datetime ): array {
		global $wpdb;

		if ( $grace_datetime === null ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT id FROM %i WHERE status = %d AND kept_alive_at IS NOT NULL AND kept_alive_at < %s',
					self::table(),
					Subscription::STATUS_CONFIRMED,
					$stale_datetime,
				),
			);
		} else {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT id FROM %i WHERE status = %d AND stale_email_sent_at IS NOT NULL AND stale_email_sent_at < %s',
					self::table(),
					Subscription::STATUS_CONFIRMED,
					$grace_datetime,
				),
			);
		}

		return \is_array( $ids ) ? \array_map( 'intval', $ids ) : [];
	}

	/**
	 * Records that the stale-warning email has been sent for a row.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return void
	 */
	public static function mark_stale_warning_sent( int $id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[ 'stale_email_sent_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ],
		);
	}

	/**
	 * Extends the keep-alive window on the row matching a token.
	 *
	 * @param string $token Token from the keep-alive URL.
	 *
	 * @return Subscription|null Updated subscription, or null when the token is invalid.
	 */
	public static function extend_keep_alive( string $token ): ?Subscription {
		global $wpdb;

		$subscription = self::find_by_token( $token );
		if ( $subscription === null || $subscription->status !== Subscription::STATUS_CONFIRMED ) {
			return null;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'kept_alive_at'       => current_time( 'mysql', true ),
				'stale_email_sent_at' => null,
			],
			[ 'id' => $subscription->id ],
			[ '%s', '%s' ],
			[ '%d' ],
		);

		return self::find_by_token( $token );
	}

	/**
	 * Deletes a list of rows by primary key.
	 *
	 * @param array<int, int> $ids Subscription IDs to remove.
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_many( array $ids ): int {
		if ( $ids === [] ) {
			return 0;
		}

		global $wpdb;

		$ids_csv = \implode( ',', \array_map( 'intval', $ids ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IDs are intval'd inline; nothing else is interpolated.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id IN (' . $ids_csv . ')',
				self::table(),
			),
		);
		// phpcs:enable

		return \is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Unsubscribes selected rows but only when each row belongs to the given
	 * email. Lets a token holder manage only their own subscriptions even if
	 * they supply IDs from someone else's set.
	 *
	 * @param array<int, int> $ids   Candidate subscription IDs.
	 * @param string          $email Owner email (will be normalized).
	 *
	 * @return int Rows updated.
	 */
	public static function unsubscribe_many( array $ids, string $email ): int {
		if ( $ids === [] ) {
			return 0;
		}

		$email = Token::normalize_email( $email );
		if ( $email === '' ) {
			return 0;
		}

		global $wpdb;

		$ids_csv = \implode( ',', \array_map( 'intval', $ids ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IDs are intval'd inline; email + statuses use placeholders.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %d WHERE email = %s AND status = %d AND id IN (' . $ids_csv . ')',
				self::table(),
				Subscription::STATUS_UNSUBSCRIBED,
				$email,
				Subscription::STATUS_CONFIRMED,
			),
		);
		// phpcs:enable

		return \is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Returns a paginated, filterable slice of subscriptions for the admin
	 * list table.
	 *
	 * @param int      $per_page Page size (clamped to ≥ 1 by the caller).
	 * @param int      $offset   Row offset.
	 * @param int|null $status   Status to filter by, or null for all.
	 * @param string   $search   LIKE-search applied to the email column; empty for none.
	 * @param string   $orderby  Column name; validated against an allow-list.
	 * @param string   $order    `ASC` or `DESC` (case-insensitive).
	 *
	 * @return array<int, Subscription>
	 */
	public static function paginate(
		int $per_page,
		int $offset,
		?int $status,
		string $search,
		string $orderby,
		string $order
	): array {
		global $wpdb;

		$orderby              = self::sanitize_orderby( $orderby );
		$order                = \strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		[ $where_sql, $args ] = self::filter_clause( $status, $search );

		// `%i` placeholders escape both table and orderby identifiers; the
		// `$order` token is constrained to ASC|DESC above and the
		// `$where_sql` fragment is built from fixed-string clauses that bind
		// every value through `%d` / `%s` so direct interpolation is safe.
		$sql = 'SELECT * FROM %i ' . $where_sql
			. ' ORDER BY %i ' . $order
			. ' LIMIT %d OFFSET %d';

		$prepared_args = \array_merge(
			[ self::table() ],
			$args,
			[ $orderby, \max( 1, $per_page ), \max( 0, $offset ) ],
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( $sql, $prepared_args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			\ARRAY_A,
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ Subscription::class, 'from_row' ], $rows );
	}

	/**
	 * Counts rows matching the same filter the list table is paginating.
	 *
	 * @param int|null $status Status to filter by, or null for all.
	 * @param string   $search LIKE-search applied to the email column; empty for none.
	 *
	 * @return int
	 */
	public static function count_total( ?int $status, string $search ): int {
		global $wpdb;

		[ $where_sql, $args ] = self::filter_clause( $status, $search );

		$prepared_args = \array_merge( [ self::table() ], $args );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is built from fixed string fragments; every value binds through %d/%s.
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i ' . $where_sql, $prepared_args ),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Builds the WHERE clause + bound args shared by `paginate` and `count_total`.
	 *
	 * @param int|null $status Status filter, or null for any.
	 * @param string   $search Email search (LIKE-wrapped here).
	 *
	 * @return array{0:string,1:array<int, scalar>} Tuple of `[ $sql, $args ]`.
	 */
	private static function filter_clause( ?int $status, string $search ): array {
		$where = [];
		$args  = [];

		if ( $status !== null ) {
			$where[] = 'status = %d';
			$args[]  = $status;
		}

		$trimmed = \trim( $search );
		if ( $trimmed !== '' ) {
			global $wpdb;
			$where[] = 'email LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $trimmed ) . '%';
		}

		$sql = $where === [] ? '' : 'WHERE ' . \implode( ' AND ', $where );

		return [ $sql, $args ];
	}

	/**
	 * Returns one of the allow-listed orderby columns; defaults to created_at.
	 *
	 * @param string $orderby Requested column.
	 *
	 * @return string
	 */
	private static function sanitize_orderby( string $orderby ): string {
		$allowed = [ 'email', 'status', 'created_at', 'confirmed_at', 'id' ];

		return \in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
	}
}
