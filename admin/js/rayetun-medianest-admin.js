/**
 * MediaNest Admin — v2.4.0
 * Folder filter: patch wp.ajax.post to inject folder param as object (before serialization).
 * This is how FileBird-style plugins work — inject into data.query before jQuery serializes.
 * Modal: watch childList changes on .media-frame-content for tab switches.
 */
( function () {
	'use strict';

	if ( typeof window.rayetunMediaNestData === 'undefined' ) { return; }

	var cfg          = window.rayetunMediaNestData;
	var s            = cfg.strings || {};
	var IS_MEDIA_LIB = ( cfg.isMediaLibrary === true || cfg.isMediaLibrary === '1' || cfg.isMediaLibrary === 1 );
	var IS_ELEMENTOR = ( cfg.isElementor === true || cfg.isElementor === '1' || cfg.isElementor === 1 );

	/* ── Settings from PHP ───────────────────────────────────── */
	var _settings         = cfg.settings || {};
	var SHOW_COUNTS       = ( _settings.showCounts !== false && _settings.showCounts !== 0 && _settings.showCounts !== '0' );
	var ALT_EDITOR_ON     = ( _settings.altEditorEnabled !== false && _settings.altEditorEnabled !== 0 && _settings.altEditorEnabled !== '0' );
	var ALT_PER_PAGE      = parseInt( _settings.altPerPage, 10 ) || 20;
	var DEFAULT_SORT      = ( typeof _settings.defaultSort === 'string' && _settings.defaultSort ) ? _settings.defaultSort : 'manual';
	/* Tracks the most recently created wp.media frame across ALL callers
	   (Elementor, Divi, Gutenberg). wp.media.frame is only set by
	   wp.media.editor.open (Classic Editor path) and is null for everyone else. */
	var _mnLastFrame = null;
	/* ── State ── */
	var STORAGE_KEY = 'rayetun_mn_last_folder';
	var state = {
		folders: [], counts: {}, activeFolderId: null, expanded: {},
		loading: true, searchQuery: '', colorPickerFor: null,
		showNewFolder: false, showSubfolderFor: null,
		bulkMode: false, selectedIds: [],
		smartFolders: [],
		starred: ( cfg.starred || [] ).map( function ( x ) { return parseInt( x, 10 ); } ),
		sortBy: DEFAULT_SORT, /* seeded from Settings → default_sort */
		compactMode: false,
		focusedFolderId: null, /* manual | az | za | count_desc | count_asc */
	};

	function isStarred( tid ) { return state.starred.indexOf( parseInt( tid, 10 ) ) !== -1; }

	/* Restore from localStorage */
	try {
		var _ls = localStorage.getItem( STORAGE_KEY );
		if ( _ls === 'uncategorized' ) { state.activeFolderId = 'uncategorized'; }
		else if ( _ls && _ls.indexOf('smart:') === 0 ) { state.activeFolderId = _ls; }
		else if ( _ls ) { var _li = parseInt( _ls, 10 ); if ( !isNaN(_li) ) state.activeFolderId = _li; }
		/* Restore smart section expand/collapse */
		var _sopen = localStorage.getItem('rayetun_mn_smart_open');
		if ( _sopen !== null ) state.smartExpanded = _sopen !== '0';
	} catch(e) {}

	/* ────────────────────────────────────────────────────────
	   PATCH wp.ajax.post — runs immediately at script parse time,
	   before WP creates any media frame or fires any queries.

	   FileBird's approach: inject into data.query AS AN OBJECT
	   before jQuery serializes it. This is cleaner and more reliable
	   than appending to a serialized string in ajaxSend.
	   ──────────────────────────────────────────────────────── */
	function patchWpAjax() {
		if ( !window.wp || !window.wp.ajax || !window.wp.ajax.post ) { return false; }
		var _origPost = window.wp.ajax.post;
		window.wp.ajax.post = function ( action, data ) {
			if ( action === 'query-attachments' && data && data.query ) {
				/* When a media modal is open (Elementor, WooCommerce, ACF, Gutenberg,
				   or any other plugin) the modal manages its own folder filter via the
				   cookie (set by modalFilter()). Injecting the sidebar's activeFolderId
				   here would override that cookie and silently filter the modal grid to
				   whatever folder the user last clicked in the Media Library sidebar —
				   which is the #1 cause of "folders don't work in other plugins". */
				var _modalOpen = !!document.querySelector( '.media-modal' );
				if ( !_modalOpen && state.activeFolderId !== null && state.activeFolderId !== undefined ) {
					data.query.rayetun_medianest_folder = state.activeFolderId;
				} else {
					delete data.query.rayetun_medianest_folder;
				}
			}
			return _origPost.apply( window.wp.ajax, arguments );
		};
		/* Preserve static methods/properties */
		var k;
		for ( k in _origPost ) {
			if ( Object.prototype.hasOwnProperty.call( _origPost, k ) ) {
				try { window.wp.ajax.post[ k ] = _origPost[ k ]; } catch(e) {}
			}
		}
		return true;
	}

	/* Try immediately; wp.ajax may already be defined */
	if ( !patchWpAjax() ) {
		/* Retry at DOM ready if not yet available */
		document.addEventListener( 'DOMContentLoaded', patchWpAjax );
	}

	/* ── Icons ── */
	var CP = ['#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#3b82f6','#8b5cf6','#ec4899','#6b7280','#a16207','#0891b2','#1d2327'];
	function iF(c)  { return '<svg width="17" height="17" viewBox="0 0 20 20" fill="'+(c||'#646970')+'" aria-hidden="true"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>'; }
	function iFO(c) { return '<svg width="17" height="17" viewBox="0 0 20 20" fill="'+(c||'#4f46e5')+'" aria-hidden="true"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1H8a3 3 0 00-3 3v2.5a2.5 2.5 0 01-2.5-2.5V6z" clip-rule="evenodd"/><path d="M6 12a2 2 0 012-2h8a2 2 0 012 2v2a2 2 0 01-2 2H2h2a2 2 0 002-2v-2z"/></svg>'; }
	/* ── Capabilities from PHP ───────────────────────────────── */
	var _caps            = cfg.caps     || {};
	var _features        = cfg.features || {};
	var CAN_LOCK_FOLDERS = !!_caps.lockFolders && !!_features.folderLocking;
	/* Bulk Alt Text Editor lists images across the whole site, so the REST endpoint
	   requires edit_others_posts. Hide the trigger button for users who lack it. */
	var CAN_BULK_ALT     = !!_caps.editOthersMedia;
	/* Reordering needs a folder-edit capability. Only show the drag handle to users
	   who can actually save a new order (else the drag silently fails). */
	var CAN_REORDER      = !!_caps.reorderFolders;

	var II = {
		files:   '<svg width="16" height="16" viewBox="0 0 20 20" fill="#646970"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>',
		plus:    '<svg width="11" height="11" viewBox="0 0 12 12"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		chev:    '<svg width="10" height="10" viewBox="0 0 10 10"><path d="M3 2l4 3-4 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		search:  '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>',
		dots:    '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><circle cx="3" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="13" cy="8" r="1.5"/></svg>',
		rename:  '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M15.502 1.94a.5.5 0 010 .706L14.459 3.69l-2-2 1.043-1.044a.5.5 0 01.707 0zm-1.75 2.456l-2-2L4.939 9.21a.5.5 0 00-.121.196l-.805 2.414a.25.25 0 00.316.316l2.414-.805a.5.5 0 00.196-.12z"/></svg>',
		palette: '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M8 5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm4 3a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM5.5 7a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm.5 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/><path d="M16 8c0 3.15-1.866 2.585-3.567 2.07C11.42 9.763 10.465 9.473 10 10c-.603.683-.475 1.819-.351 2.92C9.826 14.495 9.996 16 8 16a8 8 0 110-8zm-8 7c.611 0 .654-.171.655-.176.078-.146.124-.464.07-1.119-.014-.168-.037-.37-.061-.591-.052-.464-.112-1.005-.118-1.452-.01-.726.151-1.527.622-2.078.232-.272.5-.458.81-.566.277-.098.595-.119.908-.046C13.196 10.3 15 10.682 15 8a7 7 0 10-7 7z"/></svg>',
		sub:     '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M9.828 3h3.982a2 2 0 011.992 2.181l-.637 7A2 2 0 0113.174 14H2.826a2 2 0 01-1.991-1.819l-.637-7a1.99 1.99 0 01.342-1.31L.5 3a2 2 0 012-2h3.672a2 2 0 011.414.586l.828.828A2 2 0 009.828 3z"/></svg>',
		trash:   '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M5.5 5.5A.5.5 0 016 6v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm2.5 0a.5.5 0 01.5.5v6a.5.5 0 01-1 0V6a.5.5 0 01.5-.5zm3 .5a.5.5 0 00-1 0v6a.5.5 0 001 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 01-1 1H13v9a2 2 0 01-2 2H5a2 2 0 01-2-2V4h-.5a1 1 0 010-2H6a1 1 0 011-1h2a1 1 0 011 1h3.5a1 1 0 011 1v1zM4.118 4L4 4.059V13a1 1 0 001 1h6a1 1 0 001-1V4.059zM2.5 3V2h11v1h-11z"/></svg>',
		move:    '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M1.5 1.5A.5.5 0 001 2v4.8a2.5 2.5 0 002.5 2.5h9.793l-3.347 3.346a.5.5 0 00.708.708l4.2-4.2a.5.5 0 000-.708l-4-4a.5.5 0 00-.708.708L13.293 8.3H3.5A1.5 1.5 0 012 6.8V2a.5.5 0 00-.5-.5z"/></svg>',
		download:'<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M.5 9.9a.5.5 0 01.5.5v2.5a1 1 0 001 1h12a1 1 0 001-1v-2.5a.5.5 0 011 0v2.5a2 2 0 01-2 2H2a2 2 0 01-2-2v-2.5a.5.5 0 01.5-.5z"/><path d="M7.646 11.854a.5.5 0 00.708 0l3-3a.5.5 0 00-.708-.708L8.5 10.293V1.5a.5.5 0 00-1 0v8.793L5.354 8.146a.5.5 0 10-.708.708l3 3z"/></svg>',
		sort:    '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M3.5 2.5a.5.5 0 00-1 0v8.793l-1.146-1.147a.5.5 0 00-.708.708l2 2 .007.006a.497.497 0 00.7-.005l2-2a.5.5 0 00-.707-.708L3.5 11.293V2.5zm3.5 1a.5.5 0 01.5-.5h7a.5.5 0 010 1h-7a.5.5 0 01-.5-.5zM7.5 6a.5.5 0 000 1h5a.5.5 0 000-1h-5zm0 3a.5.5 0 000 1h3a.5.5 0 000-1h-3zm0 3a.5.5 0 000 1h1a.5.5 0 000-1h-1z"/></svg>',
		check:   '<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M13.854 3.646a.5.5 0 010 .708l-7 7a.5.5 0 01-.708 0l-3.5-3.5a.5.5 0 11.708-.708L6.5 10.293l6.646-6.647a.5.5 0 01.708 0z"/></svg>',
		remove:  '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M2.5 1a1 1 0 00-1 1v1a1 1 0 001 1H3v9a2 2 0 002 2h6a2 2 0 002-2V4h.5a1 1 0 000-2H10a1 1 0 00-1-1H7a1 1 0 00-1 1H2.5zm3 4a.5.5 0 01.5.5v7a.5.5 0 01-1 0v-7a.5.5 0 01.5-.5zM8 5a.5.5 0 01.5.5v7a.5.5 0 01-1 0v-7A.5.5 0 018 5zm3 .5v7a.5.5 0 01-1 0v-7a.5.5 0 011 0z"/></svg>',
		copy:    '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M4 1.5H3a2 2 0 00-2 2V14a2 2 0 002 2h10a2 2 0 002-2V3.5a2 2 0 00-2-2h-1v1h1a1 1 0 011 1V14a1 1 0 01-1 1H3a1 1 0 01-1-1V3.5a1 1 0 011-1h1v-1z"/><path d="M9.5 1a.5.5 0 01.5.5v1a.5.5 0 01-.5.5h-3a.5.5 0 01-.5-.5v-1a.5.5 0 01.5-.5h3zm-3-1A1.5 1.5 0 005 1.5v1A1.5 1.5 0 006.5 4h3A1.5 1.5 0 0011 2.5v-1A1.5 1.5 0 009.5 0h-3z"/></svg>',
		lock:    '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg>',
		unlock:  '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M11 1a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h5V3a3 3 0 0 1 6 0v4a.5.5 0 0 1-1 0V3a2 2 0 0 0-2-2z"/></svg>',
		folder:  iF(),
		zip:     '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M6.5 7.5a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v.938l.4 1.599a1 1 0 0 1-.416 1.074l-.93.62a1 1 0 0 1-1.109 0l-.93-.62a1 1 0 0 1-.415-1.074l.4-1.599V7.5zm2 0h-1v.938a1 1 0 0 1-.03.243l-.4 1.598.93.62.93-.62-.4-1.598A1 1 0 0 1 8.5 8.438V7.5z"/><path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2zm5.5-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H9v1H8v1h1v1H8v1h1v1H7.5V5h-1V4h1V3h-1V2h1V1z"/></svg>',
		usage:   '<svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M8 2a5.53 5.53 0 0 0-3.594 1.342c-.766.66-1.321 1.52-1.464 2.383C1.266 6.095 0 7.555 0 9.318 0 11.366 1.708 13 3.781 13H13.5a2.5 2.5 0 0 0 1.915-4.091 3 3 0 0 0-2.09-5.158 3.028 3.028 0 0 0-.482.046A5.5 5.5 0 0 0 8 2zm3.5 6h-5v1.5l-2-2 2-2V7h5v1z"/></svg>',
	};

	/* ── REST ── */
	function apiFetch( path, opts ) {
		opts = opts || {};
		var method  = ( opts.method || 'GET' ).toUpperCase();
		var headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce };

		/* Some hosts (ModSecurity) and security plugins block the DELETE/PUT/PATCH
		   HTTP verbs to the REST API, returning a 403. Tunnel them through POST with
		   the X-HTTP-Method-Override header, which WordPress core routes as the real
		   method internally. */
		if ( method === 'DELETE' || method === 'PUT' || method === 'PATCH' ) {
			headers['X-HTTP-Method-Override'] = method;
			opts.method = 'POST';
		}
		if ( opts.headers ) { for ( var k in opts.headers ) { headers[ k ] = opts.headers[ k ]; } }
		opts.headers = headers;

		return fetch( cfg.restUrl + path, opts ).then( function(r) {
			if ( !r.ok ) return r.json().catch(function(){return{};}).then(function(b){throw new Error(b.message||('Request failed ('+r.status+')'));});
			return r.json();
		} );
	}

	function loadData() {
		return apiFetch('/folders?format=flat')
			.then(function(folders){
				state.folders=folders;state.loading=false;render();
				if(IS_MEDIA_LIB){
					if(!_libInited){_libInited=true;initDragDrop();initImgMenu();initFolderReorder();initNativeBulkDetection();}
					if(state.activeFolderId!==null)forceGridFilter();
				}
				return Promise.all([
					apiFetch('/counts').catch(function(){return{};}),
					apiFetch('/smart-folders').catch(function(){return[];})
				]);
			})
			.then(function(results){state.counts=results[0];state.smartFolders=results[1]||[];render();})
			.catch(function(e){console.error('[MediaNest]',e);state.loading=false;render();});
	}

	function refreshCounts() { return apiFetch('/counts').then(function(c){state.counts=c;}).catch(function(){}); }

	function apiCreate( name, pid, color ) {
		return apiFetch( '/folders', { method:'POST', body:JSON.stringify({name:name,parent_id:pid||0,color:color||''}) } ).then(function(r) {
			state.folders.push({term_id:r.term_id,parent_id:pid||0,name:name,slug:'',color:color||'',icon:'',sort_order:999});
			/* Resolve with the new folder's ID so callers (e.g. the media-modal
			   New Folder button) can select it immediately. */
			return refreshCounts().then(function(){ return r.term_id; });
		});
	}
	function apiRename( tid, name ) {
		return apiFetch('/folders/'+tid,{method:'PUT',body:JSON.stringify({name:name})}).then(function(){var f=fnd(tid);if(f)f.name=name;});
	}
	function apiColor( tid, color ) {
		return apiFetch('/folders/'+tid,{method:'PUT',body:JSON.stringify({color:color})}).then(function(){var f=fnd(tid);if(f)f.color=color;state.colorPickerFor=null;});
	}
	function apiDelete( tid ) {
		return apiFetch('/folders/'+tid,{method:'DELETE'}).then(function(){
			state.folders=state.folders.filter(function(x){return Number(x.term_id)!==tid;});
			if(state.activeFolderId===tid){setActive(null);}
			return refreshCounts();
		});
	}
	function apiBulk( ids, tids ) {
		return apiFetch('/bulk-assign',{method:'POST',body:JSON.stringify({attachment_ids:ids,term_ids:tids,replace:true})}).then(refreshCounts);
	}
	/* Permanently delete multiple attachments */
	function apiBulkDelete( ids ) {
		return apiFetch('/bulk-delete',{method:'POST',body:JSON.stringify({attachment_ids:ids})}).then(function(res){refreshCounts();return res;});
	}
	function apiSetIcon(tid,icon){return apiFetch('/folders/'+tid,{method:'PUT',body:JSON.stringify({icon:icon})}).then(function(){var f=fnd(tid);if(f)f.icon=icon;});}
	function apiLock(tid,locked){
		return apiFetch('/folders/'+tid+'/lock',{method:'POST',body:JSON.stringify({locked:locked})}).then(function(){
			var f=fnd(tid);if(f)f.is_locked=locked?1:0;
		});
	}

	function apiReorder( orderedIds ) {
		return apiFetch('/folders/reorder',{method:'POST',body:JSON.stringify({order:orderedIds})});
	}

	function apiStar(tid,starred){
		tid=parseInt(tid,10);
		/* Optimistic local update */
		if(starred){ if(state.starred.indexOf(tid)===-1)state.starred.push(tid); }
		else{ state.starred=state.starred.filter(function(x){return x!==tid;}); }
		return apiFetch('/folders/'+tid+'/star',{method:'POST',body:JSON.stringify({starred:!!starred})})
			.then(function(res){ if(res&&Array.isArray(res.ids)){state.starred=res.ids.map(function(x){return parseInt(x,10);});} });
	}

	/* ── Alt Text Editor API helpers ─────────────────────────── */
	function apiAltList(page){
		return apiFetch('/alt-editor?page='+(page||1)+'&per_page='+ALT_PER_PAGE);
	}
	function apiSaveAlt(id,text){
		return apiFetch('/alt-editor/'+id,{method:'POST',body:JSON.stringify({alt_text:text})});
	}

	function downloadFolder( termId, folderName ) {
		/* Build admin-ajax URL with our custom action */
		var url = cfg.ajaxUrl
			+ '?action=rayetun_medianest_export_folder'
			+ '&folder_id=' + encodeURIComponent(termId)
			+ '&nonce=' + encodeURIComponent(cfg.nonce);
		/* Open in new tab — browser handles the ZIP download */
		window.open(url, '_blank');
	}

	/* Import existing media — card-style rule picker, auto-preview */
	function showImportModal() {
		var selectedRule='by_year';
		var onlyUnassigned=true;
		var overlay=el('div',{className:'rayetun-mn-modal-overlay'});
		var box=el('div',{className:'rayetun-mn-modal-box'});
		box.innerHTML='<h2 class="rayetun-mn-modal-title">Import Existing Media</h2><p class="rayetun-mn-modal-desc">Auto-organize your media into folders by a chosen rule.</p>';
		/* Rule cards */
		var rules=[{v:'by_year',label:'By Year',sub:'2022, 2023, 2024…'},{v:'by_month',label:'By Month',sub:'January 2024…'},{v:'by_type',label:'By File Type',sub:'Images, Videos, PDFs…'}];
		var cards=el('div',{className:'rayetun-mn-rule-cards'});
		var cardEls={};
		rules.forEach(function(r){
			var card=el('div',{className:'rayetun-mn-rule-card'+(r.v===selectedRule?' is-selected':''),onClick:function(){
				selectedRule=r.v;
				Object.keys(cardEls).forEach(function(k){cardEls[k].classList.toggle('is-selected',k===r.v);});
				runPreview();
			}});
			card.innerHTML='<strong>'+r.label+'</strong><span>'+r.sub+'</span>';
			cardEls[r.v]=card;cards.appendChild(card);
		});
		box.appendChild(cards);
		/* Scope checkbox */
		var scopeWrap=el('div',{className:'rayetun-mn-modal-scope'});
		var cbx=el('input',{type:'checkbox',id:'mn-scope-cb'});cbx.checked=true;
		cbx.addEventListener('change',function(){onlyUnassigned=cbx.checked;runPreview();});
		scopeWrap.appendChild(cbx);
		scopeWrap.appendChild(el('label',{'for':'mn-scope-cb'},' Only unassigned files (recommended)'));
		box.appendChild(scopeWrap);
		/* Preview — auto-runs */
		var previewArea=el('div',{className:'rayetun-mn-modal-preview'});
		previewArea.textContent='Loading preview…';
		box.appendChild(previewArea);
		function runPreview(){
			previewArea.textContent='Loading…';
			apiFetch('/import/preview?rule='+selectedRule+'&scope='+(onlyUnassigned?'unassigned':'all'))
				.then(function(res){
					if(!res.groups||!res.groups.length){previewArea.textContent='No files to organize with this rule.';return;}
					var total=res.groups.reduce(function(s,g){return s+g.count;},0);
					previewArea.innerHTML='<strong>'+res.groups.length+' folder'+(res.groups.length!==1?'s':'')+', '+total+' file'+(total!==1?'s':'')+' to organize:</strong>'+
						'<ul class="rayetun-mn-preview-list">'+
						res.groups.map(function(g){return '<li>'+(g.is_new?'<span class="mn-new-tag">+ New</span> ':'')+'<b>'+g.label+'</b> — '+g.count+' file'+(g.count!==1?'s':'')+'</li>';}).join('')+
						'</ul>';
				}).catch(function(e){previewArea.textContent='Preview error: '+e.message;});
		}
		runPreview();
		/* Buttons */
		var btnRow=el('div',{className:'rayetun-mn-modal-btns'});
		var applyBtn=el('button',{className:'rayetun-mn-modal-btn is-primary',onClick:function(){
			applyBtn.textContent='Applying…';applyBtn.disabled=true;
			apiFetch('/import/apply',{method:'POST',body:JSON.stringify({rule:selectedRule,scope:onlyUnassigned?'unassigned':'all'})})
				.then(function(res){
					previewArea.innerHTML='<span style="color:#2a6e00;font-weight:600">Done!</span> '+res.created+' folder'+(res.created!==1?'s':'')+' created, '+res.assigned+' file'+(res.assigned!==1?'s':'')+' assigned.';
					applyBtn.textContent='Done';
					return loadData();
				}).catch(function(e){previewArea.textContent='Error: '+e.message;applyBtn.disabled=false;applyBtn.textContent='Apply';});
		}},'Apply');
		btnRow.appendChild(el('button',{className:'rayetun-mn-modal-btn',onClick:function(){overlay.remove();}},'× Close'));
		btnRow.appendChild(applyBtn);
		box.appendChild(btnRow);
		overlay.appendChild(box);
		overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
		document.body.appendChild(overlay);
	}


	function apiRemoveFromFolder( ids, termId ) {
		return apiFetch('/folders/'+termId+'/remove',{method:'POST',body:JSON.stringify({attachment_ids:ids})}).then(refreshCounts);
	}
	function fnd(id){ for(var i=0;i<state.folders.length;i++){if(Number(state.folders[i].term_id)===id)return state.folders[i];} return null; }

	/* ── Cookie helpers ──
	   Cookie is sent with every AJAX request automatically.
	   PHP reads it in filter_ajax_query — works for ALL WP versions
	   regardless of whether Query uses this.args or this.props internally. */
	var COOKIE_NAME = 'rayetun_mn_folder';

	function setFolderCookie( id ) {
		var val     = ( id === null ) ? '' : encodeURIComponent( String( id ) );
		var maxAge  = ( id === null ) ? '; expires=Thu, 01 Jan 1970 00:00:00 UTC' : '; max-age=2592000'; /* 30d */
		document.cookie = COOKIE_NAME + '=' + val + maxAge + '; path=/; SameSite=Lax';
	}

	/* ── setActive ── */
	function setActive( id ) {
		state.activeFolderId = id;
		try {
			if(id===null) localStorage.removeItem(STORAGE_KEY);
			else localStorage.setItem(STORAGE_KEY, String(id));
		} catch(e) {}

		/* Cookie is read by PHP on every query-attachments request */
		setFolderCookie( id );

		if ( IS_MEDIA_LIB ) {
			forceGridFilter();
			render();
			return;
		}
		/* Modal context */
		modalFilter( id );
		render();
	}

	/* ────────────────────────────────────────────────────────
	   forceGridFilter — trigger the WP media grid to re-fetch.

	   PHP reads the folder from the cookie on every query-attachments
	   request, so we only need to cause WP to make a new AJAX call.

	   We use lib.props.set() — WP's own native mechanism (same as
	   its search/mime-type filters). The prop change fires _requery,
	   WP's scroll handler uses _.defer so lib.more() fires in the next
	   tick when lib._query is ALREADY the new query. PHP reads the
	   cookie and returns filtered results. Grid updates via _sync.
	   ──────────────────────────────────────────────────────── */
	function forceGridFilter() {
		/* On the Media Library page the frame is wp.media.frame; inside a media
		   MODAL (post editor / Elementor / Divi) it's only reachable via
		   _mnLastFrame. Support both so uploads refresh live in every context. */
		var frame = ( window.wp && window.wp.media && window.wp.media.frame ) || _mnLastFrame;
		if ( !frame ) {
			var _att = 0;
			var _iv = setInterval(function() {
				_att++;
				var f = ( window.wp && window.wp.media && window.wp.media.frame ) || _mnLastFrame;
				if (f) { clearInterval(_iv); forceGridFilter(); }
				if (_att > 40) clearInterval(_iv);
			}, 100);
			return;
		}

		var lib;
		try { lib = frame.state().get('library'); } catch(e) {}
		if (!lib) { return; }

		/* Change a prop to trigger _requery. Use a timestamp so every
		   folder click creates a distinct query (no memoization cache hit). */
		var ts = String(Date.now());
		lib.props.unset('_mn_ts', {silent: true});
		lib.props.set('_mn_ts', ts);
		/* _requery now fires, lib._query = newQuery.
		   WP's scroll/_.defer fires lib.more() in next tick on newQuery.
		   Our wp.ajax.post patch + cookie both ensure PHP applies the filter. */
	}

	/* ────────────────────────────────────────────────────────
	   initUploadRefresh — show uploads live, with the native progress bar.

	   Our folder filtering turns the Media Library grid into a CUSTOM library
	   query (the _mn_ts prop + folder-cookie filtering). WordPress only auto-
	   injects newly-uploaded files into SIMPLE, mirroring queries — so with a
	   custom query active, uploads never appear until a manual page reload.

	   WordPress keeps every in-flight upload in the global wp.Uploader.queue
	   collection (real wp.media Attachment models). We bridge each one into the
	   currently-visible grid collection ourselves: because it's WP's own model,
	   the grid renders it natively — the blue progress bar while it uploads and
	   the real thumbnail once it finishes — with no full-grid "flash".
	   ──────────────────────────────────────────────────────── */
	function initUploadRefresh() {
		if ( !window.wp || !window.wp.Uploader || !window.wp.Uploader.queue ) { return false; }
		var q = window.wp.Uploader.queue;
		if ( q._mnUploadBound ) { return true; }
		q._mnUploadBound = true;

		function currentLibrary() {
			try {
				var frame = ( window.wp.media && window.wp.media.frame ) || _mnLastFrame;
				return frame ? frame.state().get( 'library' ) : null;
			} catch ( e ) { return null; }
		}

		/* Drop the upload's model into the visible grid (deduped by id/cid). */
		function inject( model ) {
			var lib = currentLibrary();
			if ( !lib || !model ) { return; }
			if ( ( model.id && lib.get( model.id ) ) || lib.get( model.cid ) ) { return; }
			try { lib.add( model, { at: 0 } ); } catch ( e ) {}
		}

		/* 'add' → upload started: inject the model so WordPress renders it with the
		   native blue progress bar while it uploads. */
		q.on( 'add', inject );

		/* Once the whole batch finishes, the injected placeholders have done their
		   job. WordPress doesn't cleanly transition a manually-injected model to its
		   finished state (it stays stuck on "uploading…"), so we re-query the grid
		   once to reconcile it with the server — real thumbnails and correct counts.
		   This is a single, quiet refresh at the END of the upload, not per-file. */
		q.on( 'remove reset', function () {
			clearTimeout( q._mnRefreshT );
			q._mnRefreshT = setTimeout( function () {
				if ( q.length === 0 ) { forceGridFilter(); }
			}, 400 );
		} );
		return true;
	}

	/* Get attachment IDs currently selected via WP's native Bulk Select UI.
	   WP stores these in frame.state().get('selection') — a Backbone Selection. */
	function getWpSelectedIds() {
		try {
			if (!window.wp||!window.wp.media||!window.wp.media.frame) return null;
			var sel = window.wp.media.frame.state().get('selection');
			if (!sel||!sel.models||!sel.models.length) return null;
			var ids = [], i;
			for (i=0;i<sel.models.length;i++) { ids.push(sel.models[i].id); }
			return ids.length ? ids : null;
		} catch(e) { return null; }
	}

	function modalFilter( folderId ) {
		/* Set the cookie — PHP reads this on every query-attachments AJAX call */
		setFolderCookie( folderId );

		var triggered = false;

		/* Primary: trigger re-query via tracked Backbone frame */
		try {
			var frame = ( window.wp && window.wp.media && window.wp.media.frame ) || _mnLastFrame;
			if ( frame ) {
				var lib = frame.state().get( 'library' );
				if ( lib ) {
					lib.props.unset( '_mn_ts', { silent: true } );
					lib.props.set( '_mn_ts', String( Date.now() ) );
					triggered = true;
				}
			}
		} catch(e) {}

		/* Fallback: dispatch 'input' on the WP media search input.
		   WP's media view listens to input/keyup on .search to fire query-attachments.
		   Our PHP hook reads the cookie on every such request — so the cookie filter
		   will apply even when we have no Backbone frame reference (third-party plugins
		   that use non-standard media frame implementations). */
		if ( !triggered ) {
			setTimeout( function() {
				try {
					var modal = document.querySelector( '.media-modal' );
					if ( !modal ) return;
					var si = modal.querySelector( '.search.media-search-input, input.search[type="search"], input.search[type="text"], #media-search-input' );
					if ( si ) { si.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
				} catch(e) {}
			}, 30 );
		}
	}

	/* ── Tree builder ── */
	/* ── Breadcrumb builder ── */
	function buildBreadcrumb( termId ) {
		var crumbs = [], current = Number(termId), safety = 0;
		while ( current && safety++ < 20 ) {
			var f = fnd( current );
			if (!f) break;
			crumbs.unshift(f);
			current = f.parent_id ? Number(f.parent_id) : 0;
		}
		return crumbs;
	}


	function buildTree( flat, pid ) {
		pid = pid || 0;
		var out = [];
		for ( var i=0; i<flat.length; i++ ) {
			var f = flat[i];
			if ( Number(f.parent_id) !== pid ) continue;
			if ( state.searchQuery && f.name.toLowerCase().indexOf(state.searchQuery.toLowerCase()) === -1 ) continue;
			var c = Object.assign({},f);
			c.children = buildTree(flat,Number(f.term_id));
			out.push(c);
		}
		out.sort(function(a,b){
			if(state.sortBy==='az')     return a.name.localeCompare(b.name);
			if(state.sortBy==='za')     return b.name.localeCompare(a.name);
			if(state.sortBy==='count_desc') return (state.counts[Number(b.term_id)]||0)-(state.counts[Number(a.term_id)]||0);
			if(state.sortBy==='count_asc')  return (state.counts[Number(a.term_id)]||0)-(state.counts[Number(b.term_id)]||0);
			/* manual: sort_order then name */
			return (Number(a.sort_order||0)-Number(b.sort_order||0))||a.name.localeCompare(b.name);
		});
		return out;
	}

	/* ── DOM helper ── */
	function el( tag, attrs ) {
		var ch = Array.prototype.slice.call(arguments,2);
		var node = document.createElement(tag);
		if (attrs) Object.keys(attrs).forEach(function(k){
			var v=attrs[k];
			if(k==='className')node.className=v;
			else if(k==='innerHTML')node.innerHTML=v;
			else if(k==='style'&&typeof v==='object')Object.assign(node.style,v);
			else if(k.indexOf('on')===0)node.addEventListener(k.slice(2).toLowerCase(),v);
			else node.setAttribute(k,v);
		});
		ch.forEach(function(c){
			if(c==null)return;
			if(Array.isArray(c)){c.forEach(function(x){if(x)node.appendChild(typeof x==='string'?document.createTextNode(x):x);});}
			else node.appendChild(typeof c==='string'?document.createTextNode(c):c);
		});
		return node;
	}

	/* ── Inline input ── */
	function mkInput( ph, onOK, onCancel, extraCls ) {
		var row=el('div',{className:'rayetun-mn-new-folder-row'+(extraCls?' '+extraCls:'')});
		var inp=el('input',{type:'text',className:'rayetun-mn-new-folder-input',placeholder:ph||'Folder name'});
		function go(){ var v=inp.value.trim(); if(v)onOK(v); else if(onCancel)onCancel(); }
		inp.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Enter'){e.preventDefault();go();}if(e.key==='Escape'&&onCancel)onCancel();});
		row.appendChild(inp);
		row.appendChild(el('button',{className:'rayetun-mn-new-folder-ok',onClick:function(e){e.stopPropagation();go();}},'OK'));
		row.appendChild(el('button',{className:'rayetun-mn-new-folder-cancel',onClick:function(e){e.stopPropagation();if(onCancel)onCancel();}},'✕'));
		setTimeout(function(){inp.focus();},30);
		return row;
	}

	/* One-time init guard — prevents duplicate document-level listeners when
	   loadData() is called after folder operations (create, reorder, etc.). */
	var _libInited=false;

	/* ── Drag and drop ── */
	var drag={active:false,ids:[],ghost:null,startX:0,startY:0,tracking:false};
	function initDragDrop() {
		document.addEventListener('mousedown',function(e){
			if(e.button!==0)return;
			var att=e.target.closest&&e.target.closest('.attachment');
			if(!att)return;
			var id=parseInt(att.dataset.id,10);if(isNaN(id))return;
			drag.tracking=true;drag.active=false;
			drag.startX=e.clientX;drag.startY=e.clientY;

			/* Priority: WP native selection > our bulk mode > single item */
			var wpSel=getWpSelectedIds();
			if(wpSel&&wpSel.length>1){
				/* Use WP's native Bulk Select selection (all checked items) */
				drag.ids=wpSel;
			} else if(state.bulkMode&&state.selectedIds.length>0){
				drag.ids=state.selectedIds.slice();
			} else {
				/* Also check for .selected class WP adds on bulk-selected items */
				var domSel=document.querySelectorAll('.attachment.selected');
				if(domSel.length>1){
					var domIds=[],k;
					for(k=0;k<domSel.length;k++){
						var did=parseInt(domSel[k].dataset.id,10);
						if(!isNaN(did))domIds.push(did);
					}
					drag.ids=domIds.length>1?domIds:[id];
				} else {
					drag.ids=[id];
				}
			}
		},true);
		document.addEventListener('mousemove',function(e){
			if(!drag.tracking)return;
			var dx=e.clientX-drag.startX,dy=e.clientY-drag.startY;
			if(!drag.active&&Math.sqrt(dx*dx+dy*dy)<8)return;
			if(!drag.active){
				drag.active=true;
				var g=document.createElement('div');
				g.style.cssText='position:fixed;z-index:999999;pointer-events:none;background:#4f46e5;color:#fff;border-radius:4px;padding:4px 10px;font-size:12px;box-shadow:0 2px 8px rgba(0,0,0,.3);';
				g.textContent=(drag.ids.length>1?drag.ids.length+' files':'1 file')+' \u2192 drop on folder';
				document.body.appendChild(g);drag.ghost=g;
			}
			if(drag.ghost){drag.ghost.style.left=(e.clientX+14)+'px';drag.ghost.style.top=(e.clientY+8)+'px';}
			document.querySelectorAll('.rayetun-mn-row.is-drag-over').forEach(function(r){r.classList.remove('is-drag-over');});
			var under=document.elementFromPoint(e.clientX,e.clientY);
			var row=under&&under.closest&&under.closest('.rayetun-mn-row[data-mn-term]');
			if(row)row.classList.add('is-drag-over');
			e.preventDefault();
		});
		document.addEventListener('mouseup',function(e){
			if(!drag.tracking)return;
			var wasActive=drag.active;
			drag.tracking=false;drag.active=false;
			document.querySelectorAll('.rayetun-mn-row.is-drag-over').forEach(function(r){r.classList.remove('is-drag-over');});
			if(drag.ghost){drag.ghost.remove();drag.ghost=null;}
			if(!wasActive)return;
			var under=document.elementFromPoint(e.clientX,e.clientY);
			var row=under&&under.closest&&under.closest('.rayetun-mn-row[data-mn-term]');
			if(!row)return;
			var tid=parseInt(row.getAttribute('data-mn-term'),10);if(isNaN(tid))return;
			apiBulk(drag.ids.slice(),[tid]).then(function(){
				state.selectedIds=[];state.bulkMode=false;render();
				row.style.background='#d1fae5';
				setTimeout(function(){row.style.background='';},800);
			}).catch(console.error);
		});
	}

	/* ── Image right-click → Add to folder ── */
	var imgMenu=null;
	function closeImgMenu(){if(imgMenu){imgMenu.remove();imgMenu=null;}}
	function initImgMenu() {
		document.addEventListener('contextmenu',function(e){
			var att=e.target.closest&&e.target.closest('.attachment');
			if(!att)return;
			e.preventDefault();closeImgMenu();closeMenu();
			var aid=parseInt(att.dataset.id,10);if(isNaN(aid))return;
			var menu=el('div',{className:'rayetun-mn-img-menu'});

			/* Helper add-ons use to build a menu item (returns a DOM node to append). */
			function mkImgItem(iconHtml,label,cls,fn){
				var b=el('button',{className:'rayetun-mn-img-menu-item'+(cls?' '+cls:'')});
				b.innerHTML=(iconHtml||'')+'<span></span>';
				b.querySelector('span').textContent=label;
				b.addEventListener('click',function(ev){ev.stopPropagation();closeImgMenu();fn();});
				return b;
			}

			/* "Remove from [folder]" appears at the top when viewing a specific folder */
			if(state.activeFolderId&&state.activeFolderId!=='uncategorized'){
				var curFolder=fnd(state.activeFolderId);
				var folderLabel=curFolder?curFolder.name:'folder';
				var rmBtn=el('button',{className:'rayetun-mn-img-menu-item is-danger'});
				rmBtn.innerHTML=II.remove+'<span>Remove from “'+folderLabel+'”</span>';
				rmBtn.addEventListener('click',function(ev){
					ev.stopPropagation();closeImgMenu();
					apiRemoveFromFolder([aid],state.activeFolderId).then(function(){
						render();forceGridFilter();
						showToast('Removed from folder');
					}).catch(console.error);
				});
				menu.appendChild(rmBtn);
				menu.appendChild(el('div',{className:'rayetun-mn-img-menu-sep'}));
			}

			/* "Where is this used?" — usage map (only when feature enabled) */
			if(_features.mediaUsageMap!==false){
				var usageBtn=el('button',{className:'rayetun-mn-img-menu-item'});
				usageBtn.innerHTML=II.usage+'<span>Where is this used?</span>';
				usageBtn.addEventListener('click',function(ev){ev.stopPropagation();closeImgMenu();showUsageModal(aid);});
				menu.appendChild(usageBtn);
			}

			/* Extension point: add-ons (PixelVault Pro) inject image action items here.
			   Usage: wp.hooks.addAction('pixelvault.imageContextMenu','my-addon',function(menu,attachmentId,helpers){
			              menu.appendChild( helpers.addItem(iconSvg,'Label','',function(){ ... }) );
			          }); */
			if(window.wp&&wp.hooks){wp.hooks.doAction('pixelvault.imageContextMenu',menu,aid,{addItem:mkImgItem});}

			menu.appendChild(el('div',{className:'rayetun-mn-img-menu-sep'}));

			menu.appendChild(el('div',{className:'rayetun-mn-img-menu-title'},'Add to folder:'));
			menu.appendChild(el('div',{className:'rayetun-mn-img-menu-sep'}));
			var tree=buildTree(state.folders,0);
			if(!tree.length){menu.appendChild(el('div',{className:'rayetun-mn-img-menu-item'},'No folders yet'));}
			else{
				(function addI(items,depth){items.forEach(function(f){
					var b=el('button',{className:'rayetun-mn-img-menu-item',style:{paddingLeft:(10+depth*14)+'px'}});
					b.innerHTML=iF(f.color||'#646970')+'<span>'+f.name+'</span>';
					(function(ff){b.addEventListener('click',function(ev){ev.stopPropagation();closeImgMenu();
						var fromTid=(state.activeFolderId&&state.activeFolderId!=='uncategorized')?state.activeFolderId:null;
						var op=(fromTid&&fromTid!==Number(ff.term_id))?apiMoveTo([aid],fromTid,Number(ff.term_id)):apiBulk([aid],[Number(ff.term_id)]);
						op.then(function(){render();forceGridFilter();showToast('Added to '+ff.name);}).catch(console.error);
					});})(f);
					menu.appendChild(b);
					if(f.children&&f.children.length)addI(f.children,depth+1);
				});})(tree,0);
			}
			document.body.appendChild(menu);imgMenu=menu;
			/* Measure actual size so the menu always fits the viewport. */
			var vw=window.innerWidth,vh=window.innerHeight;
			var mw=menu.offsetWidth||215,mh=menu.offsetHeight||200;
			var left=e.clientX,top=e.clientY;
			if(left+mw>vw)left=Math.max(4,vw-mw-8);
			if(top+mh>vh)top=Math.max(4,vh-mh-8);
			menu.style.left=left+'px';menu.style.top=top+'px';
		});
		document.addEventListener('click',closeImgMenu);
	}

	/* ── Folder context menu ── */
	var activeMenu=null;
	function closeMenu(){if(activeMenu){activeMenu.remove();activeMenu=null;}}
	function showCtxMenu(e,folder,fromDots){
		e.preventDefault();e.stopPropagation();closeMenu();closeImgMenu();
		var tid=Number(folder.term_id);
		var isLocked=!!(folder.is_locked&&parseInt(folder.is_locked,10)>0);
		var menu=el('div',{className:'rayetun-mn-context-menu'});
		function mkI(ico,lbl,cls,fn){
			var b=el('button',{className:'rayetun-mn-context-item'+(cls?' '+cls:'')});
			b.innerHTML=ico+'<span>'+lbl+'</span>';
			b.addEventListener('click',function(ev){ev.stopPropagation();closeMenu();fn();});
			return b;
		}
		// Rename — grayed out when locked
		menu.appendChild(mkI(II.rename,'Rename',isLocked?'is-disabled':'',function(){if(!isLocked)startRename(folder);}));
		// New subfolder — always allowed
		menu.appendChild(mkI(II.sub,'New subfolder','',function(){state.showSubfolderFor=tid;state.expanded[tid]=true;render();}));
		// Star / Unstar — per-user favourite, pinned to top of sidebar
		var _isStar=isStarred(tid);
		menu.appendChild(mkI(
			_isStar
				?'<svg width="15" height="15" viewBox="0 0 24 24" fill="#f59e0b" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.6 7L12 17.8 5.8 21.2l1.6-7L2 9.5l7.1-.6z"/></svg>'
				:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.6 7L12 17.8 5.8 21.2l1.6-7L2 9.5l7.1-.6z"/></svg>',
			_isStar?'Unstar':'Star',
			'',
			function(){apiStar(tid,!_isStar).then(function(){render();}).catch(function(e){showToast((e&&e.message)||'Could not update.','error');});}
		));
		// Change color — grayed out when locked
		menu.appendChild(mkI(II.palette,'Change color',isLocked?'is-disabled':'',function(){if(!isLocked){state.colorPickerFor=(state.colorPickerFor===tid)?null:tid;render();}}));
		// Download — always allowed
		menu.appendChild(mkI(II.download,'Download all','',function(){downloadFolder(tid,folder.name);}));
		menu.appendChild(el('div',{className:'rayetun-mn-context-sep'}));
		menu.appendChild(mkI(II.copy,'Copy gallery shortcode','',function(){copyGalleryShortcode(folder);}));
		if(_features.zipImport!==false){menu.appendChild(mkI(II.zip,'Import ZIP to folder','',function(){showZipImportModal(folder);}));}
		menu.appendChild(el('div',{className:'rayetun-mn-context-sep'}));
		// Delete — grayed out when locked
		menu.appendChild(mkI(II.trash,'Delete',isLocked?'is-danger is-disabled':'is-danger',function(){
			if(isLocked)return;
			if(window.confirm('Delete this folder? Files will not be deleted.')){apiDelete(tid).then(function(){render();}).catch(function(e){showToast((e&&e.message)||'Delete failed.','error');});}
		}));
		// Lock / Unlock — admins only
		if(CAN_LOCK_FOLDERS){
			menu.appendChild(el('div',{className:'rayetun-mn-context-sep'}));
			menu.appendChild(mkI(
				isLocked?II.unlock:II.lock,
				isLocked?'Unlock Folder':'Lock Folder',
				isLocked?'is-unlock':'is-lock',
				function(){apiLock(tid,!isLocked).then(function(){render();}).catch(function(err){showToast((err&&err.message)||'Lock failed.','error');});}
			));
		}
		/* Extension point: add-ons (PixelVault Pro) inject folder action items here.
		   Usage: wp.hooks.addAction('pixelvault.folderContextMenu','my-addon',function(menu,folder,helpers){
		              menu.appendChild( helpers.addItem(iconSvg,'Label','',function(){ ... }) );
		          }); */
		if(window.wp&&wp.hooks){wp.hooks.doAction('pixelvault.folderContextMenu',menu,folder,{addItem:mkI});}

		document.body.appendChild(menu);activeMenu=menu;
		/* Measure the actual rendered size so the menu always fits the viewport,
		   regardless of how many items it has (Star, or add-on-injected items). */
		var vw=window.innerWidth,vh=window.innerHeight;
		var mw=menu.offsetWidth||200,mh=menu.offsetHeight||260;
		var left=fromDots?e.currentTarget.getBoundingClientRect().right+4:e.clientX;
		var top=fromDots?e.currentTarget.getBoundingClientRect().top:e.clientY;
		if(left+mw>vw)left=Math.max(4,vw-mw-8);
		if(top+mh>vh)top=Math.max(4,vh-mh-8);
		menu.style.left=left+'px';menu.style.top=top+'px';
	}

	/* Copy [medianest_gallery] shortcode for this folder to clipboard */
	function copyGalleryShortcode(folder){
		var sc='[medianest_gallery folder_id="'+folder.term_id+'" columns="3"]';
		/* Modern Clipboard API with legacy execCommand fallback */
		if(navigator.clipboard&&navigator.clipboard.writeText){
			navigator.clipboard.writeText(sc).then(function(){
				showToast('Shortcode copied \u2014 paste it into any post or page');
			}).catch(function(){
				legacyCopy(sc);
			});
		}else{
			legacyCopy(sc);
		}
	}

	function legacyCopy(text){
		var ta=document.createElement('textarea');
		ta.value=text;ta.style.cssText='position:fixed;left:-9999px;top:-9999px;opacity:0;';
		document.body.appendChild(ta);ta.focus();ta.select();
		try{
			document.execCommand('copy');
			showToast('Shortcode copied \u2014 paste it into any post or page');
		}catch(e){
			/* Last resort: show the shortcode in a prompt so user can manually copy */
			window.prompt('Copy this shortcode:',text);
		}
		document.body.removeChild(ta);
	}

	/* \u2500\u2500 ZIP Import modal (chunked upload + import) \u2500\u2500 */
	function showZipImportModal(folder){
		var overlay=el('div',{className:'rayetun-mn-modal-overlay'});
		var box=el('div',{className:'rayetun-mn-modal-box'});
		box.innerHTML='<h2 class="rayetun-mn-modal-title">Import ZIP to &ldquo;'+folder.name+'&rdquo;</h2>'+
			'<p class="rayetun-mn-modal-desc">Upload a .zip file &mdash; all images, videos, audio files, and PDFs inside will be extracted and added directly to this folder. Large archives are imported in batches, so they won\u2019t time out.</p>';

		var selectedFile=null;
		var isDone=false;

		/* Drop zone + hidden-ish file input (Browse). */
		var dropZone=el('div',{className:'rayetun-mn-zip-dropzone'});
		var dzHint=el('div',{className:'rayetun-mn-zip-hint'},'Drag & drop a .zip here, or');
		var fileInput=el('input',{type:'file',accept:'.zip,application/zip,application/x-zip-compressed'});
		fileInput.style.cssText='display:block;margin:8px auto 0;';
		var fileNameEl=el('div',{className:'rayetun-mn-zip-filename'});
		dropZone.appendChild(dzHint);
		dropZone.appendChild(fileInput);
		dropZone.appendChild(fileNameEl);
		box.appendChild(dropZone);

		var progressArea=el('div',{className:'rayetun-mn-modal-preview'});
		box.appendChild(progressArea);

		function setFile(f){
			if(isDone)return;
			if(!f)return;
			if(!/\.zip$/i.test(f.name)){progressArea.textContent='Please choose a .zip file.';return;}
			selectedFile=f;
			fileNameEl.textContent=f.name;
			progressArea.textContent='';
			importBtn.disabled=false;importBtn.textContent='Upload & Import';
		}
		fileInput.addEventListener('change',function(){ if(fileInput.files&&fileInput.files[0]) setFile(fileInput.files[0]); });

		/* Intercept drag/drop at the document level WHILE this modal is open, so the
		   WordPress media-library uploader (which listens on the whole window) can't
		   grab the dropped .zip and upload it as a media file. preventDefault on
		   dragover is required for 'drop' to fire; stopImmediatePropagation keeps the
		   event from ever reaching WP's handlers. */
		function isOpen(){ return document.body.contains(overlay); }
		function onOver(e){ if(!isOpen())return; e.preventDefault(); e.stopImmediatePropagation(); if(e.dataTransfer)e.dataTransfer.dropEffect='copy'; dropZone.classList.add('is-drag'); }
		function onLeave(e){ if(!isOpen())return; e.stopImmediatePropagation(); }
		function onDrop(e){ if(!isOpen())return; e.preventDefault(); e.stopImmediatePropagation(); dropZone.classList.remove('is-drag'); var f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; setFile(f); }
		document.addEventListener('dragenter',onOver,true);
		document.addEventListener('dragover',onOver,true);
		document.addEventListener('dragleave',onLeave,true);
		document.addEventListener('drop',onDrop,true);
		function closeModal(){
			document.removeEventListener('dragenter',onOver,true);
			document.removeEventListener('dragover',onOver,true);
			document.removeEventListener('dragleave',onLeave,true);
			document.removeEventListener('drop',onDrop,true);
			overlay.remove();
		}

		function parseJson(r){
			if(r.ok)return r.json();
			/* Surface the REAL reason. A WordPress REST error is JSON with a
			   `message`; but a WAF/security-plugin block, a proxy error or a PHP
			   fatal often returns an HTML page — so fall back to a cleaned-up text
			   snippet, and always include the HTTP status. */
			return r.text().then(function(t){
				var msg='';
				try{ var j=JSON.parse(t); msg=j.message||j.code||''; }
				catch(e){ msg=t?String(t).replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,180):''; }
				throw new Error(msg?(msg+' (HTTP '+r.status+')'):('Request failed — HTTP '+r.status+' '+(r.statusText||'')));
			});
		}
		function fail(e){ progressArea.textContent='Error: '+(e&&e.message?e.message:'Import failed'); importBtn.disabled=false; importBtn.textContent='Retry'; }
		function finish(acc){
			progressArea.innerHTML='<span style="color:#16a34a;font-weight:600">Done!</span> '+
				acc.imported+' file'+(acc.imported!==1?'s':'')+' imported, '+acc.skipped+' skipped'+
				(acc.errors?' ('+acc.errors+' errors)':'')+'.';
			isDone=true; importBtn.disabled=false; importBtn.textContent='Done';
			loadData();
		}
		function runBatch(session,offset,total,acc){
			progressArea.innerHTML='Importing '+Math.min(offset,total)+' of '+total+'\u2026';
			fetch(cfg.restUrl+'/zip-import/batch',{
				method:'POST',
				headers:{'X-WP-Nonce':cfg.restNonce,'Content-Type':'application/json'},
				body:JSON.stringify({session:session,offset:offset})
			}).then(parseJson).then(function(b){
				acc.imported+=(b.imported|0); acc.errors+=(b.errors|0); acc.skipped+=(b.skipped|0);
				progressArea.innerHTML='Importing '+Math.min(b.next_offset|0,total)+' of '+total+'\u2026';
				if(b.done){ finish(acc); }
				else { runBatch(session,b.next_offset|0,total,acc); }
			}).catch(fail);
		}
		function startImport(){
			importBtn.disabled=true; importBtn.textContent='Uploading\u2026'; progressArea.textContent='Uploading ZIP\u2026';
			var fd=new FormData();
			fd.append('zip_file',selectedFile);
			fd.append('folder_id',String(folder.term_id));
			/* NOTE: no Content-Type \u2014 the browser sets the multipart boundary. */
			fetch(cfg.restUrl+'/zip-import/start',{
				method:'POST',
				headers:{'X-WP-Nonce':cfg.restNonce},
				body:fd
			}).then(parseJson).then(function(res){
				var total=res.total|0, skipped=res.skipped|0;
				if(!total){ finish({imported:0,skipped:skipped,errors:0}); return; }
				importBtn.textContent='Importing\u2026';
				runBatch(res.session,0,total,{imported:0,skipped:skipped,errors:0});
			}).catch(fail);
		}

		var btnRow=el('div',{className:'rayetun-mn-modal-btns'});
		var importBtn=el('button',{className:'rayetun-mn-modal-btn is-primary',onClick:function(){
			if(isDone){ closeModal(); return; }
			if(!selectedFile){ progressArea.textContent='Please select a ZIP file first.'; return; }
			startImport();
		}},'Upload & Import');

		btnRow.appendChild(el('button',{className:'rayetun-mn-modal-btn',onClick:function(){closeModal();}},'\u00d7 Close'));
		btnRow.appendChild(importBtn);
		box.appendChild(btnRow);
		overlay.appendChild(box);
		overlay.addEventListener('click',function(e){if(e.target===overlay)closeModal();});
		document.body.appendChild(overlay);
	}

	/* \u2500\u2500 Usage Map modal \u2500\u2500 */
	function showUsageModal(attachmentId){
		var overlay=el('div',{className:'rayetun-mn-modal-overlay'});
		var box=el('div',{className:'rayetun-mn-modal-box'});
		box.innerHTML='<h2 class="rayetun-mn-modal-title">Where is this file used?</h2>'+
			'<div id="mn-usage-body"><p class="rayetun-mn-modal-desc">Loading\u2026</p></div>';
		var btnRow=el('div',{className:'rayetun-mn-modal-btns'});
		btnRow.appendChild(el('button',{className:'rayetun-mn-modal-btn',onClick:function(){overlay.remove();}},'\u00d7 Close'));
		box.appendChild(btnRow);
		overlay.appendChild(box);
		overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
		document.body.appendChild(overlay);

		apiFetch('/attachment/'+attachmentId+'/usage').then(function(res){
			var body=document.getElementById('mn-usage-body');
			if(!body)return;
			if(!res.total){
				body.innerHTML='<p class="rayetun-mn-modal-desc">This file is not used in any post or page.</p>';
				return;
			}
			var ctxLabels={featured_image:'Featured image',content:'In content'};
			var html='<p class="rayetun-mn-modal-desc">Found in <strong>'+res.total+'</strong> location'+(res.total!==1?'s':'')+':</p>'+
				'<ul class="mn-usage-list">';
			res.usages.forEach(function(u){
				var ctx=ctxLabels[u.context]||u.context;
				var title=u.title||'(no title)';
				html+='<li>'+
					'<span class="mn-usage-ctx mn-usage-ctx-'+u.context+'">'+ctx+'</span>'+
					'<a href="'+u.edit_url+'" target="_blank" rel="noopener">'+title+'</a>'+
					' <span class="mn-usage-type">('+u.post_type+')</span>'+
				'</li>';
			});
			html+='</ul>';
			body.innerHTML=html;
		}).catch(function(e){
			var body=document.getElementById('mn-usage-body');
			if(body)body.innerHTML='<p class="rayetun-mn-modal-desc" style="color:#ef4444">Error: '+(e.message||'Could not load usage data')+'</p>';
		});
	}

	document.addEventListener('click',function(e){if(!e.target.closest||!e.target.closest('#rayetun-mn-panel'))state.focusedFolderId=null;closeMenu();closeImgMenu();});
	document.addEventListener('keydown',function(e){
		if(e.key==='Escape'){closeMenu();closeImgMenu();state.colorPickerFor=null;state.showNewFolder=false;state.showSubfolderFor=null;render();return;}
		/* N = New folder (outside inputs) */
		var tag=(e.target&&e.target.tagName)||'';
		if(tag==='INPUT'||tag==='TEXTAREA'||e.target.isContentEditable)return;
		if((e.key==='n'||e.key==='N')&&!e.ctrlKey&&!e.metaKey&&!e.altKey){
			e.preventDefault();state.showNewFolder=true;state.showSubfolderFor=null;render();return;
		}
		if((e.key==='Delete'||e.key==='Backspace')&&state.focusedFolderId&&!state.showNewFolder){
			e.preventDefault();
			var dfolder=fnd(state.focusedFolderId);
			if(dfolder&&!dfolder.is_locked&&window.confirm('Delete "'+dfolder.name+'"? Files will not be deleted.')){
				apiDelete(state.focusedFolderId).then(function(){state.focusedFolderId=null;render();}).catch(function(e){showToast((e&&e.message)||'Delete failed.','error');});
			}
		}
	});

	/* ── Rename ── */
	function startRename(folder){
		var tid=Number(folder.term_id);
		var ne=document.querySelector('[data-mn-term="'+tid+'"] .rayetun-mn-folder-name');
		if(!ne)return;
		var orig=folder.name;
		var inp=el('input',{className:'rayetun-mn-rename-input',type:'text',value:orig});
		ne.replaceWith(inp);inp.select();
		function commit(){var v=inp.value.trim();if(v&&v!==orig)apiRename(tid,v).then(function(){render();}).catch(console.error);else render();}
		inp.addEventListener('blur',commit);
		inp.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Enter'){e.preventDefault();commit();}if(e.key==='Escape')render();});
	}

	/* ── Color picker ── */
	function renderColorPicker(tid,cur){
		var p=el('div',{className:'rayetun-mn-color-picker'});
		var sw=el('div',{className:'rayetun-mn-color-swatches'});
		CP.forEach(function(hex){
			var b=el('button',{className:'rayetun-mn-color-swatch'+(cur===hex?' is-selected':''),title:hex,style:{background:hex},onClick:function(e){e.stopPropagation();apiColor(tid,hex).then(function(){render();});}});
			if(cur===hex)b.innerHTML=II.check;sw.appendChild(b);
		});
		p.appendChild(sw);
		var cr=el('div',{className:'rayetun-mn-color-custom'});
		var hi=el('input',{type:'text',className:'rayetun-mn-color-hex-input',placeholder:'#3b82f6',value:cur||'',maxlength:'7'});
		hi.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Enter'){var v=hi.value.trim();if(/^#[0-9a-f]{6}$/i.test(v))apiColor(tid,v).then(function(){render();});}});
		cr.appendChild(hi);
		cr.appendChild(el('button',{className:'rayetun-mn-color-clear',onClick:function(e){e.stopPropagation();apiColor(tid,'').then(function(){render();});}},'✕'));
		p.appendChild(cr);return p;
	}

	/* ── Bulk ── */
	/* ── Legacy sidebar bulk bar ── */
	function renderBulkBar(){
		var t=el('div',{className:'rayetun-mn-bulk-toolbar'});
		t.appendChild(el('span',{className:'rayetun-mn-bulk-info'},state.selectedIds.length+' selected'));
		var mb=el('button',{className:'rayetun-mn-bulk-move',onClick:function(){showBulkFolderPicker(state.selectedIds,'add');}});
		mb.innerHTML=II.move+' <span>Move</span>';t.appendChild(mb);
		t.appendChild(el('button',{className:'rayetun-mn-bulk-cancel',onClick:function(){clearFloatingBar();}},('\u00d7')));
		return t;
	}

	/* ═══════════════════════════════════════════════════════
	 *  FLOATING BULK ACTION BAR
	 *  Appears fixed at bottom-center when images are selected
	 *  via WP's native Bulk Select OR our own selection mode.
	 * ═══════════════════════════════════════════════════════ */
	var _floatingBar=null;
	var _altModal=null;

	function getFloatingBar(){
		if(_floatingBar)return _floatingBar;
		var bar=document.createElement('div');
		bar.id='rayetun-mn-floating-bar';
		bar.className='rayetun-mn-floating-bar';
		bar.style.display='none';
	
		var countEl=document.createElement('span');
		countEl.className='rayetun-mn-fb-count';
		bar.appendChild(countEl);
	
		var actions=document.createElement('div');
		actions.className='rayetun-mn-fb-actions';
	
		function makeBtn(cls,html,title){
			var b=document.createElement('button');
			b.className='rayetun-mn-fb-btn '+cls;
			b.innerHTML=html;b.title=title||'';
			actions.appendChild(b);return b;
		}
	
		var addBtn  =makeBtn('rayetun-mn-fb-add',  II.folder+' Add to folder',   'Add to a folder (keeps existing folder assignments)');
		var moveBtn =makeBtn('rayetun-mn-fb-move', II.move+' Move to folder',    'Move to a folder (removes from current folder)');
		var removeBtn=makeBtn('rayetun-mn-fb-remove',II.trash+' Remove',          'Remove from current folder');
		var delBtn  =makeBtn('rayetun-mn-fb-delete','&#x1F5D1; Delete',           'Permanently delete selected files');
	
		removeBtn.style.display='none';moveBtn.style.display='none';

		/* Extension point: add-ons (PixelVault Pro) add bulk action buttons here.
		   Usage: wp.hooks.addAction('pixelvault.bulkActions','my-addon',function(actions,helpers){
		              var btn=helpers.makeBtn('my-cls','Label','Tooltip');
		              btn.addEventListener('click',function(){ var ids=helpers.getIds(); ... });
		          }); */
		if(window.wp&&wp.hooks){wp.hooks.doAction('pixelvault.bulkActions',actions,{makeBtn:makeBtn,getIds:function(){return bar._ids||[];}});}

		bar.appendChild(actions);
	
		var closeBtn=document.createElement('button');
		closeBtn.className='rayetun-mn-fb-close';
		closeBtn.title='Clear selection';
		closeBtn.textContent='\u00d7';
		bar.appendChild(closeBtn);
	
		addBtn.addEventListener('click',function(){showBulkFolderPicker(bar._ids,'add',bar);});
		moveBtn.addEventListener('click',function(){showBulkFolderPicker(bar._ids,'move',bar);});
		removeBtn.addEventListener('click',function(){
			var ids=bar._ids;
			if(!ids||!ids.length||!state.activeFolderId)return;
			apiRemoveFromFolder(ids,state.activeFolderId)
				.then(function(){forceGridFilter();render();clearFloatingBar();
					showToast(ids.length+' file'+(ids.length!==1?'s':'')+' removed from folder');})
				.catch(console.error);
		});
		delBtn.addEventListener('click',function(){
			var ids=bar._ids;if(!ids||!ids.length)return;
			var n=ids.length;
			if(!window.confirm('Permanently delete '+n+' file'+(n!==1?'s':'')+' from WordPress?\nThis cannot be undone.'))return;
			delBtn.disabled=true;delBtn.textContent='Deleting\u2026';
			apiBulkDelete(ids)
				.then(function(res){
					forceGridFilter();render();clearFloatingBar();
					var d=res&&typeof res.deleted==='number'?res.deleted:n;
					if(d===0){showToast('No files deleted — you may not have permission.','error');}
					else{showToast(d+' file'+(d!==1?'s':'')+' deleted');}
				})
				.catch(function(e){
					delBtn.disabled=false;delBtn.innerHTML='&#x1F5D1; Delete';
					showToast('Delete failed: '+(e&&e.message||'Unknown error'),'error');
				});
		});
		closeBtn.addEventListener('click',function(){
			clearFloatingBar();
			document.querySelectorAll('.attachment[aria-checked="true"]').forEach(function(el){el.click();});
		});
	
		document.body.appendChild(bar);
		_floatingBar=bar;
		return bar;
	}
	
	function updateFloatingBar(ids){
		if(!IS_MEDIA_LIB)return;
		var bar=getFloatingBar();
		if(!ids||!ids.length){bar.style.display='none';return;}
		bar._ids=ids;
		bar.style.display='flex';
		bar.querySelector('.rayetun-mn-fb-count').textContent=
			ids.length+' file'+(ids.length!==1?'s':'')+' selected';
		var inFolder=state.activeFolderId&&state.activeFolderId!=='uncategorized';
		bar.querySelector('.rayetun-mn-fb-remove').style.display=inFolder?'':'none';
		bar.querySelector('.rayetun-mn-fb-move').style.display=inFolder?'':'none';
	}
	
	function clearFloatingBar(){
		if(_floatingBar)_floatingBar.style.display='none';
		state.selectedIds=[];state.bulkMode=false;render();
	}
	
	/* Folder picker popup — nested tree, for Add/Move */
	function showBulkFolderPicker(ids,mode,anchorEl){
		closeMenu();
		var existing=document.getElementById('rayetun-mn-bulk-picker');
		if(existing)existing.remove();
		var picker=document.createElement('div');
		picker.id='rayetun-mn-bulk-picker';picker.className='rayetun-mn-bulk-picker';
		var hdr=document.createElement('div');
		hdr.className='rayetun-mn-picker-header';
		hdr.textContent=mode==='move'?'Move to folder:':'Add to folder:';
		picker.appendChild(hdr);
		var list=document.createElement('div');list.className='rayetun-mn-picker-list';
		var tree=buildTree(state.folders,0);
		if(!tree.length){var emp=document.createElement('div');emp.className='rayetun-mn-picker-empty';emp.textContent='No folders yet.';list.appendChild(emp);}
		(function addItems(items,depth){items.forEach(function(f){
			var item=document.createElement('div');
			item.className='rayetun-mn-picker-item';
			item.style.paddingLeft=(10+depth*16)+'px';
			var ico=f.icon&&f.icon.trim()?'<span style="font-size:14px;margin-right:4px">'+f.icon+'</span>':iF(f.color||'#646970');
			var cnt=(SHOW_COUNTS&&state.counts[f.term_id])?'<span class="rayetun-mn-picker-count">'+state.counts[f.term_id]+'</span>':'';
			item.innerHTML=ico+'<span class="rayetun-mn-picker-name">'+f.name+'</span>'+cnt;
			(function(ff){item.addEventListener('click',function(){
				picker.remove();
				var tid=Number(ff.term_id);
				var fromTid=(mode==='move'&&state.activeFolderId&&state.activeFolderId!=='uncategorized')?state.activeFolderId:null;
				var op=fromTid?apiMoveTo(ids,fromTid,tid):apiBulk(ids,[tid]);
				op.then(function(){
					forceGridFilter();render();clearFloatingBar();
					showToast(ids.length+' file'+(ids.length!==1?'s':'')+' '+(fromTid?'moved to':'added to')+' '+ff.name);
				}).catch(console.error);
			});})(f);
			list.appendChild(item);
			if(f.children&&f.children.length)addItems(f.children,depth+1);
		});})(tree,0);
		picker.appendChild(list);document.body.appendChild(picker);
		var anchor=anchorEl||_floatingBar;
		if(anchor){var ar=anchor.getBoundingClientRect();picker.style.left=Math.max(8,ar.left)+'px';picker.style.top=(ar.top-250)+'px';}
		setTimeout(function(){
			document.addEventListener('click',function handler(e){
				if(!picker.contains(e.target)){picker.remove();document.removeEventListener('click',handler);}
			});
		},0);
	}
	
	/* Toast notification */
	function showToast(msg,type){
		var t=document.createElement('div');
		t.className='rayetun-mn-toast'+(type==='error'?' is-error':'');
		t.textContent=msg;
		document.body.appendChild(t);
		setTimeout(function(){t.classList.add('is-visible');},10);
		setTimeout(function(){t.classList.remove('is-visible');setTimeout(function(){t.remove();},300);},2800);
	}
	
	/* Watch WP's native Bulk Select (aria-checked on .attachment elements) */
	function initNativeBulkDetection(){
		if(!IS_MEDIA_LIB)return;
		var _obs=new MutationObserver(function(){
			var ids=[];
			document.querySelectorAll('.attachments li.attachment[aria-checked="true"]').forEach(function(el){
				var id=parseInt(el.getAttribute('data-id'),10);if(!isNaN(id))ids.push(id);
			});
			updateFloatingBar(ids);
		});
		function attachObs(){
			var grid=document.querySelector('.attachments');
			if(grid&&!grid._mnBulkObs){grid._mnBulkObs=true;_obs.observe(grid,{subtree:true,attributes:true,attributeFilter:['aria-checked']});}
		}
		attachObs();
		var domObs=new MutationObserver(attachObs);
		(document.querySelector('#wpbody-content')||document.body).childNodes&&domObs.observe(document.querySelector('#wpbody-content')||document.body,{childList:true,subtree:false});
	}
	
	/* Legacy showBulkPicker shim */
	function showBulkPicker(){showBulkFolderPicker(state.selectedIds,'add');}
	

	/* ── Sort ── */
	function renderSort(){
		var sortBy='date';
		var wrap=el('div',{className:'rayetun-mn-sort-wrap'});
		var btn=el('button',{className:'rayetun-mn-sort-btn'});
		btn.innerHTML=II.sort+' <span>'+sortBy+'</span>';
		var dd=el('div',{className:'rayetun-mn-sort-dropdown',style:{display:'none'}});
		var open=false;
		btn.addEventListener('click',function(e){e.stopPropagation();open=!open;dd.style.display=open?'block':'none';});
		[['date','Date'],['name','Name'],['filesize','Size']].forEach(function(p){
			var v=p[0],l=p[1];
			dd.appendChild(el('button',{className:'rayetun-mn-sort-opt'+(sortBy===v?' is-active':''),onClick:function(e){
				e.stopPropagation();sortBy=v;open=false;dd.style.display='none';btn.innerHTML=II.sort+' <span>'+sortBy+'</span>';
			}},l));
		});
		wrap.appendChild(btn);wrap.appendChild(dd);return wrap;
	}

	/* ── Render row ── */
	function renderRow(folder){
		var tid=Number(folder.term_id);
		var isAct=state.activeFolderId===tid;
		var isOpen=!!state.expanded[tid];
		var hasKids=folder.children&&folder.children.length>0;
		var showSub=state.showSubfolderFor===tid;
		var color=folder.color||'#646970';
		var count=state.counts[tid]||0;
		var isEmpty=count===0;
		var isLocked=!!(folder.is_locked&&parseInt(folder.is_locked,10)>0);

		var li=el('li',{className:'rayetun-mn-item',role:'none'});
		var row=el('div',{
			className:'rayetun-mn-row'+(isAct?' is-active':'')+(isEmpty?' is-empty':'')+(isLocked?' is-locked':''),
			'data-mn-term':tid,tabindex:'0',role:'treeitem','aria-selected':isAct?'true':'false'
		});

		/* Drag handle — manual sort mode only, and only for users who can reorder */
		if(state.sortBy==='manual'&&CAN_REORDER){
			var dh=el('span',{className:'rayetun-mn-drag-handle','aria-hidden':'true',title:'Drag to reorder'});
			dh.innerHTML='<svg width="8" height="12" viewBox="0 0 8 12" fill="currentColor"><circle cx="2" cy="2" r="1.5"/><circle cx="6" cy="2" r="1.5"/><circle cx="2" cy="6" r="1.5"/><circle cx="6" cy="6" r="1.5"/><circle cx="2" cy="10" r="1.5"/><circle cx="6" cy="10" r="1.5"/></svg>';
			row.appendChild(dh);
		}

		/* Chevron toggle */
		var tog=el('button',{
			className:'rayetun-mn-toggle'+(!(hasKids||showSub)?' is-leaf':'')+(isOpen?' is-open':''),
			'aria-label':isOpen?'Collapse':'Expand',
			onClick:function(e){e.stopPropagation();state.expanded[tid]=!isOpen;render();}
		});
		tog.innerHTML=II.chev;
		row.appendChild(tog);

		var ico=el('span',{className:'rayetun-mn-folder-icon'});
		if(folder.icon&&folder.icon.trim()){ico.textContent=folder.icon.trim();ico.style.cssText='font-size:16px;line-height:1;width:17px;text-align:center;';}
		else{ico.innerHTML=(isOpen&&(hasKids||showSub))?iFO(color):iF(color);}
		row.appendChild(ico);

		/* Folder name */
		row.appendChild(el('span',{className:'rayetun-mn-folder-name'},folder.name));

		/* Lock badge — shown when folder is locked */
		if(isLocked){
			var lb=el('span',{className:'rayetun-mn-lock-badge',title:'This folder is locked','aria-label':'Locked folder'});
			lb.innerHTML=II.lock;
			row.appendChild(lb);
		}

		/* Count — right-aligned, hidden when zero or when show_counts is off */
		if(SHOW_COUNTS && count>0){
			row.appendChild(el('span',{className:'rayetun-mn-count'},String(count)));
		}

		/* Hover actions */
		var aw=el('span',{className:'rayetun-mn-row-actions'});

		/* Extension point: add-ons (PixelVault Pro) add inline folder row-action buttons,
		   shown before the ⋮ options button.
		   Usage: wp.hooks.addAction('pixelvault.folderRowActions','my-addon',function(aw,folder,helpers){
		              helpers.addBtn(iconSvg,'Title',function(){ ... });
		          }); */
		if(window.wp&&wp.hooks){
			wp.hooks.doAction('pixelvault.folderRowActions',aw,folder,{addBtn:function(iconHtml,title,fn){
				var rb=el('button',{className:'rayetun-mn-row-actions-btn','aria-label':title||'',title:title||'',
					onClick:function(e){e.stopPropagation();fn(e);}});
				rb.innerHTML=iconHtml||'';aw.appendChild(rb);return rb;
			}});
		}

		var db=el('button',{className:'rayetun-mn-row-actions-btn','aria-label':'Options',
			onClick:function(e){e.stopPropagation();showCtxMenu(e,folder,true);}});
		db.innerHTML=II.dots;aw.appendChild(db);
		row.appendChild(aw);

		/* Click handlers + keyboard focus tracking */
		row.addEventListener('click',function(){setActive(tid);state.focusedFolderId=tid;});
		row.addEventListener('focus',function(){state.focusedFolderId=tid;});
		row.addEventListener('contextmenu',function(e){showCtxMenu(e,folder,false);});
		row.addEventListener('keydown',function(e){
			if(e.key==='Enter'||e.key===' '){e.preventDefault();setActive(tid);}
			if(e.key==='F2'&&!isLocked)startRename(folder);
		});
		li.appendChild(row);

		/* Color picker (inline) */
		if(state.colorPickerFor===tid)li.appendChild(renderColorPicker(tid,color));

		/* Children */
		if(isOpen||showSub){
			var cl=el('ul',{className:'rayetun-mn-list',role:'group'});
			if(showSub){
				cl.appendChild(el('li',{className:'rayetun-mn-item'},
					mkInput('Subfolder name',
						function(name){apiCreate(name,tid,'').then(function(){state.showSubfolderFor=null;state.expanded[tid]=true;render();}).catch(function(e){console.error(e);state.showSubfolderFor=null;render();});},
						function(){state.showSubfolderFor=null;render();},'rayetun-mn-subfolder-row')
				));
			}
			if(hasKids)folder.children.forEach(function(c){cl.appendChild(renderRow(c));});
			li.appendChild(cl);
		}
		return li;
	}

	/* ── Full render ── */
	function render(){
		var panel=document.getElementById('rayetun-mn-panel');
		if(!panel)return;
		panel.className='';
		if(state.compactMode)panel.classList.add('is-compact');
		panel.innerHTML='';

		/* ── Header ── */
		var hdr=el('div',{className:'rayetun-mn-header'});
		hdr.appendChild(el('span',{className:'rayetun-mn-header-title'},'Folders'));
		var nb=el('button',{className:'rayetun-mn-new-folder-btn',title:'New folder  (N)',onClick:function(){state.showNewFolder=true;state.showSubfolderFor=null;render();}});
		nb.innerHTML=II.sub+' New Folder';
		hdr.appendChild(nb);
		panel.appendChild(hdr);

		/* ── Inline new folder input ── */
		if(state.showNewFolder){
			panel.appendChild(mkInput('Folder name',
				function(name){apiCreate(name,0,'').then(function(){state.showNewFolder=false;render();}).catch(function(e){console.error('[MediaNest]',e);var i=document.querySelector('.rayetun-mn-new-folder-input');if(i){i.style.borderColor='#d63638';i.title=e.message||'Error';}});},
				function(){state.showNewFolder=false;render();}));
		}

		/* ── Search + Sort toolbar ── */
		var toolbar=el('div',{className:'rayetun-mn-toolbar'});

		var searchWrap=el('div',{className:'rayetun-mn-search-wrap'});
		var searchIco=el('span',{className:'rayetun-mn-search-ico'});
		searchIco.innerHTML=II.search;
		var sinp=el('input',{type:'text',className:'rayetun-mn-search-input',placeholder:'Search…'});
		sinp.value=state.searchQuery;
		var _searchTimer=null;
		sinp.addEventListener('input',function(e){
			state.searchQuery=e.target.value;
			clearTimeout(_searchTimer);
			_searchTimer=setTimeout(function(){
				render();
				var i=document.querySelector('.rayetun-mn-search-input');if(i){i.value=state.searchQuery;i.focus();}
			},180);
		});
		searchWrap.appendChild(searchIco);searchWrap.appendChild(sinp);
		toolbar.appendChild(searchWrap);

		var sortWrap=el('div',{className:'rayetun-mn-sort-wrap'});
		var sortSel=el('select',{className:'rayetun-mn-sort-select','aria-label':'Sort folders',
			onChange:function(){state.sortBy=sortSel.value;render();}});
		[['manual','Manual'],['az','A–Z'],['za','Z–A'],
		 ['count_desc','Most files'],['count_asc','Fewest files']].forEach(function(o){
			var opt=el('option',{value:o[0]},o[1]);
			if(state.sortBy===o[0])opt.selected=true;
			sortSel.appendChild(opt);
		});
		sortWrap.appendChild(sortSel);
		toolbar.appendChild(sortWrap);
		panel.appendChild(toolbar);

		if(state.loading){
			var ld=el('div',{className:'rayetun-mn-loading'});
			ld.innerHTML='<div class="rayetun-mn-spinner"></div><span>Loading…</span>';
			panel.appendChild(ld);return;
		}

		/* ── Root rows: All Files + Uncategorized ── */
		var allAct=state.activeFolderId===null;
		var ar=el('div',{className:'rayetun-mn-root-item'+(allAct?' is-active':''),tabindex:'0',role:'treeitem','aria-selected':allAct?'true':'false',
			onClick:function(){setActive(null);},onKeydown:function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();setActive(null);}}});
		ar.innerHTML=II.files+'<span class="rayetun-mn-root-label">'+(s.allFiles||'All Files')+'</span>';
		if(SHOW_COUNTS&&state.counts.total>0)ar.appendChild(el('span',{className:'rayetun-mn-root-count'},String(state.counts.total)));
		panel.appendChild(ar);

		var uncAct=state.activeFolderId==='uncategorized';
		var uncCnt=state.counts.uncategorized||0;
		if(uncCnt>0||uncAct){
			var ur=el('div',{className:'rayetun-mn-root-item'+(uncAct?' is-active':''),tabindex:'0',role:'treeitem','aria-selected':uncAct?'true':'false',
				onClick:function(){setActive('uncategorized');},onKeydown:function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();setActive('uncategorized');}}});
			ur.innerHTML=II.files+'<span class="rayetun-mn-root-label">Uncategorized</span>';
			if(SHOW_COUNTS)ur.appendChild(el('span',{className:'rayetun-mn-root-count'},String(uncCnt)));
			panel.appendChild(ur);
		}

		panel.appendChild(el('div',{className:'rayetun-mn-divider'}));

		/* ── Starred folders (pinned quick-access) ── */
		if(state.starred.length&&!state.searchQuery){
			var starFolders=state.starred.map(function(tid){return fnd(tid);}).filter(function(f){return !!f;});
			if(starFolders.length){
				var stSec=el('div',{className:'rayetun-mn-starred-section'});
				stSec.appendChild(el('div',{className:'rayetun-mn-starred-title'},'★ Starred'));
				starFolders.forEach(function(f){
					var stid=Number(f.term_id);
					var sact=state.activeFolderId===stid;
					var sr=el('div',{className:'rayetun-mn-starred-item'+(sact?' is-active':''),tabindex:'0',role:'treeitem','aria-selected':sact?'true':'false',
						onClick:function(){setActive(stid);},
						onKeydown:function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();setActive(stid);}}});
					sr.innerHTML=iF(f.color||'#f59e0b')+'<span class="rayetun-mn-starred-label"></span>';
					sr.querySelector('.rayetun-mn-starred-label').textContent=f.name;
					var unst=el('button',{className:'rayetun-mn-starred-unstar',title:'Unstar','aria-label':'Unstar folder',
						onClick:function(e){e.stopPropagation();apiStar(stid,false).then(function(){render();}).catch(function(er){showToast((er&&er.message)||'Could not update.','error');});}});
					unst.innerHTML='<svg width="13" height="13" viewBox="0 0 24 24" fill="#f59e0b" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.6 7L12 17.8 5.8 21.2l1.6-7L2 9.5l7.1-.6z"/></svg>';
					sr.appendChild(unst);
					stSec.appendChild(sr);
				});
				panel.appendChild(stSec);
				panel.appendChild(el('div',{className:'rayetun-mn-divider'}));
			}
		}

		/* ── Smart Folders (collapsible tab) — hidden when feature disabled ── */
		if(_features.smartFilters!==false&&state.smartFolders&&state.smartFolders.length&&!state.searchQuery){
			var sf=el('div',{className:'rayetun-mn-smart-section'});

			/* Clickable header that toggles the section */
			var sfHdr=el('div',{className:'rayetun-mn-smart-heading'+(state.smartExpanded?' is-open':'')});
			var sfChev=el('span',{className:'rayetun-mn-smart-chev'});
			sfChev.innerHTML=II.chev; /* uses existing chevron icon */
			sfHdr.appendChild(sfChev);
			sfHdr.appendChild(el('span',null,'Quick Filters'));
			/* Total non-zero issues badge */
			var sfTotal=state.smartFolders.reduce(function(s,f){return s+f.count;},0);
			if(sfTotal>0&&!state.smartExpanded){
				var sfBadge=el('span',{className:'rayetun-mn-count is-nonzero'},String(sfTotal));
				sfHdr.appendChild(sfBadge);
			}
			sfHdr.addEventListener('click',function(){
				state.smartExpanded=!state.smartExpanded;
				try{localStorage.setItem('rayetun_mn_smart_open',state.smartExpanded?'1':'0');}catch(ex){}
				render();
			});
			sf.appendChild(sfHdr);

			/* Rows — only when expanded */
			if(state.smartExpanded){
				state.smartFolders.forEach(function(folder){
					var id='smart:'+folder.slug;
					var isActive=state.activeFolderId===id;
					var row=el('div',{
						className:'rayetun-mn-smart-row'+(isActive?' is-active':''),
						title:folder.description
					});
					row.addEventListener('click',function(){setActive(id);});
					var ico=el('span',{className:'rayetun-mn-smart-icon'});
					ico.innerHTML=folder.icon;
					var name=el('span',{className:'rayetun-mn-smart-name'},folder.label);
					var cnt=el('span',{className:'rayetun-mn-count'+(folder.count>0?' is-nonzero':'')},
						folder.count>0?String(folder.count):'0');
					row.appendChild(ico);row.appendChild(name);row.appendChild(cnt);
					/* "Edit Alt Texts" action button — appears whenever missing_alt is active, the
					   alt editor is enabled, and the user can edit others' media (REST requirement) */
					if(ALT_EDITOR_ON && CAN_BULK_ALT && folder.slug==='missing_alt' && isActive){
						var altBtn=el('button',{
							className:'rayetun-mn-smart-action-btn',
							title:'Open Bulk Alt Text Editor'
						},'✏️');
						altBtn.addEventListener('click',function(e){e.stopPropagation();openAltEditor();});
						row.appendChild(altBtn);
					}
					sf.appendChild(row);
				});
			}

			panel.appendChild(sf);
			panel.appendChild(el('div',{className:'rayetun-mn-divider'}));
		}

		/* ── Bulk bar (only when items selected) ── */
		if(state.bulkMode&&state.selectedIds.length>0)panel.appendChild(renderBulkBar());

		/* ── Folder tree ── */
		var tw=el('div',{className:'rayetun-mn-tree',role:'tree'});
		var tree=buildTree(state.folders,0);
		if(!tree.length&&!state.searchQuery){
			var emp=el('div',{className:'rayetun-mn-empty'});
			emp.innerHTML='<div class="rayetun-mn-empty-icon">'+iF('#dcdcde')+'</div>';
			emp.appendChild(el('p',{className:'rayetun-mn-empty-text'},'No folders yet.'));
			emp.appendChild(el('button',{className:'rayetun-mn-empty-btn',onClick:function(){state.showNewFolder=true;render();}},'+ New Folder'));
			tw.appendChild(emp);
		}else if(!tree.length){
			tw.appendChild(el('p',{className:'rayetun-mn-empty-text',style:{padding:'12px'}},'No match'));
		}else{
			var lst=el('ul',{className:'rayetun-mn-list',role:'group'});
			tree.forEach(function(f){lst.appendChild(renderRow(f));});
			tw.appendChild(lst);
		}
		panel.appendChild(tw);

		/* ── Footer ── */
		var ft=el('div',{className:'rayetun-mn-footer'});
		var brand=el('span',{className:'rayetun-mn-footer-brand'});
		brand.innerHTML='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="rayetun-mn-footer-logo"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg><span class="rayetun-mn-footer-name">PixelVault</span>';
		ft.appendChild(brand);
		var impBtn=el('button',{className:'rayetun-mn-footer-import-btn',title:'Auto-organise existing media into folders',onClick:function(e){e.stopPropagation();showImportModal();}});
		impBtn.innerHTML='<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/></svg> Import';
		ft.appendChild(impBtn);
		ft.appendChild(el('span',{className:'rayetun-mn-badge'},'Free'));
		panel.appendChild(ft);
	}

	/* ────────────────────────────────────────────────────────
	   MEDIA MODAL (editor pages only)

	   Tab switch fix: WP replaces CHILDREN of .media-frame-content
	   (not the element itself) when switching tabs. MutationObserver
	   on subtree catches these childList changes.
	   ──────────────────────────────────────────────────────── */
	function initModalSupport(){
		if(!window.wp||!window.wp.media)return;

		/* MutationObserver: catches childList changes inside .media-frame-content
		   that is inside a .media-modal. Fires when WP replaces tab content. */
		new MutationObserver(function(muts){
			muts.forEach(function(m){
				if(m.type!=='childList')return;
				var target=m.target;
				/* Direct watch: target IS .media-frame-content */
				if(target.classList&&target.classList.contains('media-frame-content')){
					var modal=target.closest&&target.closest('.media-modal');
					if(modal&&!target.querySelector('#rayetun-mn-modal-panel')){
						setTimeout(function(){injectModalPanel(modal);},60);
					}
					return;
				}
				/* Child watch: added nodes contain .media-frame-content */
				m.addedNodes.forEach(function(n){
					if(n.nodeType!==1)return;
					var fc=n.classList&&n.classList.contains('media-frame-content')?n:n.querySelector&&n.querySelector('.media-frame-content');
					if(!fc)return;
					var modal=fc.closest&&fc.closest('.media-modal');
					if(!modal)return;
					if(!fc.querySelector('#rayetun-mn-modal-panel')){
						setTimeout(function(){injectModalPanel(modal);},60);
					}
				});
			});
		}).observe(document.body,{childList:true,subtree:true});

		/* Hook wp.media.editor.open (Classic Editor "Add Media" button) */
		if(window.wp.media.editor&&window.wp.media.editor.open){
			var _edOpen=window.wp.media.editor.open;
			window.wp.media.editor.open=function(){
				setFolderCookie(null); /* clear stale cookie before frame opens */
				return _edOpen.apply(this,arguments);
			};
		}

		/* Hook wp.media() — fires for Classic Editor AND Elementor/Divi/Gutenberg.
		   Store every frame in _mnLastFrame because wp.media.frame is only set
		   by wp.media.editor.open (Classic Editor). Elementor, Divi, and Gutenberg
		   call wp.media() directly and never set wp.media.frame. */
		var _orig=window.wp.media;
		window.wp.media=function(){
			/* Clear stale cookie synchronously before the new frame initializes.
			   WP fires query-attachments almost immediately after frame creation. */
			setFolderCookie(null);
			var frame=_orig.apply(this,arguments);
			/* Track the latest frame so modalFilter() can reach it */
			_mnLastFrame=frame;
			if(frame&&frame.on){
				frame.on('open',function(){
					_mnLastFrame=frame;      /* refresh on each open (cached frames) */
					setFolderCookie(null);   /* clear again — belt-and-suspenders */
					setTimeout(function(){
						var modal=document.querySelector('.media-modal');
						if(modal&&!modal.querySelector('#rayetun-mn-modal-panel'))
							injectModalPanel(modal);
					},60);
				});
				frame.on('content:render:browse',function(){
					_mnLastFrame=frame;
					setTimeout(function(){
						var modal=document.querySelector('.media-modal');
						if(modal)injectModalPanel(modal);
					},60);
				});
				frame.on('content:activate:browse',function(){
					_mnLastFrame=frame;
					setTimeout(function(){
						var modal=document.querySelector('.media-modal');
						if(modal)injectModalPanel(modal);
					},60);
				});
			}
			return frame;
		};
		var k;
		for(k in _orig){if(Object.prototype.hasOwnProperty.call(_orig,k)){try{window.wp.media[k]=_orig[k];}catch(e){}}}

		/* Elementor-specific integration (async init, cached frames, null frame fix) */
		initElementorIntegration();

		/* Live-refresh uploads inside the modal too (post editor / Elementor / Divi),
		   so images uploaded into a folder appear without a page reload. Retry until
		   wp.Uploader exists (it may load just after us). */
		if ( !initUploadRefresh() ) {
			var _urAtt = 0;
			var _urIv = setInterval( function () {
				_urAtt++;
				if ( initUploadRefresh() || _urAtt > 40 ) { clearInterval( _urIv ); }
			}, 100 );
		}
	}

	/*
	 * Elementor challenges:
	 * 1. Async init — Elementor fires after DOMContentLoaded in some setups
	 * 2. Cached frames — Elementor calls wp.media() once, reuses frame.open()
	 *    so our window.wp.media wrapper only fires once per control
	 * 3. Null wp.media.frame — direct wp.media() calls don't set wp.media.frame;
	 *    fixed by _mnLastFrame in modalFilter()
	 */
	function initElementorIntegration(){
		if(typeof window.elementor==='undefined'&&typeof window.elementorCommon==='undefined'){
			window.addEventListener('elementor:loaded',onElementorReady,{once:true});
			document.addEventListener('elementor/loaded',onElementorReady,{once:true});
			var _polls=0,_pi=setInterval(function(){
				_polls++;
				if(window.elementor||window.elementorCommon){clearInterval(_pi);onElementorReady();}
				if(_polls>60)clearInterval(_pi);
			},500);
		}else{
			onElementorReady();
		}
	}

	function onElementorReady(){
		var el=window.elementor;
		if(!el)return;
		/* Channel events — fires when panel widgets open/close */
		try{
			if(el.channels&&el.channels.editor){
				el.channels.editor.on('change:editedElement change:section',function(){
					setTimeout(checkForMediaModal,200);
				});
			}
		}catch(e){}
		/* Panel init hook */
		try{
			if(el.hooks){
				el.hooks.addAction('panel:init',function(){patchElementorMediaControls();});
			}
		}catch(e){}
		patchElementorMediaControls();
	}

	/* Patch Elementor Media control openFrame() so every open clears cookie
	   and updates _mnLastFrame even when the frame is reused from cache. */
	function patchElementorMediaControls(){
		try{
			var controls=window.elementor&&window.elementor.modules&&window.elementor.modules.controls;
			if(!controls)return;
			Object.keys(controls).forEach(function(name){
				var ctrl=controls[name];
				if(!ctrl||!ctrl.prototype||!ctrl.prototype.openFrame)return;
				if(ctrl.prototype._mnPatched)return;
				var origOpen=ctrl.prototype.openFrame;
				ctrl.prototype.openFrame=function(){
					setFolderCookie(null); /* clear stale cookie before frame opens */
					var result=origOpen.apply(this,arguments);
					if(this.frame)_mnLastFrame=this.frame; /* capture cached frame */
					/* Poll for the modal — more reliable than a fixed timeout.
					   Elementor takes variable time to show the DOM. */
					pollForModal(400);
					return result;
				};
				ctrl.prototype._mnPatched=true;
			});
		}catch(e){}
	}

	/* Poll document.querySelector('.media-modal') for up to maxMs milliseconds.
	   Needed for Elementor which takes variable time before the DOM appears.
	   Injects our panel as soon as the modal is found. */
	function pollForModal(maxMs){
		var deadline=Date.now()+maxMs;
		var iv=setInterval(function(){
			var modal=document.querySelector('.media-modal');
			if(modal){clearInterval(iv);injectModalPanel(modal);return;}
			if(Date.now()>deadline)clearInterval(iv);
		},50);
	}

	function checkForMediaModal(){
		var modal=document.querySelector('.media-modal');
		if(modal)injectModalPanel(modal);
	}

	/*
	 * Divi integration — Divi Backend Builder uses standard post.php so
	 * admin_enqueue_scripts fires normally (unlike Elementor which exits early).
	 * Our window.wp.media() wrapper already catches Divi's frame creation.
	 * We add Divi-specific click hooks as belt-and-suspenders to handle cases
	 * where Divi opens its media frame without going through wp.media() directly.
	 *
	 * Divi Frontend Builder (?et_fb=1) runs on the frontend — scripts load via
	 * wp_enqueue_scripts. We detect it via et_fb global and handle separately.
	 */
	function initDiviIntegration(){
		/* Divi Backend: wait for Divi to initialize */
		var _polls=0,_pi=setInterval(function(){
			_polls++;
			if(window.ETBuilderBackend||window.et_pb_instance||
			   document.querySelector('.et-pb-option-container')){
				clearInterval(_pi);onDiviReady();
			}
			if(_polls>60)clearInterval(_pi);
		},500);

		/* Divi Frontend Builder */
		if(window.et_fb_app_data||window.ETFramework){
			onDiviReady();
		}
	}

	function onDiviReady(){
		/* Intercept all Divi media-open clicks — covers image upload, background
		   image, logo, gallery, and any other media-type option in Divi modules. */
		var DIVI_SELECTORS=[
			'.et-pb-option--upload',
			'.et_pb_upload_button',
			'.et-pb-option-upload',
			'.et-pb-media-library-open',
			'.et_pb_choose_image',
			'.et_pb_image_wrap',
		].join(',');

		document.addEventListener('click',function(e){
			var t=e.target;
			var isDivi=false;
			if(t&&t.matches){
				try{isDivi=t.matches(DIVI_SELECTORS)||(t.closest&&!!t.closest(DIVI_SELECTORS));}catch(ex){}
			}
			if(isDivi){
				setFolderCookie(null);  /* clear stale cookie before Divi opens frame */
				pollForModal(600);      /* Divi can be slow to open the frame */
			}
		},true); /* capture phase so we fire before Divi's own handler */

		/* Also patch Divi's ET_Builder media methods if accessible */
		try{
			if(window.ET_Builder&&ET_Builder.API&&ET_Builder.API.openMediaManager){
				var _orig=ET_Builder.API.openMediaManager;
				ET_Builder.API.openMediaManager=function(){
					setFolderCookie(null);
					var r=_orig.apply(this,arguments);
					pollForModal(600);
					return r;
				};
			}
		}catch(e){}
	}

	function injectModalPanel(modal){
		var content=modal.querySelector('.media-frame-content');
		if(!content)return;
		if(content.querySelector('#rayetun-mn-modal-panel'))return;

		var stale=document.getElementById('rayetun-mn-modal-panel');
		if(stale)stale.remove();

		var PANEL_W=190;

		var mp=document.createElement('div');
		mp.id='rayetun-mn-modal-panel';
		mp.style.cssText=['position:absolute','left:0','top:0',
			'width:'+PANEL_W+'px','z-index:10','background:#fff',
			'border-right:1px solid #dcdcde','overflow:hidden',
			'display:flex','flex-direction:column','box-sizing:border-box'].join(';');
		content.appendChild(mp);
		buildModalPanel(mp);

		/* Height: WP sets .media-frame-content height as inline style (e.g. 644px).
		   Use that directly; fall back to measuring from .media-frame. */
		function setPanelHeight(){
			var h=content.offsetHeight;
			if(h>10){mp.style.height=h+'px';return h;}
			var frame=modal.querySelector('.media-frame');
			if(!frame)return 0;
			var router=frame.querySelector('.media-frame-router');
			var toolbar=frame.querySelector('.media-frame-toolbar');
			h=frame.offsetHeight-(router?router.offsetHeight:0)-(toolbar?toolbar.offsetHeight:0);
			if(h>10)mp.style.height=h+'px';
			return h;
		}

		/* Browser width: DEBUG CONFIRMED that in Elementor .media-frame-content has
		   position:relative with left:200px as a SHIFT (not constraint), making its
		   computed width = full modal width (1476px) instead of modal - menu (1276px).
		   calc(100% - 160px) uses the wrong 1476px base → browser 200px too wide →
		   sidebar overflows viewport by 170px.
		
		   Fix: browserW = frame.width - menu.width - panel.width
		   Works for both layouts:
		     Elementor (content=1476px): 1476-200-160 = 1116px → browser right at modal right ✓
		     Classic  (content=1276px): 1476-200-160 = 1116px → browser right at content right ✓ */
		function nudgeBrowser(){
			var br=content.querySelector('.attachments-browser');
			if(!br)return;
			var h=mp.style.height;
			var frame=modal.querySelector('.media-frame');
			var menu=modal.querySelector('.media-frame-menu');
			var menuW=menu?Math.round(menu.getBoundingClientRect().width):0;
			var frameW=frame?Math.round(frame.getBoundingClientRect().width):0;
			if(!frameW)frameW=Math.round(content.getBoundingClientRect().width)+menuW;
			var browserW=frameW-menuW-PANEL_W;
			br.style.left='0';
			br.style.marginLeft=PANEL_W+'px';
			br.style.width=browserW+'px';
			br.style.boxSizing='border-box';
			if(h){
				br.style.height=h;
				content.style.overflow='hidden';
			}
		}

		setPanelHeight();
		nudgeBrowser();
		setTimeout(function(){setPanelHeight();nudgeBrowser();},100);
		setTimeout(function(){setPanelHeight();nudgeBrowser();},400);

		var obs=new MutationObserver(function(muts){
			muts.forEach(function(m){
				if(m.type!=='childList')return;
				m.addedNodes.forEach(function(n){
					if(n.nodeType!==1)return;
					if((n.classList&&n.classList.contains('attachments-browser'))||
					   (n.querySelector&&n.querySelector('.attachments-browser'))){
						setPanelHeight();nudgeBrowser();
					}
				});
				m.removedNodes.forEach(function(n){
					if(n.id==='rayetun-mn-modal-panel')setTimeout(function(){injectModalPanel(modal);},30);
				});
			});
		});
		obs.observe(content,{childList:true,subtree:true});
	}
	function buildModalPanel(mp){
		/* Clear stale cookie immediately — first WP query may have used the wrong
		   folder. We trigger a re-query at the end to show All Files cleanly. */
		setFolderCookie(null);
		mp.innerHTML='';

		/* ── Header ── */
		var hdr=el('div',{className:'rayetun-mn-modal-header'});
		hdr.innerHTML='<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;color:#4f46e5"><path d="M2 5a2 2 0 012-2h3.586a1 1 0 01.707.293L9.707 4.707A1 1 0 0010.414 5H16a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V5z" fill="currentColor"/></svg><span>Folders</span>';
		mp.appendChild(hdr);

		/* ── Search ── */
		/* New Folder — create + upload into a folder without leaving the
		   post / Elementor / Divi editor. Only for users who can create folders. */
		var nfHolder=el('div',{className:'rayetun-mn-modal-nf'});
		if(_caps.createFolders){
			var newBtn=el('button',{className:'rayetun-mn-modal-newfolder',title:'Create a new folder'});
			newBtn.innerHTML=II.plus+' New';
			hdr.appendChild(newBtn);
			newBtn.addEventListener('click',function(e){
				e.stopPropagation();
				if(nfHolder.firstChild){ nfHolder.innerHTML=''; return; }
				nfHolder.appendChild(mkInput('New folder name',
					function(name){
						newBtn.disabled=true;
						apiCreate(name,0,'').then(function(newId){
							buildModalPanel(mp);
							/* Delay past buildModalPanel's own modalFilter(null) (fires at
							   30ms) so our selection of the new folder wins. */
							setTimeout(function(){
								var node=mp.querySelector('.rayetun-mn-modal-item[data-mn-term="'+newId+'"]');
								if(node)node.click();
							},60);
						}).catch(function(err){
							newBtn.disabled=false;
							var i=nfHolder.querySelector('.rayetun-mn-new-folder-input');
							if(i){i.style.borderColor='#d63638';i.title=(err&&err.message)||'Error';}
						});
					},
					function(){ nfHolder.innerHTML=''; }
				));
			});
		}
		mp.appendChild(nfHolder);

		var srchWrap=el('div',{className:'rayetun-mn-modal-search'});
		var srch=el('input',{type:'text',placeholder:'Search\u2026',className:'rayetun-mn-modal-search-input'});
		srch.addEventListener('keydown',function(e){e.stopPropagation();});
		srchWrap.appendChild(srch);mp.appendChild(srchWrap);

		/* ── Folder list ── */
		var list=el('div',{className:'rayetun-mn-modal-list'});

		function setModalActive(node){
			list.querySelectorAll('.rayetun-mn-modal-item').forEach(function(x){x.classList.remove('is-active');});
			node.classList.add('is-active');
		}

		/* All Files row */
		var allBtn=el('div',{className:'rayetun-mn-modal-item is-active',title:'Show all files'});
		allBtn.innerHTML=II.files+'<span class="rayetun-mn-modal-name">All Files</span>'+(SHOW_COUNTS&&state.counts.total?'<span class="rayetun-mn-modal-count">'+state.counts.total+'</span>':'');
		allBtn.addEventListener('click',function(){setModalActive(allBtn);modalFilter(null);});
		list.appendChild(allBtn);

		/* Uncategorized row */
		if(state.counts.uncategorized){
			var ucBtn=el('div',{className:'rayetun-mn-modal-item',title:'Show unassigned files'});
			ucBtn.innerHTML=II.files+'<span class="rayetun-mn-modal-name">Uncategorized</span><span class="rayetun-mn-modal-count">'+state.counts.uncategorized+'</span>';
			ucBtn.addEventListener('click',function(){setModalActive(ucBtn);modalFilter('uncategorized');});
			list.appendChild(ucBtn);
		}

		/* Folder tree */
		var tree=buildTree(state.folders,0);
		(function addI(items,depth){items.forEach(function(f){
			var cnt=SHOW_COUNTS?state.counts[f.term_id]:0;
			var item=el('div',{className:'rayetun-mn-modal-item','data-mn-term':f.term_id,style:{paddingLeft:(12+depth*16)+'px'}});
			var iconHtml=f.icon&&f.icon.trim()?'<span style="font-size:14px;margin-right:4px;line-height:1">'+f.icon+'</span>':iF(f.color||'#646970');
			item.innerHTML=iconHtml+'<span class="rayetun-mn-modal-name">'+f.name+'</span>'+(cnt?'<span class="rayetun-mn-modal-count">'+cnt+'</span>':'');
			item.addEventListener('click',function(){setModalActive(item);modalFilter(Number(f.term_id));});
			list.appendChild(item);
			if(f.children&&f.children.length)addI(f.children,depth+1);
		});})(tree,0);

		/* Live search filter */
		srch.addEventListener('input',function(){
			var q=srch.value.toLowerCase().trim();
			list.querySelectorAll('.rayetun-mn-modal-item').forEach(function(it){
				var nm=it.querySelector('.rayetun-mn-modal-name');
				if(!nm){it.style.display='';return;} /* All Files / Uncategorized always show */
				it.style.display=(!q||nm.textContent.toLowerCase().indexOf(q)!==-1)?'':'none';
			});
		});

		mp.appendChild(list);
		/* Trigger re-query now that cookie is cleared — fixes initial empty grid */
		setTimeout(function(){ modalFilter(null); }, 30);
	}

	/* ── Folder drag-to-reorder ── */
	var folderDrag={active:false,tid:0,ghost:null,startY:0,tracking:false};

	function initFolderReorder() {
		/* Use event delegation on the panel for folder row drag */
		var panel=document.getElementById('rayetun-mn-panel');
		if(!panel)return;

		panel.addEventListener('mousedown',function(e){
			if(e.button!==0)return;
			if(!CAN_REORDER)return;
			var row=e.target.closest&&e.target.closest('.rayetun-mn-row[data-mn-term]');
			if(!row||e.target.closest('.rayetun-mn-toggle')||e.target.closest('.rayetun-mn-row-actions'))return;
			/* Reorder is only meaningful in Manual sort. Drag may start anywhere on the row
			   (the handle is just a visual cue) — the 6px threshold below distinguishes a
			   click-to-select from a drag-to-reorder, so we don't require a pixel-perfect
			   press on the tiny handle. */
			if(state.sortBy!=='manual')return;
			folderDrag.tracking=true;folderDrag.active=false;
			folderDrag.tid=parseInt(row.getAttribute('data-mn-term'),10);
			folderDrag.startY=e.clientY;
			/* Prevent the browser from starting text-selection / native drag on the
			   handle, which can swallow the mousemove tracking and make drag "do nothing". */
			e.preventDefault();
		},true);

		var _dragRaf=null;
		document.addEventListener('mousemove',function(e){
			if(!folderDrag.tracking)return;
			var dy=Math.abs(e.clientY-folderDrag.startY);
			if(!folderDrag.active&&dy<6)return;
			if(!folderDrag.active){
				folderDrag.active=true;
				var g=document.createElement('div');
				g.style.cssText='position:fixed;z-index:999999;pointer-events:none;background:#4f46e5;color:#fff;border-radius:4px;padding:3px 10px;font-size:12px;box-shadow:0 2px 6px rgba(79,70,229,.35);';
				var f=fnd(folderDrag.tid);g.textContent=f?f.name:'folder';
				document.body.appendChild(g);folderDrag.ghost=g;
			}
			/* RAF-throttle the expensive DOM queries to keep drag smooth */
			var cx=e.clientX,cy=e.clientY;
			if(folderDrag.ghost){folderDrag.ghost.style.left=(cx+12)+'px';folderDrag.ghost.style.top=(cy+6)+'px';}
			if(_dragRaf)return;
			_dragRaf=requestAnimationFrame(function(){
				_dragRaf=null;
				document.querySelectorAll('.rayetun-mn-row.is-drop-target').forEach(function(r){r.classList.remove('is-drop-target');});
				var under=document.elementFromPoint(cx,cy);
				var targetRow=under&&under.closest&&under.closest('.rayetun-mn-row[data-mn-term]');
				if(targetRow&&parseInt(targetRow.getAttribute('data-mn-term'),10)!==folderDrag.tid){
					targetRow.classList.add('is-drop-target');
				}
			});
			e.preventDefault();
		});

		document.addEventListener('mouseup',function(e){
			if(!folderDrag.tracking)return;
			var wasActive=folderDrag.active;
			folderDrag.tracking=false;folderDrag.active=false;
			document.querySelectorAll('.rayetun-mn-row.is-drop-target').forEach(function(r){r.classList.remove('is-drop-target');});
			if(folderDrag.ghost){folderDrag.ghost.remove();folderDrag.ghost=null;}
			if(!wasActive)return;
			var under=document.elementFromPoint(e.clientX,e.clientY);
			var targetRow=under&&under.closest&&under.closest('.rayetun-mn-row[data-mn-term]');
			if(!targetRow)return;
			var targetTid=parseInt(targetRow.getAttribute('data-mn-term'),10);
			if(targetTid===folderDrag.tid)return;
			/* Reorder within the same parent level.
			   Sort siblings by sort_order FIRST to get correct current order —
			   state.folders is unsorted insertion order which caused "only works once" bug. */
			var draggedF=fnd(folderDrag.tid);
			var parentId=draggedF?Number(draggedF.parent_id):0;
			var siblings=state.folders
				.filter(function(f){return Number(f.parent_id)===parentId;})
				.sort(function(a,b){return (Number(a.sort_order)||0)-(Number(b.sort_order)||0);});
			var ids=siblings.map(function(f){return Number(f.term_id);});
			var fromIdx=ids.indexOf(folderDrag.tid),toIdx=ids.indexOf(targetTid);
			if(fromIdx!==-1&&toIdx!==-1){
				ids.splice(fromIdx,1);ids.splice(toIdx,0,folderDrag.tid);
				ids.forEach(function(id,pos){var f=fnd(id);if(f)f.sort_order=pos;});
				render();
				apiReorder(ids).catch(console.error);
			}
		});
	}

	/* ── Mount ── */
	function mount(){
		if(!IS_MEDIA_LIB){
			loadData();
			initModalSupport(); /* wp.media wrapper + MutationObserver */
			if(IS_ELEMENTOR){ initElementorIntegration(); }
			/* Divi: always run on editor pages (it uses standard post.php) */
			initDiviIntegration();
			return;
		}
		if(document.getElementById('rayetun-mn-panel'))return;
		var wpBody=document.getElementById('wpbody-content');
		if(!wpBody)return;
		/* Wrap ONLY the folder panel + the media page content (.wrap) in the flex row.
		   Previously we moved EVERY child of #wpbody-content into the flex wrapper, which
		   captured WordPress's own #screen-meta / #screen-meta-links (Help & Screen Options)
		   and admin notices (e.g. the "update available" nag) — turning them into a dead
		   middle column and, when opened, pushing the media grid off-screen. Leaving those
		   native elements in place restores the normal layout. */
		var contentWrap=wpBody.querySelector('.wrap');
		if(!contentWrap)return;
		var panel=document.createElement('div');
		panel.id='rayetun-mn-panel';
		var wrapper=document.getElementById('rayetun-mn-wrap');
		if(!wrapper){
			wrapper=document.createElement('div');
			wrapper.id='rayetun-mn-wrap';
			wrapper.className='rayetun-mn-wrap';
			wrapper.style.cssText='display:flex;flex-direction:row;align-items:flex-start;';
			/* Put the wrapper where .wrap currently is, then move ONLY .wrap into it. */
			contentWrap.parentNode.insertBefore(wrapper,contentWrap);
			wrapper.appendChild(contentWrap);
		}
		wrapper.insertBefore(panel,wrapper.firstChild);
		render();
		loadData();
		/* Refresh the grid live after uploads finish (no page reload needed).
		   wp.Uploader may load just after us, so retry briefly until it exists. */
		if ( !initUploadRefresh() ) {
			var _urAtt = 0;
			var _urIv = setInterval( function () {
				_urAtt++;
				if ( initUploadRefresh() || _urAtt > 40 ) { clearInterval( _urIv ); }
			}, 100 );
		}
	}

	if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount);
	else setTimeout(mount,0);

	/* ════════════════════════════════════════════════════════════
	   BULK ALT TEXT EDITOR
	   Full-screen modal: paginated list of images missing alt text.
	   Each row has an inline <input> and a per-row Save button.
	   Footer: page navigation + "Save All Modified" batch button.
	   ════════════════════════════════════════════════════════════ */
	function openAltEditor(){
		/* If already open, just focus */
		if(_altModal&&_altModal.parentNode){_altModal.querySelector('.rayetun-mn-alt-input')&&_altModal.querySelector('.rayetun-mn-alt-input').focus();return;}

		var currentPage=1;
		var totalPages=1;
		var modified={}; /* id → new alt text */

		/* ── Shell ── */
		var overlay=el('div',{className:'rayetun-mn-alt-overlay',role:'dialog','aria-modal':'true','aria-label':'Bulk Alt Text Editor'});
		var box=el('div',{className:'rayetun-mn-alt-box'});

		/* Header */
		var hdr=el('div',{className:'rayetun-mn-alt-header'});
		var title=el('h2',{className:'rayetun-mn-alt-title'},'Bulk Alt Text Editor');
		var closeBtn=el('button',{className:'rayetun-mn-alt-close','aria-label':'Close'},'✕');
		hdr.appendChild(title);hdr.appendChild(closeBtn);
		box.appendChild(hdr);

		/* Body */
		var body=el('div',{className:'rayetun-mn-alt-body'});
		var list=el('div',{className:'rayetun-mn-alt-list'});
		body.appendChild(list);
		box.appendChild(body);

		/* Footer */
		var footer=el('div',{className:'rayetun-mn-alt-footer'});
		var pageInfo=el('span',{className:'rayetun-mn-alt-page-info'},'');
		var prevBtn=el('button',{className:'rayetun-mn-alt-page-btn'},'← Prev');
		var nextBtn=el('button',{className:'rayetun-mn-alt-page-btn'},'Next →');
		var saveAllBtn=el('button',{className:'rayetun-mn-alt-save-all'},'Save All Modified');
		footer.appendChild(prevBtn);footer.appendChild(pageInfo);footer.appendChild(nextBtn);
		footer.appendChild(saveAllBtn);
		box.appendChild(footer);

		overlay.appendChild(box);
		document.body.appendChild(overlay);
		_altModal=overlay;

		/* ── Close logic ── */
		function closeModal(){
			if(overlay.parentNode)overlay.parentNode.removeChild(overlay);
			_altModal=null;
		}
		closeBtn.addEventListener('click',closeModal);
		overlay.addEventListener('click',function(e){if(e.target===overlay)closeModal();});
		document.addEventListener('keydown',function escHandler(e){
			if(e.key==='Escape'){closeModal();document.removeEventListener('keydown',escHandler);}
		});

		/* ── Render a page of images ── */
		function renderList(data){
			totalPages=data.pages||1;
			pageInfo.textContent='Page '+data.page+' of '+totalPages+' ('+data.total+' images)';
			prevBtn.disabled=(data.page<=1);
			nextBtn.disabled=(data.page>=totalPages);

			/* Clear */
			while(list.firstChild)list.removeChild(list.firstChild);

			if(!data.items||!data.items.length){
				var emp=el('div',{className:'rayetun-mn-alt-empty'},'🎉 All images have alt text!');
				var cls=el('a',{className:'rayetun-mn-alt-close-link',href:'#'},'Close');
				cls.addEventListener('click',function(e){e.preventDefault();closeModal();});
				emp.appendChild(document.createElement('br'));
				emp.appendChild(cls);
				list.appendChild(emp);
				return;
			}

			data.items.forEach(function(img){
				var row=el('div',{className:'rayetun-mn-alt-row'});

				/* Thumbnail */
				var thumbWrap=el('div',{className:'rayetun-mn-alt-thumb'});
				if(img.thumbnail){
					var thumbImg=el('img',{className:'rayetun-mn-alt-thumb-img',src:img.thumbnail,alt:'',loading:'lazy'});
					thumbWrap.appendChild(thumbImg);
				} else {
					thumbWrap.innerHTML='<span style="font-size:28px;color:#dcdcde;">🖼️</span>';
				}
				row.appendChild(thumbWrap);

				/* Info + input */
				var info=el('div',{className:'rayetun-mn-alt-info'});
				var fname=el('div',{className:'rayetun-mn-alt-filename'},img.filename||img.title||'(untitled)');
				info.appendChild(fname);

				var inputWrap=el('div',{className:'rayetun-mn-alt-input-wrap'});
				var input=el('input',{
					className:'rayetun-mn-alt-input',
					type:'text',
					placeholder:'Enter alt text…',
					value:img.alt||''
				});

				/* Track changes */
				var originalVal=img.alt||'';
				input.addEventListener('input',function(){
					if(input.value!==originalVal){modified[img.id]=input.value;}
					else{delete modified[img.id];}
					saveRowBtn.classList.toggle('is-modified',!!modified[img.id]);
				});

				/* Per-row save button */
				var saveRowBtn=el('button',{className:'rayetun-mn-alt-save-btn'},'Save');
				(function(imgId,inp,btn){
					btn.addEventListener('click',function(){
						var text=inp.value;
						btn.disabled=true;btn.textContent='Saving…';
						apiSaveAlt(imgId,text)
							.then(function(){
								delete modified[imgId];
								originalVal=text;
								btn.textContent='Saved ✓';
								btn.classList.add('is-saved');
								btn.classList.remove('is-modified');
								setTimeout(function(){btn.textContent='Save';btn.disabled=false;btn.classList.remove('is-saved');},1800);
								/* Refresh counts in sidebar */
								refreshCounts().then(render);
							})
							.catch(function(e){
								btn.textContent='Save';btn.disabled=false;
								showToast((e&&e.message)||'Save failed.','error');
							});
					});
				})(img.id,input,saveRowBtn);

				inputWrap.appendChild(input);
				inputWrap.appendChild(saveRowBtn);
				info.appendChild(inputWrap);
				row.appendChild(info);
				list.appendChild(row);
			});
		}

		/* ── Fetch a page ── */
		function loadPage(page){
			list.innerHTML='<p style="padding:16px;color:#646970;font-size:13px;">Loading…</p>';
			apiAltList(page)
				.then(function(data){currentPage=data.page;renderList(data);})
				.catch(function(e){
					list.innerHTML='';
					list.appendChild(el('div',{className:'rayetun-mn-alt-empty'},'Error: '+((e&&e.message)||'Could not load images.')));
				});
		}

		/* ── Pagination ── */
		prevBtn.addEventListener('click',function(){if(currentPage>1)loadPage(currentPage-1);});
		nextBtn.addEventListener('click',function(){if(currentPage<totalPages)loadPage(currentPage+1);});

		/* ── Save All Modified ── */
		saveAllBtn.addEventListener('click',function(){
			var ids=Object.keys(modified);

			/* Nothing queued — show feedback ON the button (toast is also shown but
			   this ensures something is visible even if z-index ever causes issues). */
			if(!ids.length){
				var prev=saveAllBtn.textContent;
				var prevBg=saveAllBtn.style.background;
				saveAllBtn.textContent='✓ All already saved';
				saveAllBtn.style.background='#00a32a';
				saveAllBtn.disabled=true;
				showToast('All changes are already saved.');
				setTimeout(function(){
					saveAllBtn.textContent=prev;
					saveAllBtn.style.background=prevBg;
					saveAllBtn.disabled=false;
				},2200);
				return;
			}

			saveAllBtn.disabled=true;
			saveAllBtn.textContent='Saving '+ids.length+'…';

			var promises=ids.map(function(id){return apiSaveAlt(parseInt(id,10),modified[id]);});
			Promise.all(promises)
				.then(function(){
					modified={};
					var msg=ids.length+' alt text'+(ids.length!==1?'s':'')+' saved.';
					saveAllBtn.textContent='✓ '+msg;
					saveAllBtn.style.background='#00a32a';
					showToast(msg);
					refreshCounts().then(render);
					setTimeout(function(){
						saveAllBtn.disabled=false;
						saveAllBtn.textContent='Save All Modified';
						saveAllBtn.style.background='';
					},2500);
					loadPage(currentPage);
				})
				.catch(function(e){
					saveAllBtn.disabled=false;
					saveAllBtn.textContent='Save All Modified';
					saveAllBtn.style.background='';
					showToast('Some saves failed: '+((e&&e.message)||'Unknown error.'),'error');
				});
		});

		/* ── Initial load ── */
		loadPage(1);
	}

})();
