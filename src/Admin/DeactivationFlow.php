<?php

declare(strict_types=1);

namespace Plugin_Name\Admin;

use Plugin_Name\Main;

/**
 * Routes the plugin's "Deactivate" link through a confirmation screen so that
 * destructive cleanup of plugin data only runs after the user explicitly opts
 * in. The destructive path is gated by capability, nonce, and an in-form
 * checkbox in the same request — no persistent "allow delete" option.
 *
 * Derived projects fill in {@see self::cleanup_plugin_data()} with whatever
 * tables, options, transients, and metadata they own.
 */
class DeactivationFlow {

	/**
	 * Hidden admin page slug used for the confirmation screen.
	 */
	public const CONFIRM_PAGE = 'plugin-name-deactivate';

	/**
	 * admin-post.php / admin_action_* handler key for the destructive submission.
	 */
	public const DELETE_ACTION = 'plugin_name_deactivate_and_delete';

	/**
	 * Wires the deactivation flow into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$basename = plugin_basename( Main::file() );

		add_filter( "plugin_action_links_{$basename}", [ $this, 'rewrite_deactivate_link' ] );
		add_filter( "network_admin_plugin_action_links_{$basename}", [ $this, 'rewrite_deactivate_link' ] );
		add_action( 'admin_menu', [ $this, 'register_hidden_page' ] );
		add_action( 'network_admin_menu', [ $this, 'register_hidden_page' ] );
		add_action( 'admin_action_' . self::DELETE_ACTION, [ $this, 'handle_destructive' ] );
	}

	/**
	 * Replaces the default "Deactivate" plugin row link with a link to the
	 * confirmation screen.
	 *
	 * @param array<string, string> $actions Plugin action links.
	 *
	 * @return array<string, string>
	 */
	public function rewrite_deactivate_link( array $actions ): array {
		if ( ! isset( $actions['deactivate'] ) ) {
			return $actions;
		}

		$base_url = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		$url      = add_query_arg( [ 'page' => self::CONFIRM_PAGE ], $base_url );

		$actions['deactivate'] = \sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Deactivate', 'plugin-name' ),
		);

		return $actions;
	}

	/**
	 * Registers the confirmation screen as a hidden submenu page.
	 *
	 * @return void
	 */
	public function register_hidden_page(): void {
		add_submenu_page(
			'',
			__( 'Deactivate plugin-name', 'plugin-name' ),
			__( 'Deactivate plugin-name', 'plugin-name' ),
			$this->current_capability(),
			self::CONFIRM_PAGE,
			[ $this, 'render_confirm_page' ],
		);
	}

	/**
	 * Renders the confirmation screen.
	 *
	 * @return void
	 */
	public function render_confirm_page(): void {
		if ( ! current_user_can( $this->current_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to deactivate this plugin.', 'plugin-name' ), 403 );
		}

		$basename = plugin_basename( Main::file() );

		// phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Used by the included view.
		$deactivate    = $this->safe_deactivate_url( $basename );
		$delete_url    = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		$delete_action = self::DELETE_ACTION;
		$delete_nonce  = wp_create_nonce( self::DELETE_ACTION );
		// phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable

		require __DIR__ . '/views/confirm-deactivate.php';
	}

	/**
	 * Returns the capability required for both viewing the confirmation screen
	 * and submitting the destructive form. Mirrors WordPress's plugin-row
	 * deactivation gate: network-active plugins need `manage_network_plugins`,
	 * everything else uses `activate_plugins`.
	 *
	 * @return string
	 */
	private function current_capability(): string {
		return is_plugin_active_for_network( plugin_basename( Main::file() ) )
			? 'manage_network_plugins'
			: 'activate_plugins';
	}

	/**
	 * Builds the standard WordPress deactivate URL with nonce, used by the
	 * "keep data" path on the confirmation screen.
	 *
	 * @param string $basename Plugin basename, e.g. plugin-name/plugin.php.
	 *
	 * @return string
	 */
	private function safe_deactivate_url( string $basename ): string {
		$base_url = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		$url      = add_query_arg(
			[
				'action' => 'deactivate',
				'plugin' => $basename,
			],
			$base_url,
		);

		return wp_nonce_url( $url, 'deactivate-plugin_' . $basename );
	}

	/**
	 * Handles the destructive submission: verifies all gates, runs cleanup,
	 * deactivates the plugin, then redirects back to the plugins list.
	 *
	 * @return void
	 */
	public function handle_destructive(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in.', 'plugin-name' ), 403 );
		}

		if ( ! current_user_can( $this->current_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to deactivate this plugin.', 'plugin-name' ), 403 );
		}

		check_admin_referer( self::DELETE_ACTION );

		$confirm = isset( $_POST['confirm'] ) && \is_scalar( $_POST['confirm'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['confirm'] ) )
			: '';

		if ( $confirm !== '1' ) {
			wp_die( esc_html__( 'Confirmation checkbox was not checked.', 'plugin-name' ), 400 );
		}

		$this->cleanup_plugin_data();

		$basename = plugin_basename( Main::file() );
		deactivate_plugins( $basename, false, is_network_admin() );

		$redirect = add_query_arg(
			[
				'deactivate'        => 'true',
				'plugin_name_wiped' => '1',
			],
			is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ),
		);

		wp_safe_redirect( $redirect );
		exit();
	}

	/**
	 * Removes all plugin-owned data. Override or extend in derived projects
	 * to drop custom tables, options, transients, and metadata.
	 *
	 * Multisite caveat: when the plugin is **network-activated**, settings
	 * typically live in network meta (`*_site_option`), not in any single
	 * site's options table. When the plugin is **per-site activated**, each
	 * site has its own copy in its options table — wiping all of them
	 * requires iterating sites explicitly.
	 *
	 * Example extensions:
	 * - $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plugin_name_…" );
	 * - delete_metadata( 'post', 0, 'plugin_name_…', '', true );
	 * - $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_plugin_name_%'" );
	 *
	 * The CSV-export "backup before delete" pattern can also live alongside
	 * this method as a third option on the confirmation screen — see the
	 * view file for the markup hook.
	 *
	 * @return void
	 */
	protected function cleanup_plugin_data(): void {
		if ( is_multisite() && is_plugin_active_for_network( plugin_basename( Main::file() ) ) ) {
			// Network-activated: site options live in network meta;
			// `delete_site_option()` works regardless of the current blog.
			delete_site_option( 'plugin_name_settings' );

			/*
			 * To wipe per-site data on every site, iterate explicitly:
			 *
			 *     foreach ( get_sites( [ 'fields' => 'ids' ] ) as $site_id ) {
			 *         switch_to_blog( $site_id );
			 *         delete_option( 'plugin_name_per_site_settings' );
			 *         restore_current_blog();
			 *     }
			 */
			return;
		}

		// Per-site activation or single-site install: delete on the current site.
		delete_option( 'plugin_name_settings' );
	}
}
