<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

/**
 * Shows a site-wide, non-dismissible admin notice when the site has no
 * Privacy Policy page configured.
 *
 * The plugin is GDPR-by-design: the subscribe form's mandatory consent
 * checkbox links to the site's privacy policy. Without a configured
 * policy page the consent text falls back to a bare label, which would
 * defeat the design. The notice nags every admin screen until the
 * setting is fixed; there is no dismiss button on purpose.
 */
final class PrivacyPolicyNotice {

	/**
	 * Renders the notice when no privacy policy page is set and the
	 * current user can fix it.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The check mirrors what FormRenderer uses to decide whether the
		// consent label links to the policy. `get_privacy_policy_url()`
		// returns an empty string for both "option unset" and "option set
		// but target page is draft/trash" — both cases break the consent
		// label the same way, so both deserve the notice.
		if ( get_privacy_policy_url() !== '' ) {
			return;
		}

		$message = '<strong>'
			. esc_html__( 'Apermo Notify:', 'apermo-notify' )
			. '</strong> '
			. wp_kses(
				\sprintf(
					/* translators: %s: link to the Privacy settings screen */
					__(
						'No published privacy policy page is configured. The subscribe form is GDPR-by-design and requires a published Privacy Policy page — pick or publish one in %s.',
						'apermo-notify',
					),
					'<a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">'
						. esc_html__( 'Privacy settings', 'apermo-notify' )
						. '</a>',
				),
				[ 'a' => [ 'href' => true ] ],
			);

		wp_admin_notice(
			$message,
			[
				'type'           => 'warning',
				'dismissible'    => false,
				'paragraph_wrap' => true,
			],
		);
	}

	/**
	 * Registers the admin_notices hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', [ self::class, 'maybe_render' ] );
		add_action( 'network_admin_notices', [ self::class, 'maybe_render' ] );
	}
}
