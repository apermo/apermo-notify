<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Frontend\FormHandler;
use Apermo\Notify\Mail\Mailer;

/**
 * Handles confirm and unsubscribe links emitted by the Mailer.
 */
final class OptInFlow {

	/**
	 * Redirects to the post the subscription points at, scrolling to the form
	 * and surfacing a flash message via the standard result query var.
	 *
	 * Falls back to wp_die() when the target post no longer has a usable
	 * permalink (e.g. it was deleted between the subscription and the click).
	 *
	 * @param Subscription $subscription Subscription whose post to redirect to.
	 * @param string       $result       Result code for the flash (`confirmed` or `unsubscribed`).
	 *
	 * @return void
	 */
	private static function redirect_to_target( Subscription $subscription, string $result ): void {
		$permalink = $subscription->target_type === 'post' && $subscription->target_id > 0
			? get_permalink( $subscription->target_id )
			: '';

		if ( ! \is_string( $permalink ) || $permalink === '' ) {
			wp_die(
				esc_html__( 'Done. You can close this tab.', 'apermo-notify' ),
				esc_html__( 'Subscription', 'apermo-notify' ),
				[
					'response'  => 200,
					'back_link' => true,
				],
			);
		}

		$args = [ FormHandler::RESULT_QUERY_VAR => $result ];
		// Forward a base64-encoded copy of the email so the post's subscribe
		// form can prefill the input — particularly useful right after an
		// unsubscribe so re-subscribing is one click + one consent tick.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe base64 wrapping of the subscriber email so the form prefill query var stays opaque-ish in browser history.
		$encoded_email = \rtrim( \strtr( \base64_encode( $subscription->email ), '+/', '-_' ), '=' );
		if ( $encoded_email !== '' ) {
			$args['apermo_notify_email'] = $encoded_email;
		}

		$url = add_query_arg( $args, $permalink ) . '#apermo-notify-form-' . $subscription->target_id;

		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Registers the admin-post.php endpoints for confirm + unsubscribe links.
	 *
	 * @return void
	 */
	public function register(): void {
		// admin-post.php endpoints (legacy URLs in already-sent emails).
		add_action( 'admin_post_nopriv_' . Mailer::ACTION_CONFIRM, [ $this, 'handle_confirm' ] );
		add_action( 'admin_post_' . Mailer::ACTION_CONFIRM, [ $this, 'handle_confirm' ] );
		add_action( 'admin_post_nopriv_' . Mailer::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
		add_action( 'admin_post_' . Mailer::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
		add_action( 'admin_post_nopriv_' . Mailer::ACTION_KEEP_ALIVE, [ $this, 'handle_keep_alive' ] );
		add_action( 'admin_post_' . Mailer::ACTION_KEEP_ALIVE, [ $this, 'handle_keep_alive' ] );

		// Post-permalink endpoint (new URLs emitted by `Mailer::post_action_url`).
		add_action( 'template_redirect', [ $this, 'maybe_handle_post_action' ] );
	}

	/**
	 * Intercepts front-end requests carrying `apermo_notify_action` and
	 * dispatches to the matching handler.
	 *
	 * @return void
	 */
	public function maybe_handle_post_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token in the query string is the credential.
		if ( ! isset( $_GET['apermo_notify_action'] ) || ! \is_string( $_GET['apermo_notify_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['apermo_notify_action'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $action ) {
			case Mailer::ACTION_CONFIRM:
				$this->handle_confirm();
				break;
			case Mailer::ACTION_UNSUBSCRIBE:
				$this->handle_unsubscribe();
				break;
			case Mailer::ACTION_KEEP_ALIVE:
				$this->handle_keep_alive();
				break;
		}
	}

	/**
	 * Handles the confirmation link click.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		$token = $this->token_from_request();

		if ( $token === '' ) {
			wp_die(
				esc_html__( 'Invalid confirmation link.', 'apermo-notify' ),
				esc_html__( 'Subscription', 'apermo-notify' ),
				[ 'response' => 400 ],
			);
		}

		$subscription = Repository::confirm( $token );

		if ( $subscription === null ) {
			wp_die(
				esc_html__(
					'This confirmation link is no longer valid. The subscription may already be confirmed or have expired.',
					'apermo-notify',
				),
				esc_html__( 'Subscription', 'apermo-notify' ),
				[ 'response' => 410 ],
			);
		}

		/**
		 * Fires after a subscription transitions from pending to confirmed.
		 *
		 * @param Subscription $subscription The freshly confirmed subscription.
		 */
		do_action( 'apermo_notify_subscription_confirmed', $subscription );

		self::redirect_to_target( $subscription, 'confirmed' );
	}

	/**
	 * Handles the unsubscribe link click.
	 *
	 * @return void
	 */
	public function handle_unsubscribe(): void {
		$token = $this->token_from_request();

		if ( $token === '' ) {
			wp_die(
				esc_html__( 'Invalid unsubscribe link.', 'apermo-notify' ),
				esc_html__( 'Unsubscribe', 'apermo-notify' ),
				[ 'response' => 400 ],
			);
		}

		$subscription = Repository::find_by_token( $token );

		Repository::unsubscribe( $token );

		if ( $subscription === null ) {
			wp_die(
				esc_html__( 'You have been unsubscribed.', 'apermo-notify' ),
				esc_html__( 'Unsubscribe', 'apermo-notify' ),
				[
					'response'  => 200,
					'back_link' => true,
				],
			);
		}

		self::redirect_to_target( $subscription, 'unsubscribed' );
	}

	/**
	 * Handles the keep-alive link click sent in the stale-warning email.
	 *
	 * Resets the subscription's keep-alive timestamp so the cron stops
	 * counting it as stale, then redirects to the target post with a
	 * `kept_alive` flash. Invalid or non-confirmed tokens land on a 410.
	 *
	 * @return void
	 */
	public function handle_keep_alive(): void {
		$token = $this->token_from_request();

		if ( $token === '' ) {
			wp_die(
				esc_html__( 'Invalid keep-alive link.', 'apermo-notify' ),
				esc_html__( 'Subscription', 'apermo-notify' ),
				[ 'response' => 400 ],
			);
		}

		$subscription = Repository::extend_keep_alive( $token );

		if ( $subscription === null ) {
			wp_die(
				esc_html__(
					'This keep-alive link is no longer valid. The subscription may have been removed.',
					'apermo-notify',
				),
				esc_html__( 'Subscription', 'apermo-notify' ),
				[ 'response' => 410 ],
			);
		}

		self::redirect_to_target( $subscription, 'kept_alive' );
	}

	/**
	 * Reads and normalizes the token from the current request.
	 *
	 * @return string Empty string when missing or malformed.
	 */
	private function token_from_request(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token itself is the credential.
		if ( ! isset( $_GET['token'] ) || ! \is_string( $_GET['token'] ) ) {
			return '';
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return \strlen( $raw ) === Token::LENGTH ? $raw : '';
	}
}
