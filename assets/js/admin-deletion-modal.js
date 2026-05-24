/**
 * Goodbye-notification dialog wired into the "Move to Trash" action on both
 * the posts list (row-action) and the post edit screen ("Move to Trash"
 * link). Permanent-delete clicks are ignored: the server-side cleanup hook
 * handles row removal when a trashed post is purged.
 *
 * Triggered only for posts that have at least one confirmed subscriber. The
 * count map is provided server-side via wp_localize_script.
 */
( function ( $ ) {
	'use strict';

	var data = window.apermoNotifyDeletion || {};
	var counts = data.counts || {};
	var i18n = data.i18n || {};

	if ( ! data.ajaxUrl || ! data.nonce ) {
		return;
	}

	$( document ).on( 'click', 'a.submitdelete', onDeleteClick );

	function onDeleteClick( e ) {
		var href = $( e.currentTarget ).attr( 'href' ) || '';
		var action = paramFromHref( href, 'action' );
		if ( action !== 'trash' ) {
			return;
		}

		var postId = parseInt( paramFromHref( href, 'post' ), 10 ) || 0;
		if ( ! postId ) {
			return;
		}

		var count = parseInt( counts[ postId ] || 0, 10 );
		if ( count <= 0 ) {
			return;
		}

		e.preventDefault();
		openModal( postId, count, href );
	}

	function paramFromHref( href, key ) {
		var match = new RegExp( '[?&]' + key + '=([^&#]*)' ).exec( href );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	function openModal( postId, count, deleteUrl ) {
		var $overlay = $(
			'<div class="apermo-notify-modal__overlay" role="dialog" aria-modal="true">' +
				'<div class="apermo-notify-modal__panel">' +
					'<h2 class="apermo-notify-modal__title"></h2>' +
					'<p class="apermo-notify-modal__body"></p>' +
					'<label class="apermo-notify-modal__label" for="apermo-notify-modal-note"></label>' +
					'<textarea class="apermo-notify-modal__note" id="apermo-notify-modal-note" rows="4"></textarea>' +
					'<p class="apermo-notify-modal__status" aria-live="polite"></p>' +
					'<div class="apermo-notify-modal__actions">' +
						'<button type="button" class="button button-primary apermo-notify-modal__send-and-trash"></button>' +
						'<button type="button" class="button apermo-notify-modal__trash-silently"></button>' +
						'<button type="button" class="button-link apermo-notify-modal__cancel"></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$overlay.find( '.apermo-notify-modal__title' ).text( i18n.title || '' );
		$overlay.find( '.apermo-notify-modal__body' )
			.text( ( i18n.body || '' ).replace( '%d', String( count ) ) );
		$overlay.find( '.apermo-notify-modal__label' ).text( i18n.noteLabel || '' );
		$overlay.find( '.apermo-notify-modal__send-and-trash' ).text( i18n.sendAndTrash || '' );
		$overlay.find( '.apermo-notify-modal__trash-silently' ).text( i18n.trashSilently || '' );
		$overlay.find( '.apermo-notify-modal__cancel' ).text( i18n.cancel || '' );

		$( 'body' ).append( $overlay );
		$overlay.find( 'textarea' ).trigger( 'focus' );

		$overlay.on( 'click', function ( ev ) {
			if ( ev.target === $overlay[ 0 ] ) {
				closeModal( $overlay );
			}
		} );
		$overlay.find( '.apermo-notify-modal__cancel' ).on( 'click', function () {
			closeModal( $overlay );
		} );
		$overlay.find( '.apermo-notify-modal__trash-silently' ).on( 'click', function () {
			closeModal( $overlay );
			window.location.href = deleteUrl;
		} );
		$overlay.find( '.apermo-notify-modal__send-and-trash' ).on( 'click', function () {
			sendGoodbye( $overlay, postId, deleteUrl );
		} );
	}

	function sendGoodbye( $overlay, postId, deleteUrl ) {
		var note = $overlay.find( '.apermo-notify-modal__note' ).val() || '';
		var $status = $overlay.find( '.apermo-notify-modal__status' );
		var $buttons = $overlay.find( 'button' );

		$buttons.prop( 'disabled', true );
		$status.text( i18n.sending || '' );

		$.post( data.ajaxUrl, {
			action: data.action,
			_ajax_nonce: data.nonce,
			post_id: postId,
			note: note,
		} )
			.done( function () {
				closeModal( $overlay );
				window.location.href = deleteUrl;
			} )
			.fail( function () {
				$status.text( i18n.sendFailed || '' );
				$buttons.prop( 'disabled', false );
			} );
	}

	function closeModal( $overlay ) {
		$overlay.remove();
	}

	/* ------------------------------------------------------------------
	 * Block-editor trash-confirm augmentation
	 *
	 * Gutenberg's "Move to trash" opens a ConfirmDialog (role=alertdialog)
	 * with [Cancel] [Move to trash]. We watch for that dialog to appear,
	 * and when the post has confirmed subscribers we relabel the primary
	 * button to "Notify & Move to trash" and wrap its click so the
	 * goodbye AJAX fires before WP's real trash handler runs.
	 * ------------------------------------------------------------------ */

	var currentPostId = pickPostIdFromUrl();
	var augmentedDialogs = new WeakSet();

	if ( typeof MutationObserver === 'function' && currentPostId > 0 ) {
		var observer = new MutationObserver( scanForTrashDialog );
		observer.observe( document.body, { childList: true, subtree: true } );
		// Sweep once in case the dialog opens before the observer attaches.
		scanForTrashDialog();
	}

	function scanForTrashDialog() {
		var dialogs = document.querySelectorAll(
			'[role="alertdialog"], .components-confirm-dialog__container, .components-modal__frame'
		);
		for ( var i = 0; i < dialogs.length; i++ ) {
			maybeAugmentDialog( dialogs[ i ] );
		}
	}

	function maybeAugmentDialog( dialog ) {
		if ( augmentedDialogs.has( dialog ) ) {
			return;
		}

		var primary = dialog.querySelector( '.components-button.is-primary, .components-button.is-destructive' );
		if ( ! primary ) {
			return;
		}

		// Only react when the dialog text mentions trashing — keeps us out of
		// unrelated ConfirmDialogs (publish, schedule, etc.).
		var text = ( dialog.textContent || '' ).toLowerCase();
		if ( text.indexOf( 'trash' ) === -1 && text.indexOf( 'papierkorb' ) === -1 ) {
			return;
		}

		var count = parseInt( counts[ currentPostId ] || 0, 10 );
		if ( count <= 0 ) {
			return;
		}

		augmentedDialogs.add( dialog );

		// Inject the optional-author-note textarea just above the button
		// row. Mirrors the standalone modal on the posts-list screen so
		// the trash flow's UX is consistent across both surfaces.
		var buttonRow = primary.parentNode;
		var noteId = 'apermo-notify-trash-note-' + currentPostId;

		var noteWrap = document.createElement( 'div' );
		noteWrap.className = 'apermo-notify-confirm-note';

		var noteLabel = document.createElement( 'label' );
		noteLabel.htmlFor = noteId;
		noteLabel.textContent = i18n.noteLabel || 'Optional note (added to the email body):';

		var noteInput = document.createElement( 'textarea' );
		noteInput.id = noteId;
		noteInput.rows = 4;

		noteWrap.appendChild( noteLabel );
		noteWrap.appendChild( noteInput );
		buttonRow.parentNode.insertBefore( noteWrap, buttonRow );

		// Add a sibling button so the user still has the silent
		// "Move to trash" option alongside the new
		// "Notify & Move to trash". The new button does the AJAX and then
		// programmatically triggers the original primary so WP's own
		// React-driven trash flow handles the actual deletion.
		var notifyButton = document.createElement( 'button' );
		notifyButton.type = 'button';
		notifyButton.className = primary.className;
		notifyButton.textContent = i18n.sendAndTrash || 'Notify & Move to trash';
		notifyButton.addEventListener( 'click', function () {
			notifyButton.disabled = true;
			primary.disabled = true;
			noteInput.disabled = true;
			var originalLabel = notifyButton.textContent;
			notifyButton.textContent = i18n.sending || 'Sending…';

			$.post( data.ajaxUrl, {
				action: data.action,
				_ajax_nonce: data.nonce,
				post_id: currentPostId,
				note: noteInput.value || '',
			} )
				.always( function () {
					primary.disabled = false;
					notifyButton.textContent = originalLabel;
					// Trigger the original confirm button — React's handler
					// runs and trashes the post.
					primary.click();
				} );
		} );

		buttonRow.appendChild( notifyButton );
	}

	function pickPostIdFromUrl() {
		var match = /[?&]post=(\d+)/.exec( window.location.search );
		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}
} )( jQuery );
