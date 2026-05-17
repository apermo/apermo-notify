<?php

declare(strict_types=1);

namespace Apermo\Notify\Privacy;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Token;

/**
 * Registers a WordPress personal-data eraser that deletes the visitor's subscription rows.
 */
final class Eraser {

	/**
	 * Eraser slug used by WordPress.
	 */
	public const SLUG = 'apermo-notify';

	/**
	 * Adds the apermo-notify eraser to WP's eraser registry.
	 *
	 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers Registry.
	 *
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[ self::SLUG ] = [
			'eraser_friendly_name' => __( 'Subscription updates', 'apermo-notify' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Deletes all subscription rows for the given email and reports the result
	 * in WordPress's expected shape.
	 *
	 * @param string $email Visitor email being erased.
	 * @param int    $page  Pagination cursor (we delete everything on page 1).
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$normalized = Token::normalize_email( $email );

		global $wpdb;
		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Repository::table(),
			[ 'email' => $normalized ],
			[ '%s' ],
		);

		return [
			'items_removed'  => \is_int( $deleted ) && $deleted > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Wires the eraser into WP's privacy registry.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
	}
}
