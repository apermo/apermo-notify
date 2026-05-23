<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;
use WP_Post;

/**
 * Tags the configured Subscription Management page with a state label in
 * the Pages list table.
 *
 * Same affordance core uses for the Privacy Policy page (and the front /
 * posts pages): a small label next to the title so admins can spot which
 * page is wired into the plugin without opening Settings.
 */
final class ManagePageStateLabel {

	/**
	 * State key used in the filter's return array — also doubles as a CSS
	 * hook on the rendered `<span>`.
	 */
	public const STATE_KEY = 'apermo_notify_manage';

	/**
	 * Appends the manage-page label to the post-states list when the post
	 * is the one bound to `manage_page_id`.
	 *
	 * @param array<string, string> $states Current state labels.
	 * @param WP_Post|null          $post   Post being labeled.
	 *
	 * @return array<string, string>
	 */
	public static function add_state( array $states, ?WP_Post $post ): array {
		if ( ! $post instanceof WP_Post ) {
			return $states;
		}

		$page_id = Settings::manage_page_id();
		if ( $page_id > 0 && $post->ID === $page_id ) {
			$states[ self::STATE_KEY ] = __( 'Apermo Notify Subscription Management Page', 'apermo-notify' );
		}

		return $states;
	}

	/**
	 * Registers the filter on the post-states list.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'display_post_states', [ self::class, 'add_state' ], 10, 2 );
	}
}
