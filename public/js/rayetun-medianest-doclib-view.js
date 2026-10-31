/**
 * PixelVault — Document Library block (frontend view).
 *
 * PHASES 2-3: no-reload folder navigation (sidebar tree, breadcrumb, sub-folder
 * pills), in-folder debounced search, an items-per-page selector, and paginated
 * results — all driven by the public REST endpoint, which returns the same
 * server-rendered markup used on first paint (so styling/icons/escaping never
 * drift). All view state lives in data-* attributes on the block root, so any
 * control just updates state and calls navigate().
 *
 * Degrades gracefully: with JS off, the server-rendered root folder (page 1)
 * and per-file downloads still work.
 */
( function () {
	'use strict';

	var CFG = window.pvDoclibView || { restUrl: '', nonce: '', i18n: {} };

	function debounce( fn, ms ) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, ms );
		};
	}

	function searchValue( root ) {
		var box = root.querySelector( '.pv-doclib__search' );
		return box ? box.value.trim() : '';
	}

	function setLayout( root, layout ) {
		root.classList.remove( 'pv-layout-grid', 'pv-layout-list' );
		root.classList.add( 'pv-layout-' + layout );
		root.querySelectorAll( '.pv-doclib__layout-btn' ).forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-layout' ) === layout );
		} );
	}

	function setActiveFolder( root, folderId ) {
		root.querySelectorAll( '.pv-doclib__tree-btn' ).forEach( function ( b ) {
			var on = String( b.getAttribute( 'data-folder' ) ) === String( folderId );
			b.classList.toggle( 'is-active', on );
			var li = b.closest( '.pv-doclib__tree-item' );
			if ( li ) {
				if ( on ) { li.setAttribute( 'aria-current', 'true' ); }
				else { li.removeAttribute( 'aria-current' ); }
			}
		} );
	}

	function navigate( root ) {
		if ( ! CFG.restUrl ) { return; }
		var wrap = root.querySelector( '.pv-doclib__files-wrap' );
		root.classList.add( 'is-loading' );
		if ( wrap ) { wrap.setAttribute( 'aria-busy', 'true' ); }

		var params = new URLSearchParams( {
			folder:   root.getAttribute( 'data-pv-current' ) || 0,
			root:     root.getAttribute( 'data-pv-root' ) || 0,
			page:     root.getAttribute( 'data-pv-page' ) || 1,
			per_page: root.getAttribute( 'data-pv-perpage' ) || 20,
			size:     root.getAttribute( 'data-pv-size' ) || 1,
			dl:       root.getAttribute( 'data-pv-dl' ) || 1,
			counts:   1,
			search:   searchValue( root )
		} );

		fetch( CFG.restUrl + '?' + params.toString(), {
			headers: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : {}
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			var swap = function ( sel, html ) {
				var el = root.querySelector( sel );
				if ( el && typeof html === 'string' ) { el.innerHTML = html; }
			};
			swap( '.pv-doclib__files-wrap', res.files_html );
			swap( '.pv-doclib__breadcrumb', res.breadcrumb_html );
			swap( '.pv-doclib__subfolders', res.subfolders_html );
			swap( '.pv-doclib__pagination-wrap', res.pagination_html );

			var cur = res.current || root.getAttribute( 'data-pv-current' );
			root.setAttribute( 'data-pv-current', cur );
			root.setAttribute( 'data-pv-page', res.page || 1 );
			setActiveFolder( root, cur );
			updateDownloadState( root );
		} ).catch( function () {
			if ( wrap ) {
				wrap.innerHTML = '<p class="pv-doclib__empty">' + ( ( CFG.i18n && CFG.i18n.error ) || 'Could not load this folder.' ) + '</p>';
			}
		} ).then( function () {
			root.classList.remove( 'is-loading' );
			if ( wrap ) { wrap.setAttribute( 'aria-busy', 'false' ); }
		} );
	}

	/* Disable "Download all" when the current folder has no files, so the button
	   can never open the bare "no files to download" page. */
	function updateDownloadState( root ) {
		var dl = root.querySelector( '[data-pv-downloadall]' );
		if ( ! dl ) { return; }
		var hasFiles = !! root.querySelector( '.pv-doclib__files-wrap .pv-doclib__file' );
		dl.disabled = ! hasFiles;
	}

	function init( root ) {
		if ( root._pvInit ) { return; }
		root._pvInit = true;
		updateDownloadState( root );

		/* Layout toggle (grid / list). */
		root.querySelectorAll( '.pv-doclib__layout-btn[data-layout]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { setLayout( root, btn.getAttribute( 'data-layout' ) ); } );
		} );

		/* Delegated clicks: expand/collapse a tree branch, folder navigation, or pagination. */
		root.addEventListener( 'click', function ( e ) {
			var treeToggle = e.target.closest && e.target.closest( '[data-pv-treetoggle]' );
			if ( treeToggle && root.contains( treeToggle ) ) {
				e.preventDefault();
				var item = treeToggle.closest( '.pv-doclib__tree-item' );
				if ( item ) {
					item.setAttribute( 'aria-expanded', item.getAttribute( 'aria-expanded' ) === 'true' ? 'false' : 'true' );
				}
				return;
			}
			var folderBtn = e.target.closest && e.target.closest( '[data-folder]' );
			if ( folderBtn && root.contains( folderBtn ) ) {
				e.preventDefault();
				root.setAttribute( 'data-pv-current', parseInt( folderBtn.getAttribute( 'data-folder' ), 10 ) || 0 );
				root.setAttribute( 'data-pv-page', 1 );
				navigate( root );
				return;
			}
			var pageBtn = e.target.closest && e.target.closest( '[data-pv-goto]' );
			if ( pageBtn && root.contains( pageBtn ) && ! pageBtn.disabled ) {
				e.preventDefault();
				root.setAttribute( 'data-pv-page', parseInt( pageBtn.getAttribute( 'data-pv-goto' ), 10 ) || 1 );
				navigate( root );
				var top = root.querySelector( '.pv-doclib__files-wrap' );
				if ( top && top.scrollIntoView ) { top.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); }
			}
		} );

		/* In-folder search (debounced, resets to page 1). */
		var box = root.querySelector( '.pv-doclib__search' );
		if ( box ) {
			box.addEventListener( 'input', debounce( function () {
				root.setAttribute( 'data-pv-page', 1 );
				navigate( root );
			}, 300 ) );
		}

		/* Items-per-page selector (resets to page 1). */
		var sel = root.querySelector( '.pv-doclib__perpage-select' );
		if ( sel ) {
			sel.addEventListener( 'change', function () {
				root.setAttribute( 'data-pv-perpage', parseInt( sel.value, 10 ) || 20 );
				root.setAttribute( 'data-pv-page', 1 );
				navigate( root );
			} );
		}

		/* "Download all" — navigate the browser to the ZIP download for the current
		   folder (a real navigation so the browser saves the file). */
		var dl = root.querySelector( '[data-pv-downloadall]' );
		if ( dl && CFG.downloadUrl ) {
			dl.addEventListener( 'click', function () {
				if ( dl.disabled ) { return; }
				var base = CFG.downloadUrl;
				var sep  = base.indexOf( '?' ) > -1 ? '&' : '?';
				window.location.href = base + sep + 'pv_doclib_download=1&folder=' +
					( parseInt( root.getAttribute( 'data-pv-current' ), 10 ) || 0 ) +
					'&root=' + ( parseInt( root.getAttribute( 'data-pv-root' ), 10 ) || 0 );
			} );
		}

		/* ── Accessibility: keyboard navigation for the folder tree ──────────────
		   WAI-ARIA tree pattern: a single tab stop (roving tabindex) with arrow
		   keys to move between folders. Enter/Space activate natively (buttons). */
		var sidebar = root.querySelector( '.pv-doclib__sidebar' );
		if ( sidebar ) {
			var treeBtns = function () {
				return Array.prototype.slice.call( root.querySelectorAll( '.pv-doclib__tree-btn' ) );
			};
			var setRoving = function ( active ) {
				treeBtns().forEach( function ( b ) { b.setAttribute( 'tabindex', b === active ? '0' : '-1' ); } );
			};
			var initial = root.querySelector( '.pv-doclib__tree-btn.is-active' ) || root.querySelector( '.pv-doclib__tree-btn' );
			if ( initial ) { setRoving( initial ); }
			treeBtns().forEach( function ( b ) { b.addEventListener( 'focus', function () { setRoving( b ); } ); } );

			sidebar.addEventListener( 'keydown', function ( e ) {
				var btns = treeBtns();
				var i = btns.indexOf( document.activeElement );
				if ( i < 0 ) { return; }
				var next;
				switch ( e.key ) {
					case 'ArrowDown': next = btns[ Math.min( i + 1, btns.length - 1 ) ]; break;
					case 'ArrowUp':   next = btns[ Math.max( i - 1, 0 ) ]; break;
					case 'Home':      next = btns[ 0 ]; break;
					case 'End':       next = btns[ btns.length - 1 ]; break;
					default: return;
				}
				e.preventDefault();
				if ( next ) { next.focus(); }
			} );
		}
	}

	function boot() { document.querySelectorAll( '.pv-doclib' ).forEach( init ); }

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
