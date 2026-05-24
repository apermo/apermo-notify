<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Subscription;

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Subscription\OptInFlow;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests OptInFlow hook registration.
 */
final class OptInFlowTest extends TestCase {

	/**
	 * Validates a single add_action call against the expected hooks and methods.
	 *
	 * @param OptInFlow         $flow     The registered OptInFlow instance.
	 * @param string            $hook     The hook name passed to add_action.
	 * @param array<int, mixed> $callback The callback array passed to add_action.
	 *
	 * @return bool
	 */
	private static function is_optin_hook( OptInFlow $flow, string $hook, array $callback ): bool {
		$valid_hooks = [
			'admin_post_nopriv_' . Mailer::ACTION_CONFIRM,
			'admin_post_' . Mailer::ACTION_CONFIRM,
			'admin_post_nopriv_' . Mailer::ACTION_UNSUBSCRIBE,
			'admin_post_' . Mailer::ACTION_UNSUBSCRIBE,
			'admin_post_nopriv_' . Mailer::ACTION_KEEP_ALIVE,
			'admin_post_' . Mailer::ACTION_KEEP_ALIVE,
			'template_redirect',
		];

		$valid_methods = [ 'handle_confirm', 'handle_unsubscribe', 'handle_keep_alive', 'maybe_handle_post_action' ];

		return \in_array( $hook, $valid_hooks, true )
			&& $callback[0] === $flow
			&& \in_array( $callback[1], $valid_methods, true );
	}

	/**
	 * Sets up Brain Monkey.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Confirms register() wires the admin-post endpoints (confirm,
	 * unsubscribe, keep-alive) plus the template_redirect dispatcher
	 * that catches the post-permalink action URLs.
	 *
	 * @return void
	 */
	public function test_register_wires_confirm_unsubscribe_keepalive_and_template_redirect(): void {
		$flow = new OptInFlow();

		Functions\expect( 'add_action' )
			->times( 7 )
			->withArgs( static fn ( string $hook, array $callback ): bool => self::is_optin_hook( $flow, $hook, $callback ) );

		$flow->register();
	}
}
