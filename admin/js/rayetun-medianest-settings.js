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

	// ── Contextual Pro upsell card ────────────────────────────────────────────

	// CTA jumps to the "Upgrade to Pro" tab (same mechanism as the Quick Action cards).
	$( document ).on( 'click', '.rayetun-mn-procard-cta[data-qa-tab]', function () {
		var target = $( this ).data( 'qa-tab' );
		if ( target ) { activateTab( target ); }
	} );

	// Dismiss: hide immediately and remember it per-user so it never returns.
	$( document ).on( 'click', '.rayetun-mn-procard-dismiss', function () {
		var $card = $( this ).closest( '.rayetun-mn-procard' );
		$card.css( { opacity: 0, transform: 'translateY(-6px)' } );
		window.setTimeout( function () { $card.remove(); }, 180 );
		$.post( AJAX, {
			action: 'rayetun_mn_dismiss_pro_card',
			nonce : NONCE
		} );
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

	/* Storage-by-Folder view state (persists while the Analytics tab is open). */
	var folderView = { sort: 'size', dir: 'desc', filter: '', showAll: false };
	var ROOT_LIMIT = 12;

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
				initFolderSection( data, $inner );
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

		/* ── Storage by folder — toolbar + container; filled by initFolderSection() ── */
		if ( d.by_folder && d.by_folder.length ) {
			html += '<h3 class="mn-an-section-title">Storage by Folder</h3>';
			html += '<div style="margin:0 0 8px">' +
			        '<input type="text" id="mn-an-folder-filter" placeholder="Filter folders…" ' +
			        'style="width:220px;max-width:100%;padding:5px 9px;border:1px solid #dcdcde;border-radius:4px;font-size:13px" /></div>';
			html += '<div id="mn-an-folders"></div>';
		}

		return html;
	}

	/**
	 * Storage-by-Folder controller: builds the rolled-up tree (collapsed by default,
	 * Top-N roots), a flat path-aware filter view, and sortable columns. Rebinds its
	 * own controls; safe to call once per analytics load.
	 */
	function initFolderSection( d, $root ) {
		var $host = $root.find( '#mn-an-folders' );
		if ( ! $host.length || ! d.by_folder || ! d.by_folder.length ) { return; }

		var libSize = parseInt( d.total_storage, 10 ) || 0;

		/* term_id → node map, nest children, keep roots. */
		var fmap = {};
		$( d.by_folder ).each( function ( i, f ) {
			fmap[ f.term_id ] = {
				term_id:   parseInt( f.term_id, 10 ),
				parent:    parseInt( f.parent, 10 ) || 0,
				name:      f.name,
				rollCount: parseInt( f.roll_count, 10 ) || 0,
				rollSize:  parseInt( f.roll_size, 10 ) || 0,
				ownSize:   parseInt( f.total_size, 10 ) || 0,
				children:  []
			};
		} );
		var roots = [];
		Object.keys( fmap ).forEach( function ( k ) {
			var n = fmap[ k ];
			if ( n.parent && fmap[ n.parent ] ) { fmap[ n.parent ].children.push( n ); }
			else { roots.push( n ); }
		} );
		function pathOf( n ) {
			var parts = [], p = n.parent, g = 0;
			while ( p && fmap[ p ] && g < 100 ) { parts.unshift( fmap[ p ].name ); p = fmap[ p ].parent; g++; }
			return parts;
		}
		var emptyCount = 0;
		$( d.by_folder ).each( function ( i, f ) {
			if ( ( parseInt( f.roll_size, 10 ) || 0 ) === 0 ) { emptyCount++; }
		} );

		function cmp( a, b ) {
			var r;
			if ( folderView.sort === 'name' )      { r = String( a.name ).localeCompare( String( b.name ) ); }
			else if ( folderView.sort === 'files' ) { r = a.rollCount - b.rollCount; }
			else                                    { r = a.rollSize - b.rollSize; }
			return folderView.dir === 'desc' ? -r : r;
		}
		function hasStoredKids( n ) { return ( n.children || [] ).some( function ( c ) { return c.rollSize > 0; } ); }
		function sizeCell( n ) {
			var ownNote = ( hasStoredKids( n ) && n.ownSize > 0 && n.ownSize !== n.rollSize )
				? ' <span style="color:#94a3b8;font-weight:400">(' + escHtml( fmtBytes( n.ownSize ) ) + ' here)</span>' : '';
			return '<strong>' + escHtml( fmtBytes( n.rollSize ) ) + '</strong>' + ownNote;
		}
		function barCell( size ) {
			var pct = libSize > 0 ? Math.min( 100, Math.round( size / libSize * 100 ) ) : 0;
			return '<div class="mn-an-bar-wrap"><div class="mn-an-bar-fill" style="width:' + pct + '%;background:#4f46e5"></div></div>' +
			       '<span style="font-size:11px;color:#64748b;margin-left:6px">' + pct + '%</span>';
		}
		function arrow( col ) {
			if ( folderView.sort !== col ) { return ''; }
			return folderView.dir === 'desc' ? ' ▾' : ' ▴';
		}
		function headHtml() {
			return '<thead><tr>' +
			       '<th class="mn-an-sort" data-sort="name" style="cursor:pointer">Folder' + arrow( 'name' ) + '</th>' +
			       '<th class="mn-an-sort" data-sort="files" style="width:70px;cursor:pointer">Files' + arrow( 'files' ) + '</th>' +
			       '<th class="mn-an-sort" data-sort="size" style="width:110px;cursor:pointer">Size' + arrow( 'size' ) + '</th>' +
			       '<th style="width:170px">% of Library</th>' +
			       '</tr></thead>';
		}

		function render() {
			var q = folderView.filter.trim().toLowerCase();
			var body = '';

			if ( q ) {
				/* Flat, path-aware filter view across all depths. */
				var matches = [];
				Object.keys( fmap ).forEach( function ( k ) {
					var n = fmap[ k ];
					if ( n.rollSize > 0 && String( n.name ).toLowerCase().indexOf( q ) !== -1 ) { matches.push( n ); }
				} );
				matches.sort( cmp );
				if ( ! matches.length ) {
					body = '<tr><td colspan="4" style="color:#94a3b8;padding:12px">No folders match “' + escHtml( folderView.filter ) + '”.</td></tr>';
				} else {
					matches.forEach( function ( n ) {
						var path  = pathOf( n );
						var crumb = path.length ? '<span style="color:#94a3b8;font-weight:400">' + escHtml( path.join( ' / ' ) ) + ' / </span>' : '';
						body += '<tr>' +
						        '<td style="padding-left:8px">' + crumb + escHtml( n.name ) + '</td>' +
						        '<td>' + n.rollCount.toLocaleString() + '</td>' +
						        '<td>' + sizeCell( n ) + '</td>' +
						        '<td>' + barCell( n.rollSize ) + '</td>' +
						        '</tr>';
					} );
				}
				$host.html( '<table class="mn-an-table">' + headHtml() + '<tbody>' + body + '</tbody></table>' );
				return;
			}

			/* Tree view (collapsed by default), capped to Top-N roots. */
			var sortedRoots = roots.filter( function ( n ) { return n.rollSize > 0; } ).sort( cmp );
			var shown = sortedRoots, hiddenRoots = 0;
			if ( ! folderView.showAll && sortedRoots.length > ROOT_LIMIT ) {
				shown = sortedRoots.slice( 0, ROOT_LIMIT );
				hiddenRoots = sortedRoots.length - ROOT_LIMIT;
			}

			( function walk( nodes, depth ) {
				nodes.filter( function ( n ) { return n.rollSize > 0; } ).sort( cmp ).forEach( function ( n ) {
					var kids    = ( n.children || [] ).filter( function ( c ) { return c.rollSize > 0; } );
					var hasKids = kids.length > 0;
					var pad     = 8 + depth * 16;
					var caret   = hasKids
						? '<span class="mn-an-caret is-collapsed" data-term="' + n.term_id + '" role="button" tabindex="0" aria-label="Toggle subfolders" style="cursor:pointer;display:inline-block;width:14px;color:#64748b">▸</span>'
						: '<span style="display:inline-block;width:14px"></span>';
					var rowStyle = depth > 0 ? ' style="display:none"' : '';
					body += '<tr data-term="' + n.term_id + '" data-parent="' + n.parent + '"' + rowStyle + '>' +
					        '<td style="padding-left:' + pad + 'px">' + caret + escHtml( n.name ) + '</td>' +
					        '<td>' + n.rollCount.toLocaleString() + '</td>' +
					        '<td>' + sizeCell( n ) + '</td>' +
					        '<td>' + barCell( n.rollSize ) + '</td>' +
					        '</tr>';
					if ( hasKids ) { walk( kids, depth + 1 ); }
				} );
			} )( shown, 0 );

			var foot = '';
			if ( hiddenRoots > 0 ) {
				foot = '<p style="margin:8px 2px 0"><a href="#" id="mn-an-showall" style="font-size:13px">Show all ' + sortedRoots.length + ' folders</a></p>';
			} else if ( folderView.showAll && sortedRoots.length > ROOT_LIMIT ) {
				foot = '<p style="margin:8px 2px 0"><a href="#" id="mn-an-showless" style="font-size:13px">Show fewer</a></p>';
			}
			var note = emptyCount > 0
				? '<p style="color:#94a3b8;font-size:12px;margin:6px 2px 0">' + emptyCount + ' folder' + ( emptyCount !== 1 ? 's' : '' ) + ' use no storage (hidden).</p>'
				: '';
			$host.html( '<table class="mn-an-table">' + headHtml() + '<tbody>' + body + '</tbody></table>' + foot + note );
		}

		/* Caret expand/collapse — delegated on the host, survives re-renders. */
		function setVisible( parentTid, visible ) {
			$host.find( 'tr[data-parent="' + parentTid + '"]' ).each( function () {
				var $r = $( this );
				$r.toggle( visible );
				var childTid = $r.attr( 'data-term' );
				if ( ! visible ) { setVisible( childTid, false ); }
				else {
					var $c = $r.find( '.mn-an-caret' );
					setVisible( childTid, ! ( $c.length && $c.hasClass( 'is-collapsed' ) ) );
				}
			} );
		}
		function toggleCaret( $c ) {
			var collapsed = $c.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );
			$c.text( collapsed ? '▸' : '▾' );
			setVisible( $c.attr( 'data-term' ), ! collapsed );
		}

		$host.off( '.mnfs' );
		$host.on( 'click.mnfs', '.mn-an-caret', function () { toggleCaret( $( this ) ); } );
		$host.on( 'keydown.mnfs', '.mn-an-caret', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); toggleCaret( $( this ) ); }
		} );
		$host.on( 'click.mnfs', '.mn-an-sort', function () {
			var col = $( this ).attr( 'data-sort' );
			if ( folderView.sort === col ) { folderView.dir = folderView.dir === 'desc' ? 'asc' : 'desc'; }
			else { folderView.sort = col; folderView.dir = ( col === 'name' ) ? 'asc' : 'desc'; }
			render();
		} );
		$host.on( 'click.mnfs', '#mn-an-showall', function ( e ) { e.preventDefault(); folderView.showAll = true; render(); } );
		$host.on( 'click.mnfs', '#mn-an-showless', function ( e ) { e.preventDefault(); folderView.showAll = false; render(); } );

		/* Filter input lives outside $host so it keeps focus across re-renders. */
		$root.find( '#mn-an-folder-filter' ).off( 'input.mnfs' ).on( 'input.mnfs', function () {
			folderView.filter = this.value || '';
			render();
		} );

		render();
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

	// Copy a shortcode from the Shortcodes reference.
	$( document ).on( 'click', '.rayetun-mn-copy-sc', function () {
		var $btn = $( this );
		var sc   = $btn.attr( 'data-sc' ) || '';
		var label = $btn.text();
		function flash() { $btn.text( strings.copied || 'Copied!' ); setTimeout( function () { $btn.text( label ); }, 1500 ); }
		function legacy() {
			var ta = document.createElement( 'textarea' );
			ta.value = sc; ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
			document.body.appendChild( ta ); ta.select();
			try { document.execCommand( 'copy' ); flash(); } catch ( e ) { window.prompt( 'Copy this shortcode:', sc ); }
			document.body.removeChild( ta );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( sc ).then( flash ).catch( legacy );
		} else {
			legacy();
		}
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
