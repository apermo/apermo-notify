<?php

declare(strict_types=1);

namespace Apermo\Notify\Cron;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Settings;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Runs the daily housekeeping pass over the subscriptions table.
 *
 * Reads `Settings::prune_mode()` to decide whether to hard-delete stale
 * rows immediately or send a keep-alive warning first and only delete
 * after the configured grace window.
 */
final class Pruner {

	/**
	 * Executes one prune pass according to the configured mode.
	 *
	 * @return void
	 */
	public static function run(): void {
		$months = Settings::stale_after_months();
		if ( $months <= 0 ) {
			return;
		}

		$stale_cutoff = self::offset_now( '-' . $months . ' months' );
		if ( $stale_cutoff === '' ) {
			return;
		}

		if ( Settings::prune_mode() === Settings::PRUNE_MODE_DELETE ) {
			self::run_delete_mode( $stale_cutoff );
			return;
		}

		self::run_keep_alive_mode( $stale_cutoff );
	}

	/**
	 * Runs the hard-delete pass.
	 *
	 * @param string $stale_cutoff Cutoff UTC datetime; rows older than this go away.
	 *
	 * @return void
	 */
	private static function run_delete_mode( string $stale_cutoff ): void {
		$ids = Repository::find_stale_for_purge( $stale_cutoff, null );
		if ( $ids !== [] ) {
			Repository::delete_many( $ids );
		}
	}

	/**
	 * Runs the warn-then-delete pass.
	 *
	 * @param string $stale_cutoff Cutoff UTC datetime; rows older than this are
	 *                             considered stale.
	 *
	 * @return void
	 */
	private static function run_keep_alive_mode( string $stale_cutoff ): void {
		$grace_days = Settings::stale_grace_days();

		$to_warn = Repository::find_stale_for_warning( $stale_cutoff );
		foreach ( $to_warn as $subscription ) {
			self::send_warning( $subscription, $grace_days );
		}

		$grace_cutoff = self::offset_now( '-' . $grace_days . ' days' );
		if ( $grace_cutoff === '' ) {
			return;
		}

		$ids = Repository::find_stale_for_purge( $stale_cutoff, $grace_cutoff );
		if ( $ids !== [] ) {
			Repository::delete_many( $ids );
		}
	}

	/**
	 * Sends one keep-alive warning and records that it went out.
	 *
	 * @param Subscription $subscription Stale subscription about to be warned.
	 * @param int          $grace_days   Days until the row is removed if ignored.
	 *
	 * @return void
	 */
	private static function send_warning( Subscription $subscription, int $grace_days ): void {
		$post = null;
		if ( $subscription->target_type === 'post' && $subscription->target_id > 0 ) {
			$candidate = get_post( $subscription->target_id );
			if ( $candidate instanceof WP_Post ) {
				$post = $candidate;
			}
		}

		Mailer::send_stale_warning( $subscription, $post, $grace_days );
		Repository::mark_stale_warning_sent( $subscription->id );
	}

	/**
	 * Returns `current_time('mysql', true)` offset by the given relative
	 * string, or an empty string when the offset is invalid.
	 *
	 * @param string $modifier `strtotime`-style modifier (e.g. `-7 days`).
	 *
	 * @return string MySQL DATETIME in UTC, or empty on failure.
	 */
	private static function offset_now( string $modifier ): string {
		$timestamp = \strtotime( $modifier, \time() );
		if ( $timestamp === false ) {
			return '';
		}

		return \gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Registers the WP-Cron hook callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::HOOK, [ self::class, 'run' ] );
	}
}
