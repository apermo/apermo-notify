<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Admin;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Apermo_Notify\* imports get rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Apermo\Notify\Admin\ManagePageStateLabel;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests the post-states label that marks the configured manage page.
 */
final class ManagePageStateLabelTest extends TestCase {

	/**
	 * Builds a minimal WP_Post fixture for the given ID.
	 *
	 * @param int $id Post primary key.
	 *
	 * @return WP_Post
	 */
	private static function post( int $id ): WP_Post {
		// phpcs:disable Apermo.PHP.ForbiddenObjectCast.Found -- Minimal WP_Post stub needs an object.
		return new WP_Post(
			(object) [
				'ID'          => $id,
				'post_status' => 'publish',
			],
		);
		// phpcs:enable Apermo.PHP.ForbiddenObjectCast.Found
	}

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
	 * Confirms the label is attached when the post is the configured manage page.
	 *
	 * @return void
	 */
	public function test_label_added_for_configured_page(): void {
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 42 ] );

		$states = ManagePageStateLabel::add_state( [], self::post( 42 ) );

		$this->assertArrayHasKey( ManagePageStateLabel::STATE_KEY, $states );
		$this->assertSame(
			'Apermo Notify Subscription Management Page',
			$states[ ManagePageStateLabel::STATE_KEY ],
		);
	}

	/**
	 * Confirms unrelated pages are left untouched.
	 *
	 * @return void
	 */
	public function test_label_skipped_for_other_pages(): void {
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 42 ] );

		$states = ManagePageStateLabel::add_state( [], self::post( 99 ) );

		$this->assertArrayNotHasKey( ManagePageStateLabel::STATE_KEY, $states );
	}

	/**
	 * Confirms the filter is a no-op when no manage page is configured.
	 *
	 * @return void
	 */
	public function test_label_skipped_when_manage_page_unset(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$states = ManagePageStateLabel::add_state( [], self::post( 42 ) );

		$this->assertArrayNotHasKey( ManagePageStateLabel::STATE_KEY, $states );
	}

	/**
	 * Confirms register() wires the display_post_states filter.
	 *
	 * @return void
	 */
	public function test_register_hooks_display_post_states(): void {
		$hooks = [];
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			},
		);

		( new ManagePageStateLabel() )->register();

		$this->assertContains( 'display_post_states', $hooks );
	}
}
