<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Privacy;

use Apermo\Notify\Privacy\Eraser;
use Apermo\Notify\Privacy\Exporter;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests the privacy exporter and eraser registration + response shape.
 */
final class PrivacyTest extends TestCase {

	/**
	 * Sets up Brain Monkey.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			[
				'__' => static fn ( string $text ): string => $text,
			],
		);
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
	 * Confirms Exporter::register hooks `wp_privacy_personal_data_exporters`.
	 *
	 * @return void
	 */
	public function test_exporter_register_hooks_filter(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'wp_privacy_personal_data_exporters', [ Exporter::class, 'register_exporter' ] );

		( new Exporter() )->register();
	}

	/**
	 * Confirms register_exporter appends the apermo-notify entry.
	 *
	 * @return void
	 */
	public function test_exporter_appends_entry_to_registry(): void {
		$registry = Exporter::register_exporter( [] );

		$this->assertArrayHasKey( Exporter::SLUG, $registry );
		$this->assertSame( [ Exporter::class, 'export' ], $registry[ Exporter::SLUG ]['callback'] );
	}

	/**
	 * Confirms Eraser::register hooks `wp_privacy_personal_data_erasers`.
	 *
	 * @return void
	 */
	public function test_eraser_register_hooks_filter(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'wp_privacy_personal_data_erasers', [ Eraser::class, 'register_eraser' ] );

		( new Eraser() )->register();
	}

	/**
	 * Confirms register_eraser appends the apermo-notify entry.
	 *
	 * @return void
	 */
	public function test_eraser_appends_entry_to_registry(): void {
		$registry = Eraser::register_eraser( [] );

		$this->assertArrayHasKey( Eraser::SLUG, $registry );
		$this->assertSame( [ Eraser::class, 'erase' ], $registry[ Eraser::SLUG ]['callback'] );
	}
}
