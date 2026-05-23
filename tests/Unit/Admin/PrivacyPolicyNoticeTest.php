<?php

declare(strict_types=1);

namespace Apermo\Notify\Tests\Unit\Admin;

// phpcs:disable SlevomatCodingStandard.Namespaces.AlphabeticallySortedUses.IncorrectlyOrderedUses -- The Apermo_Notify\* imports get rewritten by setup.sh; final alphabetical position depends on the chosen namespace.

use Apermo\Notify\Admin\PrivacyPolicyNotice;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests the GDPR-by-design privacy policy admin notice.
 *
 * The plugin's consent label hard-links to wp_page_for_privacy_policy; the
 * notice is the only feedback the admin gets that the page is missing. The
 * behaviour must therefore reliably toggle on the option's presence.
 */
final class PrivacyPolicyNoticeTest extends TestCase {

	/**
	 * Sets up Brain Monkey.
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
	 * Confirms the notice renders when no privacy policy page is configured
	 * and the current user has manage_options.
	 *
	 * @return void
	 */
	public function test_renders_when_policy_page_is_unset(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 0 );

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'options-privacy.php', $output );
		$this->assertStringContainsString( 'No privacy policy page is configured', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
	}

	/**
	 * Confirms the notice is silent once the privacy policy page is set.
	 *
	 * @return void
	 */
	public function test_silent_when_policy_page_is_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 7 );

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Confirms a string-typed option (legacy serialized "7") still counts
	 * as a configured page and suppresses the notice.
	 *
	 * @return void
	 */
	public function test_silent_when_policy_page_option_is_string(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '7' );

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Confirms users without manage_options never see the notice, even when
	 * the policy page is missing.
	 *
	 * @return void
	 */
	public function test_silent_for_users_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_option' )->never();

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
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

		( new PrivacyPolicyNotice() )->register();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'network_admin_notices', $hooks );
	}
}
