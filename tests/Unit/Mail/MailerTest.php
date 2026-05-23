<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Mail;

use Apermo\Notify\Mail\Mailer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

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
	 * Confirms manage_url is a front-of-site URL with the manage action and token.
	 *
	 * @return void
	 */
	public function test_manage_url_builds_home_url(): void {
		Functions\stubs(
			[
				'home_url'      => static fn ( string $path ): string => 'https://example.tld' . $path,
				'add_query_arg' => static fn ( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
			],
		);

		$url = Mailer::manage_url( \str_repeat( 'b', 64 ) );

		$this->assertStringStartsWith( 'https://example.tld/', $url );
		$this->assertStringNotContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Mailer::ACTION_MANAGE, $url );
		$this->assertStringContainsString( 'token=' . \str_repeat( 'b', 64 ), $url );
	}
}
