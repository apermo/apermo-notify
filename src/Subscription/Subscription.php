<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

/**
 * Represents a single subscriber row from the subscriptions table.
 */
final class Subscription {

	/**
	 * Pending status: created but not yet confirmed via email link.
	 */
	public const STATUS_PENDING = 0;

	/**
	 * Confirmed status: subscriber clicked the confirm link.
	 */
	public const STATUS_CONFIRMED = 1;

	/**
	 * Unsubscribed status: subscriber clicked the unsubscribe link.
	 */
	public const STATUS_UNSUBSCRIBED = 2;

	// phpcs:disable Apermo.CodeQuality.ExcessiveParameterCount.TooMany -- Named-parameter constructor mirroring the DB schema.

	/**
	 * Constructs a Subscription value object.
	 *
	 * @param int|null    $id               Primary key, null for unsaved rows.
	 * @param string      $target_type      Target type slug (`post`, `author`, …).
	 * @param int         $target_id        Target identifier (post ID, user ID, term ID, or 0).
	 * @param string      $target_meta      Secondary target qualifier (post_type slug, …).
	 * @param string|null $filter_json      Optional filter payload, JSON-encoded.
	 * @param string      $email            Subscriber email, normalized lowercase.
	 * @param string      $token            Confirmation/unsubscribe token, 64 hex chars.
	 * @param int         $status           One of the STATUS_* constants.
	 * @param string      $created_at       Creation timestamp in MySQL DATETIME format.
	 * @param string|null $confirmed_at     Confirmation timestamp or null.
	 * @param string|null $last_notified_at Last successful notification timestamp or null.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $target_type,
		public readonly int $target_id,
		public readonly string $target_meta,
		public readonly ?string $filter_json,
		public readonly string $email,
		public readonly string $token,
		public readonly int $status,
		public readonly string $created_at,
		public readonly ?string $confirmed_at,
		public readonly ?string $last_notified_at,
	) {
	}

	// phpcs:enable Apermo.CodeQuality.ExcessiveParameterCount.TooMany

	/**
	 * Builds a Subscription from a raw DB row.
	 *
	 * @param array<string, string|int|null> $row Associative row from $wpdb.
	 *
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			target_type: (string) ( $row['target_type'] ?? '' ),
			target_id: (int) ( $row['target_id'] ?? 0 ),
			target_meta: (string) ( $row['target_meta'] ?? '' ),
			filter_json: isset( $row['filter_json'] ) ? (string) $row['filter_json'] : null,
			email: (string) ( $row['email'] ?? '' ),
			token: (string) ( $row['token'] ?? '' ),
			status: (int) ( $row['status'] ?? self::STATUS_PENDING ),
			created_at: (string) ( $row['created_at'] ?? '' ),
			confirmed_at: isset( $row['confirmed_at'] ) ? (string) $row['confirmed_at'] : null,
			last_notified_at: isset( $row['last_notified_at'] ) ? (string) $row['last_notified_at'] : null,
		);
	}
}
