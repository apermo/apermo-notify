<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit;

use Apermo\Notify\I18n;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Traduttore Registry project registration.
 *
 * The library autoloads its `Required\Traduttore_Registry\add_project()`
 * function eagerly (composer `files` autoload), so the function is always
 * defined in the test process and cannot be redefined by Brain Monkey. These
 * tests therefore drive the real `add_project()` and assert on the WordPress
 * hooks it wires.
 */
final class I18nTest extends TestCase {

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
	 * Confirms register() hooks the registration on init.
	 *
	 * @return void
	 */
	public function test_register_hooks_init(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			},
		);

		( new I18n() )->register();

		$this->assertContains( 'init', $hooks );
	}

	/**
	 * Confirms the registry library is bundled so the runtime guard resolves.
	 *
	 * @return void
	 */
	public function test_registry_library_is_available(): void {
		$this->assertTrue(
			\function_exists( 'Required\Traduttore_Registry\add_project' ),
			'The Traduttore Registry library must ship with the plugin.',
		);
	}

	/**
	 * Confirms add_project() registers the project with the registry, wiring
	 * the `translations_api` short-circuit for the apermo-notify slug.
	 *
	 * @return void
	 */
	public function test_adds_project_when_library_present(): void {
		$filters = [];
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$filters ): bool {
				$filters[] = $hook;

				return true;
			},
		);

		( new I18n() )->add_project();

		$this->assertContains( 'translations_api', $filters );
		$this->assertContains( 'site_transient_update_plugins', $filters );
	}
}
