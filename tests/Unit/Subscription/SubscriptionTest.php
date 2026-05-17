<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Subscription;

use Apermo\Notify\Subscription\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Subscription value object's row hydration.
 */
final class SubscriptionTest extends TestCase {

	/**
	 * Confirms from_row() hydrates every column with appropriate type coercion.
	 *
	 * @return void
	 */
	public function test_from_row_hydrates_all_columns(): void {
		// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- Mirrors the 11-column DB row.
		$row = [
			'id'               => '7',
			'target_type'      => 'post',
			'target_id'        => '42',
			'target_meta'      => '',
			'filter_json'      => null,
			'email'            => 'visitor@example.tld',
			'token'            => \str_repeat( 'a', 64 ),
			'status'           => '1',
			'created_at'       => '2026-01-01 00:00:00',
			'confirmed_at'     => '2026-01-01 00:05:00',
			'last_notified_at' => null,
		];

		$subscription = Subscription::from_row( $row );

		$this->assertSame( 7, $subscription->id );
		$this->assertSame( 'post', $subscription->target_type );
		$this->assertSame( 42, $subscription->target_id );
		$this->assertSame( Subscription::STATUS_CONFIRMED, $subscription->status );
		$this->assertSame( '2026-01-01 00:05:00', $subscription->confirmed_at );
		$this->assertNull( $subscription->last_notified_at );
	}

	/**
	 * Confirms missing columns default sensibly when hydrating partial rows.
	 *
	 * @return void
	 */
	public function test_from_row_defaults_when_columns_missing(): void {
		$subscription = Subscription::from_row( [] );

		$this->assertNull( $subscription->id );
		$this->assertSame( '', $subscription->target_type );
		$this->assertSame( 0, $subscription->target_id );
		$this->assertSame( Subscription::STATUS_PENDING, $subscription->status );
	}
}
