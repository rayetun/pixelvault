/**
 * Blocks Manager — persist each block's on/off state as its toggle changes.
 */
( function ( wp ) {
	'use strict';
	var cfg = window.mnBlocksManager;
	if ( ! cfg ) { return; }
	var apiFetch = wp.apiFetch;
	if ( apiFetch && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
	}

	function init() {
		var status = document.getElementById( 'mn-bm-status' );
		document.querySelectorAll( '.mn-bm-toggle' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var block = cb.getAttribute( 'data-block' );
				cb.disabled = true;
				if ( status ) { status.textContent = '…'; }
				apiFetch( { url: cfg.restUrl, method: 'POST', data: { block: block, enabled: cb.checked } } )
					.then( function () {
						if ( status ) { status.textContent = cfg.strings.saved; }
					} )
					.catch( function () {
						cb.checked = ! cb.checked; // revert on failure
						if ( status ) { status.textContent = cfg.strings.error; }
					} )
					.finally( function () { cb.disabled = false; } );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window.wp || {} );
