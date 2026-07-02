/* global rayetunMNSettings, jQuery */
( function ( $ ) {
	'use strict';

	var cfg       = rayetunMNSettings;          // localised data
	var NONCE     = cfg.nonce;
	var AJAX      = cfg.ajaxUrl;
	var REST_URL  = cfg.restUrl  || '';
	var REST_NONCE= cfg.restNonce|| '';
	var strings   = cfg.strings;

	// ── Tab switching ─────────────────────────────────────────────────────────

	function activateTab( id ) {
		$( '.rayetun-mn-nav-item' ).removeClass( 'is-active' );
		$( '.rayetun-mn-nav-item[data-tab="' + id + '"]' ).addClass( 'is-active' );
		$( '.rayetun-mn-panel' ).hide();
		$( '.rayetun-mn-panel[data-panel="' + id + '"]' ).show();
		try { sessionStorage.setItem( 'mn_active_tab', id ); } catch ( e ) { /* ignore */ }
	}

	$( '.rayetun-mn-nav-item' ).on( 'click', function () {
		var tabId = $( this ).data( 'tab' );
		activateTab( tabId );
		if ( tabId === 'analytics' && ! analyticsLoaded ) {
			loadAnalytics();
		}
		if ( tabId === 'tools' && ! toolsLoaded ) {
			/* Reset to the Scan button — user can trigger detection manually */
			toolsLoaded = false; // allow re-detect
		}
		if ( tabId === 'tools' ) { loadTemplates(); }
	} );

	// ── Folder Templates ─────────────────────────────────────────────────────

	function tplRest( path, method, data ) {
		method = ( method || 'GET' ).toUpperCase();
		var override = null;
		var httpMethod = method;
		// Tunnel DELETE/PUT/PATCH through POST for hosts/WAFs that block those verbs.
		if ( method === 'DELETE' || method === 'PUT' || method === 'PATCH' ) {
			override = method;
			httpMethod = 'POST';
		}
		return $.ajax( {
			url:        REST_URL + path,
			method:     httpMethod,
			data:       data ? JSON.stringify( data ) : undefined,
			contentType:'application/json',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', REST_NONCE );
				if ( override ) { xhr.setRequestHeader( 'X-HTTP-Method-Override', override ); }
			}
		} );
	}

	function loadTemplates() {
		var $list = $( '#mn-template-list' );
		if ( ! $list.length ) { return; }
		tplRest( '/templates', 'GET' ).done( function ( items ) {
			$list.empty();
			if ( ! items || ! items.length ) {
				$list.append( $( '<p class="rayetun-mn-template-empty"></p>' ).text( 'No templates saved yet.' ) );
				return;
			}
			items.forEach( function ( t ) {
				var $row = $( '<div class="rayetun-mn-template-row"></div>' );
				$( '<span class="rayetun-mn-template-name"></span>' ).text( t.name + ' (' + t.count + ')' ).appendTo( $row );
				var $apply = $( '<button type="button" class="rayetun-mn-save-btn rayetun-mn-btn-secondary">Apply</button>' );
				var $del   = $( '<button type="button" class="rayetun-mn-template-del" aria-label="Delete">&times;</button>' );
				$apply.on( 'click', function () {
					if ( ! window.confirm( 'Create these folders now?' ) ) { return; }
					$apply.prop( 'disabled', true ).text( 'Applying…' );
					tplRest( '/templates/' + encodeURIComponent( t.id ) + '/apply', 'POST' )
						.done( function ( r ) { $apply.text( ( r && r.message ) ? r.message : 'Done' ); } )
						.fail( function () { $apply.prop( 'disabled', false ).text( 'Apply' ); } );
				} );
				$del.on( 'click', function () {
					if ( ! window.confirm( 'Delete this template?' ) ) { return; }
					tplRest( '/templates/' + encodeURIComponent( t.id ), 'DELETE' ).done( loadTemplates );
				} );
				$row.append( $apply ).append( $del );
				$list.append( $row );
			} );
		} );
	}

	$( document ).on( 'click', '#mn-template-save-btn', function () {
		var $btn = $( this );
		var name = $.trim( $( '#mn-template-name' ).val() );
		var $status = $( '#mn-template-status' );
		if ( ! name ) { $status.text( 'Enter a template name.' ).addClass( 'is-error is-visible' ); return; }
		$btn.prop( 'disabled', true );
		$status.removeClass( 'is-error' ).text( 'Saving…' ).addClass( 'is-visible' );
		tplRest( '/templates', 'POST', { name: name } )
			.done( function () {
				$( '#mn-template-name' ).val( '' );
				$status.text( 'Saved.' );
				loadTemplates();
			} )
			.fail( function ( xhr ) {
				var m = ( xhr && xhr.responseJSON && xhr.responseJSON.message ) ? xhr.responseJSON.message : 'Save failed.';
				$status.text( m ).addClass( 'is-error' );
			} )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	// Honour ?tab= URL param (e.g. from the WP dashboard widget "View Full Analytics" link).
	var urlTab = '';
	try {
		urlTab = new URLSearchParams( window.location.search ).get( 'tab' ) || '';
	} catch ( e ) { /* URLSearchParams not available in very old browsers */ }

	// Restore last tab or default to dashboard.
	var lastTab = 'dashboard';
	try { lastTab = sessionStorage.getItem( 'mn_active_tab' ) || 'dashboard'; } catch ( e ) { /* ignore */ }
	// URL param overrides stored tab
	if ( urlTab ) { lastTab = urlTab; }
	activateTab( lastTab );

	// ── +/- number buttons ────────────────────────────────────────────────────

	$( document ).on( 'click', '.rayetun-mn-num-btn', function () {
		var $btn   = $( this );
		var $input = $btn.siblings( '.rayetun-mn-number-input' );
		var dir    = parseInt( $btn.data( 'dir' ), 10 ) || 1;
		var val    = parseInt( $input.val(), 10 ) || 0;
		var min    = parseInt( $input.attr( 'min' ), 10 );
		var max    = parseInt( $input.attr( 'max' ), 10 );
		var next   = val + dir;
		if ( ! isNaN( min ) ) { next = Math.max( min, next ); }
		if ( ! isNaN( max ) ) { next = Math.min( max, next ); }
		$input.val( next );
	} );

	// ── Collect settings values ───────────────────────────────────────────────

	function collectSettings() {
		var out = {};
		// Checkboxes / toggles
		$( '.rayetun-mn-panel:visible .rayetun-mn-toggle input[data-setting]' ).each( function () {
			out[ $( this ).data( 'setting' ) ] = this.checked ? 1 : 0;
		} );
		// But also collect ALL toggles across all settings panels (not just active)
		$( '.rayetun-mn-toggle input[data-setting]' ).each( function () {
			out[ $( this ).data( 'setting' ) ] = this.checked ? 1 : 0;
		} );
		// Selects
		$( '.rayetun-mn-select[data-setting]' ).each( function () {
			out[ $( this ).data( 'setting' ) ] = $( this ).val();
		} );
		// Numbers
		$( '.rayetun-mn-number-input[data-setting]' ).each( function () {
			out[ $( this ).data( 'setting' ) ] = parseInt( $( this ).val(), 10 ) || 0;
		} );
		return out;
	}

	function collectPermissions() {
		var out = {};
		$( '.rayetun-mn-perms-table tbody tr' ).each( function () {
			var $row = $( this );
			$row.find( 'input[type="checkbox"][name]' ).each( function () {
				// name = "rayetun_mn_caps[role][cap]"
				var match = $( this ).attr( 'name' ).match( /\[([^\]]+)\]\[([^\]]+)\]/ );
				if ( ! match ) { return; }
				var role = match[ 1 ];
				var cap  = match[ 2 ];
				if ( ! out[ role ] ) { out[ role ] = {}; }
				out[ role ][ cap ] = this.checked ? 1 : 0;
			} );
		} );
		return out;
	}

	// ── Feature toggle — instant save + sidebar sync ─────────────────────────

	$( document ).on( 'change', '.rayetun-mn-feature-chk', function () {
		var $chk    = $( this );
		var feature = $chk.data( 'feature' );
		var tab     = $chk.data( 'tab' ) || '';
		var enabled = this.checked ? 1 : 0;
		var $card   = $chk.closest( '.rayetun-mn-feature-card' );

		// Update card visual state
		$card.toggleClass( 'is-active', !! enabled ).toggleClass( 'is-inactive', ! enabled );

		// Show or hide the corresponding sidebar nav item
		if ( tab ) {
			var $nav = $( '.rayetun-mn-nav-item[data-tab="' + tab + '"]' );
			if ( enabled ) {
				$nav.show();
			} else {
				$nav.hide();
				if ( $nav.hasClass( 'is-active' ) ) { activateTab( 'dashboard' ); }
			}
		}

		// Show or hide the matching section inside the Tools panel (Tools-group features
		// have no sidebar tab of their own — the tab stays put, only the section toggles).
		var $section = $( '.rayetun-mn-tools-section[data-feature-section="' + feature + '"]' );
		if ( $section.length ) {
			$section.toggle( !! enabled );
			// Update the "all tools disabled" placeholder visibility.
			var anyVisible = $( '.rayetun-mn-tools-section' ).filter( function () {
				return $( this ).css( 'display' ) !== 'none';
			} ).length > 0;
			$( '.rayetun-mn-tools-empty' ).toggle( ! anyVisible );
		}

		// Persist via dedicated AJAX handler (no page reload needed)
		$.post( AJAX, {
			action : 'rayetun_mn_toggle_feature',
			nonce  : NONCE,
			feature: feature,
			value  : enabled
		} );
	} );

	// ── Quick Action cards → jump to a tab ───────────────────────────────────

	$( document ).on( 'click', '.rayetun-mn-qa-card[data-qa-tab]', function () {
		var target = $( this ).data( 'qa-tab' );
		if ( target ) {
			activateTab( target );
			if ( target === 'analytics' && ! analyticsLoaded ) { loadAnalytics(); }
		}
	} );

	// ── Folder structure export (JSON download) ──────────────────────────────

	$( document ).on( 'click', '#mn-export-structure-btn', function () {
		window.location.href = AJAX
			+ '?action=rayetun_medianest_export_structure'
			+ '&nonce=' + encodeURIComponent( cfg.exportNonce || '' );
	} );

	// ── Folder structure import (JSON upload) ────────────────────────────────

	$( document ).on( 'change', '#mn-import-structure-file', function () {
		var file = this.files && this.files[0];
		var $status = $( '#mn-import-structure-status' );
		if ( ! file ) { return; }

		$status.text( strings.importing ).removeClass( 'is-error' ).addClass( 'is-visible' );

		var reader = new FileReader();
		reader.onload = function ( e ) {
			$.post( AJAX, {
				action : 'rayetun_mn_import_structure',
				nonce  : NONCE,
				data   : e.target.result
			} )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					$status.text( resp.data.message ).removeClass( 'is-error' );
				} else {
					$status.text( ( resp && resp.data && resp.data.message ) || strings.importError ).addClass( 'is-error' );
				}
			} )
			.fail( function () {
				$status.text( strings.importError ).addClass( 'is-error' );
			} );
		};
		reader.readAsText( file );
		// Reset so the same file can be re-selected.
		this.value = '';
	} );

	// ── Save button handler ───────────────────────────────────────────────────

	$( document ).on( 'click', '.rayetun-mn-save-btn', function () {
		var $btn      = $( this );
		var $status   = $btn.siblings( '.rayetun-mn-save-status' );
		var saveType  = $btn.data( 'save-type' );   // "settings" or "permissions"

		$btn.prop( 'disabled', true ).addClass( 'is-loading' ).text( strings.saving );
		$status.removeClass( 'is-visible is-error' );

		var postData = {
			action: saveType === 'permissions' ? 'rayetun_mn_save_permissions' : 'rayetun_mn_save_settings',
			nonce:  NONCE,
		};

		if ( saveType === 'permissions' ) {
			postData.caps = collectPermissions();
		} else {
			postData.settings = collectSettings();
		}

		$.post( AJAX, postData )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					$btn.removeClass( 'is-loading' ).addClass( 'is-success' ).text( strings.saved );
					$status.text( resp.data && resp.data.message ? resp.data.message : strings.saved )
					       .addClass( 'is-visible' );
					setTimeout( function () {
						$btn.removeClass( 'is-success' ).text( 'Save Changes' ).prop( 'disabled', false );
						$status.removeClass( 'is-visible' );
					}, 2500 );
				} else {
					throw new Error( ( resp && resp.data && resp.data.message ) || strings.error );
				}
			} )
			.fail( function () {
				$btn.removeClass( 'is-loading is-success' ).text( 'Save Changes' ).prop( 'disabled', false );
				$status.text( strings.error ).addClass( 'is-visible is-error' );
				setTimeout( function () { $status.removeClass( 'is-visible is-error' ); }, 3000 );
			} );
	} );

	// ── Analytics Tab ─────────────────────────────────────────────────────────

	var analyticsLoaded = false;

	function loadAnalytics() {
		var $inner = $( '#mn-analytics-inner' );
		$inner.html(
			'<div class="mn-analytics-loading">' +
			'<span class="mn-spin">&#8635;</span> Loading analytics…' +
			'</div>'
		);

		$.ajax( {
			url: REST_URL + '/analytics',
			method: 'GET',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', REST_NONCE );
			},
			success: function ( data ) {
				analyticsLoaded = true;
				$inner.html( renderAnalytics( data ) );
			},
			error: function () {
				$inner.html(
					'<p class="mn-analytics-error">Could not load analytics. Please refresh the page and try again.</p>'
				);
			},
		} );
	}

	/** Safely HTML-escape a string value. */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#39;'  );
	}

	/** Human-readable byte size. */
	function fmtBytes( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		if ( bytes >= 1073741824 ) { return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB'; }
		if ( bytes >= 1048576 )    { return ( bytes / 1048576    ).toFixed( 1 ) + ' MB'; }
		if ( bytes >= 1024 )       { return Math.round( bytes / 1024 ) + ' KB'; }
		return bytes + ' B';
	}

	/** Build the full analytics HTML from the REST payload — fully table-based layout. */
	function renderAnalytics( d ) {
		var totalCount = parseInt( d.total_count,   10 ) || 0;
		var totalSize  = parseInt( d.total_storage, 10 ) || 0;
		var avgSize    = totalCount > 0 ? Math.round( totalSize / totalCount ) : 0;
		var noSize     = parseInt( d.no_size_count, 10 ) || 0;
		var largestFile= ( d.top_files && d.top_files.length ) ? parseInt( d.top_files[0].file_size, 10 ) : 0;

		var maxType   = ( d.by_type   && d.by_type.length   ) ? parseInt( d.by_type[0].total_size,   10 ) || 1 : 1;
		var maxFolder = ( d.by_folder && d.by_folder.length ) ? parseInt( d.by_folder[0].total_size, 10 ) || 1 : 1;

		var typeColors = {
			Images : '#4f46e5',
			Videos : '#ef4444',
			Audio  : '#f59e0b',
			PDFs   : '#10b981',
			Other  : '#64748b',
		};

		var html = '';

		/* ── Storage overview — stat table ── */
		html += '<table class="mn-an-stats-tbl">';
		html += '<tr>';
		html += '<td class="mn-an-stat-cell">'  +
		        '<span class="mn-an-stat-num">'  + escHtml( fmtBytes( totalSize ) ) + '</span>' +
		        '<span class="mn-an-stat-lbl">Total Storage</span>' +
		        '</td>';
		html += '<td class="mn-an-stat-cell mn-an-stat-sep">' +
		        '<span class="mn-an-stat-num">'  + totalCount.toLocaleString() + '</span>' +
		        '<span class="mn-an-stat-lbl">Total Files</span>' +
		        '</td>';
		html += '<td class="mn-an-stat-cell mn-an-stat-sep">' +
		        '<span class="mn-an-stat-num">'  + escHtml( fmtBytes( avgSize ) ) + '</span>' +
		        '<span class="mn-an-stat-lbl">Avg. File Size</span>' +
		        '</td>';
		html += '<td class="mn-an-stat-cell mn-an-stat-sep">' +
		        '<span class="mn-an-stat-num">'  + escHtml( fmtBytes( largestFile ) ) + '</span>' +
		        '<span class="mn-an-stat-lbl">Largest File</span>' +
		        '</td>';
		if ( noSize > 0 ) {
			html += '<td class="mn-an-stat-cell mn-an-stat-sep mn-an-stat-warn">' +
			        '<span class="mn-an-stat-num">'  + noSize + '</span>' +
			        '<span class="mn-an-stat-lbl">Missing Size Data</span>' +
			        '</td>';
		}
		html += '</tr></table>';

		/* ── File type breakdown — table ── */
		if ( d.by_type && d.by_type.length ) {
			html += '<h3 class="mn-an-section-title">File Type Breakdown</h3>';
			html += '<table class="mn-an-table">';
			html += '<thead><tr>' +
			        '<th style="width:110px">Type</th>' +
			        '<th style="width:80px">Files</th>' +
			        '<th style="width:100px">Storage</th>' +
			        '<th>Distribution</th>' +
			        '</tr></thead><tbody>';
			$( d.by_type ).each( function ( i, t ) {
				var pct   = maxType > 0 ? Math.round( parseInt( t.total_size, 10 ) / maxType * 100 ) : 0;
				var color = typeColors[ t.label ] || '#64748b';
				html += '<tr>' +
				        '<td><span class="mn-an-dot" style="background:' + escHtml( color ) + '"></span>' +
				              escHtml( t.label ) + '</td>' +
				        '<td>' + parseInt( t.count, 10 ).toLocaleString() + '</td>' +
				        '<td><strong>' + escHtml( fmtBytes( t.total_size ) ) + '</strong></td>' +
				        '<td><div class="mn-an-bar-wrap">' +
				               '<div class="mn-an-bar-fill" style="width:' + pct + '%;background:' + escHtml( color ) + '"></div>' +
				             '</div></td>' +
				        '</tr>';
			} );
			html += '</tbody></table>';
		}

		/* ── Top 10 largest files — table ── */
		if ( d.top_files && d.top_files.length ) {
			html += '<h3 class="mn-an-section-title">Top ' + d.top_files.length + ' Largest Files</h3>';
			html += '<table class="mn-an-table"><thead><tr>' +
			        '<th style="width:36px">#</th>' +
			        '<th>File Name</th>' +
			        '<th style="width:120px">Type</th>' +
			        '<th style="width:80px">Size</th>' +
			        '</tr></thead><tbody>';
			$( d.top_files ).each( function ( i, f ) {
				var name     = escHtml( f.title || f.url.split( '/' ).pop() );
				var editHref = escHtml( f.edit_url || '#' );
				html += '<tr>' +
				        '<td class="mn-an-rank">' + ( i + 1 ) + '</td>' +
				        '<td><a href="' + editHref + '" target="_blank" rel="noopener">' + name + '</a></td>' +
				        '<td><span class="mn-an-mime">' + escHtml( f.mime_type ) + '</span></td>' +
				        '<td><strong>' + escHtml( fmtBytes( f.file_size ) ) + '</strong></td>' +
				        '</tr>';
			} );
			html += '</tbody></table>';
		}

		/* ── Storage by folder — table ── */
		if ( d.by_folder && d.by_folder.length ) {
			html += '<h3 class="mn-an-section-title">Storage by Folder</h3>';
			html += '<table class="mn-an-table"><thead><tr>' +
			        '<th>Folder</th>' +
			        '<th style="width:70px">Files</th>' +
			        '<th style="width:100px">Total Size</th>' +
			        '<th style="width:160px">Distribution</th>' +
			        '</tr></thead><tbody>';
			$( d.by_folder ).each( function ( i, f ) {
				var pct = maxFolder > 0 ? Math.round( parseInt( f.total_size, 10 ) / maxFolder * 100 ) : 0;
				html += '<tr>' +
				        '<td>' + escHtml( f.name ) + '</td>' +
				        '<td>' + parseInt( f.count, 10 ).toLocaleString() + '</td>' +
				        '<td><strong>' + escHtml( fmtBytes( f.total_size ) ) + '</strong></td>' +
				        '<td><div class="mn-an-bar-wrap">' +
				               '<div class="mn-an-bar-fill" style="width:' + pct + '%;background:#4f46e5"></div>' +
				             '</div></td>' +
				        '</tr>';
			} );
			html += '</tbody></table>';
		}

		return html;
	}

	// Trigger analytics load if analytics tab was the initially restored/URL tab.
	if ( lastTab === 'analytics' && ! analyticsLoaded ) {
		loadAnalytics();
	}

	// ── Tools Tab — Competitor Import ─────────────────────────────────────────

	var toolsLoaded = false;

	/** Safely HTML-escape for Tools tab output. */
	function escHtmlTools( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#39;'  );
	}

	/** Call GET /migrate/detect and render result cards. */
	function loadMigrateDetect() {
		var $area = $( '#mn-tools-migrate-area' );
		$area.html( '<p class="mn-tools-loading"><span class="mn-spin">&#8635;</span> ' + escHtmlTools( strings.detecting || 'Scanning…' ) + '</p>' );
		toolsLoaded = true;

		$.ajax( {
			url:    REST_URL + '/migrate/detect',
			method: 'GET',
			beforeSend: function ( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', REST_NONCE ); },
			success: function ( data ) {
				var keys = Object.keys( data );
				if ( ! keys.length ) {
					$area.html(
						'<p class="mn-tools-empty">' + escHtmlTools( strings.noPlugins || 'No compatible plugins detected.' ) + '</p>' +
						'<button type="button" class="rayetun-mn-save-btn" id="mn-tools-detect-btn" style="margin-top:12px">' +
						escHtmlTools( 'Scan Again' ) + '</button>'
					);
					return;
				}

				var html = '<div class="mn-migrate-grid">';
				keys.forEach( function ( key ) {
					var info = data[ key ];
					html +=
						'<div class="mn-migrate-card">' +
							'<div class="mn-migrate-card-name">' + escHtmlTools( info.label ) + '</div>' +
							'<div class="mn-migrate-card-count">' + escHtmlTools( info.folders ) + ' folder' + ( info.folders !== 1 ? 's' : '' ) + ' found</div>' +
							'<button type="button" class="rayetun-mn-save-btn mn-migrate-run-btn"' +
							' data-source="' + escHtmlTools( key ) + '"' +
							' data-label="' + escHtmlTools( info.label ) + '">' +
							'Import' +
							'</button>' +
							'<div class="mn-migrate-result"></div>' +
						'</div>';
				} );
				html += '</div>';
				$area.html( html );
			},
			error: function () {
				$area.html(
					'<p class="mn-tools-error">' + escHtmlTools( strings.detectError || 'Scan failed. Please try again.' ) + '</p>' +
					'<button type="button" class="rayetun-mn-save-btn" id="mn-tools-detect-btn" style="margin-top:12px">Retry</button>'
				);
				toolsLoaded = false;
			},
		} );
	}

	// Scan button (initial and retry).
	$( document ).on( 'click', '#mn-tools-detect-btn', function () {
		loadMigrateDetect();
	} );

	// Import button for a specific plugin.
	$( document ).on( 'click', '.mn-migrate-run-btn', function () {
		var $btn    = $( this );
		var source  = $btn.data( 'source' );
		var $result = $btn.siblings( '.mn-migrate-result' );

		$btn.prop( 'disabled', true ).text( strings.importing || 'Importing…' );
		$result.html( '' );

		$.ajax( {
			url:         REST_URL + '/migrate/run',
			method:      'POST',
			contentType: 'application/json',
			data:        JSON.stringify( { source: source } ),
			beforeSend:  function ( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', REST_NONCE ); },
			success: function ( resp ) {
				$btn.text( strings.importDone || 'Done ✓' ).prop( 'disabled', true );
				$result.html(
					'<span class="mn-migrate-success">' +
					escHtmlTools( resp.folders_created ) + ' ' + escHtmlTools( strings.foldersCreated || 'folders created' ) + ', ' +
					escHtmlTools( resp.files_assigned )  + ' ' + escHtmlTools( strings.filesAssigned  || 'files assigned'  ) + ', ' +
					escHtmlTools( resp.skipped )          + ' ' + escHtmlTools( strings.alreadyExisted || 'skipped'         ) +
					'.</span>'
				);
			},
			error: function ( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) ? xhr.responseJSON.message : ( strings.importError || 'Import failed.' );
				$btn.text( 'Retry' ).prop( 'disabled', false );
				$result.html( '<span class="mn-migrate-error">' + escHtmlTools( msg ) + '</span>' );
			},
		} );
	} );

} )( jQuery );
