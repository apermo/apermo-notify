<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Mail;

use Apermo\Notify\Mail\Mailer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests the Mailer URL-building helper.
 */
final class MailerTest extends TestCase {

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
	 * Confirms action_url builds an admin-post.php URL with action + token.
	 *
	 * @return void
	 */
	public function test_action_url_includes_action_and_token(): void {
		Functions\stubs(
			[
				'admin_url'     => static fn ( string $path ): string => 'https://example.tld/wp-admin/' . $path,
				'add_query_arg' => static fn ( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
			],
		);

		$url = Mailer::action_url( Mailer::ACTION_CONFIRM, \str_repeat( 'a', 64 ) );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Mailer::ACTION_CONFIRM, $url );
		$this->assertStringContainsString( 'token=' . \str_repeat( 'a', 64 ), $url );
	}

	/**
	 * Confirms manage_url falls back to the home-URL shape when no manage
	 * page is configured (Settings::manage_page_id() === 0).
	 *
	 * @return void
	 */
	public function test_manage_url_falls_back_to_home_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 0 ] );
		Functions\stubs(
			[
				'home_url'      => static fn ( string $path ): string => 'https://example.tld' . $path,
				'add_query_arg' => static fn ( $args, string $url ): string =>
					$url . '?' . ( \is_array( $args ) ? \http_build_query( $args ) : '' ),
				'__'            => static fn ( string $text ): string => $text,
			],
		);

		$url = Mailer::manage_url( \str_repeat( 'b', 64 ) );

		$this->assertStringStartsWith( 'https://example.tld/', $url );
		$this->assertStringNotContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Mailer::ACTION_MANAGE, $url );
		$this->assertStringContainsString( 'token=' . \str_repeat( 'b', 64 ), $url );
	}

	/**
	 * Confirms manage_url uses the configured page permalink when set + published.
	 *
	 * @return void
	 */
	public function test_manage_url_uses_configured_page_permalink(): void {
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 42 ] );
		// phpcs:disable Apermo.PHP.ForbiddenObjectCast.Found -- Minimal WP_Post stub needs an object.
		Functions\when( 'get_post' )->alias(
			static fn (): WP_Post => new WP_Post(
				(object) [
					'ID'          => 42,
					'post_status' => 'publish',
				],
			),
		);
		// phpcs:enable Apermo.PHP.ForbiddenObjectCast.Found
		Functions\stubs(
			[
				'get_permalink' => static fn (): string => 'https://example.tld/manage-subs/',
				'home_url'      => static fn ( string $path ): string => 'https://example.tld' . $path,
				'add_query_arg' => static function ( ...$args ): string {
					if ( \is_array( $args[0] ) ) {
						return $args[1] . '?' . \http_build_query( $args[0] );
					}
					return $args[2] . '?' . $args[0] . '=' . $args[1];
				},
				'__'            => static fn ( string $text ): string => $text,
			],
		);

		$token = \str_repeat( 'c', 64 );
		$url   = Mailer::manage_url( $token );

		$this->assertStringStartsWith( 'https://example.tld/manage-subs/', $url );
		$this->assertStringContainsString( 'token=' . $token, $url );
		$this->assertStringNotContainsString( 'action=' . Mailer::ACTION_MANAGE, $url );
	}
}
