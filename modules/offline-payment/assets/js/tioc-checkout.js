/**
 * Tutor Instructor Offline Payment — checkout.
 *
 * Three small jobs, no dependencies:
 *
 * 1. reveal the coupon field (the field itself posts to a sibling GET form via
 *    the HTML `form=` attribute, so no AJAX is involved),
 * 2. validate receipt uploads before the browser sends several megabytes,
 * 3. ask once before submitting, because the student is telling us they have
 *    already sent money.
 *
 * Everything degrades: with JS off, the coupon field is simply always visible
 * once the page is rendered without the `tutor-d-none` class, uploads are
 * validated again server-side, and the confirmation is skipped.
 */
( function () {
	'use strict';

	var config = window.TIOC_Checkout || {};
	var i18n = config.i18n || {};

	/**
	 * Fill a single %s placeholder.
	 *
	 * @param {string} template Message with one %s.
	 * @param {string} value    Replacement.
	 *
	 * @return {string} Formatted message.
	 */
	function format( template, value ) {
		return String( template || '' ).replace( '%s', value );
	}

	/**
	 * Lowercase extension of a filename, without the dot.
	 *
	 * @param {string} name Filename.
	 *
	 * @return {string} Extension.
	 */
	function extensionOf( name ) {
		var parts = String( name || '' ).split( '.' );

		return parts.length > 1 ? parts.pop().toLowerCase() : '';
	}

	/**
	 * Show or clear the error message that belongs to one field.
	 *
	 * @param {HTMLInputElement} input   File input.
	 * @param {string}           message Empty string clears.
	 *
	 * @return {void}
	 */
	function setError( input, message ) {
		var slot = input.parentNode.querySelector( '.tioc-field-error' );

		if ( message ) {
			if ( ! slot ) {
				slot = document.createElement( 'p' );
				slot.className = 'tioc-field-error';
				slot.setAttribute( 'role', 'alert' );
				input.parentNode.insertBefore( slot, input.nextSibling );
			}

			slot.textContent = message;
			input.setAttribute( 'aria-invalid', 'true' );
		} else {
			if ( slot ) {
				slot.parentNode.removeChild( slot );
			}

			input.removeAttribute( 'aria-invalid' );
		}

		// Keeps the browser's own validation in step, so a bad file also blocks
		// submission on its own.
		if ( input.setCustomValidity ) {
			input.setCustomValidity( message );
		}
	}

	/**
	 * Check one receipt input against the configured limits.
	 *
	 * @param {HTMLInputElement} input File input.
	 *
	 * @return {boolean} Whether the file is acceptable.
	 */
	function validateInput( input ) {
		var file = input.files && input.files[ 0 ];
		var extensions = config.extensions || [];
		var maxBytes = parseInt( config.maxUploadBytes, 10 ) || 0;
		var extension;

		if ( ! file ) {
			setError( input, '' );

			return true;
		}

		if ( maxBytes && file.size > maxBytes ) {
			setError( input, format( i18n.tooLarge, config.maxUploadLabel || '' ) );

			return false;
		}

		extension = extensionOf( file.name );

		if ( extensions.length && extensions.indexOf( extension ) === -1 ) {
			setError( input, format( i18n.badType, extensions.join( ', ' ) ) );

			return false;
		}

		setError( input, '' );

		return true;
	}

	/**
	 * Show a custom confirmation dialog in place of the browser's native
	 * window.confirm(), which on some hosts renders as an unbranded,
	 * disorienting "example.com says" popup right before the student pays.
	 *
	 * @param {string}   message   Body text.
	 * @param {string}   title     Optional heading.
	 * @param {Function} onConfirm Called when the student confirms.
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
	 * Copy text with a modern clipboard API and a small legacy fallback.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise|void} Clipboard promise when available.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
		} catch ( error ) {
			// Best effort; the text remains available in the dialog.
		}
		area.remove();
	}

	/**
	 * Offer web-mail/copy fallbacks for visitors without a mail application.
	 *
	 * @param {HTMLElement} link Email checkout link.
	 * @return {void}
	 */
	function showEmailFallbackModal( link ) {
		var email = link.getAttribute( 'data-tioc-email' ) || '';
		var subject = link.getAttribute( 'data-tioc-email-subject' ) || '';
		var body = link.getAttribute( 'data-tioc-email-body' ) || '';
		var overlay = document.createElement( 'div' );
		var inner = document.createElement( 'div' );
		var actions = document.createElement( 'div' );
		var previousActive = document.activeElement;

		if ( ! email ) {
			return;
		}

		overlay.className = 'tioc-confirm-modal tioc-email-fallback-modal';
		inner.className = 'tioc-confirm-modal-inner';
		inner.setAttribute( 'role', 'dialog' );
		inner.setAttribute( 'aria-modal', 'true' );

		var heading = document.createElement( 'p' );
		heading.className = 'tioc-confirm-modal-title';
		heading.textContent = i18n.emailTitle || 'Email instructor';
		inner.appendChild( heading );

		var help = document.createElement( 'p' );
		help.className = 'tioc-confirm-modal-message';
		help.textContent = i18n.emailHelp || 'Choose how you want to send the payment message.';
		inner.appendChild( help );

		var address = document.createElement( 'div' );
		address.className = 'tioc-email-fallback-address';
		address.textContent = email;
		inner.appendChild( address );

		var openApp = document.createElement( 'a' );
		openApp.className = 'tioc-btn tioc-btn-primary';
		openApp.href = 'mailto:' + email + '?subject=' + encodeURIComponent( subject ) + '&body=' + encodeURIComponent( body );
		openApp.textContent = i18n.emailOpenApp || 'Open email app';
		inner.appendChild( openApp );

		var webActions = document.createElement( 'div' );
		webActions.className = 'tioc-email-fallback-grid';
		var gmail = document.createElement( 'a' );
		gmail.className = 'tioc-btn tioc-btn-ghost';
		gmail.href = 'https://mail.google.com/mail/?view=cm&fs=1&to=' + encodeURIComponent( email ) + '&su=' + encodeURIComponent( subject ) + '&body=' + encodeURIComponent( body );
		gmail.target = '_blank';
		gmail.rel = 'noopener noreferrer';
		gmail.textContent = i18n.emailGmail || 'Open Gmail';
		webActions.appendChild( gmail );

		var outlook = document.createElement( 'a' );
		outlook.className = 'tioc-btn tioc-btn-ghost';
		outlook.href = 'https://outlook.live.com/mail/0/deeplink/compose?to=' + encodeURIComponent( email ) + '&subject=' + encodeURIComponent( subject ) + '&body=' + encodeURIComponent( body );
		outlook.target = '_blank';
		outlook.rel = 'noopener noreferrer';
		outlook.textContent = i18n.emailOutlook || 'Open Outlook';
		webActions.appendChild( outlook );
		inner.appendChild( webActions );

		actions.className = 'tioc-confirm-modal-actions tioc-email-fallback-actions';
		var copyEmail = document.createElement( 'button' );
		copyEmail.type = 'button';
		copyEmail.className = 'tioc-btn tioc-btn-ghost';
		copyEmail.textContent = i18n.emailCopyAddress || 'Copy email';
		copyEmail.addEventListener( 'click', function () {
			Promise.resolve( copyText( email ) ).then( function () {
				copyEmail.textContent = i18n.emailCopied || 'Copied';
				setTimeout( function () { copyEmail.textContent = i18n.emailCopyAddress || 'Copy email'; }, 1400 );
			} );
		} );
		actions.appendChild( copyEmail );

		var copyMessage = document.createElement( 'button' );
		copyMessage.type = 'button';
		copyMessage.className = 'tioc-btn tioc-btn-ghost';
		copyMessage.textContent = i18n.emailCopyMessage || 'Copy message';
		copyMessage.addEventListener( 'click', function () {
			Promise.resolve( copyText( body ) ).then( function () {
				copyMessage.textContent = i18n.emailCopied || 'Copied';
				setTimeout( function () { copyMessage.textContent = i18n.emailCopyMessage || 'Copy message'; }, 1400 );
			} );
		} );
		actions.appendChild( copyMessage );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'tioc-btn tioc-btn-ghost';
		closeBtn.textContent = i18n.confirmCancel || 'Close';
		actions.appendChild( closeBtn );
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

		closeBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKeydown );
		document.body.appendChild( overlay );
		openApp.focus();
	}

	/**
	 * Wire everything up.
	 *
	 * @return {void}
	 */
	function init() {
		var form = document.getElementById( 'tioc-checkout-form' );
		var toggle = document.getElementById( 'tioc-toggle-coupon' );
		var inputs = document.querySelectorAll( '.tioc-proof-input' );
		var emailLinks = document.querySelectorAll( '.tioc-contact-email[data-tioc-email]' );
		var i;

		for ( i = 0; i < emailLinks.length; i++ ) {
			emailLinks[ i ].addEventListener( 'click', function ( event ) {
				// Show the fallback first so visitors without a mail handler still have
				// a clear path to Gmail, Outlook, or copy/paste.
				event.preventDefault();
				showEmailFallbackModal( event.currentTarget );
			} );
		}

		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var box = document.querySelector( '.tioc-apply-coupon' );

				if ( ! box ) {
					return;
				}

				box.classList.remove( 'tutor-d-none' );
				toggle.parentNode.classList.add( 'tutor-d-none' );

				var field = box.querySelector( 'input[name="coupon_code"]' );

				if ( field ) {
					field.focus();
				}
			} );
		}

		for ( i = 0; i < inputs.length; i++ ) {
			inputs[ i ].addEventListener( 'change', function ( event ) {
				validateInput( event.target );
			} );
		}

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var valid = true;
			var first = null;
			var index;

			for ( index = 0; index < inputs.length; index++ ) {
				if ( ! validateInput( inputs[ index ] ) ) {
					valid = false;

					if ( ! first ) {
						first = inputs[ index ];
					}
				}
			}

			if ( ! valid ) {
				event.preventDefault();

				if ( first ) {
					first.focus();
				}

				return;
			}

			// Stop a double submission creating two orders per instructor.
			var submit = document.getElementById( 'tioc-submit-order' );

			function disableSubmit() {
				if ( submit ) {
					submit.disabled = true;
					submit.setAttribute( 'aria-busy', 'true' );
				}
			}

			if ( i18n.confirm ) {
				event.preventDefault();

				showConfirmModal( i18n.confirm, i18n.confirmTitle, function () {
					disableSubmit();

					// A native submit() does not re-fire the 'submit' event, so
					// this runs the actual submission after the modal closes.
					HTMLFormElement.prototype.submit.call( form );
				} );

				return;
			}

			disableSubmit();
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
