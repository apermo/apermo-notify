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
use RuntimeException;
use WP_UnitTestCase;

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
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();

		$this->post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$_POST                  = [];
		$_GET                   = [];
		$_REQUEST               = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		// `FormHandler::handle()` ends in `wp_safe_redirect( … ); exit;`. The
		// bare `exit` would halt PHPUnit, so we intercept `wp_redirect` to throw
		// before the header is sent and before `exit` executes.
		add_filter(
			'wp_redirect',
			static function ( string $location ): never {
				throw new RuntimeException( esc_html( 'redirect:' . $location ) );
			},
			1,
		);
	}

	/**
	 * Tears down the redirect interceptor.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	/**
	 * Confirms a valid submission creates a pending subscription and redirects.
	 *
	 * @return void
	 */
	public function test_valid_submission_creates_pending_row(): void {
		$nonce = wp_create_nonce( FormHandler::NONCE_ACTION );
		$_POST = [
			'post_id'               => (string) $this->post_id,
			'email'                 => 'visitor@example.tld',
			'apermo_notify_consent' => '1',
			'_wpnonce'              => $nonce,
		];
		// `check_admin_referer()` reads `$_REQUEST['_wpnonce']`, not `$_POST['_wpnonce']`.
		// PHP only mirrors $_POST into $_REQUEST when `request_order` includes it,
		// which CLI/PHPUnit does not do automatically — set it explicitly.
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			( new FormHandler() )->handle();
			$this->fail( 'FormHandler::handle() should have redirected.' );
		} catch ( RuntimeException $caught ) {
			$this->assertStringStartsWith( 'redirect:', $caught->getMessage() );
		}

		$this->assertSame( 1, $this->pending_count() );
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
		$nonce                  = wp_create_nonce( FormHandler::NONCE_ACTION );
		$_POST                  = [
			'post_id'               => (string) $this->post_id,
			'email'                 => 'visitor@example.tld',
			'apermo_notify_consent' => '1',
			'_wpnonce'              => $nonce,
		];
		$_REQUEST['_wpnonce']   = $nonce;

		try {
			( new FormHandler() )->handle();
			$this->fail( 'FormHandler::handle() should have redirected.' );
		} catch ( RuntimeException $caught ) {
			$this->assertStringContainsString( 'apermo_notify_result', $caught->getMessage() );
		}

		$this->assertSame( 1, $this->pending_count() );
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
