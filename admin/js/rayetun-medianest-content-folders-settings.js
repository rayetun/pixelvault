/**
 * Content Folders - settings-tab post-type chooser.
 *
 * Saves the set of content types that get folders as soon as a card toggle changes.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.mnCFSettings;
	if ( ! cfg ) { return; }
	var apiFetch = wp.apiFetch;
	if ( apiFetch && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
	}

	function init() {
		var box = document.getElementById( 'mn-cf-type-list' );
		var status = document.getElementById( 'mn-cf-save-status' );
		if ( ! box ) { return; }

		box.addEventListener( 'change', function ( ev ) {
			if ( ! ev.target || ev.target.type !== 'checkbox' ) { return; }
			var types = [];
			box.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( cb ) {
				types.push( cb.value );
			} );
			if ( status ) {
				status.className = 'mn-cf-status';
				status.textContent = cfg.strings.saving;
			}
			apiFetch( { url: cfg.restUrl, method: 'POST', data: { types: types } } )
				.then( function () {
					if ( status ) { status.className = 'mn-cf-status is-ok'; status.textContent = cfg.strings.saved; }
				} )
				.catch( function () {
					if ( status ) { status.className = 'mn-cf-status is-err'; status.textContent = cfg.strings.error; }
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window.wp || {} );
