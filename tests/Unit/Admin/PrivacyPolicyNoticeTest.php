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
	 * Confirms the notice renders when no privacy policy page is configured
	 * and the current user has manage_options.
	 *
	 * @return void
	 */
	public function test_renders_when_policy_url_is_empty(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'options-privacy.php', $output );
		$this->assertStringContainsString( 'No published privacy policy page is configured', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
	}

	/**
	 * Confirms the notice fires even when the option points at a draft —
	 * WordPress's `get_privacy_policy_url()` returns empty for drafts and
	 * trashed pages, so the visitor-facing form has no link to render.
	 *
	 * @return void
	 */
	public function test_renders_when_policy_page_is_draft(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// Mirrors the real WP function: it returns '' whenever the assigned
		// page is missing or non-published, regardless of whether the option
		// is set. The unit just needs the empty-string branch.
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );

		\ob_start();
		PrivacyPolicyNotice::maybe_render();
		$output = (string) \ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
	}

	/**
	 * Confirms the notice is silent once a published privacy policy page
	 * is reachable (i.e. `get_privacy_policy_url()` returns a non-empty URL).
	 *
	 * @return void
	 */
	public function test_silent_when_policy_url_is_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.tld/privacy/' );

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
		Functions\expect( 'get_privacy_policy_url' )->never();

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
