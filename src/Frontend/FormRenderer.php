<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;

/**
 * Renders the visitor-facing subscribe form.
 *
 * The form HTML and the flash-message lookup were previously in
 * `Shortcode.php`; this helper exposes them so any placement strategy
 * (auto-append via `the_content`, block render callback, template tag,
 * future REST endpoint) can share the same markup.
 */
final class FormRenderer {

	/**
	 * Renders the form HTML for a given post.
	 *
	 * @param int $post_id Post the subscription targets.
	 *
	 * @return string HTML, or empty string when the post ID is invalid.
	 */
	public static function render( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		$result_code = self::result_code();
		$message     = self::message_for( $result_code );

		\ob_start();
		?>
		<form
			class="apermo-notify-form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		>
			<?php
			self::render_intro( Settings::subscription_text() );
			self::render_hidden_inputs( $post_id );
			self::render_email_field( $post_id );
			self::render_submit();
			self::render_message( $result_code, $message );
			?>
		</form>
		<?php

		return (string) \ob_get_clean();
	}

	/**
	 * Prints the intro paragraph above the form when configured.
	 *
	 * @param string $intro Sanitized intro HTML.
	 *
	 * @return void
	 */
	private static function render_intro( string $intro ): void {
		if ( $intro === '' ) {
			return;
		}
		echo '<p class="apermo-notify-form__intro">'
			. wp_kses_post( $intro )
			. '</p>';
	}

	/**
	 * Prints the hidden action + post_id + nonce inputs.
	 *
	 * @param int $post_id Subscription target.
	 *
	 * @return void
	 */
	private static function render_hidden_inputs( int $post_id ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( FormHandler::ACTION ) . '" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
		wp_nonce_field( FormHandler::NONCE_ACTION );
	}

	/**
	 * Prints the email label + input pair.
	 *
	 * @param int $post_id Subscription target (used to scope the input ID).
	 *
	 * @return void
	 */
	private static function render_email_field( int $post_id ): void {
		$input_id = 'apermo-notify-email-' . $post_id;
		echo '<p class="apermo-notify-form__field comment-form-email">';
		echo '<label class="apermo-notify-form__label" for="'
			. esc_attr( $input_id ) . '">'
			. esc_html__( 'Email address', 'apermo-notify' )
			. '</label>';
		echo '<input class="apermo-notify-form__input" type="email" id="'
			. esc_attr( $input_id ) . '" name="email" required autocomplete="email" />';
		echo '</p>';
	}

	/**
	 * Prints the submit-button container.
	 *
	 * @return void
	 */
	private static function render_submit(): void {
		echo '<p class="apermo-notify-form__actions form-submit">'
			. '<button class="apermo-notify-form__submit submit wp-block-button__link" type="submit">'
			. esc_html__( 'Notify me about updates', 'apermo-notify' )
			. '</button></p>';
	}

	/**
	 * Prints the flash-message paragraph when present.
	 *
	 * @param string $code    Result code.
	 * @param string $message Resolved message text.
	 *
	 * @return void
	 */
	private static function render_message( string $code, string $message ): void {
		if ( $message === '' ) {
			return;
		}
		echo '<p class="apermo-notify-message apermo-notify-message--' . esc_attr( $code ) . '" role="status">'
			. esc_html( $message )
			. '</p>';
	}

	/**
	 * Reads the result-code query parameter set by FormHandler's redirect.
	 *
	 * @return string Sanitized code or empty string when absent.
	 */
	private static function result_code(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flash message.
		if (
			! isset( $_GET[ FormHandler::RESULT_QUERY_VAR ] )
			|| ! \is_string( $_GET[ FormHandler::RESULT_QUERY_VAR ] )
		) {
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ FormHandler::RESULT_QUERY_VAR ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Maps a result code to a translatable, human-readable message.
	 *
	 * @param string $code Result code.
	 *
	 * @return string
	 */
	private static function message_for( string $code ): string {
		switch ( $code ) {
			case 'pending':
				return __( 'Almost done — please check your inbox for a confirmation link.', 'apermo-notify' );
			case 'duplicate':
				return __( 'You are already subscribed to this content.', 'apermo-notify' );
			case 'throttled':
				return __( 'Please wait a moment before trying again.', 'apermo-notify' );
			case 'invalid_email':
				return __( 'That email address does not look valid.', 'apermo-notify' );
			case 'invalid':
				return __( 'Something went wrong. Please try again.', 'apermo-notify' );
			case 'mail_failure':
				return __( 'We could not send the confirmation email. Please try again in a moment.', 'apermo-notify' );
			default:
				return '';
		}
	}
}
