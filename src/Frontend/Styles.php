<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Main;

/**
 * Enqueues the plugin's minimal frontend stylesheet on pages where the
 * subscribe form is actually rendered.
 *
 * Themes can opt out entirely with:
 *
 *     add_filter( 'apermo_notify_enqueue_styles', '__return_false' );
 */
final class Styles {

	/**
	 * Handle for the enqueued stylesheet.
	 */
	public const HANDLE = 'apermo-notify';

	/**
	 * Registers the enqueue hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	/**
	 * Enqueues the stylesheet when AutoAppend will render the form for the
	 * current page, and the opt-out filter has not vetoed it.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		/**
		 * Filters whether the plugin's stylesheet should be enqueued.
		 *
		 * Return false to ship your own theme styles for the form.
		 *
		 * @param bool $should_enqueue Default true.
		 *
		 * @return bool
		 */
		$should_enqueue = (bool) apply_filters( 'apermo_notify_enqueue_styles', true );

		if ( ! $should_enqueue ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		// phpcs:ignore Apermo.WordPress.ImplicitPostFunction.MissingArgument -- Frontend enqueue runs once per request; global post is the contract.
		$post = get_post();
		if ( $post === null || ! AutoAppend::should_render_for( $post ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/apermo-notify.css', Main::file() ),
			[],
			Main::VERSION,
		);
	}
}
