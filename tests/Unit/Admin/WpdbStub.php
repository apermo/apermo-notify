<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Admin;

/**
 * Provides a minimal $wpdb stub for unit tests exercising Activation::drop_all().
 */
final class WpdbStub {

	/**
	 * Mirrors $wpdb->prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Mirrors $wpdb->rows_affected.
	 *
	 * @var int
	 */
	public int $rows_affected = 0;

	/**
	 * Records a raw SQL query and reports success.
	 *
	 * @param string $sql Raw SQL.
	 *
	 * @return int
	 */
	public function query( string $sql ): int {
		unset( $sql );
		$this->rows_affected = 1;
		return 1;
	}

	/**
	 * Returns the SQL unchanged — sufficient for the drop_table code path.
	 *
	 * @param string $sql      Raw SQL.
	 * @param mixed  ...$args  Placeholders (ignored).
	 *
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		unset( $args );
		return $sql;
	}
}
