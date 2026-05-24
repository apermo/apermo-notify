<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Apermo_Notify\* import gets rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Apermo\Notify\Main;

/**
 * Tests for the Main class.
 */
class MainTest extends TestCase {

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
	 * Verifies init registers activation and deactivation hooks.
	 *
	 * @return void
	 */
	public function test_init_registers_hooks(): void {
		$file = '/tmp/plugin.php';

		Functions\expect( 'register_activation_hook' )
			->once()
			->with( $file, [ Main::class, 'activate' ] );

		Functions\expect( 'register_deactivation_hook' )
			->once()
			->with( $file, [ Main::class, 'deactivate' ] );

		Functions\expect( 'add_action' )
			->once()
			->with( 'plugins_loaded', [ Main::class, 'boot' ] );

		Main::init( $file );
	}

	/**
	 * Verifies init stores the plugin file path.
	 *
	 * @return void
	 */
	public function test_init_stores_file_path(): void {
		$file = '/tmp/plugin.php';

		Functions\stubs(
			[
				'register_activation_hook',
				'register_deactivation_hook',
				'add_action',
			],
		);

		Main::init( $file );

		$this->assertSame( $file, Main::file() );
	}

	/**
	 * Verifies deactivate can be called without error.
	 *
	 * Activation's smoke test was moved to tests/Integration/ActivationTest.php
	 * once the real implementation gained DB side effects.
	 *
	 * @return void
	 */
	public function test_deactivate(): void {
		Functions\stubs( [ 'wp_clear_scheduled_hook' ] );

		Main::deactivate();
		$this->assertTrue( true );
	}

	/**
	 * Verifies boot can be called without error.
	 *
	 * @return void
	 */
	public function test_boot(): void {
		// OPT-IN: confirm-deactivate — delete this stub if you declined the example.
		Functions\stubs(
			[
				'is_admin'   => false,
				'add_action' => null,
				'add_filter' => null,
			],
		);

		Main::boot();
		$this->assertTrue( true );
	}
}
