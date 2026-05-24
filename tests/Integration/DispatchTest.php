<?php
/**
 * Tests the dispatch pipeline end-to-end against a real WordPress instance.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

namespace Apermo\Notify\Tests\Integration;

use Apermo\Notify\Activation;
use Apermo\Notify\Dispatch\Dispatcher;
use Apermo\Notify\Dispatch\PostHooks;
use Apermo\Notify\Dispatch\SentLog;
use Apermo\Notify\Subscription\Repository;
use WP_UnitTestCase;

/**
 * Exercises publish/update dispatch on the real WP hook flow.
 */
final class DispatchTest extends WP_UnitTestCase {

	/**
	 * Count of wp_mail invocations during the current test.
	 *
	 * @var int
	 */
	private int $mail_count = 0;

	/**
	 * Sets up clean tables, a single confirmed subscriber, and a wp_mail spy.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Activation::drop_all();
		Activation::activate();

		$this->mail_count = 0;

		add_filter(
			'pre_wp_mail',
			function ( $result ): bool {
				unset( $result );
				$this->mail_count++;
				return true;
			},
		);

		remove_all_filters( 'apermo_notify_should_send' );
	}

	/**
	 * Tears down our pre_wp_mail spy.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	/**
	 * Confirms publishing a draft fires one email per confirmed subscriber.
	 *
	 * @return void
	 */
	public function test_publishing_a_post_dispatches_to_confirmed_subscribers(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->subscribe_and_confirm( $post_id, 'a@example.tld' );
		$this->subscribe_and_confirm( $post_id, 'b@example.tld' );

		( new PostHooks() )->register();

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			],
		);

		$this->assertSame( 2, $this->mail_count );
	}

	/**
	 * Confirms a second publish of the same post does not re-dispatch.
	 *
	 * @return void
	 */
	public function test_second_publish_is_deduped_via_sent_log(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$sub_id  = $this->subscribe_and_confirm( $post_id, 'visitor@example.tld' );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		Dispatcher::dispatch( $post, 'publish' );
		$first = $this->mail_count;
		Dispatcher::dispatch( $post, 'publish' );

		$this->assertSame( $first, $this->mail_count );
		$this->assertTrue( SentLog::has_sent( $sub_id, $post_id, 'publish' ) );
	}

	/**
	 * Confirms that a routine post update no longer auto-dispatches — the
	 * notification path is now an explicit, opt-in REST call fired from the
	 * editor snackbar.
	 *
	 * @return void
	 */
	public function test_update_does_not_auto_dispatch(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->subscribe_and_confirm( $post_id, 'visitor@example.tld' );

		( new PostHooks() )->register();

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'silent update',
			],
		);

		$this->assertSame( 0, $this->mail_count );
	}

	/**
	 * Confirms direct calls to Dispatcher::dispatch( …, 'update' ) — the path
	 * taken by the REST endpoint behind the editor snackbar — still send.
	 *
	 * @return void
	 */
	public function test_explicit_update_dispatch_sends(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->subscribe_and_confirm( $post_id, 'visitor@example.tld' );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		Dispatcher::dispatch( $post, 'update' );

		$this->assertSame( 1, $this->mail_count );
	}

	/**
	 * Confirms the `apermo_notify_should_send` filter can veto a dispatch.
	 *
	 * @return void
	 */
	public function test_should_send_filter_can_veto(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->subscribe_and_confirm( $post_id, 'visitor@example.tld' );

		add_filter( 'apermo_notify_should_send', '__return_false' );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		Dispatcher::dispatch( $post, 'publish' );

		$this->assertSame( 0, $this->mail_count );

		remove_filter( 'apermo_notify_should_send', '__return_false' );
	}

	/**
	 * Creates a pending subscription and immediately confirms it.
	 *
	 * @param int    $post_id Post the subscription targets.
	 * @param string $email   Subscriber email.
	 *
	 * @return int Subscription ID.
	 */
	private function subscribe_and_confirm( int $post_id, string $email ): int {
		$id = Repository::create_pending( 'post', $post_id, '', $email );
		$this->assertGreaterThan( 0, $id );

		global $wpdb;
		$token = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT token FROM %i WHERE id = %d',
				Repository::table(),
				$id,
			),
		);

		Repository::confirm( $token );

		return $id;
	}
}
