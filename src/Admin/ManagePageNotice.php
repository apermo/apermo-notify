<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;
use WP_Post;

/**
 * Shows a site-wide, non-dismissible admin notice when no Subscription
 * Management page has been configured (or the configured page no longer
 * exists / isn't published).
 *
 * Every outgoing email links to the management page; without a target
 * the link points at the site root and visitors land on the home page
 * with no manage UI in sight. The notice nags every admin screen until
 * the setting is fixed; there is no dismiss button on purpose.
 */
final class ManagePageNotice {

	/**
	 * Renders the notice when no manage page is set and the current user
	 * can fix it.
	 *
	 * @return void
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::has_published_page() ) {
			return;
		}

		$message = '<strong>'
			. esc_html__( 'Apermo Notify:', 'apermo-notify' )
			. '</strong> '
			. wp_kses(
				\sprintf(
					/* translators: %s: link to the Apermo Notify settings screen */
					__(
						'No Subscription Management page is configured. Every notification email links to that page — pick or publish one in %s.',
						'apermo-notify',
					),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">'
						. esc_html__( 'Apermo Notify settings', 'apermo-notify' )
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
	 * Reports whether the configured manage page resolves to a published post.
	 *
	 * Matches the precondition used by Mailer::manage_url and the
	 * `the_content` injector — option set AND target is published.
	 *
	 * @return bool
	 */
	private static function has_published_page(): bool {
		$page_id = Settings::manage_page_id();
		if ( $page_id <= 0 ) {
			return false;
		}

		$post = get_post( $page_id );
		return $post instanceof WP_Post && $post->post_status === 'publish';
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
