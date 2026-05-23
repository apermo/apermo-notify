<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Frontend;

use Apermo\Notify\Frontend\AutoAppend;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests the visibility resolution rules for AutoAppend.
 */
final class AutoAppendTest extends TestCase {

	/**
	 * Builds a stub WP_Post-like object.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return WP_Post
	 */
	private static function post( string $post_type ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_type   = $post_type;
		$post->post_status = 'publish';
		return $post;
	}

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
	 * Confirms register() hooks the_content at priority 20.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_content_filter(): void {
		Functions\expect( 'add_filter' )
			->once()
			->withArgs(
				static fn ( string $hook ): bool => $hook === 'the_content',
			);

		( new AutoAppend() )->register();
	}

	/**
	 * Confirms the per-post `show` override forces rendering regardless of settings.
	 *
	 * @return void
	 */
	public function test_per_post_show_override_wins(): void {
		Functions\when( 'get_post_meta' )->justReturn( AutoAppend::VISIBILITY_SHOW );

		$this->assertTrue( AutoAppend::should_render_for( self::post( 'post' ) ) );
	}

	/**
	 * Confirms the per-post `hide` override blocks rendering regardless of settings.
	 *
	 * @return void
	 */
	public function test_per_post_hide_override_blocks(): void {
		Functions\when( 'get_post_meta' )->justReturn( AutoAppend::VISIBILITY_HIDE );
		Functions\when( 'get_option' )->justReturn(
			[
				'enabled_post_types'  => [ 'post' ],
				'auto_append_default' => true,
			],
		);

		$this->assertFalse( AutoAppend::should_render_for( self::post( 'post' ) ) );
	}

	/**
	 * Confirms the default falls back to settings when no per-post override exists.
	 *
	 * @return void
	 */
	public function test_default_follows_settings(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		// Enabled type + default on → show.
		Functions\when( 'get_option' )->justReturn(
			[
				'enabled_post_types'  => [ 'post' ],
				'auto_append_default' => true,
			],
		);
		$this->assertTrue( AutoAppend::should_render_for( self::post( 'post' ) ) );

		// Non-enabled type + default on → hide.
		$this->assertFalse( AutoAppend::should_render_for( self::post( 'page' ) ) );
	}

	/**
	 * Confirms the default-off setting hides the form on enabled types.
	 *
	 * @return void
	 */
	public function test_default_off_hides_even_on_enabled_types(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			[
				'enabled_post_types'  => [ 'post' ],
				'auto_append_default' => false,
			],
		);

		$this->assertFalse( AutoAppend::should_render_for( self::post( 'post' ) ) );
	}
}
