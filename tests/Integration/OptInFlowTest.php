<?php
/**
 * Tests the OptInFlow against a real WordPress instance.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Subscription\OptInFlow;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_UnitTestCase;
use WPDieException;

/**
 * Exercises the confirm/unsubscribe handlers end-to-end.
 */
final class OptInFlowTest extends WP_UnitTestCase {

	/**
	 * Reads the token assigned to an inserted row.
	 *
	 * @param int $id Subscription ID.
	 *
	 * @return string
	 */
	private static function token_for( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT token FROM %i WHERE id = %d', Repository::table(), $id ),
		);
	}

	/**
	 * Resets tables before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();
		$_GET = [];
	}

	/**
	 * Confirms handle_confirm transitions a pending row and exits via wp_die.
	 *
	 * @return void
	 */
	public function test_handle_confirm_marks_subscription_confirmed(): void {
		$id    = Repository::create_pending( 'post', 1, '', 'visitor@example.tld' );
		$token = self::token_for( $id );

		$_GET = [ 'token' => $token ];

		$this->expectException( WPDieException::class );

		try {
			( new OptInFlow() )->handle_confirm();
		} finally {
			$row = Repository::find_by_token( $token );
			$this->assertNotNull( $row );
			$this->assertSame( Subscription::STATUS_CONFIRMED, $row->status );
		}
	}

	/**
	 * Confirms an invalid token returns a 400 wp_die.
	 *
	 * @return void
	 */
	public function test_handle_confirm_rejects_missing_token(): void {
		$_GET = [];

		$this->expectException( WPDieException::class );

		( new OptInFlow() )->handle_confirm();
	}

	/**
	 * Confirms handle_unsubscribe flips status to unsubscribed.
	 *
	 * @return void
	 */
	public function test_handle_unsubscribe_marks_unsubscribed(): void {
		$id    = Repository::create_pending( 'post', 1, '', 'visitor@example.tld' );
		$token = self::token_for( $id );
		Repository::confirm( $token );

		$_GET = [ 'token' => $token ];

		$this->expectException( WPDieException::class );

		try {
			( new OptInFlow() )->handle_unsubscribe();
		} finally {
			$row = Repository::find_by_token( $token );
			$this->assertNotNull( $row );
			$this->assertSame( Subscription::STATUS_UNSUBSCRIBED, $row->status );
		}
	}
}
