<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

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
		<form class="apermo-notify-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( FormHandler::ACTION ); ?>" />
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
			<?php wp_nonce_field( FormHandler::NONCE_ACTION ); ?>
			<p>
				<label for="apermo-notify-email-<?php echo esc_attr( (string) $post_id ); ?>">
					<?php esc_html_e( 'Email address', 'apermo-notify' ); ?>
				</label>
				<input
					type="email"
					id="apermo-notify-email-<?php echo esc_attr( (string) $post_id ); ?>"
					name="email"
					required
					autocomplete="email"
				/>
			</p>
			<p>
				<button type="submit">
					<?php esc_html_e( 'Notify me about updates', 'apermo-notify' ); ?>
				</button>
			</p>
			<?php if ( $message !== '' ) { ?>
				<p
					class="apermo-notify-message apermo-notify-message--<?php echo esc_attr( $result_code ); ?>"
					role="status"
				>
					<?php echo esc_html( $message ); ?>
				</p>
			<?php } ?>
		</form>
		<?php

		return (string) \ob_get_clean();
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
