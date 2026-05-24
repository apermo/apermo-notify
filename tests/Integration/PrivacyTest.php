<?php
/**
 * Tests the privacy exporter and eraser against a real WordPress instance.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Privacy\Eraser;
use Apermo\Notify\Privacy\Exporter;
use Apermo\Notify\Subscription\Repository;
use WP_UnitTestCase;

/**
 * Verifies WP privacy hooks return the expected payloads against real rows.
 */
final class PrivacyTest extends WP_UnitTestCase {

	/**
	 * Resets tables before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();
	}

	/**
	 * Confirms the exporter returns one item per subscription row for the given email.
	 *
	 * @return void
	 */
	public function test_exporter_returns_rows_for_email(): void {
		Repository::create_pending( 'post', 1, '', 'visitor@example.tld' );
		Repository::create_pending( 'post', 2, '', 'visitor@example.tld' );
		Repository::create_pending( 'post', 3, '', 'other@example.tld' );

		$response = Exporter::export( 'visitor@example.tld' );

		$this->assertTrue( $response['done'] );
		$this->assertCount( 2, $response['data'] );
	}

	/**
	 * Confirms the eraser deletes rows matching the email and reports items_removed.
	 *
	 * @return void
	 */
	public function test_eraser_deletes_rows_by_email(): void {
		Repository::create_pending( 'post', 1, '', 'visitor@example.tld' );
		Repository::create_pending( 'post', 2, '', 'other@example.tld' );

		$response = Eraser::erase( 'visitor@example.tld' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['done'] );

		global $wpdb;
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email = %s',
				Repository::table(),
				'visitor@example.tld',
			),
		);

		$this->assertSame( 0, $remaining );
	}
}
