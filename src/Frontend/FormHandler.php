<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Handles the admin-post.php POST submitted by the visitor-facing subscribe form.
 */
final class FormHandler {

	/**
	 * admin-post.php action name for the subscribe submission.
	 */
	public const ACTION = 'apermo_notify_subscribe';

	/**
	 * Nonce action used by the form.
	 */
	public const NONCE_ACTION = 'apermo_notify_subscribe_nonce';

	/**
	 * Soft per-IP throttle window in seconds.
	 */
	public const THROTTLE_SECONDS = 60;

	/**
	 * Query var key used to surface the result on the redirect target.
	 */
	public const RESULT_QUERY_VAR = 'apermo_notify_result';

	/**
	 * Reloads a subscription by ID via the token-based lookup.
	 *
	 * @param int $id Subscription primary key.
	 *
	 * @return Subscription|null
	 */
	private static function reload( int $id ): ?Subscription {
		global $wpdb;

		$token = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT token FROM %i WHERE id = %d',
				Repository::table(),
				$id,
			),
		);

		return \is_string( $token ) ? Repository::find_by_token( $token ) : null;
	}

	/**
	 * Registers the admin-post.php endpoints for the subscribe submission.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Processes the submitted form: validates input, throttles, creates a
	 * pending subscription, sends the confirm email, then redirects back to
	 * the originating post with a result flag.
	 *
	 * @return void
	 */
	public function handle(): void {
		$post_id = $this->require_post_id();
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			$this->redirect_with_result( $post_id, 'invalid' );
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$email = $this->require_email();
		if ( $email === '' ) {
			$this->redirect_with_result( $post_id, 'invalid_email' );
			return;
		}

		if ( $this->is_throttled() ) {
			$this->redirect_with_result( $post_id, 'throttled' );
			return;
		}
		$this->mark_throttled();

		$id = Repository::create_pending( 'post', $post_id, '', $email );
		if ( $id === 0 ) {
			$this->redirect_with_result( $post_id, 'duplicate' );
			return;
		}

		$subscription = self::reload( $id );
		if ( $subscription !== null ) {
			Mailer::send_confirm( $subscription, $post );
		}

		$this->redirect_with_result( $post_id, 'pending' );
	}

	/**
	 * Reads the post_id from the request, sanitized.
	 *
	 * @return int Zero when missing or malformed.
	 */
	private function require_post_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is checked after we know the post.
		if ( ! isset( $_POST['post_id'] ) || ! \is_scalar( $_POST['post_id'] ) ) {
			return 0;
		}

		return (int) sanitize_text_field( wp_unslash( (string) $_POST['post_id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Reads, sanitizes, and validates the email from the request.
	 *
	 * @return string Empty string when missing or invalid.
	 */
	private function require_email(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce was checked by handle() before this call.
		if ( ! isset( $_POST['email'] ) || ! \is_string( $_POST['email'] ) ) {
			return '';
		}

		$raw = sanitize_email( wp_unslash( $_POST['email'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_email( $raw ) ? $raw : '';
	}

	/**
	 * Reports whether the current IP has submitted within the throttle window.
	 *
	 * @return bool
	 */
	private function is_throttled(): bool {
		$key = $this->throttle_key();
		return $key !== '' && get_transient( $key ) !== false;
	}

	/**
	 * Stores the per-IP throttle flag.
	 *
	 * @return void
	 */
	private function mark_throttled(): void {
		$key = $this->throttle_key();
		if ( $key !== '' ) {
			set_transient( $key, 1, self::THROTTLE_SECONDS );
		}
	}

	/**
	 * Builds the per-IP throttle transient key.
	 *
	 * @return string Empty when no IP could be determined.
	 */
	private function throttle_key(): string {
		// phpcs:disable WordPress.VIP.SuperGlobalInputUsage.AccessDetected -- The remote address is the rate-limit subject.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && \is_string( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		// phpcs:enable WordPress.VIP.SuperGlobalInputUsage.AccessDetected

		return $ip === '' ? '' : 'apermo_notify_throttle_' . \md5( $ip );
	}

	/**
	 * Redirects back to the originating post with a result code on the query.
	 *
	 * @param int    $post_id Post the form was submitted from (0 for unknown).
	 * @param string $result  Result code (`pending`, `throttled`, `duplicate`, `invalid_email`, `invalid`).
	 *
	 * @return void
	 */
	private function redirect_with_result( int $post_id, string $result ): void {
		$target = $post_id > 0 ? (string) get_permalink( $post_id ) : home_url( '/' );
		if ( $target === '' ) {
			$target = home_url( '/' );
		}

		$url = add_query_arg( self::RESULT_QUERY_VAR, $result, $target );

		wp_safe_redirect( $url );
		exit();
	}
}
