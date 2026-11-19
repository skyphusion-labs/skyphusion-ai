/**
 * Count the posts an embedding run would send, one small page per request.
 *
 * The settings screen does not load every post. This asks the server for
 * one page at a time and shows the estimate when the last page is counted.
 * No library. No build step.
 */
( function () {
	'use strict';

	var button = document.querySelector( '.skyphusion-ai-embed-count' );
	var out = document.querySelector( '.skyphusion-ai-embed-estimate' );
	if ( ! button || ! out || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}

	function say( text ) {
		out.textContent = text;
	}

	function working( seen ) {
		return String( button.getAttribute( 'data-working' ) || '' ).replace( '%d', String( seen ) );
	}

	function fail() {
		say( button.getAttribute( 'data-failed' ) || '' );
		button.disabled = false;
	}

	function step( page ) {
		var body = new URLSearchParams();
		body.set( 'action', 'skyphusion_ai_embed_count' );
		body.set( '_ajax_nonce', button.getAttribute( 'data-nonce' ) || '' );
		body.set( 'page', String( page ) );
		window.fetch( button.getAttribute( 'data-url' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} ).then( function ( json ) {
			var data = json && json.success ? json.data : null;
			if ( ! data ) {
				throw new Error( 'no data' );
			}
			if ( 'done' === data.state ) {
				say( String( data.text || '' ) );
				button.disabled = false;
				return;
			}
			if ( 'more' !== data.state || ! data.next ) {
				throw new Error( 'state ' + data.state );
			}
			say( working( data.seen ) );
			step( data.next );
		} ).catch( fail );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		say( working( 0 ) );
		step( 1 );
	} );
}() );
