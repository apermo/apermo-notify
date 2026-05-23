<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;

/**
 * Renders the admin page listing every subscription row.
 *
 * v0.1 ships a simple table (no pagination/sorting). A WP_List_Table-based
 * implementation lands in v0.2 alongside bulk actions and CSV export.
 */
final class SubscribersPage {

	/**
	 * Capability required to view the page.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Top-level menu slug, shared with the parent so the subscribers screen
	 * is the default landing page for "Apermo Notify".
	 */
	public const SLUG = 'apermo-notify';

	/**
	 * Fetches recent subscriptions (capped at 200 for v0.1).
	 *
	 * @return array<int, Subscription>
	 */
	private static function recent(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d',
				Repository::table(),
				200,
			),
			\ARRAY_A,
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ Subscription::class, 'from_row' ], $rows );
	}

	/**
	 * Formats a subscription's target for display.
	 *
	 * @param Subscription $row Subscription.
	 *
	 * @return string
	 */
	private static function format_target( Subscription $row ): string {
		if ( $row->target_type === 'post' ) {
			$post = get_post( $row->target_id );
			if ( $post !== null ) {
				return \sprintf(
					/* translators: 1: post title, 2: post ID */
					__( '%1$s (#%2$d)', 'apermo-notify' ),
					$post->post_title,
					$row->target_id,
				);
			}
			return \sprintf(
				/* translators: %d: post ID */
				__( 'post #%d', 'apermo-notify' ),
				$row->target_id,
			);
		}

		return \sprintf(
			/* translators: 1: target type slug, 2: target identifier */
			__( '%1$s #%2$d', 'apermo-notify' ),
			$row->target_type,
			$row->target_id,
		);
	}

	/**
	 * Maps a status int to a human-readable label.
	 *
	 * @param int $status Status constant.
	 *
	 * @return string
	 */
	private static function format_status( int $status ): string {
		switch ( $status ) {
			case Subscription::STATUS_PENDING:
				return __( 'pending', 'apermo-notify' );
			case Subscription::STATUS_CONFIRMED:
				return __( 'confirmed', 'apermo-notify' );
			case Subscription::STATUS_UNSUBSCRIBED:
				return __( 'unsubscribed', 'apermo-notify' );
			default:
				return (string) $status;
		}
	}

	/**
	 * Wires the menu entry.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	/**
	 * Registers the top-level "Apermo Notify" menu plus the Subscribers
	 * submenu that acts as the landing page. SettingsPage adds the second
	 * submenu separately.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Apermo Notify', 'apermo-notify' ),
			__( 'Apermo Notify', 'apermo-notify' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-email-alt',
			76,
		);

		// Renames the auto-added first submenu from "Apermo Notify" → "Subscribers".
		add_submenu_page(
			self::SLUG,
			__( 'Subscribers', 'apermo-notify' ),
			__( 'Subscribers', 'apermo-notify' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ],
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$rows = self::recent();

		echo '<div class="wrap"><h1>' . esc_html__( 'Subscribers', 'apermo-notify' ) . '</h1>';

		if ( $rows === [] ) {
			echo '<p>' . esc_html__( 'No subscriptions yet.', 'apermo-notify' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Email', 'apermo-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Target', 'apermo-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'apermo-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'apermo-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Confirmed', 'apermo-notify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td>' . esc_html( self::format_target( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::format_status( $row->status ) ) . '</td>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '<td>' . esc_html( $row->confirmed_at ?? '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
