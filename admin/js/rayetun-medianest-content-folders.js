/**
 * Content Folders â€” edit.php list-screen sidebar.
 *
 * A folder rail beside WordPress's list table, built to feel like the free PixelVault
 * media sidebar: inline create/rename, in-place re-render (no page reloads), toasts,
 * collapsible branches, drag-and-drop filing, and folder filtering that swaps the table
 * body over AJAX (falling back to a normal navigation if that ever fails).
 */
( function ( wp ) {
	'use strict';

	var cfg = window.mnCF;
	if ( ! cfg ) {
		return;
	}
	var apiFetch = wp.apiFetch;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var sprintf = ( wp.i18n && wp.i18n.sprintf ) ? wp.i18n.sprintf : function ( f ) {
		var args = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return f.replace( /%(\d+\$)?[sd]/g, function () { return args[ i++ ]; } );
	};
	if ( apiFetch && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );
	}

	var S = cfg.strings;
	var state = {
		folders: [],
		counts: cfg.counts || {},
		active: cfg.active || '',
		expanded: loadExpanded(),
		dragIds: null
	};

	// DOM handles filled in by mount().
	var treeEl, navEl, sidebarEl, layoutEl, mainEl, selectEl, toastEl;

	// â”€â”€ Small helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text != null ) { e.textContent = text; }
		return e;
	}
	function svg( paths ) {
		return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
	}
	function count( key ) {
		var c = state.counts && state.counts[ key ];
		return ( typeof c === 'number' ) ? c : 0;
	}
	function loadExpanded() {
		try { return JSON.parse( window.localStorage.getItem( 'mnCFExpanded_' + cfg.postType ) || '[]' ); }
		catch ( e ) { return []; }
	}
	function saveExpanded() {
		try { window.localStorage.setItem( 'mnCFExpanded_' + cfg.postType, JSON.stringify( state.expanded ) ); }
		catch ( e ) {}
	}
	function isExpanded( id ) { return state.expanded.indexOf( id ) !== -1; }
	function setExpanded( id, on ) {
		var i = state.expanded.indexOf( id );
		if ( on && i === -1 ) { state.expanded.push( id ); }
		if ( ! on && i !== -1 ) { state.expanded.splice( i, 1 ); }
		saveExpanded();
	}

	function checkedIds() {
		var ids = [];
		document.querySelectorAll( '#the-list input[name="post[]"]:checked' ).forEach( function ( cb ) {
			var v = parseInt( cb.value, 10 );
			if ( v ) { ids.push( v ); }
		} );
		return ids;
	}

	function toast( msg, kind ) {
		if ( ! toastEl ) { return; }
		toastEl.textContent = msg;
		toastEl.className = 'mn-cf-toast is-show' + ( kind === 'error' ? ' is-error' : '' );
		window.clearTimeout( toast._t );
		toast._t = window.setTimeout( function () { toastEl.className = 'mn-cf-toast'; }, 2600 );
	}

	// An inline "type a name" input with Save / Cancel; Enter saves, Esc cancels.
	function mkInput( placeholder, initial, onSave, onCancel ) {
		var wrap = el( 'div', 'mn-cf-inputrow' );
		var input = el( 'input', 'mn-cf-input' );
		input.type = 'text';
		input.placeholder = placeholder;
		input.value = initial || '';
		var save = el( 'button', 'mn-cf-ibtn is-ok', 'âœ“' );
		save.type = 'button'; save.title = S.save;
		var cancel = el( 'button', 'mn-cf-ibtn', 'âœ•' );
		cancel.type = 'button'; cancel.title = S.cancel;
		function commit() {
			var v = input.value.trim();
			if ( ! v ) { input.focus(); return; }
			onSave( v, input );
		}
		save.addEventListener( 'click', commit );
		cancel.addEventListener( 'click', function () { onCancel(); } );
		input.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Enter' ) { ev.preventDefault(); commit(); }
			else if ( ev.key === 'Escape' ) { ev.preventDefault(); onCancel(); }
		} );
		wrap.appendChild( input );
		wrap.appendChild( save );
		wrap.appendChild( cancel );
		setTimeout( function () { input.focus(); }, 10 );
		return wrap;
	}

	// â”€â”€ REST calls â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function fetchTree() {
		return apiFetch( { url: cfg.treeUrl + '?post_type=' + encodeURIComponent( cfg.postType ) } )
			.then( function ( data ) {
				state.folders = ( data && data.folders ) || [];
				state.counts = ( data && data.counts ) || {};
				paintTree();
			} );
	}
	function apiCreate( name, parentId ) {
		return apiFetch( { url: cfg.restUrl, method: 'POST', data: { name: name, post_type: cfg.postType, parent_id: parentId || 0 } } );
	}
	function apiRename( termId, name ) {
		return apiFetch( { url: cfg.restUrl + '/' + termId, method: 'POST', data: { name: name } } );
	}
	function apiDelete( termId ) {
		return apiFetch( { url: cfg.restUrl + '/' + termId, method: 'DELETE' } );
	}
	function apiAssign( op, folderId, ids ) {
		return apiFetch( { url: cfg.assignUrl, method: 'POST', data: { post_ids: ids, folder_id: folderId, op: op } } );
	}

	// â”€â”€ Actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function doCreate( parentId, holder ) {
		if ( holder.firstChild ) { holder.innerHTML = ''; return; }
		holder.appendChild( mkInput( S.newFolderName, '', function ( name, input ) {
			input.disabled = true;
			apiCreate( name, parentId ).then( function () {
				if ( parentId ) { setExpanded( parentId, true ); }
				holder.innerHTML = '';
				return fetchTree();
			} ).then( function () {
				toast( sprintf( S.created, name ) );
			} ).catch( function () {
				input.disabled = false;
				input.classList.add( 'has-error' );
			} );
		}, function () { holder.innerHTML = ''; } ) );
	}

	function doRename( f, row, link ) {
		if ( row.querySelector( '.mn-cf-inputrow' ) ) { return; }
		var tools = row.querySelector( '.mn-cf-tools' );
		if ( tools ) { tools.style.display = 'none'; }
		var input = mkInput( S.newFolderName, f.name, function ( name, inp ) {
			inp.disabled = true;
			apiRename( f.term_id, name ).then( function () { return fetchTree(); } )
				.then( function () { toast( sprintf( S.renamedTo, name ) ); } )
				.catch( function () { inp.disabled = false; inp.classList.add( 'has-error' ); } );
		}, function () { paintTree(); } );
		link.style.display = 'none';
		link.parentNode.insertBefore( input, link.nextSibling );
	}

	function doDelete( f, rowEl ) {
		// Inline confirm inside the row.
		if ( rowEl.querySelector( '.mn-cf-confirm' ) ) { return; }
		var bar = el( 'div', 'mn-cf-confirm' );
		bar.appendChild( el( 'span', 'mn-cf-confirm-q', S.deleteQ ) );
		var yes = el( 'button', 'mn-cf-ibtn is-danger', S.delete );
		yes.type = 'button';
		var no = el( 'button', 'mn-cf-ibtn', S.cancel );
		no.type = 'button';
		no.addEventListener( 'click', function () { bar.remove(); } );
		yes.addEventListener( 'click', function () {
			yes.disabled = true;
			apiDelete( f.term_id ).then( function () {
				var wasActive = String( state.active ) === String( f.term_id );
				return fetchTree().then( function () {
					toast( sprintf( S.deletedName, f.name ) );
					if ( wasActive ) { applyFilter( null ); }
				} );
			} ).catch( function () { yes.disabled = false; toast( S.error, 'error' ); } );
		} );
		bar.appendChild( yes );
		bar.appendChild( no );
		rowEl.appendChild( bar );
	}

	function assign( op, folderId, ids, folderName ) {
		if ( ! ids.length ) { toast( S.selectFirst, 'error' ); return; }
		if ( ! folderId ) { toast( S.chooseFolder, 'error' ); return; }
		apiAssign( op, folderId, ids ).then( function ( res ) {
			var n = ( res && res.changed ) || 0;
			var msg = ( op === 'remove' ) ? sprintf( S.removedFrom, n, folderName ) : sprintf( S.filedInto, n, folderName );
			return fetchTree().then( function () {
				toast( msg );
				refreshList();
			} );
		} ).catch( function () { toast( S.error, 'error' ); } );
	}

	function folderName( id ) {
		for ( var i = 0; i < state.folders.length; i++ ) {
			if ( state.folders[ i ].term_id === id ) { return state.folders[ i ].name; }
		}
		return '';
	}

	// â”€â”€ AJAX list filtering (falls back to navigation) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function urlFor( value ) {
		var u = new URL( window.location.href );
		if ( value === null || value === '' ) { u.searchParams.delete( cfg.queryVar ); }
		else { u.searchParams.set( cfg.queryVar, value ); }
		u.searchParams.delete( 'paged' );
		return u;
	}

	function replacePairs( selector, doc ) {
		var cur = document.querySelectorAll( selector );
		var next = doc.querySelectorAll( selector );
		var n = Math.min( cur.length, next.length );
		for ( var i = 0; i < n; i++ ) { cur[ i ].innerHTML = next[ i ].innerHTML; }
	}

	function applyFilter( value, opts ) {
		opts = opts || {};
		var u = urlFor( value );
		fetch( u.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } } )
			.then( function ( r ) { return r.text(); } )
			.then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				var newList = doc.querySelector( '#the-list' );
				var curList = document.querySelector( '#the-list' );
				if ( ! newList || ! curList ) { throw new Error( 'no list' ); }
				curList.innerHTML = newList.innerHTML;
				replacePairs( '.tablenav-pages', doc );
				replacePairs( '.subsubsub', doc );
				bindRowDnD();
				state.active = ( value === null || value === '' ) ? '' : String( value );
				cfg.active = state.active;
				if ( ! opts.noPush ) { window.history.pushState( { mnFolder: value }, '', u.toString() ); }
				paintTree();
			} )
			.catch( function () { window.location.href = u.toString(); } );
	}

	// Re-run the current filter to refresh rows + the Folders column after an assignment.
	function refreshList() {
		applyFilter( state.active === '' ? null : state.active, { noPush: true } );
	}

	window.addEventListener( 'popstate', function () {
		var u = new URL( window.location.href );
		var v = u.searchParams.get( cfg.queryVar );
		applyFilter( v === null ? null : v, { noPush: true } );
	} );

	// â”€â”€ Drag and drop â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function bindRowDnD() {
		document.querySelectorAll( '#the-list tr' ).forEach( function ( tr ) {
			var cb = tr.querySelector( 'input[name="post[]"]' );
			if ( ! cb || tr.getAttribute( 'data-mn-cf-dnd' ) ) { return; }
			tr.setAttribute( 'data-mn-cf-dnd', '1' );
			tr.setAttribute( 'draggable', 'true' );
			tr.addEventListener( 'dragstart', function ( ev ) {
				var self = parseInt( cb.value, 10 );
				var ids = checkedIds();
				if ( ids.indexOf( self ) === -1 ) { ids = [ self ]; }
				state.dragIds = ids;
				try { ev.dataTransfer.setData( 'text/plain', ids.join( ',' ) ); ev.dataTransfer.effectAllowed = 'copyMove'; } catch ( e ) {}
				document.body.classList.add( 'mn-cf-dragging' );
			} );
			tr.addEventListener( 'dragend', function () {
				state.dragIds = null;
				document.body.classList.remove( 'mn-cf-dragging' );
				clearDropTargets();
			} );
		} );
	}
	function clearDropTargets() {
		if ( ! treeEl ) { return; }
		treeEl.querySelectorAll( '.is-droptarget' ).forEach( function ( x ) { x.classList.remove( 'is-droptarget' ); } );
	}

	// â”€â”€ Rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function childrenOf( parent ) {
		return state.folders.filter( function ( f ) { return ( f.parent_id || 0 ) === parent; } );
	}
	function hasChildren( id ) {
		return state.folders.some( function ( f ) { return ( f.parent_id || 0 ) === id; } );
	}

	function makeDropTarget( rowEl, folderId ) {
		rowEl.addEventListener( 'dragover', function ( ev ) {
			if ( ! state.dragIds ) { return; }
			ev.preventDefault();
			ev.dataTransfer.dropEffect = 'copy';
			rowEl.classList.add( 'is-droptarget' );
		} );
		rowEl.addEventListener( 'dragleave', function () { rowEl.classList.remove( 'is-droptarget' ); } );
		rowEl.addEventListener( 'drop', function ( ev ) {
			ev.preventDefault();
			rowEl.classList.remove( 'is-droptarget' );
			var ids = state.dragIds || [];
			if ( ! ids.length ) {
				var raw = ( ev.dataTransfer.getData( 'text/plain' ) || '' ).split( ',' );
				ids = raw.map( function ( x ) { return parseInt( x, 10 ); } ).filter( Boolean );
			}
			if ( ids.length ) { assign( 'add', folderId, ids, folderName( folderId ) ); }
		} );
	}

	var ICON = {
		folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
		all: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
		unfiled: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'
	};
	function ico( paths ) {
		var s = el( 'span', 'mn-cf-ico' );
		s.innerHTML = svg( paths );
		return s;
	}

	function fixedRow( label, value, key, iconPaths ) {
		var a = el( 'a', 'mn-cf-row mn-cf-fixed' + ( String( state.active ) === String( value === null ? '' : value ) ? ' is-active' : '' ) );
		a.href = urlFor( value ).toString();
		a.appendChild( el( 'span', 'mn-cf-chevslot' ) ); // empty gutter, keeps icons aligned with folders
		var content = el( 'span', 'mn-cf-link' );
		content.appendChild( ico( iconPaths ) );
		content.appendChild( el( 'span', 'mn-cf-name', label ) );
		content.appendChild( el( 'span', 'mn-cf-count', String( count( key ) ) ) );
		a.appendChild( content );
		a.addEventListener( 'click', function ( ev ) { ev.preventDefault(); applyFilter( value ); } );
		return a;
	}

	function folderRow( f, depth ) {
		var id = f.term_id;
		var row = el( 'div', 'mn-cf-row' );
		// A fixed chevron gutter keeps every icon aligned with All / Unfiled; each level
		// adds 16px so sub-folders indent.
		row.style.paddingLeft = ( 8 + depth * 16 ) + 'px';
		if ( String( state.active ) === String( id ) ) { row.className += ' is-active'; }

		var slot = el( 'span', 'mn-cf-chevslot' );
		if ( hasChildren( id ) ) {
			var chev = el( 'button', 'mn-cf-chev' + ( isExpanded( id ) ? ' is-open' : '' ) );
			chev.type = 'button';
			chev.innerHTML = svg( '<polyline points="9 18 15 12 9 6"/>' );
			chev.addEventListener( 'click', function ( ev ) {
				ev.preventDefault(); ev.stopPropagation();
				setExpanded( id, ! isExpanded( id ) );
				paintTree();
			} );
			slot.appendChild( chev );
		}
		row.appendChild( slot );

		var link = el( 'a', 'mn-cf-link' );
		link.href = urlFor( id ).toString();
		link.appendChild( ico( ICON.folder ) );
		var nameEl = el( 'span', 'mn-cf-name', f.name );
		link.appendChild( nameEl );
		link.appendChild( el( 'span', 'mn-cf-count', String( count( id ) ) ) );
		link.addEventListener( 'click', function ( ev ) { ev.preventDefault(); applyFilter( id ); } );
		row.appendChild( link );

		if ( cfg.canManage || cfg.canCreate ) {
			var tools = el( 'span', 'mn-cf-tools' );
			if ( cfg.canCreate ) {
				var sub = toolBtn( svg( '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>' ), S.newSubfolder, function ( ev ) {
					ev.preventDefault(); ev.stopPropagation();
					setExpanded( id, true );
					paintTree();
					// After repaint, open a child input under this folder.
					var holder = treeEl.querySelector( '.mn-cf-childinput[data-parent="' + id + '"]' );
					if ( holder ) { doCreate( id, holder ); }
				} );
				tools.appendChild( sub );
			}
			if ( cfg.canManage ) {
				tools.appendChild( toolBtn( svg( '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>' ), S.rename, function ( ev ) {
					ev.preventDefault(); ev.stopPropagation(); doRename( f, row, link );
				} ) );
				tools.appendChild( toolBtn( svg( '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' ), S.delete, function ( ev ) {
					ev.preventDefault(); ev.stopPropagation(); doDelete( f, row );
				} ) );
			}
			row.appendChild( tools );
		}

		makeDropTarget( row, id );
		return row;
	}

	function toolBtn( html, title, fn ) {
		var b = el( 'button', 'mn-cf-tool' );
		b.type = 'button'; b.title = title; b.setAttribute( 'aria-label', title );
		b.innerHTML = html;
		b.addEventListener( 'click', fn );
		return b;
	}

	function branch( parent, depth, frag ) {
		childrenOf( parent ).forEach( function ( f ) {
			frag.appendChild( folderRow( f, depth ) );
			// Placeholder holder for an inline "new subfolder" input under this folder.
			var holder = el( 'div', 'mn-cf-childinput' );
			holder.setAttribute( 'data-parent', f.term_id );
			holder.style.paddingLeft = ( 8 + ( depth + 1 ) * 16 ) + 'px';
			frag.appendChild( holder );
			if ( hasChildren( f.term_id ) && isExpanded( f.term_id ) ) {
				branch( f.term_id, depth + 1, frag );
			}
		} );
	}

	function paintTree() {
		if ( ! navEl || ! treeEl ) { return; }
		// Fixed rows (All / Unfiled).
		navEl.innerHTML = '';
		navEl.appendChild( fixedRow( S.allItems, null, '__all', ICON.all ) );
		navEl.appendChild( fixedRow( S.unfiled, 'unfiled', '__unfiled', ICON.unfiled ) );
		// Tree.
		treeEl.innerHTML = '';
		var frag = document.createDocumentFragment();
		branch( 0, 0, frag );
		treeEl.appendChild( frag );
		syncSelect();
	}

	// â”€â”€ Mount â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	function mount() {
		var filter = document.getElementById( 'posts-filter' );
		if ( ! filter ) { return; }

		sidebarEl = el( 'aside', 'mn-cf-sidebar' );

		var head = el( 'div', 'mn-cf-head' );
		var toggle = el( 'button', 'mn-cf-toggle', '' );
		toggle.type = 'button';
		toggle.innerHTML = svg( '<polyline points="15 18 9 12 15 6"/>' );
		head.appendChild( toggle );
		head.appendChild( el( 'span', 'mn-cf-title', S.title ) );
		if ( cfg.canCreate ) {
			var add = el( 'button', 'mn-cf-add' );
			add.type = 'button';
			add.title = S.newFolder;
			add.innerHTML = svg( '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>' );
			head.appendChild( add );
		}
		var collapsedHint = el( 'span', 'mn-cf-collapsed-hint', S.title );
		collapsedHint.hidden = true;
		head.appendChild( collapsedHint );
		sidebarEl.appendChild( head );

		// Inline "new root folder" holder (shown when + is clicked).
		var rootInput = el( 'div', 'mn-cf-rootinput' );
		sidebarEl.appendChild( rootInput );
		if ( cfg.canCreate ) {
			add.addEventListener( 'click', function () { doCreate( 0, rootInput ); } );
		}

		navEl = el( 'div', 'mn-cf-nav' );
		sidebarEl.appendChild( navEl );

		treeEl = el( 'div', 'mn-cf-tree' );
		sidebarEl.appendChild( treeEl );

		// Assignment bar (fallback to the sidebar select for keyboard users / no-drag).
		var bar = el( 'div', 'mn-cf-assign' );
		selectEl = el( 'select', 'mn-cf-select' );
		bar.appendChild( selectEl );
		var addBtn = el( 'button', 'button button-primary mn-cf-apply', S.addTo );
		addBtn.type = 'button';
		addBtn.addEventListener( 'click', function () { assign( 'add', parseInt( selectEl.value, 10 ), checkedIds(), folderName( parseInt( selectEl.value, 10 ) ) ); } );
		bar.appendChild( addBtn );
		var rmBtn = el( 'button', 'button mn-cf-apply', S.removeFrom );
		rmBtn.type = 'button';
		rmBtn.addEventListener( 'click', function () { assign( 'remove', parseInt( selectEl.value, 10 ), checkedIds(), folderName( parseInt( selectEl.value, 10 ) ) ); } );
		bar.appendChild( rmBtn );
		sidebarEl.appendChild( bar );

		toastEl = el( 'div', 'mn-cf-toast' );
		sidebarEl.appendChild( toastEl );

		// Lay out beside the list table.
		layoutEl = el( 'div', 'mn-cf-layout' );
		mainEl = el( 'div', 'mn-cf-main' );
		filter.parentNode.insertBefore( layoutEl, filter );
		layoutEl.appendChild( sidebarEl );
		layoutEl.appendChild( mainEl );
		mainEl.appendChild( filter );

		// Collapse toggle (remembered per browser + post type).
		var storeKey = 'mnCFCollapsed_' + cfg.postType;
		function applyCollapsed( collapsed ) {
			layoutEl.classList.toggle( 'is-collapsed', collapsed );
			toggle.innerHTML = collapsed ? svg( '<polyline points="9 18 15 12 9 6"/>' ) : svg( '<polyline points="15 18 9 12 15 6"/>' );
			toggle.title = collapsed ? S.expand : S.collapse;
			toggle.setAttribute( 'aria-label', toggle.title );
			collapsedHint.hidden = ! collapsed;
			try { window.localStorage.setItem( storeKey, collapsed ? '1' : '0' ); } catch ( e ) {}
		}
		var startCollapsed = false;
		try { startCollapsed = window.localStorage.getItem( storeKey ) === '1'; } catch ( e ) {}
		applyCollapsed( startCollapsed );
		toggle.addEventListener( 'click', function () { applyCollapsed( ! layoutEl.classList.contains( 'is-collapsed' ) ); } );

		// First paint from localized counts, then hydrate the folder list.
		paintTree();
		bindRowDnD();
		fetchTree().then( function () { syncSelect(); } ).catch( function () {} );
	}

	function syncSelect() {
		if ( ! selectEl ) { return; }
		selectEl.innerHTML = '';
		var ph = el( 'option', null, S.chooseFolder );
		ph.value = '';
		selectEl.appendChild( ph );
		state.folders.forEach( function ( f ) {
			var o = el( 'option', null, f.name );
			o.value = f.term_id;
			selectEl.appendChild( o );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp || {} );
