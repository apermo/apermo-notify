<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Editor;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Apermo_Notify\* imports get rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Apermo\Notify\Editor\UpdateDialog;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests UpdateDialog hook registration.
 */
final class UpdateDialogTest extends TestCase {

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
	 * Confirms register() wires the editor enqueue and the REST init hooks.
	 *
	 * @return void
	 */
	public function test_register_wires_editor_and_rest_hooks(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			},
		);

		( new UpdateDialog() )->register();

		$this->assertContains( 'enqueue_block_editor_assets', $hooks );
		$this->assertContains( 'rest_api_init', $hooks );
	}
}
