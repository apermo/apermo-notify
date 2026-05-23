<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Admin;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Apermo_Notify\* imports get rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Apermo\Notify\Admin\ManagePageNotice;
use Apermo\Notify\Admin\SettingsPage;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests the manage-page admin notice.
 */
final class ManagePageNoticeTest extends TestCase {

	/**
	 * Sets up Brain Monkey + the WP-stub set the notice needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn ( string $path ): string => 'https://example.tld/wp-admin/' . $path );
		Functions\when( 'wp_kses' )->alias( static fn ( string $html ): string => $html );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( string $message, array $args ): void {
				$type        = $args['type'] ?? 'info';
				$dismissible = ! empty( $args['dismissible'] ) ? ' is-dismissible' : '';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stub mimics WP's wp_admin_notice; caller is responsible for escaping the message, as in production.
				echo '<div class="notice notice-' . $type . $dismissible . '"><p>' . $message . '</p></div>';
			},
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
	 * Confirms the notice renders when no manage page is set.
	 *
	 * @return void
	 */
	public function test_renders_when_manage_page_unset(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'get_post' )->never();

		\ob_start();
		ManagePageNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'admin.php?page=' . SettingsPage::SLUG, $output );
		$this->assertStringContainsString( 'No Subscription Management page is configured', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
	}

	/**
	 * Confirms the notice still fires when the option points at a draft page.
	 *
	 * @return void
	 */
	public function test_renders_when_manage_page_is_draft(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 7 ] );
		// phpcs:disable Apermo.PHP.ForbiddenObjectCast.Found -- Minimal WP_Post stub needs an object.
		Functions\when( 'get_post' )->alias(
			static fn (): WP_Post => new WP_Post(
				(object) [
					'ID'          => 7,
					'post_status' => 'draft',
				],
			),
		);
		// phpcs:enable Apermo.PHP.ForbiddenObjectCast.Found

		\ob_start();
		ManagePageNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * Confirms the notice is silent when the manage page is published.
	 *
	 * @return void
	 */
	public function test_silent_when_published_page_is_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'manage_page_id' => 7 ] );
		// phpcs:disable Apermo.PHP.ForbiddenObjectCast.Found -- Minimal WP_Post stub needs an object.
		Functions\when( 'get_post' )->alias(
			static fn (): WP_Post => new WP_Post(
				(object) [
					'ID'          => 7,
					'post_status' => 'publish',
				],
			),
		);
		// phpcs:enable Apermo.PHP.ForbiddenObjectCast.Found

		\ob_start();
		ManagePageNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Confirms users without manage_options never see the notice.
	 *
	 * @return void
	 */
	public function test_silent_for_users_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_option' )->never();

		\ob_start();
		ManagePageNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Confirms register() hooks both admin_notices and network_admin_notices.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_and_network_notices(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			},
		);

		( new ManagePageNotice() )->register();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'network_admin_notices', $hooks );
	}
}
