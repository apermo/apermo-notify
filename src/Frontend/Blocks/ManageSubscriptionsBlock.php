<?php

declare(strict_types=1);

namespace Apermo\Notify\Frontend\Blocks;

\defined( 'ABSPATH' ) || exit();

use Apermo\Notify\Main;

/**
 * Registers the `apermo-notify/manage-subscriptions` block.
 *
 * The block is PHP-only (server rendered via `block.json` →
 * `render: "file:./render.php"`); the JS side ships nothing of its own,
 * the editor uses the standard server-side-render preview to surface
 * what the block will output.
 */
final class ManageSubscriptionsBlock {

	/**
	 * Block name as it appears in `block.json`.
	 */
	public const NAME = 'apermo-notify/manage-subscriptions';

	/**
	 * Script handle for the editor-side block registration. `block.json`
	 * references this handle via its `editorScript` field; the handle
	 * itself is registered in `register_block_type()` below so we can
	 * declare the right `wp-*` dependencies without shipping a
	 * webpack-style `.asset.php` file.
	 */
	private const EDITOR_SCRIPT_HANDLE = 'apermo-notify-manage-subscriptions-editor';

	/**
	 * Registers the block from its `block.json` manifest.
	 *
	 * @return void
	 */
	public static function register_block_type(): void {
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			plugins_url( 'blocks/manage-subscriptions/index.js', Main::file() ),
			[
				'wp-blocks',
				'wp-block-editor',
				'wp-element',
				'wp-server-side-render',
			],
			Main::VERSION,
			true,
		);

		register_block_type( \dirname( Main::file() ) . '/blocks/manage-subscriptions' );
	}

	/**
	 * Wires the registration on `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_block_type' ] );
	}
}
