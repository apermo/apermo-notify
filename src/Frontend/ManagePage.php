<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;

/**
 * Renders the per-email manage UI inside the page the admin configured as
 * the Subscription Management page.
 *
 * The admin picks a real published page in the plugin settings; on that
 * page only, this class hooks `the_content` and replaces the rendered
 * body with the manage UI whenever a valid `token` query var is present.
 * Header, footer, sidebars — anything the active theme renders around
 * the page content — keeps working.
 *
 * The bulk-unsubscribe form posts to admin-post.php and lands back on
 * the same page with `apermo_notify_result=managed`.
 */
final class ManagePage {

	/**
	 * admin-post.php action name for the bulk-unsubscribe submission.
	 */
	public const POST_ACTION = 'apermo_notify_manage_action';

	/**
	 * Nonce action used by the bulk-unsubscribe form.
	 */
	public const NONCE_ACTION = 'apermo_notify_manage_nonce';

	/**
	 * Wires the content filter and the POST handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ $this, 'filter_content' ], 20 );
		add_action( 'admin_post_nopriv_' . self::POST_ACTION, [ $this, 'handle_post' ] );
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'handle_post' ] );
	}

	/**
	 * Replaces the rendered page content with the manage UI on the
	 * configured page when a `token` query var is present.
	 *
	 * @param string $content Original content.
	 *
	 * @return string
	 */
	public function filter_content( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$page_id    = Settings::manage_page_id();
		$current_id = isset( $GLOBALS['post'] ) && \is_object( $GLOBALS['post'] ) && isset( $GLOBALS['post']->ID )
			? (int) $GLOBALS['post']->ID
			: 0;
		if ( $page_id <= 0 || $current_id !== $page_id ) {
			return $content;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token in the query string is the credential.
		$token = isset( $_GET['token'] ) && \is_string( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';
		$flash = isset( $_GET['apermo_notify_result'] ) && \is_string( $_GET['apermo_notify_result'] )
			? sanitize_key( wp_unslash( $_GET['apermo_notify_result'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $token === '' ) {
			return $content;
		}

		$owner = Repository::find_by_token( $token );
		if ( $owner === null ) {
			return $content . $this->render_invalid_token_block();
		}

		$rows = Repository::find_confirmed_by_email( $owner->email );

		return $content . $this->render_manage_block( $token, $owner->email, $rows, $flash );
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

		wp_safe_redirect( $this->target_url( $token, 'managed' ) );
		exit();
	}

	/**
	 * Reads the checkbox array from the submission as a list of positive ints.
	 *
	 * @return array<int, int>
	 */
	private function selected_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce checked in handle_post(); each value is intval'd below.
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
	 * Builds the redirect URL back to the manage page after a POST handler.
	 *
	 * @param string $token  Token of the requesting subscriber.
	 * @param string $result Result code to flash.
	 *
	 * @return string
	 */
	private function target_url( string $token, string $result ): string {
		$page_id   = Settings::manage_page_id();
		$permalink = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
		if ( $permalink === '' ) {
			$permalink = home_url( '/' );
		}

		return add_query_arg(
			[
				'token'                => $token,
				'apermo_notify_result' => $result,
			],
			$permalink,
		);
	}

	/**
	 * Builds the manage HTML block injected into the page content.
	 *
	 * @param string                   $token Token from the request.
	 * @param string                   $email Owner email used in the heading.
	 * @param array<int, Subscription> $rows  Confirmed subscriptions to list.
	 * @param string                   $flash Optional result code (e.g. `managed`).
	 *
	 * @return string
	 */
	private function render_manage_block( string $token, string $email, array $rows, string $flash ): string {
		\ob_start();

		echo '<section class="apermo-notify-manage">';
		echo '<p class="apermo-notify-form__intro">';
		\printf(
			/* translators: %s: email address */
			esc_html__( 'Showing every confirmed subscription for %s.', 'apermo-notify' ),
			'<code>' . esc_html( $email ) . '</code>',
		);
		echo '</p>';

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

		echo '</section>';

		return (string) \ob_get_clean();
	}

	/**
	 * Returns the HTML appended when the token is invalid / expired.
	 *
	 * @return string
	 */
	private function render_invalid_token_block(): string {
		return '<section class="apermo-notify-manage apermo-notify-manage--error">'
			. '<p class="apermo-notify-message apermo-notify-message--error" role="status">'
			. esc_html__(
				'This manage link is no longer valid. Please use the latest email you received from us.',
				'apermo-notify',
			)
			. '</p></section>';
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
}
