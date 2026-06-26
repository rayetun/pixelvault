/* Rayetun PixelVault — Gutenberg Gallery Block (no JSX, no build step) */
(function(){
	'use strict';
	if(!window.wp||!wp.blocks||!wp.element)return;

	var el=wp.element.createElement;
	var __=wp.i18n.__;
	var registerBlockType=wp.blocks.registerBlockType;
	var InspectorControls=wp.blockEditor.InspectorControls;
	var useBlockProps=wp.blockEditor.useBlockProps;
	var ServerSideRender=wp.serverSideRender;
	var PanelBody=wp.components.PanelBody;
	var SelectControl=wp.components.SelectControl;
	var RangeControl=wp.components.RangeControl;
	var ToggleControl=wp.components.ToggleControl;
	var Placeholder=wp.components.Placeholder;
	var Spinner=wp.components.Spinner;

	var ICON=el('svg',{xmlns:'http://www.w3.org/2000/svg',viewBox:'0 0 24 24',fill:'currentColor'},
		el('path',{d:'M4 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6z'}),
		el('path',{d:'M3 15l4-4 3 3 4-5 5 6H3z'})
	);

	registerBlockType('rayetun-medianest/gallery',{
		title: __('PixelVault Gallery','pixelvault'),
		description: __('Display images from a PixelVault folder as a beautiful gallery.','pixelvault'),
		category: 'media',
		icon: ICON,
		keywords: [__('gallery'),__('folder'),__('media'),__('images'),__('pixelvault')],
		supports: { align: ['wide','full'] },
		attributes: {
			folder_id:     {type:'number',  default:0},
			columns:       {type:'number',  default:3},
			size:          {type:'string',  default:'medium'},
			aspect_ratio:  {type:'string',  default:'auto'},
			gap:           {type:'number',  default:12},
			lightbox:      {type:'boolean', default:true},
			captions:      {type:'boolean', default:false},
			orderby:       {type:'string',  default:'date'},
			order:         {type:'string',  default:'DESC'},
			limit:         {type:'number',  default:50},
		},

		edit: function(props){
			var attr=props.attributes;
			var set=props.setAttributes;
			var blockProps=useBlockProps();

			/* Load folders from REST API */
			var folders=wp.data.select('rayetun-medianest/store')&&
				wp.data.select('rayetun-medianest/store').getFolders?
				wp.data.select('rayetun-medianest/store').getFolders():null;

			/* Fallback: use apiFetch on first render */
			var storeRef=wp.element.useRef(null);
			var setFolders=wp.element.useState([])[1];
			var folderList=wp.element.useState([])[0];
			var setFolderList=wp.element.useState([])[1];

			/* Simple approach: fetch folders once */
			var _folders=wp.element.useRef([]);
			var _setFolders=wp.element.useState([])[1];
			var displayFolders=_folders.current;

			wp.element.useEffect(function(){
				wp.apiFetch({path:'/rayetun-medianest/v1/folders?format=flat'})
					.then(function(res){
						_folders.current=res||[];
						_setFolders(_folders.current);
					})
					.catch(function(){});
			},[]);

			var folderOptions=[{label:__('— Select a folder —','pixelvault'),value:0}]
				.concat(displayFolders.map(function(f){
					var prefix='';
					var p=f;
					while(p.parent_id&&p.parent_id>0){
						prefix='\u00a0\u00a0'+prefix;
						p=displayFolders.find(function(x){return x.term_id==p.parent_id;})||{parent_id:0};
					}
					return {label:prefix+f.name+(f.count?' ('+f.count+')':''),value:f.term_id};
				}));

			var inspector=el(InspectorControls,null,
				el(PanelBody,{title:__('Gallery Settings','pixelvault'),initialOpen:true},
					el(SelectControl,{
						label:__('Folder','pixelvault'),
						value:attr.folder_id,
						options:folderOptions,
						onChange:function(v){set({folder_id:parseInt(v,10)||0});}
					}),
					el(RangeControl,{
						label:__('Columns','pixelvault'),
						value:attr.columns,min:1,max:6,step:1,
						onChange:function(v){set({columns:v});}
					}),
					el(SelectControl,{
						label:__('Aspect Ratio','pixelvault'),
						value:attr.aspect_ratio,
						options:[
							{label:__('Auto (natural)','pixelvault'),value:'auto'},
							{label:__('Square (1:1)','pixelvault'),value:'square'},
							{label:__('Landscape (16:9)','pixelvault'),value:'landscape'},
							{label:__('Portrait (3:4)','pixelvault'),value:'portrait'},
						],
						onChange:function(v){set({aspect_ratio:v});}
					}),
					el(RangeControl,{
						label:__('Gap (px)','pixelvault'),
						value:attr.gap,min:0,max:40,step:2,
						onChange:function(v){set({gap:v});}
					})
				),
				el(PanelBody,{title:__('Display Options','pixelvault'),initialOpen:false},
					el(SelectControl,{
						label:__('Image Size','pixelvault'),
						value:attr.size,
						options:[
							{label:'Thumbnail (150px)',value:'thumbnail'},
							{label:'Medium (300px)',value:'medium'},
							{label:'Medium Large (768px)',value:'medium_large'},
							{label:'Large (1024px)',value:'large'},
							{label:'Full',value:'full'},
						],
						onChange:function(v){set({size:v});}
					}),
					el(ToggleControl,{
						label:__('Enable Lightbox','pixelvault'),
						checked:attr.lightbox,
						onChange:function(v){set({lightbox:v});}
					}),
					el(ToggleControl,{
						label:__('Show Captions','pixelvault'),
						checked:attr.captions,
						onChange:function(v){set({captions:v});}
					}),
					el(SelectControl,{
						label:__('Order By','pixelvault'),
						value:attr.orderby,
						options:[
							{label:'Date uploaded',value:'date'},
							{label:'Title',value:'title'},
							{label:'Random',value:'rand'},
							{label:'Menu Order',value:'menu_order'},
						],
						onChange:function(v){set({orderby:v});}
					}),
					el(SelectControl,{
						label:__('Order','pixelvault'),
						value:attr.order,
						options:[{label:'Newest first (DESC)',value:'DESC'},{label:'Oldest first (ASC)',value:'ASC'}],
						onChange:function(v){set({order:v});}
					}),
					el(RangeControl,{
						label:__('Max Images','pixelvault'),
						value:attr.limit,min:1,max:200,step:1,
						onChange:function(v){set({limit:v});}
					})
				)
			);

			if(!attr.folder_id){
				return el('div',blockProps,
					inspector,
					el(Placeholder,{
						icon:ICON,
						label:__('PixelVault Gallery','pixelvault'),
						instructions:__('Select a folder from the sidebar to display images.','pixelvault')
					})
				);
			}

			return el('div',blockProps,
				inspector,
				el(ServerSideRender,{
					block:'rayetun-medianest/gallery',
					attributes:attr
				})
			);
		},

		save:function(){return null;} /* server-side render */
	});
})();
