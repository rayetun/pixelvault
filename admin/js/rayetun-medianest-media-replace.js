/* global rayetunMNReplace, jQuery */
( function ( $ ) {
	'use strict';

	// Open the file picker when the Replace button is clicked.
	$( document ).on( 'click', '.rayetun-mn-replace-btn', function () {
		$( this ).siblings( '.rayetun-mn-replace-file' ).trigger( 'click' );
	} );

	// Handle file selection → upload → update admin view in-place.
	$( document ).on( 'change', '.rayetun-mn-replace-file', function () {
		var file    = this.files[ 0 ];
		var $input  = $( this );
		var $btn    = $input.siblings( '.rayetun-mn-replace-btn' );
		var $status = $input.siblings( '.rayetun-mn-replace-status' );
		var id      = parseInt( $btn.data( 'id' ), 10 );

		if ( ! file || ! id ) {
			return;
		}

		$status.removeAttr( 'style' ).text( rayetunMNReplace.strings.replacing );
		$btn.prop( 'disabled', true );

		var formData = new FormData();
		formData.append( 'file', file );

		fetch(
			rayetunMNReplace.restUrl + '/attachments/' + id + '/replace',
			{
				method:  'POST',
				headers: { 'X-WP-Nonce': rayetunMNReplace.restNonce },
				body:    formData,
			}
		)
		.then( function ( response ) {
			if ( ! response.ok ) {
				return response.json().then( function ( err ) {
					throw new Error( err.message || rayetunMNReplace.strings.errorGeneric );
				} );
			}
			return response.json();
		} )
		.then( function ( data ) {
			$status.css( 'color', 'green' ).text( rayetunMNReplace.strings.replaced );
			$btn.prop( 'disabled', false );
			$input.val( '' );

			/*
			 * url_nocache = canonical URL + ?_mn_cb=<timestamp>.
			 * Used only to update the img src in the WP admin right now so
			 * the admin view shows the new file without a page reload.
			 * The canonical attachment URL (without any query string) is not
			 * changed — no risk of broken images anywhere on the site.
			 */
			var adminSrc = ( data && data.url_nocache ) ? data.url_nocache
			             : ( data && data.url )         ? data.url + '?_mn_cb=' + Date.now()
			             : null;

			if ( adminSrc ) {
				// Swap every thumbnail currently visible for this attachment in the admin.
				$( [
					'[data-attachment-id="' + id + '"] img',
					'.attachment-details img',
					'.attachment-preview img',
				].join( ', ' ) ).attr( 'src', adminSrc );
			}

			/*
			 * Re-fetch the Backbone attachment model so WP's detail pane
			 * re-renders with fresh metadata (dimensions, mime type, etc.).
			 */
			if ( window.wp && wp.media && wp.media.model && wp.media.model.Attachment ) {
				var model = wp.media.model.Attachment.get( id );
				if ( model ) { model.fetch(); }
			}

			// Force the Backbone grid to re-query and reload its thumbnails.
			if ( window.wp && wp.media && wp.media.frame ) {
				var content = wp.media.frame.content && wp.media.frame.content.get();
				if ( content && content.collection ) {
					content.collection.props.set( { ignore: +new Date() } );
				}
			}
		} )
		.catch( function ( err ) {
			$status.css( 'color', '#d63638' ).text( err.message || rayetunMNReplace.strings.errorGeneric );
			$btn.prop( 'disabled', false );
			$input.val( '' );
		} );
	} );

} )( jQuery );
