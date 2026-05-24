<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Settings;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Renders the per-email subscription-management UI.
 *
 * Primary delivery is the `apermo-notify/manage-subscriptions` block: an
 * admin drops the block on the page configured under Apermo Notify →
 * Settings. The block calls `ManagePage::render_block_html()` to produce
 * the HTML, which has three states:
 *
 *  - `?token=…` valid → list every confirmed subscription for that token's
 *    email, with bulk unsubscribe.
 *  - `?token=…` invalid/expired → "link no longer valid" message.
 *  - no token → "request a manage link" form (email field) so visitors
 *    arriving with no link can ask us to email them one.
 *
 * Fallback: when the configured page exists but doesn't contain the
 * block, `the_content` is filtered to append the same HTML so the URLs
 * baked into already-sent emails keep working even if the admin
 * forgets to add the block.
 *
 * Two `admin-post.php` POST endpoints back the forms:
 *  - bulk unsubscribe from a manage view (uses the token).
 *  - request a manage link (uses an email; throttled per IP).
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
	 * admin-post.php action name for the "request a manage link" submission.
	 */
	public const REQUEST_LINK_ACTION = 'apermo_notify_request_manage_link';

	/**
	 * Nonce action used by the "request a manage link" form.
	 */
	public const REQUEST_LINK_NONCE = 'apermo_notify_request_manage_link_nonce';

	/**
	 * Per-IP throttle window (seconds) on the "request a link" form. Mirrors
	 * the subscribe form's throttle so this can't be looped as a mail bomb.
	 */
	public const REQUEST_LINK_THROTTLE_SECONDS = 60;

	/**
	 * Renders the manage UI for the current request — block entry point.
	 *
	 * Lives here (not in the block class) so unit tests can exercise the
	 * markup without booting the block API. Reads the request-scoped
	 * `token` and `apermo_notify_result` query vars directly.
	 *
	 * @return string Rendered HTML, never empty.
	 */
	public static function render_block_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token in the query string is the credential; flash is read-only.
		$token = isset( $_GET['token'] ) && \is_string( $_GET['token'] )
			? sanitize_text_field( wp_unslash( $_GET['token'] ) )
			: '';
		$flash = isset( $_GET['apermo_notify_result'] ) && \is_string( $_GET['apermo_notify_result'] )
			? sanitize_key( wp_unslash( $_GET['apermo_notify_result'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $token === '' ) {
			return self::render_request_link_block( $flash );
		}

		$owner = Repository::find_by_token( $token );
		if ( ! $owner instanceof Subscription ) {
			return self::render_invalid_token_block();
		}

		$rows = Repository::find_confirmed_by_email( $owner->email );

		return self::render_manage_block( $token, $owner->email, $rows, $flash );
	}

	/**
	 * Builds the manage HTML block returned to a valid-token visitor.
	 *
	 * @param string                   $token Token from the request.
	 * @param string                   $email Owner email used in the heading.
	 * @param array<int, Subscription> $rows  Confirmed subscriptions to list.
	 * @param string                   $flash Optional result code (e.g. `managed`).
	 *
	 * @return string
	 */
	private static function render_manage_block( string $token, string $email, array $rows, string $flash ): string {
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
			self::render_unsubscribe_form( $token, $rows );
		}

		echo '</section>';

		return (string) \ob_get_clean();
	}

	/**
	 * Returns the HTML for the invalid / expired token state.
	 *
	 * @return string
	 */
	private static function render_invalid_token_block(): string {
		return '<section class="apermo-notify-manage apermo-notify-manage--error">'
			. '<p class="apermo-notify-message apermo-notify-message--error" role="status">'
			. esc_html__(
				'This manage link is no longer valid. Please request a fresh one with your email below.',
				'apermo-notify',
			)
			. '</p>'
			. self::request_link_form_html( '' )
			. '</section>';
	}

	/**
	 * Returns the HTML for the no-token state — invites the visitor to
	 * request a manage link by email.
	 *
	 * @param string $flash Optional result code (e.g. `manage_link_sent`).
	 *
	 * @return string
	 */
	private static function render_request_link_block( string $flash ): string {
		return '<section class="apermo-notify-manage apermo-notify-manage--request">'
			. self::request_link_form_html( $flash )
			. '</section>';
	}

	/**
	 * Builds the "request a manage link" form HTML.
	 *
	 * @param string $flash Optional result code (e.g. `manage_link_sent`).
	 *
	 * @return string
	 */
	private static function request_link_form_html( string $flash ): string {
		\ob_start();

		echo '<p class="apermo-notify-form__intro">'
			. esc_html__(
				'Enter the email address you used to subscribe and we will send you a link to manage every subscription tied to it.',
				'apermo-notify',
			)
			. '</p>';

		if ( $flash === 'manage_link_sent' ) {
			echo '<p class="apermo-notify-message apermo-notify-message--info" role="status">'
				. esc_html__(
					'If that email matches any subscriptions on this site, a manage link is on its way.',
					'apermo-notify',
				)
				. '</p>';
		}

		echo '<form class="apermo-notify-form apermo-notify-manage__request-form" method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REQUEST_LINK_ACTION ) . '" />';
		wp_nonce_field( self::REQUEST_LINK_NONCE );

		echo '<p class="apermo-notify-form__field comment-form-email">';
		echo '<label class="apermo-notify-form__label" for="apermo-notify-request-link-email">'
			. esc_html__( 'Email address', 'apermo-notify' )
			. '</label>';
		echo '<input class="apermo-notify-form__input" type="email" id="apermo-notify-request-link-email"'
			. ' name="email" required autocomplete="email" />';
		echo '</p>';

		echo '<p class="apermo-notify-form__actions form-submit">'
			. '<button class="apermo-notify-form__submit submit wp-block-button__link" type="submit">'
			. esc_html__( 'Email me the manage link', 'apermo-notify' )
			. '</button></p>';
		echo '</form>';

		return (string) \ob_get_clean();
	}

	/**
	 * Renders the bulk-unsubscribe form.
	 *
	 * @param string                   $token Token used to scope the request.
	 * @param array<int, Subscription> $rows  Confirmed subscriptions to list.
	 *
	 * @return void
	 */
	private static function render_unsubscribe_form( string $token, array $rows ): void {
		echo '<form class="apermo-notify-form apermo-notify-manage__form" method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '" />';
		echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<ul class="apermo-notify-manage__list">';
		foreach ( $rows as $row ) {
			self::render_row( $row );
		}
		echo '</ul>';

		echo '<p class="apermo-notify-form__actions form-submit">'
			. '<button class="apermo-notify-form__submit submit wp-block-button__link" type="submit">'
			. esc_html__( 'Unsubscribe from selected', 'apermo-notify' )
			. '</button></p>';
		echo '</form>';
	}

	/**
	 * Prints a single subscription row with the date the subscriber
	 * confirmed plus the related post's publish and last-modified dates.
	 *
	 * @param Subscription $row Confirmed subscription.
	 *
	 * @return void
	 */
	private static function render_row( Subscription $row ): void {
		$post = null;
		if ( $row->target_type === 'post' && $row->target_id > 0 ) {
			$candidate = get_post( $row->target_id );
			if ( $candidate instanceof WP_Post ) {
				$post = $candidate;
			}
		}

		$title = $post instanceof WP_Post ? (string) get_the_title( $post ) : '';
		if ( $title === '' ) {
			/* translators: %d: subscription primary key */
			$title = \sprintf( __( 'Subscription #%d', 'apermo-notify' ), $row->id );
		}

		$input_id = 'apermo-notify-manage-' . $row->id;

		echo '<li class="apermo-notify-manage__item">';
		echo '<label class="apermo-notify-form__label" for="' . esc_attr( $input_id ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $input_id ) . '" name="ids[]" value="'
			. esc_attr( (string) $row->id ) . '" /> ';

		echo '<span class="apermo-notify-manage__title">';
		if ( $post instanceof WP_Post ) {
			$permalink = (string) get_permalink( $post );
			if ( $permalink !== '' ) {
				echo '<a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
		} else {
			echo esc_html( $title );
		}
		echo '</span>';

		self::render_row_meta( $row, $post );

		echo '</label>';
		echo '</li>';
	}

	/**
	 * Prints the meta list (subscribed-since, post published, last updated)
	 * beneath a single subscription row.
	 *
	 * @param Subscription $row  Subscription.
	 * @param WP_Post|null $post Related post when it still exists.
	 *
	 * @return void
	 */
	private static function render_row_meta( Subscription $row, ?WP_Post $post ): void {
		$subscribed_since = self::format_datetime( $row->confirmed_at ?? $row->created_at );
		$post_published   = $post instanceof WP_Post ? self::format_datetime( $post->post_date_gmt ) : '';
		$post_modified    = $post instanceof WP_Post ? self::format_datetime( $post->post_modified_gmt ) : '';

		echo '<ul class="apermo-notify-manage__meta">';

		if ( $subscribed_since !== '' ) {
			echo '<li class="apermo-notify-manage__meta-item apermo-notify-manage__meta-item--subscribed-since">'
				. \sprintf(
					/* translators: %s: date the visitor confirmed the subscription */
					esc_html__( 'Subscribed since %s', 'apermo-notify' ),
					'<time datetime="' . esc_attr( $row->confirmed_at ?? $row->created_at ) . '">'
						. esc_html( $subscribed_since ) . '</time>',
				)
				. '</li>';
		}

		if ( $post_published !== '' ) {
			echo '<li class="apermo-notify-manage__meta-item apermo-notify-manage__meta-item--published">'
				. \sprintf(
					/* translators: %s: date the post was first published */
					esc_html__( 'Published %s', 'apermo-notify' ),
					'<time datetime="' . esc_attr( $post->post_date_gmt ?? '' ) . '">'
						. esc_html( $post_published ) . '</time>',
				)
				. '</li>';
		}

		if ( $post_modified !== '' && $post_modified !== $post_published ) {
			echo '<li class="apermo-notify-manage__meta-item apermo-notify-manage__meta-item--modified">'
				. \sprintf(
					/* translators: %s: date the post was last updated */
					esc_html__( 'Last updated %s', 'apermo-notify' ),
					'<time datetime="' . esc_attr( $post->post_modified_gmt ?? '' ) . '">'
						. esc_html( $post_modified ) . '</time>',
				)
				. '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Formats a MySQL UTC datetime through the site's locale-aware
	 * date+time formatter. Returns an empty string for null / unparsable input.
	 *
	 * @param string|null $mysql_datetime DATETIME in UTC, e.g. `2026-05-23 21:57:14`.
	 *
	 * @return string
	 */
	private static function format_datetime( ?string $mysql_datetime ): string {
		if ( $mysql_datetime === null || $mysql_datetime === '' ) {
			return '';
		}
		$timestamp = \strtotime( $mysql_datetime . ' UTC' );
		if ( $timestamp === false ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'Y-m-d' )
			. ' ' . (string) get_option( 'time_format', 'H:i' );

		return date_i18n( $format, $timestamp );
	}

	/**
	 * Wires the fallback content filter and the admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ $this, 'maybe_append_fallback' ], 20 );

		add_action( 'admin_post_nopriv_' . self::POST_ACTION, [ $this, 'handle_post' ] );
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'handle_post' ] );

		add_action( 'admin_post_nopriv_' . self::REQUEST_LINK_ACTION, [ $this, 'handle_request_link' ] );
		add_action( 'admin_post_' . self::REQUEST_LINK_ACTION, [ $this, 'handle_request_link' ] );
	}

	/**
	 * Appends the rendered block HTML to the configured page's content
	 * only when the block isn't already present in the page.
	 *
	 * Keeps the manage page working for admins who configured a page but
	 * never added the block to it, and protects already-sent emails from
	 * landing on a "page contains nothing relevant" surprise.
	 *
	 * @param string $content Original page content.
	 *
	 * @return string
	 */
	public function maybe_append_fallback( string $content ): string {
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

		// If the admin added the block, the block already rendered the UI.
		$page = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post
			? $GLOBALS['post']
			: get_post( $page_id );
		if ( $page instanceof WP_Post && has_block( Blocks\ManageSubscriptionsBlock::NAME, $page ) ) {
			return $content;
		}

		return $content . self::render_block_html();
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
		if ( ! $owner instanceof Subscription ) {
			wp_safe_redirect( home_url( '/' ) );
			exit();
		}

		$ids = $this->selected_ids();
		if ( $ids !== [] ) {
			Repository::unsubscribe_many( $ids, $owner->email );
		}

		wp_safe_redirect( $this->target_url( [ 'token' => $token ], 'managed' ) );
		exit();
	}

	/**
	 * Processes the "request a manage link" form.
	 *
	 * Returns the same redirect regardless of whether the email is on
	 * file to prevent enumeration. Per-IP throttled so it can't be
	 * looped as a mail bomb.
	 *
	 * @return void
	 */
	public function handle_request_link(): void {
		check_admin_referer( self::REQUEST_LINK_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$raw_email = isset( $_POST['email'] ) && \is_string( $_POST['email'] )
			? sanitize_email( wp_unslash( $_POST['email'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$flash = 'manage_link_sent';

		if ( ! $this->is_throttled() && is_email( $raw_email ) ) {
			$subscription = Repository::find_first_confirmed_by_email( (string) $raw_email );
			if ( $subscription instanceof Subscription ) {
				Mailer::send_manage_link( $subscription );
			}
			$this->mark_throttled();
		}

		wp_safe_redirect( $this->target_url( [], $flash ) );
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
			set_transient( $key, 1, self::REQUEST_LINK_THROTTLE_SECONDS );
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

		return $ip === '' ? '' : 'apermo_notify_request_link_throttle_' . \md5( $ip );
	}

	/**
	 * Builds the redirect URL back to the manage page after a POST handler.
	 *
	 * @param array<string, string|int> $args   Extra query vars to forward (e.g. token).
	 * @param string                    $result Result code to flash.
	 *
	 * @return string
	 */
	private function target_url( array $args, string $result ): string {
		$page_id   = Settings::manage_page_id();
		$permalink = $page_id > 0 ? (string) get_permalink( $page_id ) : '';
		if ( $permalink === '' ) {
			$permalink = home_url( '/' );
		}

		$args['apermo_notify_result'] = $result;
		return add_query_arg( $args, $permalink );
	}
}
