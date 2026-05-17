<?php

declare(strict_types=1);

namespace Apermo\Notify\Dispatch;

use WP_Post;

/**
 * Wires WordPress post-lifecycle hooks to the dispatch pipeline.
 */
final class PostHooks {

	/**
	 * Post-meta key that authors set on a save when they want the update to
	 * trigger a subscriber notification. Reset after dispatch.
	 */
	public const NOTIFY_META = '_apermo_notify_send_on_save';

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
	 * Fires the `update` event when the editor explicitly flagged this save
	 * via the author-facing checkbox. Resets the flag after dispatch so a
	 * subsequent save doesn't re-notify.
	 *
	 * @param int     $post_id     Post ID being updated.
	 * @param WP_Post $post_after  Post after the save.
	 * @param WP_Post $post_before Post before the save.
	 *
	 * @return void
	 */
	public static function on_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		unset( $post_before );

		if ( $post_after->post_status !== 'publish' ) {
			return;
		}

		$flag = (string) get_post_meta( $post_id, self::NOTIFY_META, true );
		if ( $flag === '' ) {
			return;
		}

		delete_post_meta( $post_id, self::NOTIFY_META );

		Dispatcher::dispatch( $post_after, 'update' );
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', [ self::class, 'on_transition' ], 10, 3 );
		add_action( 'post_updated', [ self::class, 'on_updated' ], 10, 3 );
	}
}
