# PixelVault — Developer Hooks Reference

PixelVault exposes PHP actions/filters and JavaScript hooks so add-ons (such as
**PixelVault Pro**) can extend it without modifying core files.

> **Note on prefixes:** internal hook names use the historical `rayetun_medianest_`
> prefix (kept stable so existing integrations never break). JavaScript hooks use
> the `pixelvault.` namespace via the `@wordpress/hooks` package.

---

## PHP Actions

| Action | Arguments | Fires when |
|---|---|---|
| `rayetun_medianest_folder_created` | `$term_id, $folder_id, $args` | A folder is created |
| `rayetun_medianest_folder_renamed` | `$term_id, $new_name` | A folder is renamed |
| `rayetun_medianest_folder_moved` | `$term_id, $new_parent, $old_parent` | A folder is moved to a new parent |
| `rayetun_medianest_folder_meta_updated` | `$term_id, $sanitized` | A folder's colour/icon/visibility/roles change |
| `rayetun_medianest_folder_deleted` | `$term_id, $folder` | A folder is deleted |
| `rayetun_medianest_folders_reordered` | `$ordered_term_ids` | Folder sort order changes |
| `rayetun_medianest_folder_lock_changed` | `$term_id, $locked` | A folder is locked/unlocked |
| `rayetun_medianest_attachment_assigned` | `$attachment_id, $term_ids, $context` | A file is assigned to folder(s). `$context` = `'manual'` \| `'auto_assign'` |
| `rayetun_medianest_attachments_removed` | `$term_id, $attachment_ids` | Files are removed from a specific folder |
| `rayetun_medianest_bulk_assigned` | `$attachment_ids, $term_ids` | Bulk assignment completes |
| `rayetun_medianest_media_changed` | *(none)* | Generic "media changed, flush caches" signal (bulk ops, imports, deletes) |
| `rayetun_medianest_alt_text_saved` | `$attachment_id, $alt_text` | Alt text is saved via the Bulk Alt Text editor |
| `rayetun_medianest_media_replaced` | `$attachment_id, $original_file, $mime` | A file is replaced in place |
| `rayetun_medianest_folder_meta_save` | `$term_id, $request` | After a folder is created/updated via REST — persist custom fields here |
| `rayetun_medianest_render_settings_panels` | `$settings` | Renders the settings page — output custom `<div data-panel="…">` panels here |

### Example — log every folder deletion
```php
add_action( 'rayetun_medianest_folder_deleted', function ( $term_id, $folder ) {
    error_log( 'PixelVault folder deleted: ' . $term_id );
}, 10, 2 );
```

---

## PHP Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `rayetun_medianest_get_folders` | `$folders, $post_type` | Modify/enrich the folder list before it is returned |
| `rayetun_medianest_settings_defaults` | `$defaults` | Register default option values |
| `rayetun_medianest_settings_tabs` | `$tabs, $settings` | Add sidebar tabs to the settings page |
| `rayetun_medianest_dashboard_features` | `$features, $settings` | Add cards to the dashboard "Active Features" grid |
| `rayetun_medianest_smart_folders` | `$defs` | Register custom dynamic (smart) folders |
| `rayetun_medianest_smart_folder_counts` | `$counts` | Provide the count for a custom smart folder |
| `rayetun_medianest_smart_folder_query_args` | `$args, $type` | Filter the media grid when a custom smart folder is active |
| `rayetun_medianest_auto_assign_target` | `$term_id, $attachment_id` | Override the folder a new upload is assigned to (rules engines) |
| `rayetun_medianest_folder_rest_args` | `$args, $op` | Register custom REST fields on folder create/update (`$op` = `'create'`\|`'update'`) |

### Example — register a custom "Duplicates" smart folder
```php
add_filter( 'rayetun_medianest_smart_folders', function ( $defs ) {
    $defs['duplicates'] = array(
        'label'       => 'Duplicates',
        'icon'        => '&#128203;',
        'description' => 'Files with identical content.',
    );
    return $defs;
} );
add_filter( 'rayetun_medianest_smart_folder_counts', function ( $counts ) {
    $counts['duplicates'] = my_count_duplicates();
    return $counts;
} );
add_filter( 'rayetun_medianest_smart_folder_query_args', function ( $args, $type ) {
    if ( 'duplicates' === $type ) {
        $args['post__in'] = my_duplicate_ids();
    }
    return $args;
}, 10, 2 );
```

### Example — auto-assign rule (rules engine backbone)
```php
add_filter( 'rayetun_medianest_auto_assign_target', function ( $term_id, $attachment_id ) {
    $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( false !== stripos( (string) $file, 'invoice' ) ) {
        return my_get_folder_id( 'Invoices' );
    }
    return $term_id; // leave unchanged
}, 10, 2 );
```

---

## JavaScript Hooks (`@wordpress/hooks`)

Enqueue depends on `wp-hooks`. Register with `wp.hooks.addAction( name, namespace, callback )`.

| Action | Callback args | Purpose |
|---|---|---|
| `pixelvault.imageContextMenu` | `menu, attachmentId, { addItem }` | Add items to the media item right-click menu |
| `pixelvault.folderContextMenu` | `menu, folder, { addItem }` | Add items to the folder right-click/⋮ menu |
| `pixelvault.folderRowActions` | `aw, folder, { addBtn }` | Add inline buttons to a folder row |
| `pixelvault.bulkActions` | `actions, { makeBtn, getIds }` | Add buttons to the floating bulk-action bar |

`addItem( iconHtml, label, cssClass, onClick )` and `addBtn( iconHtml, title, onClick )`
return a DOM node already appended to the menu/row. `makeBtn( cls, html, title )` returns
a bulk-bar button; `getIds()` returns the current selection.

### Example — add an "AI Alt Text" item to the image menu
```js
wp.hooks.addAction( 'pixelvault.imageContextMenu', 'pixelvault-pro', function ( menu, id, helpers ) {
    menu.appendChild( helpers.addItem( '&#10024;', 'Generate AI Alt Text', '', function () {
        myGenerateAlt( id );
    } ) );
} );
```

### Example — add a "Bulk Rename" bulk action
```js
wp.hooks.addAction( 'pixelvault.bulkActions', 'pixelvault-pro', function ( actions, helpers ) {
    var btn = helpers.makeBtn( 'pvpro-rename', 'Rename', 'Bulk rename selected files' );
    btn.addEventListener( 'click', function () {
        myBulkRename( helpers.getIds() );
    } );
} );
```

---

## REST API

Base: `/wp-json/rayetun-medianest/v1/` — authenticate with an `X-WP-Nonce` header.
Full folder CRUD lives at `/folders`. Add-ons can register custom folder fields via
the `rayetun_medianest_folder_rest_args` filter and persist them in the
`rayetun_medianest_folder_meta_save` action.
