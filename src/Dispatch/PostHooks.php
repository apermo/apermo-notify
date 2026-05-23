<?php

declare(strict_types=1);

namespace Apermo\Notify\Dispatch;

\defined( 'ABSPATH' ) || exit();

use WP_Post;

/**
 * Wires WordPress post-lifecycle hooks to the dispatch pipeline.
 *
 * Only the first publish is automatic. Subsequent updates are opt-in via
 * the editor-side snackbar in `Editor\UpdateDialog`, which hits a REST
 * endpoint that calls `Dispatcher::dispatch( $post, 'update' )` directly.
 */
final class PostHooks {

	/**
	 * Fires the `publish` event the first time a post transitions to the
	 * publish state.
	 *
	 * @param string  $new_status New status slug.
	 * @param string  $old_status Previous status slug.
	 * @param WP_Post $post       Post being transitioned.
	 *
	 * @return void
	 */
	public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status !== 'publish' || $old_status === 'publish' ) {
			return;
		}

		Dispatcher::dispatch( $post, 'publish' );
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', [ self::class, 'on_transition' ], 10, 3 );
	}
}
