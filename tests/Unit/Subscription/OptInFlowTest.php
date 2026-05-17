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
		];

		return \in_array( $hook, $valid_hooks, true )
			&& $callback[0] === $flow
			&& \in_array( $callback[1], [ 'handle_confirm', 'handle_unsubscribe' ], true );
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
	 * Confirms register() wires admin_post and admin_post_nopriv hooks for both actions.
	 *
	 * @return void
	 */
	public function test_register_wires_confirm_and_unsubscribe_endpoints(): void {
		$flow = new OptInFlow();

		Functions\expect( 'add_action' )
			->times( 4 )
			->withArgs( static fn ( string $hook, array $callback ): bool => self::is_optin_hook( $flow, $hook, $callback ) );

		$flow->register();
	}
}
