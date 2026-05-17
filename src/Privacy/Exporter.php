<?php

declare(strict_types=1);

namespace Apermo\Notify\Privacy;

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use Apermo\Notify\Subscription\Token;

/**
 * Registers a WordPress personal-data exporter that returns the visitor's subscription rows.
 */
final class Exporter {

	/**
	 * Exporter slug used by WordPress.
	 */
	public const SLUG = 'apermo-notify';

	/**
	 * Adds the apermo-notify exporter to WP's exporter registry.
	 *
	 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters Registry.
	 *
	 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[ self::SLUG ] = [
			'exporter_friendly_name' => __( 'Subscription updates', 'apermo-notify' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Returns this email address's subscription rows in WP's exporter shape.
	 *
	 * @param string $email Visitor email being exported.
	 * @param int    $page  Pagination cursor (we return everything on page 1).
	 *
	 * @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		unset( $page );

		$normalized = Token::normalize_email( $email );
		$rows       = self::find_by_email( $normalized );

		$data = [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => self::SLUG,
				'group_label' => __( 'Subscription updates', 'apermo-notify' ),
				'item_id'     => self::SLUG . '-' . (string) $row->id,
				'data'        => [
					[
						'name'  => __( 'Target', 'apermo-notify' ),
						'value' => $row->target_type . ' #' . (string) $row->target_id,
					],
					[
						'name'  => __( 'Status', 'apermo-notify' ),
						'value' => (string) $row->status,
					],
					[
						'name'  => __( 'Created', 'apermo-notify' ),
						'value' => $row->created_at,
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Loads all rows for an email (any status).
	 *
	 * @param string $email Normalized email.
	 *
	 * @return array<int, Subscription>
	 */
	private static function find_by_email( string $email ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s',
				Repository::table(),
				$email,
			),
			\ARRAY_A,
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ Subscription::class, 'from_row' ], $rows );
	}

	/**
	 * Wires the exporter into WP's privacy registry.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
	}
}
