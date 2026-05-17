<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Dispatch;

use Apermo\Notify\Dispatch\PostHooks;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests PostHooks hook registration and the early-exit branches of its callbacks.
 */
final class PostHooksTest extends TestCase {

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
	 * Confirms register() wires both lifecycle hooks.
	 *
	 * @return void
	 */
	public function test_register_wires_lifecycle_hooks(): void {
		Functions\expect( 'add_action' )
			->twice()
			->withArgs(
				static fn ( string $hook ): bool => \in_array( $hook, [ 'transition_post_status', 'post_updated' ], true ),
			);

		( new PostHooks() )->register();
	}
}
