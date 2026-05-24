<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Main;

/**
 * Enqueues the plugin's minimal frontend stylesheet on every frontend page.
 *
 * Themes that ship their own form styles can drop it with:
 *
 *     add_action( 'wp_enqueue_scripts', function () {
 *         wp_dequeue_style( 'apermo-notify' );
 *     }, 20 );
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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueues the stylesheet.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/css/apermo-notify.css', Main::file() ),
			[],
			Main::VERSION,
		);
	}
}
