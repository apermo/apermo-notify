/**
 * Editor-side registration for the `apermo-notify/manage-subscriptions` block.
 *
 * The block has no settings of its own — it just renders the manage UI
 * server-side via `render.php` based on the visitor's URL state. We still
 * need a JS-side `registerBlockType` so the editor knows the block exists
 * and shows a preview (otherwise the editor surfaces a "Your site doesn't
 * include support for this block" warning when admins open a page that
 * contains it).
 *
 * Pure ES5 + global `wp.*` — no build pipeline.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var el = wp.element.createElement;

	registerBlockType( 'apermo-notify/manage-subscriptions', {
		edit: function () {
			var props = useBlockProps();
			if ( ServerSideRender ) {
				return el(
					'div',
					props,
					el( ServerSideRender, {
						block: 'apermo-notify/manage-subscriptions',
					} ),
				);
			}
			// Fallback for editors without wp-server-side-render loaded.
			return el(
				'div',
				props,
				el( 'em', null, 'Apermo Notify — Manage subscriptions (rendered on the front-end)' ),
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
