<?php

declare(strict_types=1);

namespace Apermo\Notify\Dispatch;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Iterates resolved subscriptions, dedups them via SentLog, applies hooks, and sends.
 */
final class Dispatcher {

	/**
	 * Dispatches notifications for the given post/event combination.
	 *
	 * @param WP_Post $post  Post that triggered dispatch.
	 * @param string  $event Event slug ('publish' or 'update').
	 *
	 * @return int Number of notifications actually sent.
	 */
	public static function dispatch( WP_Post $post, string $event ): int {
		$sent = 0;

		foreach ( Resolver::for_post( $post ) as $subscription ) {
			if ( ! self::should_send( $subscription, $post, $event ) ) {
				continue;
			}

			if ( SentLog::has_sent( (int) $subscription->id, $post->ID, $event ) ) {
				continue;
			}

			$delivered = Mailer::send_update( $subscription, $post, $event );

			if ( $delivered ) {
				SentLog::record( (int) $subscription->id, $post->ID, $event );
				$sent++;

				/**
				 * Fires after a notification email has been sent to a subscriber.
				 *
				 * @param Subscription $subscription Subscriber that was notified.
				 * @param WP_Post      $post         Related post.
				 * @param string       $event        Event slug.
				 */
				do_action( 'apermo_notify_email_sent', $subscription, $post, $event );
			}
		}

		return $sent;
	}

	/**
	 * Lets extenders veto a dispatch via the `apermo_notify_should_send` filter.
	 *
	 * @param Subscription $subscription Subscriber under consideration.
	 * @param WP_Post      $post         Related post.
	 * @param string       $event        Event slug.
	 *
	 * @return bool
	 */
	private static function should_send( Subscription $subscription, WP_Post $post, string $event ): bool {
		/**
		 * Filters whether a notification should be sent.
		 *
		 * @param bool         $should_send Default true.
		 * @param Subscription $subscription Subscriber under consideration.
		 * @param WP_Post      $post         Related post.
		 * @param string       $event        Event slug.
		 *
		 * @return bool
		 */
		$decision = apply_filters( 'apermo_notify_should_send', true, $subscription, $post, $event );

		return (bool) $decision;
	}
}
