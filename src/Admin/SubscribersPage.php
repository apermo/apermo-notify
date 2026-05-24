<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;

/**
 * Renders the Subscribers admin screen.
 *
 * Owns the menu registration, the surrounding `<form>` that drives the
 * WP_List_Table (search box + bulk-action posts), and the delete-action
 * handlers (single via GET, bulk via POST). The table itself is in
 * SubscribersListTable.
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
	 * Nonce action used by the single-row delete link.
	 */
	public const DELETE_NONCE_ACTION = 'apermo_notify_delete_subscription';

	/**
	 * Processes the delete actions submitted to the page before the table
	 * is rendered, returning the number of rows actually deleted.
	 *
	 * @return int
	 */
	private static function handle_actions(): int {
		$single_action = isset( $_GET['apermo_notify_action'] ) && \is_string( $_GET['apermo_notify_action'] )
			? sanitize_key( wp_unslash( $_GET['apermo_notify_action'] ) )
			: '';
		$single_id = isset( $_GET['id'] ) && \is_scalar( $_GET['id'] )
			? (int) $_GET['id']
			: 0;
		$bulk_action = '';
		if ( isset( $_POST['action'] ) && \is_string( $_POST['action'] ) && $_POST['action'] !== '-1' ) {
			$bulk_action = sanitize_key( wp_unslash( $_POST['action'] ) );
		} elseif ( isset( $_POST['action2'] ) && \is_string( $_POST['action2'] ) && $_POST['action2'] !== '-1' ) {
			$bulk_action = sanitize_key( wp_unslash( $_POST['action2'] ) );
		}

		if ( $single_action === 'delete' && $single_id > 0 ) {
			check_admin_referer( self::DELETE_NONCE_ACTION . '_' . $single_id );
			return Repository::delete_many( [ $single_id ] );
		}

		if ( $bulk_action === 'delete' ) {
			check_admin_referer( self::DELETE_NONCE_ACTION . '_bulk' );
			return Repository::delete_many( self::posted_ids() );
		}

		return 0;
	}

	/**
	 * Reads the bulk-action `ids[]` array from $_POST as positive ints.
	 *
	 * @return array<int, int>
	 */
	private static function posted_ids(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce checked in handle_actions(); each value is intval'd below.
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

		$deleted = self::handle_actions();

		$table = new SubscribersListTable();
		$table->prepare_items();

		echo '<div class="wrap"><h1 class="wp-heading-inline">'
			. esc_html__( 'Subscribers', 'apermo-notify' )
			. '</h1><hr class="wp-header-end" />';

		if ( $deleted > 0 ) {
			wp_admin_notice(
				\sprintf(
					/* translators: %d: number of deleted rows */
					_n(
						'%d subscription deleted.',
						'%d subscriptions deleted.',
						$deleted,
						'apermo-notify',
					),
					$deleted,
				),
				[
					'type'           => 'success',
					'dismissible'    => true,
					'paragraph_wrap' => true,
				],
			);
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		$table->search_box(
			__( 'Search by email', 'apermo-notify' ),
			'apermo-notify-search',
		);
		echo '</form>';

		echo '<form method="post">';
		wp_nonce_field( self::DELETE_NONCE_ACTION . '_bulk' );
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		// Preserve current filters so bulk-delete POSTs return to the same view.
		$status = SubscribersListTable::current_status_filter();
		if ( $status !== null ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( (string) $status ) . '" />';
		}
		$search = SubscribersListTable::current_search();
		if ( $search !== '' ) {
			echo '<input type="hidden" name="s" value="' . esc_attr( $search ) . '" />';
		}
		$table->display();
		echo '</form>';

		echo '</div>';
	}
}
