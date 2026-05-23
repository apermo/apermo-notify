<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;

/**
 * Renders the per-email "manage all my subscriptions" page and handles the
 * bulk-unsubscribe POST submitted from it.
 *
 * The page is reached via a token-bearing URL emailed to every confirmed
 * subscriber. The token identifies an email address; from it we list every
 * confirmed subscription that address owns and offer per-row checkboxes.
 */
final class ManagePage {

	/**
	 * Query var used to invoke the manage page from the front-of-site router.
	 */
	public const ACTION = 'apermo_notify_manage';

	/**
	 * admin-post.php action name for the bulk-unsubscribe submission.
	 */
	public const POST_ACTION = 'apermo_notify_manage_action';

	/**
	 * Nonce action used by the bulk-unsubscribe form.
	 */
	public const NONCE_ACTION = 'apermo_notify_manage_nonce';

	/**
	 * Registers the render hook and the POST handler endpoints.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_action( 'admin_post_nopriv_' . self::POST_ACTION, [ $this, 'handle_post' ] );
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'handle_post' ] );
	}

	/**
	 * Intercepts front-end requests carrying the manage action query var and
	 * renders the page in place of the normal template.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-gated, read-only render.
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== self::ACTION ) {
			return;
		}
		$token = isset( $_GET['token'] ) && \is_string( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';
		$flash = isset( $_GET['apermo_notify_result'] ) && \is_string( $_GET['apermo_notify_result'] )
			? sanitize_key( wp_unslash( $_GET['apermo_notify_result'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$owner = $token !== '' ? Repository::find_by_token( $token ) : null;
		if ( $owner === null ) {
			$this->render_error_page();
			exit();
		}

		$rows = Repository::find_confirmed_by_email( $owner->email );
		$this->render_page( $token, $owner->email, $rows, $flash );
		exit();
	}

	/**
	 * Processes the bulk-unsubscribe POST and redirects back to the GET view.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below via check_admin_referer.
		$token = isset( $_POST['token'] ) && \is_string( $_POST['token'] )
			? sanitize_text_field( wp_unslash( $_POST['token'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_ACTION );

		$owner = $token !== '' ? Repository::find_by_token( $token ) : null;
		if ( $owner === null ) {
			wp_safe_redirect( home_url( '/' ) );
			exit();
		}

		$ids = $this->selected_ids();
		if ( $ids !== [] ) {
			Repository::unsubscribe_many( $ids, $owner->email );
		}

		$url = add_query_arg(
			[
				'action'               => self::ACTION,
				'token'                => $token,
				'apermo_notify_result' => 'managed',
			],
			home_url( '/' ),
		);

		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Reads the checkbox array from the submission as a list of positive ints.
	 *
	 * @return array<int, int>
	 */
	private function selected_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified by handle_post() before this call; each value is cast to int below.
		$raw = isset( $_POST['ids'] ) && \is_array( $_POST['ids'] ) ? $_POST['ids'] : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$ids = [];
		foreach ( $raw as $value ) {
			if ( \is_scalar( $value ) ) {
				$id = (int) $value;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return \array_values( \array_unique( $ids ) );
	}

	/**
	 * Renders the manage page HTML wrapped in `get_header()` / `get_footer()`.
	 *
	 * @param string                   $token Token from the request.
	 * @param string                   $email Owner email used in the heading.
	 * @param array<int, Subscription> $rows  Confirmed subscriptions to list.
	 * @param string                   $flash Optional result code (e.g. `managed`).
	 *
	 * @return void
	 */
	private function render_page( string $token, string $email, array $rows, string $flash ): void {
		status_header( 200 );
		nocache_headers();
		get_header();

		echo '<main class="apermo-notify-manage">';
		echo '<h1>' . esc_html__( 'Manage your subscriptions', 'apermo-notify' ) . '</h1>';
		echo '<p class="apermo-notify-form__intro">'
			. \sprintf(
				/* translators: %s: email address */
				esc_html__( 'Showing every confirmed subscription for %s.', 'apermo-notify' ),
				'<code>' . esc_html( $email ) . '</code>',
			)
			. '</p>';

		if ( $flash === 'managed' ) {
			echo '<p class="apermo-notify-message apermo-notify-message--managed" role="status">'
				. esc_html__( 'Your subscriptions have been updated.', 'apermo-notify' )
				. '</p>';
		}

		if ( $rows === [] ) {
			echo '<p class="apermo-notify-message apermo-notify-message--info" role="status">'
				. esc_html__( 'You have no active subscriptions on this site.', 'apermo-notify' )
				. '</p>';
		} else {
			$this->render_form( $token, $rows );
		}

		echo '</main>';
		get_footer();
	}

	/**
	 * Renders the bulk-unsubscribe form.
	 *
	 * @param string                   $token Token used to scope the request.
	 * @param array<int, Subscription> $rows  Confirmed subscriptions to list.
	 *
	 * @return void
	 */
	private function render_form( string $token, array $rows ): void {
		echo '<form class="apermo-notify-form apermo-notify-manage__form" method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '" />';
		echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<ul class="apermo-notify-manage__list">';
		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}
		echo '</ul>';

		echo '<p class="apermo-notify-form__actions form-submit">'
			. '<button class="apermo-notify-form__submit submit wp-block-button__link" type="submit">'
			. esc_html__( 'Unsubscribe from selected', 'apermo-notify' )
			. '</button></p>';
		echo '</form>';
	}

	/**
	 * Prints a single subscription row.
	 *
	 * @param Subscription $row Confirmed subscription.
	 *
	 * @return void
	 */
	private function render_row( Subscription $row ): void {
		$title = '';
		if ( $row->target_type === 'post' && $row->target_id > 0 ) {
			$title = (string) get_the_title( $row->target_id );
		}
		if ( $title === '' ) {
			/* translators: %d: subscription primary key */
			$title = \sprintf( __( 'Subscription #%d', 'apermo-notify' ), $row->id );
		}

		$input_id = 'apermo-notify-manage-' . $row->id;

		echo '<li class="apermo-notify-manage__item">';
		echo '<label class="apermo-notify-form__label" for="' . esc_attr( $input_id ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $input_id ) . '" name="ids[]" value="'
			. esc_attr( (string) $row->id ) . '" /> ';
		echo esc_html( $title );
		echo '</label>';
		echo '</li>';
	}

	/**
	 * Renders the page shown when the manage link contains a bad/expired token.
	 *
	 * @return void
	 */
	private function render_error_page(): void {
		status_header( 404 );
		nocache_headers();
		get_header();
		echo '<main class="apermo-notify-manage apermo-notify-manage--error">';
		echo '<h1>' . esc_html__( 'Link expired', 'apermo-notify' ) . '</h1>';
		echo '<p>' . esc_html__( 'This manage link is no longer valid. Please use the latest email you received from us.', 'apermo-notify' ) . '</p>';
		echo '</main>';
		get_footer();
	}
}
