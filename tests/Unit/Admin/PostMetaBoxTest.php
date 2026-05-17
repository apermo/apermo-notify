<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Admin;

use Apermo\Notify\Admin\PostMetaBox;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests PostMetaBox hook registration.
 */
final class PostMetaBoxTest extends TestCase {

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
	 * Confirms register() wires the add_meta_boxes and save_post hooks.
	 *
	 * @return void
	 */
	public function test_register_wires_admin_hooks(): void {
		Functions\expect( 'add_action' )
			->twice()
			->withArgs(
				static fn ( string $hook ): bool => \in_array(
					$hook,
					[ 'add_meta_boxes', 'pre_post_update' ],
					true,
				),
			);

		( new PostMetaBox() )->register();
	}
}
