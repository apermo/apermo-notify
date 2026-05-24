<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_List_Table;
use WP_Post;

// The WP_List_Table parent lives in wp-admin/includes/; PSR-4 autoloading
// never reaches it. Loaded lazily so non-admin requests don't pay the cost.
if ( ! \class_exists( WP_List_Table::class ) ) {
	require_once \ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the Subscribers admin screen as a core WP_List_Table.
 *
 * Wraps the paginated Repository query and exposes the standard core
 * affordances: column sorting, status filter, email search, single-row
 * delete, and bulk delete. The page wrapper (SubscribersPage) is
 * responsible for the surrounding `<form>` and for handling delete POSTs
 * before the table is prepared.
 */
final class SubscribersListTable extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Sets the list-table singular/plural strings used in CSS hooks.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'apermo_notify_subscription',
				'plural'   => 'apermo_notify_subscriptions',
				'ajax'     => false,
			],
		);
	}

	/**
	 * Reads the current status filter from the request.
	 *
	 * @return int|null Null when "all" is selected.
	 */
	public static function current_status_filter(): ?int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter on a manage_options-gated screen.
		if ( ! isset( $_REQUEST['status'] ) || ! \is_scalar( $_REQUEST['status'] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( (string) $_REQUEST['status'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $raw === '' ) {
			return null;
		}

		$valid = [
			Subscription::STATUS_PENDING,
			Subscription::STATUS_CONFIRMED,
			Subscription::STATUS_UNSUBSCRIBED,
		];
		$value = (int) $raw;
		return \in_array( $value, $valid, true ) ? $value : null;
	}

	/**
	 * Reads the search box value, sanitized.
	 *
	 * @return string Empty string when absent.
	 */
	public static function current_search(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search on a manage_options-gated screen.
		if ( ! isset( $_REQUEST['s'] ) || ! \is_string( $_REQUEST['s'] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Reads the `orderby` query var, validated against the allow-list.
	 *
	 * @return string
	 */
	private static function current_orderby(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sort on a manage_options-gated screen.
		if ( ! isset( $_REQUEST['orderby'] ) || ! \is_string( $_REQUEST['orderby'] ) ) {
			return 'created_at';
		}
		return sanitize_key( wp_unslash( $_REQUEST['orderby'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Reads the `order` query var, clamped to ASC|DESC.
	 *
	 * @return string
	 */
	private static function current_order(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sort on a manage_options-gated screen.
		if ( ! isset( $_REQUEST['order'] ) || ! \is_string( $_REQUEST['order'] ) ) {
			return 'DESC';
		}
		$raw = \strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $raw === 'ASC' ? 'ASC' : 'DESC';
	}

	/**
	 * Translates a status int to its admin label.
	 *
	 * @param int $status Status constant.
	 *
	 * @return string
	 */
	public static function format_status( int $status ): string {
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
	 * Formats a subscription's target for display.
	 *
	 * @param Subscription $row Subscription.
	 *
	 * @return string
	 */
	public static function format_target( Subscription $row ): string {
		if ( $row->target_type === 'post' ) {
			$post = get_post( $row->target_id );
			if ( $post instanceof WP_Post ) {
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
	 * Declares the columns rendered by `column_default()` / `column_<key>()`.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox" />',
			'email'        => __( 'Email', 'apermo-notify' ),
			'target'       => __( 'Target', 'apermo-notify' ),
			'status'       => __( 'Status', 'apermo-notify' ),
			'created_at'   => __( 'Created', 'apermo-notify' ),
			'confirmed_at' => __( 'Confirmed', 'apermo-notify' ),
		];
	}

	/**
	 * Declares which columns are sortable, with the default sort key + order.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return [
			'email'        => [ 'email', false ],
			'status'       => [ 'status', false ],
			'created_at'   => [ 'created_at', true ],
			'confirmed_at' => [ 'confirmed_at', false ],
		];
	}

	/**
	 * Declares bulk actions exposed by both selector dropdowns.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete permanently', 'apermo-notify' ),
		];
	}

	/**
	 * Renders the per-row checkbox bound to bulk delete.
	 *
	 * @phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 *
	 * @param array<array-key, mixed>|object $item Current row (Subscription).
	 *
	 * @return string
	 */
	public function column_cb( $item ): string {
		$id = $item instanceof Subscription ? $item->id : 0;
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $id ) . '" />';
	}

	/**
	 * Renders fields that don't have a dedicated column_<name> method.
	 *
	 * @phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 *
	 * @param array<array-key, mixed>|object $item        Current row (Subscription).
	 * @param string                         $column_name Column key.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		if ( ! $item instanceof Subscription ) {
			return '';
		}
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item->created_at );
			case 'confirmed_at':
				return esc_html( $item->confirmed_at ?? '—' );
			case 'status':
				return esc_html( self::format_status( $item->status ) );
			default:
				return '';
		}
	}

	/**
	 * Renders the email column and the row-action links.
	 *
	 * @param Subscription $item Current row.
	 *
	 * @return string
	 */
	protected function column_email( Subscription $item ): string {
		$delete_url = wp_nonce_url(
			add_query_arg(
				[
					'page'                 => SubscribersPage::SLUG,
					'apermo_notify_action' => 'delete',
					'id'                   => $item->id,
				],
				admin_url( 'admin.php' ),
			),
			SubscribersPage::DELETE_NONCE_ACTION . '_' . $item->id,
		);

		$actions = [
			'delete' => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete">'
				. esc_html__( 'Delete', 'apermo-notify' )
				. '</a>',
		];

		return '<strong>' . esc_html( $item->email ) . '</strong>'
			. $this->row_actions( $actions );
	}

	/**
	 * Renders the target column (post title with ID, or generic type/ID pair).
	 *
	 * @param Subscription $item Current row.
	 *
	 * @return string
	 */
	protected function column_target( Subscription $item ): string {
		return esc_html( self::format_target( $item ) );
	}

	/**
	 * Provides the status filter dropdown above the table.
	 *
	 * @phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
	 *
	 * @param string $which `top` or `bottom`.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( $which !== 'top' ) {
			return;
		}

		$current_value = self::current_status_filter() === null
			? ''
			: (string) self::current_status_filter();

		$options = [
			''                                         => __( 'All statuses', 'apermo-notify' ),
			(string) Subscription::STATUS_PENDING      => __( 'Pending', 'apermo-notify' ),
			(string) Subscription::STATUS_CONFIRMED    => __( 'Confirmed', 'apermo-notify' ),
			(string) Subscription::STATUS_UNSUBSCRIBED => __( 'Unsubscribed', 'apermo-notify' ),
		];

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="filter-by-status">'
			. esc_html__( 'Filter by status', 'apermo-notify' )
			. '</label>';
		echo '<select name="status" id="filter-by-status">';
		foreach ( $options as $value => $label ) {
			$selected = (string) $value === $current_value ? ' selected' : '';
			echo '<option value="' . esc_attr( (string) $value ) . '"' . esc_attr( $selected ) . '>'
				. esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
		submit_button(
			__( 'Filter', 'apermo-notify' ),
			'',
			'filter_action',
			false,
		);
		echo '</div>';
	}

	/**
	 * Populates `$items`, computes pagination, and wires column metadata.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'email' ];

		$status  = self::current_status_filter();
		$search  = self::current_search();
		$page    = $this->get_pagenum();
		$orderby = self::current_orderby();
		$order   = self::current_order();
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		$this->items = Repository::paginate(
			self::PER_PAGE,
			$offset,
			$status,
			$search,
			$orderby,
			$order,
		);

		$total = Repository::count_total( $status, $search );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) \ceil( $total / self::PER_PAGE ),
			],
		);
	}
}
