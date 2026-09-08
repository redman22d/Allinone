/**
 * Tutor Instructor Offline Payment — dashboard.
 *
 * The three dashboard pages are plain HTML forms. This file upgrades them to
 * AJAX so an instructor can confirm a payment without losing their place in a
 * long list, and toggles the inline edit panels.
 *
 * Every form still carries all of its data in named inputs, so if this file
 * fails to load the pages remain readable — only the interactivity is lost.
 */
( function () {
	'use strict';

	var config = window.TIOC_Dashboard || {};
	var i18n = config.i18n || {};

	/**
	 * Confirmation text for a given `data-tioc-confirm` value.
	 *
	 * @param {string} kind Confirmation kind.
	 *
	 * @return {string} Message, or an empty string when none applies.
	 */
	function confirmMessage( kind ) {
		if ( 'delete' === kind ) {
			return i18n.confirmDelete || '';
		}

		if ( 'approve' === kind ) {
			return i18n.confirmApprove || '';
		}

		if ( 'bulk_approve' === kind ) {
			return i18n.confirmBulkApprove || '';
		}

		return '';
	}

	/**
	 * Title that goes with a confirmation message, for a given
	 * `data-tioc-confirm` value.
	 *
	 * @param {string} kind Confirmation kind.
	 *
	 * @return {string} Title, or an empty string when none applies.
	 */
	function confirmTitle( kind ) {
		if ( 'delete' === kind ) {
			return i18n.confirmDeleteTitle || '';
		}

		if ( 'approve' === kind ) {
			return i18n.confirmApproveTitle || '';
		}

		if ( 'bulk_approve' === kind ) {
			return i18n.confirmBulkApproveTitle || '';
		}

		return '';
	}

	/**
	 * Show a custom confirmation dialog in place of the browser's native
	 * window.confirm(), which on some hosts renders as an unbranded,
	 * disorienting "example.com says" popup.
	 *
	 * @param {string}   message  Body text.
	 * @param {string}   title    Optional heading.
	 * @param {Function} onConfirm Called when the user confirms.
	 *
	 * @return {void}
	 */
	function showConfirmModal( message, title, onConfirm ) {
		var overlay = document.createElement( 'div' );
		var inner = document.createElement( 'div' );
		var actions = document.createElement( 'div' );
		var cancelBtn = document.createElement( 'button' );
		var okBtn = document.createElement( 'button' );
		var previousActive = document.activeElement;

		overlay.className = 'tioc-confirm-modal';
		inner.className = 'tioc-confirm-modal-inner';
		inner.setAttribute( 'role', 'alertdialog' );
		inner.setAttribute( 'aria-modal', 'true' );

		if ( title ) {
			var heading = document.createElement( 'p' );
			heading.className = 'tioc-confirm-modal-title';
			heading.textContent = title;
			inner.appendChild( heading );
			inner.setAttribute( 'aria-label', title );
		}

		var body = document.createElement( 'p' );
		body.className = 'tioc-confirm-modal-message';
		body.textContent = message;
		inner.appendChild( body );

		actions.className = 'tioc-confirm-modal-actions';

		cancelBtn.type = 'button';
		cancelBtn.className = 'tioc-btn tioc-btn-ghost';
		cancelBtn.textContent = i18n.confirmCancel || 'Cancel';

		okBtn.type = 'button';
		okBtn.className = 'tioc-btn tioc-btn-primary';
		okBtn.textContent = i18n.confirmOk || 'OK';

		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		inner.appendChild( actions );
		overlay.appendChild( inner );

		function close() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKeydown );

			if ( previousActive && previousActive.focus ) {
				previousActive.focus();
			}
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		}

		cancelBtn.addEventListener( 'click', close );
		okBtn.addEventListener( 'click', function () {
			close();
			onConfirm();
		} );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', onKeydown );
		document.body.appendChild( overlay );
		okBtn.focus();
	}

	/**
	 * The notice area closest to a form.
	 *
	 * @param {HTMLFormElement} form Form.
	 *
	 * @return {HTMLElement|null} Notice slot.
	 */
	function noticeSlot( form ) {
		var page = form.closest ? form.closest( '.tioc-dash' ) : null;

		return ( page || document ).querySelector( '.tioc-notice-slot' );
	}

	/**
	 * Show a message above the page content.
	 *
	 * @param {HTMLFormElement} form    Form the message belongs to.
	 * @param {string}          message Message text.
	 * @param {string}          type    success|error.
	 *
	 * @return {void}
	 */
	function notify( form, message, type ) {
		var slot = noticeSlot( form );

		if ( ! slot ) {
			if ( 'error' === type ) {
				window.alert( message );
			}

			return;
		}

		slot.innerHTML = '';

		var alert = document.createElement( 'div' );
		alert.className = 'tioc-alert tioc-alert-' + ( 'error' === type ? 'error' : 'success' );
		alert.textContent = message;
		slot.appendChild( alert );

		if ( slot.scrollIntoView ) {
			slot.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	/**
	 * Disable a form's buttons while it is in flight.
	 *
	 * @param {HTMLFormElement} form    Form.
	 * @param {boolean}         busy    Whether the request is running.
	 *
	 * @return {void}
	 */
	function setBusy( form, busy ) {
		var buttons = form.querySelectorAll( 'button, input[type="submit"]' );
		var i;

		for ( i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = busy;
		}

		if ( busy ) {
			form.setAttribute( 'aria-busy', 'true' );
		} else {
			form.removeAttribute( 'aria-busy' );
		}
	}

	/**
	 * Send one form to admin-ajax.
	 *
	 * @param {HTMLFormElement} form Form with a data-tioc-action attribute.
	 *
	 * @return {void}
	 */
	function submitForm( form ) {
		var action = form.getAttribute( 'data-tioc-action' );
		var data = new FormData( form );

		data.append( 'action', action );
		data.append( 'nonce', config.nonce || '' );

		setBusy( form, true );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: i18n.error } };
			} );
		} ).then( function ( payload ) {
			var body = payload && payload.data ? payload.data : {};
			var message = body.message || ( payload && payload.success ? '' : i18n.error );

			if ( ! payload || ! payload.success ) {
				setBusy( form, false );
				notify( form, message || i18n.error, 'error' );

				return;
			}

			if ( body.reload ) {
				// The list, the counters and the nav badge all change together, so a
				// reload is both simpler and more honest than patching the DOM.
				window.location.reload();

				return;
			}

			setBusy( form, false );
			notify( form, message, 'success' );
		} ).catch( function () {
			setBusy( form, false );
			notify( form, i18n.error, 'error' );
		} );
	}

	/**
	 * Handle a submission, including any confirmation step.
	 *
	 * @param {Event} event Submit event.
	 *
	 * @return {void}
	 */
	function onSubmit( event ) {
		var form = event.target;

		if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-tioc-action' ) ) {
			return;
		}

		event.preventDefault();

		if ( form.getAttribute( 'aria-busy' ) ) {
			return;
		}

		if ( form.hasAttribute( 'data-tioc-bulk' ) ) {
			var checked = document.querySelectorAll( '.tioc-bulk-checkbox:checked' );

			// Re-injecting fresh each time keeps this correct even if the
			// selection changed between an aborted submit and this one.
			var stale = form.querySelectorAll( '[data-tioc-bulk-id]' );
			var s;

			for ( s = 0; s < stale.length; s++ ) {
				stale[ s ].remove();
			}

			if ( ! checked.length ) {
				notify( form, i18n.bulkNone || '', 'error' );
				return;
			}

			var c;
			for ( c = 0; c < checked.length; c++ ) {
				var hiddenInput = document.createElement( 'input' );
				hiddenInput.type = 'hidden';
				hiddenInput.name = 'order_ids[]';
				hiddenInput.value = checked[ c ].value;
				hiddenInput.setAttribute( 'data-tioc-bulk-id', '1' );
				form.appendChild( hiddenInput );
			}
		}

		var kind = form.getAttribute( 'data-tioc-confirm' );

		if ( 'reject' === kind ) {
			var field = form.querySelector( '[name="reason"]' );

			if ( ! field || ! field.value.trim() ) {
				notify( form, i18n.error || '', 'error' );
				return;
			}

			submitForm( form );
			return;
		}

		if ( kind ) {
			var message = confirmMessage( kind );

			if ( message ) {
				showConfirmModal( message, confirmTitle( kind ), function () {
					submitForm( form );
				} );
				return;
			}
		}

		submitForm( form );
	}

	/**
	 * Show or hide an inline panel.
	 *
	 * @param {Event} event Click event.
	 *
	 * @return {void}
	 */
	function onClick( event ) {
		var rejectOpen = event.target.closest ? event.target.closest( '[data-tioc-reject-open]' ) : null;
		var rejectCancel = event.target.closest ? event.target.closest( '[data-tioc-reject-cancel]' ) : null;
		var trigger;
		var panel;
		var hidden;

		if ( rejectOpen || rejectCancel ) {
			var rejectForm = ( rejectOpen || rejectCancel ).closest( '.tioc-reject-form' );
			var rejectPanel = rejectForm ? rejectForm.querySelector( '.tioc-reject-panel' ) : null;
			var reason = rejectForm ? rejectForm.querySelector( '[name="reason"]' ) : null;

			if ( ! rejectPanel ) {
				return;
			}

			event.preventDefault();
			if ( rejectOpen ) {
				rejectPanel.removeAttribute( 'hidden' );
				rejectOpen.setAttribute( 'hidden', 'hidden' );
				rejectOpen.setAttribute( 'aria-expanded', 'true' );
				if ( reason ) {
					reason.focus();
				}
			} else {
				rejectPanel.setAttribute( 'hidden', 'hidden' );
				if ( reason ) {
					reason.value = '';
				}
				var openButton = rejectForm.querySelector( '[data-tioc-reject-open]' );
				if ( openButton ) {
					openButton.removeAttribute( 'hidden' );
					openButton.setAttribute( 'aria-expanded', 'false' );
					openButton.focus();
				}
			}
			return;
		}

		trigger = event.target.closest ? event.target.closest( '[data-tioc-toggle]' ) : null;
		if ( ! trigger ) {
			return;
		}

		panel = document.getElementById( trigger.getAttribute( 'data-tioc-toggle' ) );
		if ( ! panel ) {
			return;
		}

		event.preventDefault();
		hidden = panel.hasAttribute( 'hidden' );
		if ( hidden ) {
			panel.removeAttribute( 'hidden' );
		} else {
			panel.setAttribute( 'hidden', 'hidden' );
		}
		trigger.setAttribute( 'aria-expanded', hidden ? 'true' : 'false' );
	}

	/**
	 * Open an image receipt in a small popup instead of a new tab.
	 * Non-image files (PDFs, etc.) are left to open normally.
	 *
	 * @param {Event} event Click event.
	 *
	 * @return {void}
	 */
	function onReceiptClick( event ) {
		var link = event.target.closest ? event.target.closest( '[data-tioc-receipt]' ) : null;

		if ( ! link ) {
			return;
		}

		var mime = link.getAttribute( 'data-mime' ) || '';

		if ( 0 !== mime.indexOf( 'image/' ) ) {
			return;
		}

		event.preventDefault();

		var overlay = document.createElement( 'div' );
		overlay.className = 'tioc-receipt-modal';
		overlay.innerHTML =
			'<div class="tioc-receipt-modal-inner">' +
				'<button type="button" class="tioc-receipt-modal-close" aria-label="Close">&times;</button>' +
				'<img src="' + link.href + '" alt="">' +
			'</div>';

		function close() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKeydown );
		}

		function onKeydown( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		}

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay || e.target.classList.contains( 'tioc-receipt-modal-close' ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', onKeydown );
		document.body.appendChild( overlay );
	}

	/**
	 * Bind the two delegated listeners.
	 *
	 * @return {void}
	 */
	/**
	 * Show only the typed fields for the selected payment profile. Fields remain
	 * named so FormData submits both legacy instructions and typed profiles.
	 *
	 * @param {HTMLFormElement} form Payment-method form.
	 * @return {void}
	 */
	function syncProfileFields( form ) {
		var select = form.querySelector( '.tioc-profile-type' );
		var fields;
		var i;

		if ( ! select ) {
			return;
		}

		fields = form.querySelectorAll( '[data-tioc-profile]' );
		for ( i = 0; i < fields.length; i++ ) {
			var isActive = fields[ i ].getAttribute( 'data-tioc-profile' ) === select.value;
			var controls = fields[ i ].querySelectorAll( 'input, select, textarea, button' );
			var c;

			fields[ i ].hidden = ! isActive;

			// Several profile panels intentionally use the same field names (for
			// example profile[account_holder]). Disabled controls are excluded from
			// FormData, so only the currently selected payment type is submitted.
			for ( c = 0; c < controls.length; c++ ) {
				controls[ c ].disabled = ! isActive;
			}
		}
	}

	function syncAllProfileFields() {
		var forms = document.querySelectorAll( '.tioc-method-form' );
		var i;

		for ( i = 0; i < forms.length; i++ ) {
			syncProfileFields( forms[ i ] );
		}
	}

	function onChange( event ) {
		var select = event.target.closest ? event.target.closest( '.tioc-profile-type' ) : null;

		if ( select && select.form ) {
			syncProfileFields( select.form );
		}
	}

	function init() {
		if ( ! window.fetch || ! window.FormData || ! config.ajaxUrl ) {
			return;
		}

		var rejectPanels = document.querySelectorAll( '.tioc-reject-panel' );
		var rejectButtons = document.querySelectorAll( '[data-tioc-reject-open]' );
		var i;
		for ( i = 0; i < rejectPanels.length; i++ ) {
			rejectPanels[ i ].setAttribute( 'hidden', 'hidden' );
		}
		for ( i = 0; i < rejectButtons.length; i++ ) {
			rejectButtons[ i ].removeAttribute( 'hidden' );
		}

		syncAllProfileFields();
		document.addEventListener( 'submit', onSubmit );
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'click', onReceiptClick );
		document.addEventListener( 'change', onChange );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
