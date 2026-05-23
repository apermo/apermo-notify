/**
 * Goodbye-notification dialog wired into the posts list's "Delete Permanently"
 * row action. Triggered only for posts that have at least one confirmed
 * subscriber — count map is provided server-side via wp_localize_script.
 */
( function ( $ ) {
	'use strict';

	var data = window.apermoNotifyDeletion || {};
	var counts = data.counts || {};
	var i18n = data.i18n || {};

	if ( ! data.ajaxUrl || ! data.nonce ) {
		return;
	}

	/**
	 * Attaches a one-click handler on the row-action delete links. We bind
	 * delegated on document so it survives Quick Edit re-renders.
	 */
	$( document ).on( 'click', 'a.submitdelete', onDeleteClick );

	function onDeleteClick( e ) {
		var $link = $( e.currentTarget );
		var $row = $link.closest( 'tr' );
		if ( $row.length === 0 ) {
			return;
		}
		var postId = parseInt( ( $row.attr( 'id' ) || '' ).replace( 'post-', '' ), 10 );
		if ( ! postId ) {
			return;
		}
		var count = parseInt( counts[ postId ] || 0, 10 );
		if ( count <= 0 ) {
			return;
		}

		e.preventDefault();
		openModal( postId, count, $link.attr( 'href' ) );
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
						'<button type="button" class="button button-primary apermo-notify-modal__send"></button>' +
						'<button type="button" class="button apermo-notify-modal__silent"></button>' +
						'<button type="button" class="button-link apermo-notify-modal__cancel"></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$overlay.find( '.apermo-notify-modal__title' ).text( i18n.title || '' );
		$overlay.find( '.apermo-notify-modal__body' )
			.text( ( i18n.body || '' ).replace( '%d', String( count ) ) );
		$overlay.find( '.apermo-notify-modal__label' ).text( i18n.noteLabel || '' );
		$overlay.find( '.apermo-notify-modal__send' ).text( i18n.sendAndDelete || '' );
		$overlay.find( '.apermo-notify-modal__silent' ).text( i18n.deleteSilently || '' );
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
		$overlay.find( '.apermo-notify-modal__silent' ).on( 'click', function () {
			closeModal( $overlay );
			window.location.href = deleteUrl;
		} );
		$overlay.find( '.apermo-notify-modal__send' ).on( 'click', function () {
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
