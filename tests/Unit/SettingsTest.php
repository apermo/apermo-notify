<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit;

use Apermo\Notify\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Settings option reader/writer.
 */
final class SettingsTest extends TestCase {

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
	 * Confirms defaults() returns the documented MVP defaults.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$defaults = Settings::defaults();
		$this->assertSame( [ 'post' ], $defaults['enabled_post_types'] );
		$this->assertTrue( $defaults['auto_append_default'] );
	}

	/**
	 * Confirms all() merges defaults over a stored option, missing keys included.
	 *
	 * @return void
	 */
	public function test_all_merges_defaults(): void {
		Functions\when( 'get_option' )->justReturn( [ 'enabled_post_types' => [ 'post', 'page' ] ] );

		$result = Settings::all();
		$this->assertSame( [ 'post', 'page' ], $result['enabled_post_types'] );
		$this->assertTrue( $result['auto_append_default'] );
	}

	/**
	 * Confirms all() recovers from a non-array stored option.
	 *
	 * @return void
	 */
	public function test_all_recovers_from_non_array_option(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$result = Settings::all();
		$this->assertSame( [ 'post' ], $result['enabled_post_types'] );
		$this->assertTrue( $result['auto_append_default'] );
	}

	/**
	 * Confirms save() sanitizes types and persists the toggle.
	 *
	 * @return void
	 */
	public function test_save_sanitizes_and_persists(): void {
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\expect( 'update_option' )
			->once()
			->with(
				Settings::OPTION,
				[
					'enabled_post_types'  => [ 'post', 'page' ],
					'auto_append_default' => true,
				],
				false,
			);

		Settings::save(
			[
				'enabled_post_types'  => [ 'post', 'page', '', 'post' ],
				'auto_append_default' => '1',
			],
		);
	}

	/**
	 * Confirms save() treats missing checkbox as unchecked.
	 *
	 * @return void
	 */
	public function test_save_missing_auto_append_default_is_false(): void {
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\expect( 'update_option' )
			->once()
			->with(
				Settings::OPTION,
				[
					'enabled_post_types'  => [ 'post' ],
					'auto_append_default' => false,
				],
				false,
			);

		Settings::save( [ 'enabled_post_types' => [ 'post' ] ] );
	}
}
