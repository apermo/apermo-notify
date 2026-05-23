<?php

declare(strict_types=1);

namespace Apermo\Notify\Editor;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Dispatch\Dispatcher;
use Apermo\Notify\Main;
use Apermo\Notify\Settings;
use Apermo\Notify\Subscription\Repository;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surfaces a snackbar inside the block editor after each post update,
 * offering to notify confirmed subscribers about the change.
 *
 * Replaces the older "Notify subscribers about this update" metabox
 * checkbox — that flow required the author to remember to tick the box
 * *before* saving, which usually meant either over-notifying or
 * forgetting entirely. Asking after the save is the better UX.
 *
 * First-publish notifications stay automatic via
 * Dispatch\PostHooks::on_transition; this dialog only fires for
 * subsequent updates of an already-published post.
 */
final class UpdateDialog {

	/**
	 * Asset handle for the editor script.
	 */
	public const HANDLE = 'apermo-notify-editor-update-dialog';

	/**
	 * REST namespace used by the dispatch endpoint.
	 */
	public const REST_NAMESPACE = 'apermo-notify/v1';

	/**
	 * REST route for the dispatch endpoint.
	 */
	public const REST_ROUTE = '/dispatch-update';

	/**
	 * Registers the editor enqueue hook and the REST route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Enqueues the editor script and feeds it the data it needs to decide
	 * whether to surface the snackbar.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$candidate = $GLOBALS['post'] ?? null;
		$post      = $candidate instanceof WP_Post ? $candidate : null;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! \in_array( $post->post_type, Settings::enabled_post_types(), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/editor-update-dialog.js', Main::file() ),
			[ 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-plugins' ],
			Main::VERSION,
			true,
		);

		wp_localize_script(
			self::HANDLE,
			'apermoNotifyEditor',
			[
				'postId'       => $post->ID,
				'count'        => Repository::count_confirmed_for_target( 'post', $post->ID ),
				// `publish` at script-load time. If the post is already
				// published, an Update click should surface the dialog.
				// A fresh draft going through its first publish skips it
				// because the publish-transition fires the auto-notify.
				'wasPublished' => $post->post_status === 'publish',
				'i18n'         => [
					/* translators: %d: confirmed-subscriber count, rendered client-side */
					'offer'   => __( 'Post updated. Notify %d subscribers about the change?', 'apermo-notify' ),
					'notify'  => __( 'Notify subscribers', 'apermo-notify' ),
					/* translators: %d: number of notifications sent, rendered client-side */
					'sent'    => __( 'Notification queued for %d subscribers.', 'apermo-notify' ),
					'failure' => __( 'Could not queue the update notification.', 'apermo-notify' ),
				],
			],
		);
	}

	/**
	 * Registers the REST route the snackbar's action button hits.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_dispatch' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'post_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			],
		);
	}

	/**
	 * Allows the request only when the current user can edit the target post.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 *
	 * @return bool
	 */
	public function permission_check( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Dispatches the update notification for the given post.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_dispatch( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Post is not published.', 'apermo-notify' ) ],
				409,
			);
		}

		$count = Repository::count_confirmed_for_target( 'post', $post_id );
		Dispatcher::dispatch( $post, 'update' );

		return new WP_REST_Response(
			[
				'queued' => $count,
			],
		);
	}
}
