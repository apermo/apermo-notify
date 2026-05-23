<?php

declare(strict_types=1);

namespace Apermo\Notify\Admin;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Frontend\AutoAppend;
use Apermo\Notify\Settings;
use Apermo\Notify\Subscription\Repository;
use WP_Post;

/**
 * Registers the editor meta box that surfaces the subscriber count and the
 * per-post visibility override for the auto-appended form.
 *
 * The previous "Notify subscribers on next save" checkbox was removed when
 * the editor-side update-notification dialog (`Editor\UpdateDialog`) was
 * introduced — the dialog offers to notify *after* a successful save,
 * which is the only point in the editing flow where the author actually
 * knows whether they want to send.
 */
final class PostMetaBox {

	/**
	 * Nonce action for the meta-box submission.
	 */
	public const NONCE_ACTION = 'apermo_notify_metabox';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'apermo_notify_metabox_nonce';

	/**
	 * POST field that carries the visibility override.
	 */
	public const VISIBILITY_FIELD = 'apermo_notify_visibility';

	/**
	 * Prints one radio for the visibility override field.
	 *
	 * @param string $value   Radio value ('', 'show', 'hide').
	 * @param string $current Currently saved value.
	 * @param string $label   Label text.
	 *
	 * @return void
	 */
	private static function radio( string $value, string $current, string $label ): void {
		\printf(
			'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( self::VISIBILITY_FIELD ),
			esc_attr( $value ),
			esc_attr( $value === $current ? ' checked="checked"' : '' ),
			esc_html( $label ),
		);
	}

	/**
	 * Wires the meta-box registration and save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'pre_post_update', [ $this, 'on_pre_update' ], 10, 2 );
	}

	/**
	 * Adds the meta box to every post type that the Settings page has enabled.
	 *
	 * @return void
	 */
	public function add_box(): void {
		foreach ( Settings::enabled_post_types() as $screen ) {
			add_meta_box(
				'apermo-notify-metabox',
				__( 'Apermo Notify', 'apermo-notify' ),
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
		$count        = Repository::count_confirmed_for_target( 'post', $post->ID );
		$visibility   = (string) get_post_meta( $post->ID, AutoAppend::VISIBILITY_META, true );
		$default_on   = Settings::auto_append_default();
		$default_copy = $default_on
			? __( 'Default (shown — set in Settings)', 'apermo-notify' )
			: __( 'Default (hidden — set in Settings)', 'apermo-notify' );

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

		echo '<p><strong>' . esc_html__( 'Form on this post:', 'apermo-notify' ) . '</strong></p>';
		echo '<p>';
		self::radio( '', $visibility, $default_copy );
		echo '<br />';
		self::radio( AutoAppend::VISIBILITY_SHOW, $visibility, __( 'Show', 'apermo-notify' ) );
		echo '<br />';
		self::radio( AutoAppend::VISIBILITY_HIDE, $visibility, __( 'Hide', 'apermo-notify' ) );
		echo '</p>';
	}

	/**
	 * Persists meta-box state into post meta before the post UPDATE runs.
	 *
	 * @param int                  $post_id Post being saved.
	 * @param array<string, mixed> $data    Sanitized post data being written.
	 *
	 * @return void
	 */
	public function on_pre_update( int $post_id, array $data ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}

		$post_type = (string) ( $data['post_type'] ?? '' );
		if ( ! \in_array( $post_type, Settings::enabled_post_types(), true ) ) {
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

		$visibility = isset( $_POST[ self::VISIBILITY_FIELD ] ) && \is_string( $_POST[ self::VISIBILITY_FIELD ] )
			? sanitize_key( wp_unslash( $_POST[ self::VISIBILITY_FIELD ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $visibility === AutoAppend::VISIBILITY_SHOW || $visibility === AutoAppend::VISIBILITY_HIDE ) {
			update_post_meta( $post_id, AutoAppend::VISIBILITY_META, $visibility );
		} else {
			delete_post_meta( $post_id, AutoAppend::VISIBILITY_META );
		}
	}
}
