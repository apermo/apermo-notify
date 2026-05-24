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
		Functions\when( '__' )->returnArg();
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
		$this->assertNotSame( '', $defaults['subscription_text'] );
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
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\expect( 'update_option' )
			->once()
			->with(
				Settings::OPTION,
				[
					'enabled_post_types'  => [ 'post', 'page' ],
					'auto_append_default' => true,
					'subscription_text'   => 'Hello visitors',
					'stale_after_months'  => 12,
					'prune_mode'          => Settings::PRUNE_MODE_DELETE,
					'stale_grace_days'    => 14,
					'manage_page_id'      => 42,
				],
				false,
			);

		Settings::save(
			[
				'enabled_post_types'  => [ 'post', 'page', '', 'post' ],
				'auto_append_default' => '1',
				'subscription_text'   => 'Hello visitors',
				'stale_after_months'  => '12',
				'prune_mode'          => Settings::PRUNE_MODE_DELETE,
				'stale_grace_days'    => '14',
				'manage_page_id'      => '42',
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
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\expect( 'update_option' )
			->once()
			->with(
				Settings::OPTION,
				[
					'enabled_post_types'  => [ 'post' ],
					'auto_append_default' => false,
					'subscription_text'   => '',
					'stale_after_months'  => 6,
					'prune_mode'          => Settings::PRUNE_MODE_KEEP_ALIVE,
					'stale_grace_days'    => 7,
					'manage_page_id'      => 0,
				],
				false,
			);

		Settings::save( [ 'enabled_post_types' => [ 'post' ] ] );
	}

	/**
	 * Confirms save() rejects an out-of-range stale_after_months value.
	 *
	 * @return void
	 */
	public function test_save_clamps_unknown_stale_after(): void {
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $key, $value ): bool {
					return $key === Settings::OPTION
						&& \is_array( $value )
						&& $value['stale_after_months'] === 6;
				},
			);

		Settings::save( [ 'stale_after_months' => 999 ] );
	}
}
