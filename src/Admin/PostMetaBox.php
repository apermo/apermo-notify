<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

use Apermo\Notify\Dispatch\PostHooks;
use Apermo\Notify\Subscription\Repository;
use WP_Post;

/**
 * Registers the editor meta box that surfaces the subscriber count and the
 * opt-in checkbox for the next-save notification.
 */
final class PostMetaBox {

	/**
	 * Nonce action for the checkbox submission.
	 */
	public const NONCE_ACTION = 'apermo_notify_metabox';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'apermo_notify_metabox_nonce';

	/**
	 * Wires the meta-box registration and save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
	}

	/**
	 * Adds the meta box to post and page edit screens.
	 *
	 * @return void
	 */
	public function add_box(): void {
		foreach ( [ 'post', 'page' ] as $screen ) {
			add_meta_box(
				'apermo-notify-metabox',
				__( 'Subscribers', 'apermo-notify' ),
				[ $this, 'render' ],
				$screen,
				'side',
			);
		}
	}

	/**
	 * Renders the meta-box contents for a given post.
	 *
	 * @param WP_Post $post Post being edited.
	 *
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$count   = Repository::count_confirmed_for_target( 'post', $post->ID );
		$pending = (string) get_post_meta( $post->ID, PostHooks::NOTIFY_META, true ) !== '';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<p>'
			. esc_html(
				\sprintf(
					/* translators: %d: subscriber count */
					_n( '%d confirmed subscriber.', '%d confirmed subscribers.', $count, 'apermo-notify' ),
					$count,
				),
			)
			. '</p>';

		echo '<p><label><input type="checkbox" name="apermo_notify_send_on_save" value="1"'
			. ( $pending ? ' checked="checked"' : '' )
			. ' /> '
			. esc_html__( 'Notify subscribers about this update', 'apermo-notify' )
			. '</label></p>';

		echo '<p class="description">'
			. esc_html__(
				'Only the next save will trigger a notification. The checkbox resets afterwards.',
				'apermo-notify',
			)
			. '</p>';
	}

	/**
	 * Saves the checkbox state into post meta.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 *
	 * @return void
	 */
	public function on_save( int $post_id, WP_Post $post ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}

		if ( ! \in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked immediately below.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! \is_string( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( isset( $_POST['apermo_notify_send_on_save'] ) ) {
			update_post_meta( $post_id, PostHooks::NOTIFY_META, '1' );
		} else {
			delete_post_meta( $post_id, PostHooks::NOTIFY_META );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
