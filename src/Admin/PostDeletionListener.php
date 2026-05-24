<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Mail\Mailer;
use Apermo\Notify\Main;
use Apermo\Notify\Subscription\Repository;
use Apermo\Notify\Subscription\Subscription;
use WP_Post;

/**
 * Owns the post-removal lifecycle for subscriptions.
 *
 * - On `deleted_post`: wipes every subscription pointing at the removed post
 *   (covers both "Delete Permanently" and "Empty Trash" paths).
 * - On the posts list and post edit screens: enqueues a JS modal that fires
 *   for `action=trash` clicks when the post has confirmed subscribers,
 *   offering to send a goodbye email before the trash request fires.
 * - Exposes an AJAX endpoint the modal calls to dispatch the goodbye email
 *   (with an optional author note). The actual trash request runs after
 *   the AJAX returns; trash is reversible, so subscriptions stay in place
 *   until the eventual permanent delete triggers the cleanup hook above.
 */
final class PostDeletionListener {

	/**
	 * AJAX action name used by the modal to fire the goodbye emails.
	 */
	public const AJAX_ACTION = 'apermo_notify_send_goodbye_to_post';

	/**
	 * Nonce action paired with `AJAX_ACTION`.
	 */
	public const NONCE_ACTION = 'apermo_notify_goodbye_nonce';

	/**
	 * Asset handle for the modal JS + CSS pair.
	 */
	public const HANDLE = 'apermo-notify-admin-deletion';

	/**
	 * Drops every subscription row pointing at the deleted post.
	 *
	 * @param int $post_id Post that was just removed.
	 *
	 * @return void
	 */
	public static function cleanup( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		Repository::delete_for_target( 'post', $post_id );
	}

	/**
	 * Returns counts keyed by post ID for every row visible on `edit.php`.
	 *
	 * The values are read from the main query which has already been
	 * primed by `wp` at this point in the request, so this stays one DB
	 * query regardless of the screen's per-page setting.
	 *
	 * @return array<int,int> Map of `post_id => confirmed_count`.
	 */
	private static function counts_for_posts_list(): array {
		$ids = [];
		if (
			isset( $GLOBALS['wp_query'] )
			&& \is_object( $GLOBALS['wp_query'] )
			&& isset( $GLOBALS['wp_query']->posts )
			&& \is_array( $GLOBALS['wp_query']->posts )
		) {
			foreach ( $GLOBALS['wp_query']->posts as $candidate ) {
				if ( $candidate instanceof WP_Post ) {
					$ids[] = $candidate->ID;
				} elseif ( \is_scalar( $candidate ) ) {
					$ids[] = (int) $candidate;
				}
			}
		}
		$ids = \array_values( \array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );

		if ( $ids === [] ) {
			return [];
		}

		return Repository::counts_by_target( 'post', $ids );
	}

	/**
	 * Returns the count for the single post on the post edit screen.
	 *
	 * @return array<int,int> Single-entry map, or empty when no post id is in scope.
	 */
	private static function counts_for_post_editor(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen lookup; no state change.
		$post_id = isset( $_GET['post'] ) && \is_scalar( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $post_id <= 0 ) {
			return [];
		}

		return Repository::counts_by_target( 'post', [ $post_id ] );
	}

	/**
	 * Returns every confirmed subscriber of a post.
	 *
	 * Pulled into a small helper purely to keep handle_ajax readable.
	 *
	 * @param int $post_id Post identifier.
	 *
	 * @return array<int,Subscription>
	 */
	private static function confirmed_subscribers( int $post_id ): array {
		return Repository::find_confirmed_for_target( 'post', $post_id, '' );
	}

	/**
	 * Wires all hooks for this feature.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'deleted_post', [ self::class, 'cleanup' ], 10, 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax' ] );
	}

	/**
	 * Enqueues the modal asset bundle on the posts list screens only.
	 *
	 * @param string $hook_suffix Current screen hook (e.g. `edit.php`).
	 *
	 * @return void
	 */
	public function maybe_enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== 'edit.php' && $hook_suffix !== 'post.php' ) {
			return;
		}
		if ( ! current_user_can( 'delete_posts' ) ) {
			return;
		}

		$counts = $hook_suffix === 'edit.php'
			? self::counts_for_posts_list()
			: self::counts_for_post_editor();
		if ( $counts === [] ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/admin-deletion-modal.css', Main::file() ),
			[],
			Main::VERSION,
		);
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/admin-deletion-modal.js', Main::file() ),
			[ 'jquery' ],
			Main::VERSION,
			true,
		);
		wp_localize_script(
			self::HANDLE,
			'apermoNotifyDeletion',
			[
				'counts'  => $counts,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'i18n'    => [
					'title'          => __( 'Notify subscribers before trashing?', 'apermo-notify' ),
					/* translators: %d: confirmed-subscriber count, rendered client-side */
					'body'           => __( '%d people are subscribed to this post.', 'apermo-notify' ),
					'noteLabel'      => __( 'Optional note (added to the email body):', 'apermo-notify' ),
					'sendAndTrash'   => __( 'Notify and trash', 'apermo-notify' ),
					'trashSilently'  => __( 'Trash without notifying', 'apermo-notify' ),
					'cancel'         => __( 'Cancel', 'apermo-notify' ),
					'sending'        => __( 'Sending goodbye emails…', 'apermo-notify' ),
					'sendFailed'     => __( 'Could not send the emails. Trash anyway?', 'apermo-notify' ),
				],
			],
		);
	}

	/**
	 * Handles the AJAX call fired by the modal's "Notify and delete" button.
	 *
	 * Sends a goodbye email to every confirmed subscriber of the post and
	 * returns a JSON payload the JS can use to flash a success state. The
	 * actual post-delete request is fired by the JS immediately after this
	 * succeeds, so this endpoint does NOT delete the post itself.
	 *
	 * @return void
	 */
	public function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden.', 'apermo-notify' ) ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$post_id     = isset( $_POST['post_id'] ) && \is_scalar( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$custom_note = isset( $_POST['note'] ) && \is_string( $_POST['note'] )
			? sanitize_textarea_field( wp_unslash( $_POST['note'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $post_id <= 0 || ! current_user_can( 'delete_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden.', 'apermo-notify' ) ], 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'Post no longer exists.', 'apermo-notify' ) ], 404 );
		}

		$sent = 0;
		foreach ( self::confirmed_subscribers( $post_id ) as $subscription ) {
			if ( Mailer::send_goodbye( $subscription, $post, $custom_note ) ) {
				$sent++;
			}
		}

		// GDPR-by-design: notifying ends the relationship. Drop every
		// subscription row pointing at this post — the visitor was told
		// the post is gone, so no further notifications make sense even
		// if the admin later restores from trash. Silent-trash leaves
		// rows alone; the deleted_post hook still cleans up on permanent
		// purge.
		Repository::delete_for_target( 'post', $post_id );

		wp_send_json_success( [ 'sent' => $sent ] );
	}
}
