/**
 * Block-editor notice that asks whether to notify confirmed subscribers
 * after every successful update of an already-published post.
 *
 * Replaces the metabox checkbox that required the author to opt in *before*
 * saving. The new flow runs entirely post-save: detect a successful update,
 * surface a persistent editor notice with a "Notify subscribers" action,
 * hit the REST endpoint when the user clicks it.
 *
 * Pure ES5 + global `wp.*` — no build pipeline.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var config = window.apermoNotifyEditor || {};
	if ( ! config.postId ) {
		return;
	}

	var OFFER_NOTICE_ID = 'apermo-notify-update-offer';
	var REST_PATH_DISPATCH = '/apermo-notify/v1/dispatch-update';
	var REST_PATH_COUNT = '/apermo-notify/v1/subscriber-count';

	var i18n = config.i18n || {};
	var notices = wp.data.dispatch( 'core/notices' );
	var editor = wp.data.select( 'core/editor' );

	if ( ! editor || ! notices ) {
		return;
	}

	// Tracks whether a save is currently in flight. We need to detect the
	// transition from "saving" → "not saving" *after* the save succeeded,
	// because reading didPostSaveRequestSucceed while saving is still in
	// progress always returns true (it reflects the previous save).
	var wasSaving = false;

	// Refresh-on-load: if the post is already published, every subsequent
	// update should offer the snackbar.
	var publishedBeforeFirstSave = !! config.wasPublished;

	wp.data.subscribe( function () {
		var saving = editor.isSavingPost();
		var autosaving = editor.isAutosavingPost();

		if ( autosaving ) {
			return;
		}

		if ( saving && ! wasSaving ) {
			wasSaving = true;
			return;
		}

		if ( ! saving && wasSaving ) {
			wasSaving = false;

			if ( ! editor.didPostSaveRequestSucceed() ) {
				return;
			}

			var status = editor.getEditedPostAttribute( 'status' );
			if ( status !== 'publish' ) {
				return;
			}

			if ( ! publishedBeforeFirstSave ) {
				// First publish ran the auto-notify on the server. From the
				// next save onward the post is considered published.
				publishedBeforeFirstSave = true;
				return;
			}

			// Live count: editors can confirm subscriptions in another tab
			// after the editor loaded, so the localized count is unreliable.
			wp.apiFetch( {
				path: REST_PATH_COUNT + '?post_id=' + encodeURIComponent( config.postId ),
				method: 'GET',
			} )
				.then( function ( response ) {
					var count = response && response.count ? parseInt( response.count, 10 ) : 0;
					if ( count > 0 ) {
						offerNotify( count );
					}
				} )
				.catch( function () {
					// Silent — failing to fetch the count just means no offer
					// surfaces; the user can still trigger a notification by
					// editing again.
				} );
		}
	} );

	function offerNotify( count ) {
		// Persistent top-of-editor notice (no `type: 'snackbar'`). The same
		// stable id means a second save replaces the previous offer in
		// place instead of stacking duplicates.
		notices.createInfoNotice(
			( i18n.offer || '' ).replace( '%d', String( count ) ),
			{
				id: OFFER_NOTICE_ID,
				isDismissible: true,
				actions: [
					{
						label: i18n.notify || 'Notify subscribers',
						onClick: function () {
							notices.removeNotice( OFFER_NOTICE_ID );
							dispatchNotify( count );
						},
					},
				],
			},
		);
	}

	function dispatchNotify( fallbackCount ) {
		// `path:` (not `url:`) so apiFetch's default middlewares prepend the
		// REST root and add the X-WP-Nonce header — without those the
		// permission_callback sees an anonymous request and rejects.
		wp.apiFetch( {
			path: REST_PATH_DISPATCH,
			method: 'POST',
			data: { post_id: config.postId },
		} )
			.then( function ( response ) {
				var queued = response && response.queued ? response.queued : fallbackCount;
				notices.createSuccessNotice(
					( i18n.sent || '' ).replace( '%d', String( queued ) ),
					{ type: 'snackbar' },
				);
			} )
			.catch( function () {
				notices.createErrorNotice(
					i18n.failure || 'Could not queue the update notification.',
					{ type: 'snackbar' },
				);
			} );
	}
} )( window.wp );
