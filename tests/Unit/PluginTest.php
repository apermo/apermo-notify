<?php

declare(strict_types=1);

namespace Plugin_Name\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plugin_Name\Plugin;

/**
 * Tests for the Plugin class.
 */
class PluginTest extends TestCase {

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
			->with( $file, [ Plugin::class, 'activate' ] );

		Functions\expect( 'register_deactivation_hook' )
			->once()
			->with( $file, [ Plugin::class, 'deactivate' ] );

		Functions\expect( 'add_action' )
			->once()
			->with( 'plugins_loaded', [ Plugin::class, 'boot' ] );

		Plugin::init( $file );
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

		Plugin::init( $file );

		$this->assertSame( $file, Plugin::file() );
	}

	/**
	 * Verifies activate can be called without error.
	 *
	 * @return void
	 */
	public function test_activate(): void {
		Plugin::activate();
		$this->assertTrue( true );
	}

	/**
	 * Verifies deactivate can be called without error.
	 *
	 * @return void
	 */
	public function test_deactivate(): void {
		Plugin::deactivate();
		$this->assertTrue( true );
	}

	/**
	 * Verifies boot can be called without error.
	 *
	 * @return void
	 */
	public function test_boot(): void {
		Plugin::boot();
		$this->assertTrue( true );
	}
}
