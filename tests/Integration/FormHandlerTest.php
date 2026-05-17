<?php
/**
 * Tests the FormHandler against a real WordPress instance.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Frontend\FormHandler;
use Apermo\Notify\Subscription\Repository;
use WP_UnitTestCase;
use WPDieException;

/**
 * Drives the subscribe form's POST handler against the real DB.
 */
final class FormHandlerTest extends WP_UnitTestCase {

	/**
	 * Post ID created for each test.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Resets state and creates a post per test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();

		$this->post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$_POST                  = [];
		$_GET                   = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	/**
	 * Confirms a valid submission creates a pending subscription and redirects.
	 *
	 * @return void
	 */
	public function test_valid_submission_creates_pending_row(): void {
		$_POST = [
			'post_id'  => (string) $this->post_id,
			'email'    => 'visitor@example.tld',
			'_wpnonce' => wp_create_nonce( FormHandler::NONCE_ACTION ),
		];

		$this->expectException( WPDieException::class );

		try {
			( new FormHandler() )->handle();
		} finally {
			$this->assertSame( 1, Repository::count_confirmed_for_target( 'post', $this->post_id ) + $this->pending_count() );
		}
	}

	/**
	 * Confirms a duplicate email returns the duplicate redirect path.
	 *
	 * @return void
	 */
	public function test_duplicate_submission_is_rejected_by_unique_constraint(): void {
		Repository::create_pending( 'post', $this->post_id, '', 'visitor@example.tld' );

		// Different IP so throttle does not interfere.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$_POST                  = [
			'post_id'  => (string) $this->post_id,
			'email'    => 'visitor@example.tld',
			'_wpnonce' => wp_create_nonce( FormHandler::NONCE_ACTION ),
		];

		$this->expectException( WPDieException::class );

		try {
			( new FormHandler() )->handle();
		} finally {
			$this->assertSame( 1, $this->pending_count() );
		}
	}

	/**
	 * Counts pending rows for the test post.
	 *
	 * @return int
	 */
	private function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE target_type = %s AND target_id = %d AND status = %d',
				Repository::table(),
				'post',
				$this->post_id,
				0,
			),
		);
	}
}
