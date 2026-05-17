<?php

declare(strict_types=1);

namespace Apermo\Notify\Dispatch;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Activation;

/**
 * Tracks which subscribers have already received a given (post, event) pair.
 */
final class SentLog {

	/**
	 * Returns the fully prefixed sent-log table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activation::SENT_LOG_TABLE;
	}

	/**
	 * Reports whether a notification for this (subscription, post, event) tuple
	 * has already been recorded.
	 *
	 * @param int    $subscription_id Subscription primary key.
	 * @param int    $post_id         Post that triggered the notification.
	 * @param string $event           Event slug ('publish' or 'update').
	 *
	 * @return bool
	 */
	public static function has_sent( int $subscription_id, int $post_id, string $event ): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id FROM %i WHERE subscription_id = %d AND post_id = %d AND event = %s LIMIT 1',
				self::table(),
				$subscription_id,
				$post_id,
				$event,
			),
		);

		return $found !== null;
	}

	/**
	 * Records a successful notification dispatch.
	 *
	 * @param int    $subscription_id Subscription primary key.
	 * @param int    $post_id         Post that triggered the notification.
	 * @param string $event           Event slug ('publish' or 'update').
	 *
	 * @return void
	 */
	public static function record( int $subscription_id, int $post_id, string $event ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'subscription_id' => $subscription_id,
				'post_id'         => $post_id,
				'event'           => $event,
				'sent_at'         => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s' ],
		);
	}
}
