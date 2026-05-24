<?php

declare(strict_types=1);

namespace Apermo\Notify\Cron;

\defined( 'ABSPATH' ) || exit();

/**
 * Owns the WP-Cron event registration for the daily prune workflow.
 *
 * The schedule itself is the WordPress built-in `daily` recurrence; this
 * class only ensures the event exists on activation (and on every `init`
 * as a self-healing measure) and removes it on deactivation/uninstall.
 */
final class Scheduler {

	/**
	 * Hook name fired by WP-Cron once per day.
	 */
	public const HOOK = 'apermo_notify_prune';

	/**
	 * Ensures the prune event is on the WP-Cron queue.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) === false ) {
			wp_schedule_event( \time() + \HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes every scheduled instance of the prune event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Registers the self-healing `init` hook that re-schedules the event
	 * if it's missing (e.g. someone called `wp cron event delete`).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ self::class, 'schedule' ] );
	}
}
