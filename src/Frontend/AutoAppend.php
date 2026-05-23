<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Settings;
use WP_Post;

/**
 * Appends the subscribe form to the post content on enabled post types.
 *
 * Visibility resolution per post:
 *
 *  - per-post meta `_apermo_notify_show_form` set to `show` → always render
 *  - per-post meta set to `hide` → never render
 *  - empty meta value → render iff the global "auto-append default" setting
 *    is on AND the post's post_type is in the enabled list
 */
final class AutoAppend {

	/**
	 * Per-post override key.
	 */
	public const VISIBILITY_META = '_apermo_notify_show_form';

	/**
	 * Per-post override: render the form here regardless of settings.
	 */
	public const VISIBILITY_SHOW = 'show';

	/**
	 * Per-post override: hide the form here regardless of settings.
	 */
	public const VISIBILITY_HIDE = 'hide';

	/**
	 * Decides whether the form should be appended to a given post's content.
	 *
	 * @param \WP_Post $post Post being rendered.
	 *
	 * @return bool
	 */
	public static function should_render_for( WP_Post $post ): bool {
		$override = (string) get_post_meta( (int) $post->ID, self::VISIBILITY_META, true );

		if ( $override === self::VISIBILITY_SHOW ) {
			return true;
		}
		if ( $override === self::VISIBILITY_HIDE ) {
			return false;
		}

		if ( ! Settings::auto_append_default() ) {
			return false;
		}

		return \in_array( $post->post_type, Settings::enabled_post_types(), true );
	}

	/**
	 * Registers the content filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ $this, 'maybe_append' ], 20 );
	}

	/**
	 * Appends the form to `$content` when the current post should display it.
	 *
	 * @param string $content Post content from `the_content` filter.
	 *
	 * @return string
	 */
	public function maybe_append( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post(); // phpcs:ignore Apermo.WordPress.ImplicitPostFunction.MissingArgument -- the_content runs inside the loop; global post is the contract.
		if ( $post === null ) {
			return $content;
		}

		if ( ! self::should_render_for( $post ) ) {
			return $content;
		}

		return $content . FormRenderer::render( (int) $post->ID );
	}
}
