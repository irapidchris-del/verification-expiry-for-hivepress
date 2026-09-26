/* Verification Expiry for HivePress: front-end behaviour.
 *
 * Three things, all on the Verification card:
 *   1. "Start verification with Stripe": posts the start route, then polls the redirect route
 *      every two seconds for up to a minute and follows the URL it hands back once.
 *   2. A confirm before a file is removed, ahead of core's own file-delete handler.
 *   3. Nothing user-supplied ever goes through innerHTML; messages are set with textContent.
 */
( function ( $ ) {
	'use strict';

	var labels = ( window.hpveFrontendData && window.hpveFrontendData.labels ) || {};

	function nonce() {
		return window.hivepressCoreData ? window.hivepressCoreData.apiNonce : '';
	}

	/**
	 * Shows a message under the card's buttons.
	 *
	 * @param {Element} card The card element.
	 * @param {string}  text The message.
	 */
	function say( card, text ) {
		var box = card.querySelector( '.hpve-card__message' );

		if ( ! box ) {
			return;
		}

		box.textContent = text;
		box.hidden = ! text;
	}

	/**
	 * Polls the redirect route until a URL arrives or the time runs out.
	 *
	 * @param {Element} button The start button.
	 * @param {Element} card The card element.
	 * @param {number}  started When polling began, in milliseconds.
	 */
	function poll( button, card, started ) {
		if ( Date.now() - started > 60000 ) {
			button.removeAttribute( 'data-state' );
			button.removeAttribute( 'disabled' );
			say( card, labels.timeout || 'Sorry, that took too long. Please try again in a minute.' );

			return;
		}

		$.ajax( {
			url: button.getAttribute( 'data-hpve-poll' ),
			method: 'GET',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', nonce() );
			},
			complete: function ( xhr ) {
				var response = xhr.responseJSON;

				if ( xhr.status === 200 && response && response.data && response.data.url ) {
					window.location.href = response.data.url;

					return;
				}

				if ( xhr.status !== 200 ) {
					button.removeAttribute( 'data-state' );
					button.removeAttribute( 'disabled' );
					say( card, labels.failed || 'Something went wrong. Please try again.' );

					return;
				}

				window.setTimeout( function () {
					poll( button, card, started );
				}, 2000 );
			}
		} );
	}

	$( document ).on( 'click', '[data-hpve-start]', function ( e ) {
		var button = this,
			card = button.closest( '.hpve-card' );

		e.preventDefault();

		if ( button.hasAttribute( 'disabled' ) ) {
			return;
		}

		button.setAttribute( 'disabled', 'disabled' );
		button.setAttribute( 'data-state', 'loading' );
		say( card, '' );

		$.ajax( {
			url: button.getAttribute( 'data-hpve-start' ),
			method: 'POST',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', nonce() );
			},
			complete: function ( xhr ) {
				var response = xhr.responseJSON,
					message = labels.failed || 'Something went wrong. Please try again.';

				if ( xhr.status === 202 ) {
					poll( button, card, Date.now() );

					return;
				}

				if ( response && response.error && response.error.errors && response.error.errors[ 0 ] ) {
					message = response.error.errors[ 0 ].message;
				}

				button.removeAttribute( 'data-state' );
				button.removeAttribute( 'disabled' );
				say( card, message );
			}
		} );
	} );

	// Capturing listener, so the confirm runs before core's own delete handler on the document.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '.hpve-file__delete' ) : null;

		if ( ! link ) {
			return;
		}

		if ( ! window.confirm( labels.confirmDelete || 'Remove this file?' ) ) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	}, true );
} )( jQuery );
