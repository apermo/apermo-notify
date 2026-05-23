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

		$settings   = Settings::all();
		$post_types = self::candidate_post_types();
		$saved      = self::saved_flash();

		echo '<div class="wrap"><h1>' . esc_html__( 'Apermo Notify settings', 'apermo-notify' ) . '</h1>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'apermo-notify' )
				. '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Enabled post types', 'apermo-notify' ) . '</th><td>';
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>'
			. esc_html__( 'Enabled post types', 'apermo-notify' )
			. '</span></legend>';
		foreach ( $post_types as $slug => $label ) {
			$checked = \in_array( $slug, $settings['enabled_post_types'], true ) ? ' checked="checked"' : '';
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

		$default_checked = $settings['auto_append_default'] ? ' checked="checked"' : '';
		echo '<tr><th scope="row">' . esc_html__( 'Default placement', 'apermo-notify' ) . '</th><td>';
		\printf(
			'<label><input type="checkbox" name="auto_append_default" value="1"%1$s /> %2$s</label>',
			esc_attr( $default_checked ),
			esc_html__( 'Append the subscribe form to the end of every enabled post by default', 'apermo-notify' ),
		);
		echo '<p class="description">'
			. esc_html__( 'Individual posts can override this in the editor sidebar.', 'apermo-notify' )
			. '</p>';
		echo '</td></tr>';

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
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Settings::save(
			[
				'enabled_post_types'  => $enabled,
				'auto_append_default' => $auto_append,
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
