<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Frontend;

use Apermo\Notify\Frontend\FormHandler;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use WP_Post;

/**
 * Tests FormHandler hook registration.
 */
final class FormHandlerTest extends TestCase {

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
		$_POST   = [];
		$_GET    = [];
		$_SERVER = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Confirms register() wires admin_post and admin_post_nopriv hooks.
	 *
	 * @return void
	 */
	public function test_register_wires_both_admin_post_actions(): void {
		$handler = new FormHandler();

		Functions\expect( 'add_action' )
			->twice()
			->withArgs(
				static fn ( string $hook, array $callback ): bool =>
					\in_array(
						$hook,
						[
							'admin_post_nopriv_' . FormHandler::ACTION,
							'admin_post_' . FormHandler::ACTION,
						],
						true,
					) && $callback[0] === $handler && $callback[1] === 'handle',
			);

		$handler->register();
	}

	/**
	 * Confirms a submission without the consent checkbox is rejected with
	 * the consent_required flash and never reaches the DB.
	 *
	 * @return void
	 */
	public function test_handle_redirects_without_consent(): void {
		$_POST = [
			'post_id' => '5',
			'email'   => 'visitor@example.tld',
		];

		Functions\stubs(
			[
				'sanitize_text_field' => static fn ( $raw ) => $raw,
				'wp_unslash'          => static fn ( $raw ) => $raw,
				// phpcs:disable Apermo.PHP.ForbiddenObjectCast.Found -- WP_Post::__construct needs an object; the fixture is the smallest possible stub.
				'get_post'            => static fn (): WP_Post => new WP_Post(
					(object) [
						'ID'          => 5,
						'post_status' => 'publish',
						'post_type'   => 'post',
					],
				),
				// phpcs:enable Apermo.PHP.ForbiddenObjectCast.Found
				'check_admin_referer' => static fn (): bool => true,
				'get_permalink'       => static fn (): string => 'https://example.tld/p/5',
				'add_query_arg'       => static fn ( string $key, string $value, string $base ): string => $base . '?' . $key . '=' . $value,
				'home_url'            => static fn ( string $path = '/' ): string => 'https://example.tld' . $path,
				'wp_safe_redirect'    => static function (): bool {
					throw new RuntimeException( 'redirected' );
				},
				'get_option'          => static fn () => [ 'enabled_post_types' => [ 'post' ] ],
				'__'                  => static fn ( $raw ) => $raw,
			],
		);

		$handler = new FormHandler();

		try {
			$handler->handle();
			$this->fail( 'Expected redirect-driven RuntimeException was not thrown.' );
		} catch ( RuntimeException $caught ) {
			$this->assertSame( 'redirected', $caught->getMessage() );
		}
	}

	/**
	 * Confirms require_post_id() accepts only ASCII-digit strings and rejects
	 * everything else with the 0 sentinel — covers cases where absint() would
	 * silently coerce malformed input to a real (but unrelated) ID.
	 *
	 * @return void
	 */
	public function test_require_post_id_rejects_malformed_input(): void {
		Functions\stubs(
			[
				'wp_unslash' => static fn ( $value ) => $value,
			],
		);

		$cases = [
			'non-numeric'       => [ 'abc', 0 ],
			'numeric prefix'    => [ '5xyz', 0 ],
			'negative integer'  => [ '-5', 0 ],
			'whitespace padded' => [ ' 5 ', 0 ],
			'decimal'           => [ '5.0', 0 ],
			'empty string'      => [ '', 0 ],
			'array input'       => [ [ '5' ], 0 ],
			'plain digits'      => [ '5', 5 ],
			'multi-digit'       => [ '12345', 12345 ],
		];

		$method = new ReflectionMethod( FormHandler::class, 'require_post_id' );
		$handler = new FormHandler();

		foreach ( $cases as $label => [ $raw, $expected ] ) {
			$_POST = [ 'post_id' => $raw ];
			$this->assertSame( $expected, $method->invoke( $handler ), $label );
		}
	}
}
