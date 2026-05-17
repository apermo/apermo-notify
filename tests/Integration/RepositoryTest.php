<?php
/**
 * Tests the Repository CRUD methods against a real database.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use Apermo\Notify\Subscription\Token;
use WP_UnitTestCase;

/**
 * Exercises Repository against the real custom tables created by activation.
 */
final class RepositoryTest extends WP_UnitTestCase {

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
	 * Sets up clean tables for every test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();
	}

	/**
	 * Confirms a fresh subscription is inserted and findable by token.
	 *
	 * @return void
	 */
	public function test_create_pending_inserts_row(): void {
		$id = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );

		$this->assertGreaterThan( 0, $id );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Repository::table(), $id ),
			\ARRAY_A,
		);

		$this->assertIsArray( $row );
		$this->assertSame( 'visitor@example.tld', $row['email'] );
		$this->assertSame( (string) Subscription::STATUS_PENDING, (string) $row['status'] );
		$this->assertSame( 64, \strlen( (string) $row['token'] ) );
	}

	/**
	 * Confirms the unique constraint prevents duplicate (target, email) inserts.
	 *
	 * @return void
	 */
	public function test_create_pending_returns_zero_for_duplicate(): void {
		Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );
		$second = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );

		$this->assertSame( 0, $second );
	}

	/**
	 * Confirms email normalization on insert.
	 *
	 * @return void
	 */
	public function test_create_pending_normalizes_email(): void {
		$id = Repository::create_pending( 'post', 42, '', '  Visitor@Example.TLD ' );

		global $wpdb;
		$email = $wpdb->get_var(
			$wpdb->prepare( 'SELECT email FROM %i WHERE id = %d', Repository::table(), $id ),
		);

		$this->assertSame( 'visitor@example.tld', $email );
	}

	/**
	 * Confirms confirm() flips status and stamps confirmed_at.
	 *
	 * @return void
	 */
	public function test_confirm_transitions_pending_to_confirmed(): void {
		$id    = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );
		$token = self::token_for( $id );

		$confirmed = Repository::confirm( $token );

		$this->assertNotNull( $confirmed );
		$this->assertSame( Subscription::STATUS_CONFIRMED, $confirmed->status );
		$this->assertNotNull( $confirmed->confirmed_at );
	}

	/**
	 * Confirms confirm() ignores already-confirmed rows.
	 *
	 * @return void
	 */
	public function test_confirm_returns_null_for_non_pending(): void {
		$id    = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );
		$token = self::token_for( $id );
		Repository::confirm( $token );

		$this->assertNull( Repository::confirm( $token ) );
	}

	/**
	 * Confirms unsubscribe() flips status to unsubscribed.
	 *
	 * @return void
	 */
	public function test_unsubscribe_flips_status(): void {
		$id    = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );
		$token = self::token_for( $id );
		Repository::confirm( $token );

		$this->assertTrue( Repository::unsubscribe( $token ) );

		$row = Repository::find_by_token( $token );
		$this->assertNotNull( $row );
		$this->assertSame( Subscription::STATUS_UNSUBSCRIBED, $row->status );
	}

	/**
	 * Confirms find_confirmed_for_target returns only confirmed rows for the target.
	 *
	 * @return void
	 */
	public function test_find_confirmed_for_target(): void {
		$first  = Repository::create_pending( 'post', 42, '', 'a@example.tld' );
		$second = Repository::create_pending( 'post', 42, '', 'b@example.tld' );
		Repository::create_pending( 'post', 99, '', 'c@example.tld' );

		Repository::confirm( self::token_for( $first ) );
		Repository::confirm( self::token_for( $second ) );

		$found = Repository::find_confirmed_for_target( 'post', 42 );

		$this->assertCount( 2, $found );
		$emails = \array_map( static fn ( Subscription $row ): string => $row->email, $found );
		\sort( $emails );
		$this->assertSame( [ 'a@example.tld', 'b@example.tld' ], $emails );
	}

	/**
	 * Confirms count_confirmed_for_target matches find_confirmed_for_target.
	 *
	 * @return void
	 */
	public function test_count_confirmed_for_target(): void {
		$first = Repository::create_pending( 'post', 42, '', 'a@example.tld' );
		Repository::create_pending( 'post', 42, '', 'pending@example.tld' );
		Repository::confirm( self::token_for( $first ) );

		$this->assertSame( 1, Repository::count_confirmed_for_target( 'post', 42 ) );
	}

	/**
	 * Confirms purge_unconfirmed_older_than deletes only old pending rows.
	 *
	 * @return void
	 */
	public function test_purge_unconfirmed_older_than(): void {
		$stale = Repository::create_pending( 'post', 1, '', 'stale@example.tld' );

		global $wpdb;
		$wpdb->update(
			Repository::table(),
			[ 'created_at' => '2020-01-01 00:00:00' ],
			[ 'id' => $stale ],
		);

		$fresh = Repository::create_pending( 'post', 2, '', 'fresh@example.tld' );
		$kept  = Repository::create_pending( 'post', 3, '', 'kept@example.tld' );
		Repository::confirm( self::token_for( $kept ) );

		$deleted = Repository::purge_unconfirmed_older_than( '2024-01-01 00:00:00' );

		$this->assertSame( 1, $deleted );
		$this->assertNotNull( Repository::find_by_token( self::token_for( $fresh ) ) );
	}

	/**
	 * Confirms verifying a tampered token returns false.
	 *
	 * @return void
	 */
	public function test_token_verify_catches_tampering(): void {
		$id    = Repository::create_pending( 'post', 42, '', 'visitor@example.tld' );
		$token = self::token_for( $id );

		$this->assertTrue( Token::verify( $token, $token ) );
		$this->assertFalse( Token::verify( $token, \str_repeat( 'f', 64 ) ) );
	}
}
