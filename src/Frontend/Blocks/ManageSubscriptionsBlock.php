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
	 * Registers the block from its `block.json` manifest.
	 *
	 * @return void
	 */
	public static function register_block_type(): void {
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
