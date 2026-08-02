/**
 * PixelVault — Document Library block (editor).
 *
 * Schema-driven: every InspectorControl is generated from pvDoclibConfig.schema
 * (localized from PHP), so adding an option is a one-line schema change with no
 * editor code. No-JSX vanilla wp.element, matching the plugin convention.
 */
( function ( blocks, element, blockEditor, components, i18n, apiFetch, ssr ) {
	var el = element.createElement;
	var __ = i18n.__;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var ServerSideRender = ssr;

	var CFG = window.pvDoclibConfig || { schema: [], restUrl: '', nonce: '' };

	/* Human labels for the option panels. */
	var PANELS = {
		source:  __( 'Source', 'pixelvault' ),
		design:  __( 'Design', 'pixelvault' ),
		display: __( 'Display', 'pixelvault' )
	};

	/* Render one control from a schema row. */
	function control( row, attrs, setAttrs, folderOptions ) {
		var key = row.key;
		var value = attrs[ key ];
		var onChange = function ( v ) { var u = {}; u[ key ] = v; setAttrs( u ); };

		if ( row.type === 'toggle' ) {
			return el( ToggleControl, { key: key, label: row.label, checked: !!value, onChange: onChange } );
		}
		if ( row.type === 'range' ) {
			return el( RangeControl, { key: key, label: row.label, value: Number( value ), min: row.min || 1, max: row.max || 10, onChange: onChange } );
		}
		if ( row.type === 'select' || row.type === 'preset' ) {
			var opts = Object.keys( row.choices || {} ).map( function ( k ) {
				return { value: k, label: row.choices[ k ] };
			} );
			return el( SelectControl, { key: key, label: row.label, value: value, options: opts, onChange: onChange } );
		}
		if ( row.type === 'folder' ) {
			var fopts = [ { value: 0, label: __( 'All top-level folders', 'pixelvault' ) } ].concat( folderOptions );
			return el( SelectControl, {
				key: key, label: row.label, help: row.help || '',
				value: String( value ),
				options: fopts.map( function ( o ) { return { value: String( o.value ), label: o.label }; } ),
				onChange: function ( v ) { onChange( parseInt( v, 10 ) || 0 ); }
			} );
		}
		if ( row.type === 'color' ) {
			/* Lightweight color input. Inline styles because InspectorControls render
			   in the editor sidebar, outside the block's stylesheet scope. */
			return el( 'div', {
				key: key,
				style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', margin: '4px 0 20px' }
			},
				el( 'span', {
					style: { fontSize: '11px', fontWeight: 500, textTransform: 'uppercase', letterSpacing: '0.02em', color: '#1e1e1e' }
				}, row.label ),
				el( 'input', {
					type: 'color',
					value: value || '#4f46e5',
					'aria-label': row.label,
					onChange: function ( e ) { onChange( e.target.value ); },
					style: { width: '44px', height: '30px', padding: '2px', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer', flex: '0 0 auto' }
				} )
			);
		}
		return null;
	}

	blocks.registerBlockType( 'rayetun-medianest/document-library', {
		apiVersion: 3,
		title: __( 'PixelVault Document Library', 'pixelvault' ),
		description: __( 'A browsable, downloadable document library from your media folders.', 'pixelvault' ),
		icon: 'category',
		category: 'media',
		keywords: [ 'folder', 'file', 'document', 'download', 'library' ],
		supports: { align: [ 'wide', 'full' ], html: false },

		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttrs = props.setAttributes;

			var stateHook = useState( [] );
			var folderOptions = stateHook[ 0 ];
			var setFolderOptions = stateHook[ 1 ];

			/* Fetch folders once for the folder-picker control. */
			useEffect( function () {
				if ( ! CFG.restUrl ) { return; }
				apiFetch( { url: CFG.restUrl, headers: { 'X-WP-Nonce': CFG.nonce } } ).then( function ( list ) {
					var opts = ( list || [] ).map( function ( f ) {
						var depth = 0, pid = f.parent_id;
						/* cheap indent by walking parents is overkill here; keep flat labels */
						return { value: f.term_id, label: f.name };
					} );
					setFolderOptions( opts );
				} ).catch( function () {} );
			}, [] );

			/* Group schema rows by panel. */
			var byPanel = {};
			( CFG.schema || [] ).forEach( function ( row ) {
				( byPanel[ row.panel ] = byPanel[ row.panel ] || [] ).push( row );
			} );

			var panels = Object.keys( byPanel ).map( function ( pk, idx ) {
				return el( PanelBody, { key: pk, title: PANELS[ pk ] || pk, initialOpen: idx === 0 },
					byPanel[ pk ].map( function ( row ) { return control( row, attrs, setAttrs, folderOptions ); } )
				);
			} );

			var blockProps = useBlockProps();

			return el( Fragment, {},
				el( InspectorControls, {}, panels ),
				el( 'div', blockProps,
					el( ServerSideRender, {
						block: 'rayetun-medianest/document-library',
						attributes: attrs
					} )
				)
			);
		},

		save: function () { return null; } /* server-rendered */
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.apiFetch, window.wp.serverSideRender );
