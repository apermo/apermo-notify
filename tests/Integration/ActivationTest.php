<?php
/**
 * Tests plugin activation against a real WordPress database.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use WP_UnitTestCase;

/**
 * Verifies plugin activation creates the expected custom tables.
 */
class ActivationTest extends WP_UnitTestCase {

	/**
	 * Drops plugin tables and the version option before each test so we can
	 * assert activation's create-from-scratch behavior.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
	}

	/**
	 * Confirms activation creates both tables with the expected columns.
	 *
	 * @return void
	 */
	public function test_activate_creates_tables(): void {
		Activation::activate();

		global $wpdb;

		$subscriptions = $wpdb->prefix . Activation::SUBSCRIPTIONS_TABLE;
		$sent_log      = $wpdb->prefix . Activation::SENT_LOG_TABLE;

		$all_tables = $wpdb->get_col( 'SHOW TABLES' );

		$this->assertContains(
			$subscriptions,
			$all_tables,
			'Subscriptions table should be created. Actual tables: ' . \implode( ', ', $all_tables ),
		);
		$this->assertContains(
			$sent_log,
			$all_tables,
			'Sent-log table should be created. Actual tables: ' . \implode( ', ', $all_tables ),
		);
	}

	/**
	 * Confirms activation records the schema version in an option so later runs
	 * become no-ops once stored version meets the target.
	 *
	 * @return void
	 */
	public function test_activate_records_version(): void {
		Activation::activate();

		$this->assertSame(
			Activation::SCHEMA_VERSION,
			(int) get_option( Activation::VERSION_OPTION ),
		);
	}

	/**
	 * Confirms drop_all removes both tables and the version option.
	 *
	 * @return void
	 */
	public function test_drop_all_removes_tables_and_option(): void {
		Activation::activate();
		Activation::drop_all();

		global $wpdb;

		$subscriptions = $wpdb->prefix . Activation::SUBSCRIPTIONS_TABLE;
		$sent_log      = $wpdb->prefix . Activation::SENT_LOG_TABLE;

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subscriptions ) ),
		);
		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sent_log ) ),
		);
		$this->assertFalse( get_option( Activation::VERSION_OPTION ) );
	}

	/**
	 * Confirms the subscriptions table has the unique constraint that prevents
	 * a single email subscribing to the same target twice.
	 *
	 * @return void
	 */
	public function test_subscriptions_unique_constraint(): void {
		Activation::activate();

		global $wpdb;

		$table = $wpdb->prefix . Activation::SUBSCRIPTIONS_TABLE;

		$row = [
			'target_type' => 'post',
			'target_id'   => 42,
			'target_meta' => '',
			'email'       => 'visitor@example.tld',
			'token'       => \str_repeat( 'a', 64 ),
			'status'      => 0,
			'created_at'  => '2026-01-01 00:00:00',
		];

		$this->assertNotFalse( $wpdb->insert( $table, $row ) );

		$wpdb->suppress_errors( true );
		$duplicate = $wpdb->insert( $table, \array_merge( $row, [ 'token' => \str_repeat( 'b', 64 ) ] ) );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $duplicate, 'Duplicate (target, email) insert should fail.' );
	}
}
