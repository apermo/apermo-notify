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
} )( jQuery );
