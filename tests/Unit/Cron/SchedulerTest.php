<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Cron;

use Apermo\Notify\Cron\Scheduler;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests Scheduler's WP-Cron event lifecycle.
 */
final class SchedulerTest extends TestCase {

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
	 * Confirms schedule() registers the daily event when none exists.
	 *
	 * @return void
	 */
	public function test_schedule_registers_event_when_missing(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->withArgs(
				static fn ( int $timestamp, string $recurrence, string $hook ): bool =>
					$recurrence === 'daily' && $hook === Scheduler::HOOK,
			);

		Scheduler::schedule();
	}

	/**
	 * Confirms schedule() leaves an existing event alone.
	 *
	 * @return void
	 */
	public function test_schedule_skips_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1717000000 );
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::schedule();
	}

	/**
	 * Confirms unschedule() clears the hook.
	 *
	 * @return void
	 */
	public function test_unschedule_clears_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( Scheduler::HOOK );

		Scheduler::unschedule();
	}
}
