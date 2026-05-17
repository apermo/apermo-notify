<?php

declare(strict_types=1);

namespace Plugin_Name\Tests\Unit\Admin;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Plugin_Name\* imports get rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Plugin_Name\Admin\DeactivationFlow;
use Plugin_Name\Main;
use RuntimeException;

/**
 * Tests for the DeactivationFlow admin class.
 */
class DeactivationFlowTest extends TestCase {

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
				'register_activation_hook',
				'register_deactivation_hook',
			],
		);

		Main::init( '/tmp/plugin.php' );
	}

	/**
	 * Tears down Brain Monkey and superglobals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST    = [];
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verifies register() wires the expected filters and actions.
	 *
	 * @return void
	 */
	public function test_register_wires_hooks(): void {
		Functions\stubs( [ 'plugin_basename' => 'plugin-name/plugin.php' ] );

		( new DeactivationFlow() )->register();

		$this->assertTrue( has_filter( 'plugin_action_links_plugin-name/plugin.php' ) > 0 );
		$this->assertTrue( has_filter( 'network_admin_plugin_action_links_plugin-name/plugin.php' ) > 0 );
		$this->assertTrue( has_action( 'admin_menu' ) > 0 );
		$this->assertTrue( has_action( 'network_admin_menu' ) > 0 );
		$this->assertTrue( has_action( 'admin_action_' . DeactivationFlow::DELETE_ACTION ) > 0 );
	}

	/**
	 * Verifies rewrite_deactivate_link replaces the deactivate URL with the
	 * confirmation page URL.
	 *
	 * @return void
	 */
	public function test_rewrite_deactivate_link_points_to_confirm_page(): void {
		Functions\stubs(
			[
				'is_network_admin' => false,
				'admin_url'        => static fn ( string $path ): string => 'https://example.tld/wp-admin/' . $path,
				'add_query_arg'    => static fn ( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
				'esc_url'          => static fn ( string $url ): string => $url,
				'esc_html__'       => static fn ( string $text ): string => $text,
			],
		);

		$result = ( new DeactivationFlow() )->rewrite_deactivate_link(
			[ 'deactivate' => '<a href="original">Original</a>' ],
		);

		$this->assertStringContainsString( 'page=' . DeactivationFlow::CONFIRM_PAGE, $result['deactivate'] );
		$this->assertStringNotContainsString( 'original', $result['deactivate'] );
	}

	/**
	 * Verifies rewrite_deactivate_link is a no-op when no deactivate link
	 * exists (e.g. plugin already in must-use slot).
	 *
	 * @return void
	 */
	public function test_rewrite_deactivate_link_passes_through_when_absent(): void {
		$actions = [ 'activate' => '<a>Activate</a>' ];

		$this->assertSame( $actions, ( new DeactivationFlow() )->rewrite_deactivate_link( $actions ) );
	}

	/**
	 * Verifies the destructive handler dies when the user lacks capability.
	 *
	 * @return void
	 */
	public function test_handle_destructive_dies_on_missing_capability(): void {
		Functions\stubs(
			[
				'is_user_logged_in'            => true,
				'plugin_basename'              => 'plugin-name/plugin.php',
				'is_plugin_active_for_network' => false,
				'current_user_can'             => false,
				'esc_html__'                   => static fn ( string $text ): string => $text,
			],
		);

		Functions\expect( 'wp_die' )
			->once()
			->with( Mockery::type( 'string' ), 403 )
			->andReturnUsing(
				static function (): never {
					throw new RuntimeException( 'wp_die_403' );
				},
			);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die_403' );

		( new DeactivationFlow() )->handle_destructive();
	}

	/**
	 * Verifies the destructive handler dies when the confirm checkbox is missing.
	 *
	 * @return void
	 */
	public function test_handle_destructive_dies_on_missing_checkbox(): void {
		Functions\stubs(
			[
				'is_user_logged_in'            => true,
				'plugin_basename'              => 'plugin-name/plugin.php',
				'is_plugin_active_for_network' => false,
				'current_user_can'             => true,
				'check_admin_referer'          => 1,
				'esc_html__'                   => static fn ( string $text ): string => $text,
			],
		);

		Functions\expect( 'wp_die' )
			->once()
			->with( Mockery::type( 'string' ), 400 )
			->andReturnUsing(
				static function (): never {
					throw new RuntimeException( 'wp_die_400' );
				},
			);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die_400' );

		$_POST = [];

		( new DeactivationFlow() )->handle_destructive();
	}

	/**
	 * Verifies the destructive handler runs cleanup, deactivates, and
	 * redirects when all gates pass.
	 *
	 * @return void
	 */
	public function test_handle_destructive_completes_full_flow(): void {
		Functions\stubs(
			[
				'is_user_logged_in'            => true,
				'plugin_basename'              => 'plugin-name/plugin.php',
				'is_plugin_active_for_network' => false,
				'current_user_can'             => true,
				'check_admin_referer'          => 1,
				'is_multisite'                 => false,
				'is_network_admin'             => false,
				'esc_html__'                   => static fn ( string $text ): string => $text,
				'wp_unslash'                   => static fn ( string $value ): string => $value,
			],
		);

		Functions\stubs(
			[
				'admin_url'           => static fn ( string $path ): string => 'https://example.tld/wp-admin/' . $path,
				'add_query_arg'       => static fn ( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
				'sanitize_text_field' => static fn ( string $value ): string => $value,
			],
		);

		Functions\expect( 'delete_option' )
			->once()
			->with( 'plugin_name_settings' );

		Functions\expect( 'deactivate_plugins' )
			->once()
			->with( 'plugin-name/plugin.php', false, false );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				static function (): never {
					throw new RuntimeException( 'redirected' );
				},
			);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );

		$_POST = [ 'confirm' => '1' ];

		( new DeactivationFlow() )->handle_destructive();
	}

	/**
	 * Verifies the network-admin path: capability is `manage_network_plugins`,
	 * cleanup uses `delete_site_option`, deactivation is network-wide, and
	 * the redirect target is the network plugins screen.
	 *
	 * @return void
	 */
	public function test_handle_destructive_network_admin_path(): void {
		Functions\stubs(
			[
				'is_user_logged_in'            => true,
				'plugin_basename'              => 'plugin-name/plugin.php',
				'is_plugin_active_for_network' => true,
				'current_user_can'             => true,
				'check_admin_referer'          => 1,
				'is_multisite'                 => true,
				'is_network_admin'             => true,
				'esc_html__'                   => static fn ( string $text ): string => $text,
				'wp_unslash'                   => static fn ( string $value ): string => $value,
			],
		);

		Functions\stubs(
			[
				'network_admin_url'   => static fn ( string $path ): string => 'https://example.tld/wp-admin/network/' . $path,
				'add_query_arg'       => static fn ( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
				'sanitize_text_field' => static fn ( string $value ): string => $value,
			],
		);

		Functions\expect( 'delete_site_option' )
			->once()
			->with( 'plugin_name_settings' );

		Functions\expect( 'deactivate_plugins' )
			->once()
			->with( 'plugin-name/plugin.php', false, true );

		$captured_url = null;
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing(
				static function ( string $url ) use ( &$captured_url ): never {
					$captured_url = $url;
					throw new RuntimeException( 'redirected' );
				},
			);

		$this->expectException( RuntimeException::class );

		$_POST = [ 'confirm' => '1' ];

		try {
			( new DeactivationFlow() )->handle_destructive();
		} finally {
			$this->assertStringContainsString( 'wp-admin/network/plugins.php', (string) $captured_url );
		}
	}
}
