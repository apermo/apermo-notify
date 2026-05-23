<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;

/**
 * Renders the "Apermo Notify → Settings" admin submenu.
 *
 * The form posts to admin-post.php so the same nonce-guarded, capability-
 * checked redirect pattern as the visitor-facing FormHandler applies.
 */
final class SettingsPage {

	/**
	 * Capability required to view and submit the page.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'apermo-notify-settings';

	/**
	 * admin-post.php action name for the settings submission.
	 */
	public const ACTION = 'apermo_notify_save_settings';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'apermo_notify_settings';

	/**
	 * Query var that surfaces the save result on the redirect.
	 */
	public const RESULT_QUERY_VAR = 'apermo_notify_settings_saved';

	/**
	 * Returns the list of post types eligible for subscription, keyed by slug,
	 * value is the human label.
	 *
	 * @return array<string, string>
	 */
	private static function candidate_post_types(): array {
		$result     = [];
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		foreach ( $post_types as $type ) {
			if ( $type->name === 'attachment' ) {
				continue;
			}
			$result[ $type->name ] = $type->labels->singular_name ?? $type->name;
		}

		\ksort( $result );

		return $result;
	}

	/**
	 * Reads the saved-flash query parameter for the success notice.
	 *
	 * @return bool
	 */
	private static function saved_flash(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Flash flag only.
		return isset( $_GET[ self::RESULT_QUERY_VAR ] ) && (string) $_GET[ self::RESULT_QUERY_VAR ] === '1';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Renders the post-types checkbox group.
	 *
	 * @param array<int, string> $enabled Currently enabled slugs.
	 *
	 * @return void
	 */
	private static function render_post_types_field( array $enabled ): void {
		echo '<tr><th scope="row">' . esc_html__( 'Enabled post types', 'apermo-notify' ) . '</th><td>';
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>'
			. esc_html__( 'Enabled post types', 'apermo-notify' )
			. '</span></legend>';
		foreach ( self::candidate_post_types() as $slug => $label ) {
			$checked = \in_array( $slug, $enabled, true ) ? ' checked="checked"' : '';
			\printf(
				'<label><input type="checkbox" name="enabled_post_types[]" value="%1$s"%2$s /> %3$s <code>%1$s</code></label><br />',
				esc_attr( $slug ),
				esc_attr( $checked ),
				esc_html( $label ),
			);
		}
		echo '<p class="description">'
			. esc_html__( 'The subscribe form and the editor meta box are only available on these post types.', 'apermo-notify' )
			. '</p>';
		echo '</fieldset></td></tr>';
	}

	/**
	 * Renders the auto-append default toggle.
	 *
	 * @param bool $checked Current value.
	 *
	 * @return void
	 */
	private static function render_auto_append_field( bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html__( 'Default placement', 'apermo-notify' ) . '</th><td>';
		\printf(
			'<label><input type="checkbox" name="auto_append_default" value="1"%1$s /> %2$s</label>',
			esc_attr( $checked ? ' checked="checked"' : '' ),
			esc_html__( 'Append the subscribe form to the end of every enabled post by default', 'apermo-notify' ),
		);
		echo '<p class="description">'
			. esc_html__( 'Individual posts can override this in the editor sidebar.', 'apermo-notify' )
			. '</p>';
		echo '</td></tr>';
	}

	/**
	 * Renders the subscription-text textarea.
	 *
	 * @param string $value Current value.
	 *
	 * @return void
	 */
	private static function render_subscription_text_field( string $value ): void {
		echo '<tr><th scope="row"><label for="apermo_notify_subscription_text">'
			. esc_html__( 'Subscription text', 'apermo-notify' )
			. '</label></th><td>';
		\printf(
			'<textarea id="apermo_notify_subscription_text" name="subscription_text" rows="3" class="large-text">%s</textarea>',
			esc_textarea( $value ),
		);
		echo '<p class="description">'
			. esc_html__( 'Shown above the email field on the subscribe form. Basic HTML allowed.', 'apermo-notify' )
			. '</p>';
		echo '</td></tr>';
	}

	/**
	 * Renders the stale-after-months select.
	 *
	 * @param int $value Currently saved months.
	 *
	 * @return void
	 */
	private static function render_stale_after_field( int $value ): void {
		echo '<tr><th scope="row"><label for="apermo_notify_stale_after_months">'
			. esc_html__( 'Mark subscriptions stale after', 'apermo-notify' )
			. '</label></th><td>';
		echo '<select id="apermo_notify_stale_after_months" name="stale_after_months">';
		foreach ( Settings::STALE_AFTER_MONTHS_CHOICES as $months ) {
			\printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $months ),
				selected( $value, $months, false ),
				esc_html(
					\sprintf(
						/* translators: %d: months */
						_n( '%d month', '%d months', $months, 'apermo-notify' ),
						$months,
					),
				),
			);
		}
		echo '</select>';
		echo '<p class="description">'
			. esc_html__( 'Confirmed subscriptions that haven\'t been kept alive within this window are eligible for pruning.', 'apermo-notify' )
			. '</p>';
		echo '</td></tr>';
	}

	/**
	 * Renders the prune-mode radios.
	 *
	 * @param string $value Currently saved mode.
	 *
	 * @return void
	 */
	private static function render_prune_mode_field( string $value ): void {
		echo '<tr><th scope="row">' . esc_html__( 'Prune mode', 'apermo-notify' ) . '</th><td>';
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Prune mode', 'apermo-notify' ) . '</span></legend>';
		\printf(
			'<label><input type="radio" name="prune_mode" value="%1$s"%2$s /> %3$s</label><br />',
			esc_attr( Settings::PRUNE_MODE_KEEP_ALIVE ),
			checked( $value, Settings::PRUNE_MODE_KEEP_ALIVE, false ),
			esc_html__( 'Ask first: email the subscriber a keep-alive link and only delete if they don\'t respond within the grace window.', 'apermo-notify' ),
		);
		\printf(
			'<label><input type="radio" name="prune_mode" value="%1$s"%2$s /> %3$s</label>',
			esc_attr( Settings::PRUNE_MODE_DELETE ),
			checked( $value, Settings::PRUNE_MODE_DELETE, false ),
			esc_html__( 'Delete immediately when stale, no email.', 'apermo-notify' ),
		);
		echo '</fieldset>';
		echo '</td></tr>';
	}

	/**
	 * Renders the stale-grace-days select (only meaningful for keep-alive mode).
	 *
	 * @param int $value Currently saved grace days.
	 *
	 * @return void
	 */
	private static function render_stale_grace_field( int $value ): void {
		echo '<tr><th scope="row"><label for="apermo_notify_stale_grace_days">'
			. esc_html__( 'Keep-alive grace window', 'apermo-notify' )
			. '</label></th><td>';
		echo '<select id="apermo_notify_stale_grace_days" name="stale_grace_days">';
		foreach ( Settings::STALE_GRACE_DAYS_CHOICES as $days ) {
			\printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $days ),
				selected( $value, $days, false ),
				esc_html(
					\sprintf(
						/* translators: %d: days */
						_n( '%d day', '%d days', $days, 'apermo-notify' ),
						$days,
					),
				),
			);
		}
		echo '</select>';
		echo '<p class="description">'
			. esc_html__( 'After sending the keep-alive email, wait this long before deleting if the subscriber doesn\'t click. Ignored when prune mode is "delete immediately".', 'apermo-notify' )
			. '</p>';
		echo '</td></tr>';
	}

	/**
	 * Renders an admin warning when the site has no Privacy Policy page set.
	 *
	 * @return void
	 */
	private static function render_privacy_policy_notice(): void {
		$policy_page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $policy_page_id > 0 ) {
			return;
		}

		$link = '<a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">'
			. esc_html__( 'Privacy settings', 'apermo-notify' )
			. '</a>';

		echo '<div class="notice notice-warning"><p>'
			. wp_kses(
				\sprintf(
					/* translators: %s: link to the Privacy settings screen */
					__( 'No privacy policy page is configured. The subscribe form needs one to be GDPR-compliant — pick or create a page in %s.', 'apermo-notify' ),
					$link,
				),
				[ 'a' => [ 'href' => true ] ],
			)
			. '</p></div>';
	}

	/**
	 * Wires the menu entry and POST handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
	}

	/**
	 * Adds the "Settings" submenu under the "Apermo Notify" top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			SubscribersPage::SLUG,
			__( 'Apermo Notify settings', 'apermo-notify' ),
			__( 'Settings', 'apermo-notify' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
		);
	}

	/**
	 * Renders the settings form.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = Settings::all();

		echo '<div class="wrap"><h1>' . esc_html__( 'Apermo Notify settings', 'apermo-notify' ) . '</h1>';

		self::render_privacy_policy_notice();

		if ( self::saved_flash() ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'apermo-notify' )
				. '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_post_types_field( $settings['enabled_post_types'] );
		self::render_auto_append_field( $settings['auto_append_default'] );
		self::render_subscription_text_field( $settings['subscription_text'] );
		self::render_stale_after_field( $settings['stale_after_months'] );
		self::render_prune_mode_field( $settings['prune_mode'] );
		self::render_stale_grace_field( $settings['stale_grace_days'] );
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'apermo-notify' ) );
		echo '</form></div>';
	}

	/**
	 * Handles the admin-post.php submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to update these settings.', 'apermo-notify' ),
				esc_html__( 'Apermo Notify settings', 'apermo-notify' ),
				[ 'response' => 403 ],
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$enabled = isset( $_POST['enabled_post_types'] ) && \is_array( $_POST['enabled_post_types'] )
			? \array_map( 'sanitize_key', wp_unslash( $_POST['enabled_post_types'] ) )
			: [];

		$auto_append = isset( $_POST['auto_append_default'] );

		$subscription_text = isset( $_POST['subscription_text'] ) && \is_string( $_POST['subscription_text'] )
			? wp_kses_post( wp_unslash( $_POST['subscription_text'] ) )
			: '';

		$stale_after = isset( $_POST['stale_after_months'] ) && \is_scalar( $_POST['stale_after_months'] )
			? (int) $_POST['stale_after_months']
			: 6;

		$prune_mode = isset( $_POST['prune_mode'] ) && \is_string( $_POST['prune_mode'] )
			? sanitize_key( wp_unslash( $_POST['prune_mode'] ) )
			: Settings::PRUNE_MODE_KEEP_ALIVE;

		$grace_days = isset( $_POST['stale_grace_days'] ) && \is_scalar( $_POST['stale_grace_days'] )
			? (int) $_POST['stale_grace_days']
			: 7;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Settings::save(
			[
				'enabled_post_types'  => $enabled,
				'auto_append_default' => $auto_append,
				'subscription_text'   => $subscription_text,
				'stale_after_months'  => $stale_after,
				'prune_mode'          => $prune_mode,
				'stale_grace_days'    => $grace_days,
			],
		);

		$redirect = add_query_arg(
			[
				'page'                 => self::SLUG,
				self::RESULT_QUERY_VAR => '1',
			],
			admin_url( 'admin.php' ),
		);

		wp_safe_redirect( $redirect );
		exit();
	}
}
