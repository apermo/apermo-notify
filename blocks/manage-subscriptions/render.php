<?php
/**
 * Renders the `apermo-notify/manage-subscriptions` block.
 *
 * `block.json` points at this file via `render: "file:./render.php"`, so
 * WordPress evaluates it server-side on every front-end render *and* on
 * every REST preview the block editor requests. The actual HTML lives on
 * `ManagePage::render_block_html()` so the unit tests can exercise it
 * without booting the block API.
 *
 * Variables provided by core: `$attributes`, `$content`, `$block`.
 *
 * @package Apermo\Notify
 */

declare(strict_types=1);

\defined( 'ABSPATH' ) || exit();

echo \Apermo\Notify\Frontend\ManagePage::render_block_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built and escaped inside ManagePage::render_block_html().
