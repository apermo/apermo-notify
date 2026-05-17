<?php

declare(strict_types=1);

namespace Apermo\Notify\Subscription;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;

/**
 * Handles confirm and unsubscribe links emitted by the Mailer.
 */
final class OptInFlow {

	/**
	 * Registers the admin-post.php endpoints for confirm + unsubscribe links.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nopriv_' . Mailer::ACTION_CONFIRM, [ $this, 'handle_confirm' ] );
		add_action( 'admin_post_' . Mailer::ACTION_CONFIRM, [ $this, 'handle_confirm' ] );
		add_action( 'admin_post_nopriv_' . Mailer::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
		add_action( 'admin_post_' . Mailer::ACTION_UNSUBSCRIBE, [ $this, 'handle_unsubscribe' ] );
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

		wp_die(
			esc_html__( 'Your subscription is confirmed. Thank you!', 'apermo-notify' ),
			esc_html__( 'Subscription', 'apermo-notify' ),
			[
				'response'  => 200,
				'back_link' => true,
			],
		);
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

		Repository::unsubscribe( $token );

		wp_die(
			esc_html__( 'You have been unsubscribed.', 'apermo-notify' ),
			esc_html__( 'Unsubscribe', 'apermo-notify' ),
			[
				'response'  => 200,
				'back_link' => true,
			],
		);
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
