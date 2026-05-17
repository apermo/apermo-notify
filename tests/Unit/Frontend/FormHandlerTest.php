<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Frontend;

use Apermo\Notify\Frontend\FormHandler;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests FormHandler hook registration.
 */
final class FormHandlerTest extends TestCase {

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
		$_POST   = [];
		$_GET    = [];
		$_SERVER = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Confirms register() wires admin_post and admin_post_nopriv hooks.
	 *
	 * @return void
	 */
	public function test_register_wires_both_admin_post_actions(): void {
		$handler = new FormHandler();

		Functions\expect( 'add_action' )
			->twice()
			->withArgs(
				static fn ( string $hook, array $callback ): bool =>
					\in_array(
						$hook,
						[
							'admin_post_nopriv_' . FormHandler::ACTION,
							'admin_post_' . FormHandler::ACTION,
						],
						true,
					) && $callback[0] === $handler && $callback[1] === 'handle',
			);

		$handler->register();
	}
}
