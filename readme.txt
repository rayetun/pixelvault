=== PixelVault — Media Library Folders ===
Contributors:      rayetun
Donate link:       https://wise.com/pay/me/mdrayhanu2
Tags:              media library, media folders, file organizer, media organizer, folders
Requires at least: 6.2
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Unlimited nested media folders for WordPress. Colour-coded folders, drag-and-drop, bulk ZIP, analytics, role permissions, one-click migration.

== Description ==

📁 **PixelVault** adds a drag-and-drop folder panel to your WordPress Media Library so you can sort images, videos, PDFs, and audio into unlimited nested folders.

Folders are **virtual** — physical files never move, no URLs change, and standard WordPress export/import works without any special steps.

= ✨ Core Features =

* 📂 **Unlimited nested folders and subfolders** — no depth limit, no folder count limit
* 🔗 **Assign files to multiple folders** — one file can appear in as many folders as you like
* 🎨 **Folder colour coding** — colour-label folders for instant visual orientation
* 🖱️ **Drag-and-drop sidebar** — reorder folders, move files, and create subfolders by dragging
* ⚡ **Auto-assign uploads** — new files go straight into whichever folder is selected
* 🔍 **Search and sort** — find folders instantly; sort manually, by name, or by file count
* ⌨️ **Keyboard shortcut** — press "n" to create a new folder
* 🖲️ **Right-click context menu** — Rename, Delete, Lock, Create subfolders, Copy gallery shortcode, Import ZIP to folder, Download all files
* 🌐 **RTL language support** — full right-to-left layout

= 🛠️ Organisation and Productivity Tools =

**📦 ZIP File Import**
Right-click any folder and choose "Import ZIP to folder". Upload a .zip archive and PixelVault extracts and assigns every image, video, audio file, and PDF directly into that folder.

**🗺️ Media Usage Map**
Right-click any image and choose "Where is this used?" to see every post, page, or custom post type that references the file — useful before replacing or deleting files.

**⬇️ Bulk Download as ZIP**
Right-click any folder to download all its files as a ZIP archive.

**🔒 Folder Locking**
Lock any folder to prevent accidental renaming, deletion, moving, or file reassignment. Locked folders display a padlock badge.

**📊 Storage Analytics**
Full storage overview with total size, average file size, file-type breakdown chart, top-10 largest files, and per-folder size distribution. Available under **Settings → Analytics** and as a WordPress Dashboard widget.

**✏️ Bulk Alt Text Editor**
Batch-edit alt text on all images that are missing it — useful for accessibility and image SEO. Edit multiple files at once without opening each one individually.

**🔄 Media Replace**
Swap any file in place, keeping the same URL and attachment ID. All existing references on posts and pages continue to work automatically.

**🎯 Smart Filters**
Dynamic built-in views that update automatically: Missing Alt Text, Unused Files, Recent Uploads, and Large Files.

**🗂️ Auto-Categorise Existing Media**
Bulk-organise your existing media library in one click via the import icon at the bottom of the folder panel. Choose a rule:

* 📅 **By Year** — creates a folder per year (e.g. *2023*, *2024*).
* 🗓️ **By Month** — creates a folder per month (e.g. *January 2024*, *February 2024*).
* 🏷️ **By File Type** — creates *Images*, *Videos*, *Audio*, *PDFs*, *Documents*, and *Spreadsheets* folders.

Choose **Unassigned only** to leave already-organised files untouched, or **All files** to reassign everything. PixelVault previews the result before making any changes.

**🚚 Import from Other Plugins**
One-click migration from FileBird, Real Media Library, WP Media Folder, and Enhanced Media Library. Folder hierarchy and all file assignments are copied automatically. Go to **Settings → Tools → Import from Another Plugin**. The original plugin's data is never modified.

**💾 Export and Import**
Back up your entire folder structure as a JSON file and restore it on any WordPress site — no SQL required.

**🔐 Role Permissions**
Granular folder access per WordPress user role: control who can create, edit, and delete folders independently.

= 🧩 Gutenberg, Elementor, and Divi =

* 🖼️ **PixelVault Gallery block** — insert a folder's contents as a responsive gallery with lightbox directly in the block editor
* 📝 **[pixelvault_gallery] shortcode** — classic editor, widgets, and theme template support
* 🎛️ **Elementor** — folder panel works inside the Elementor media modal
* 🏗️ **Divi** — folder panel works in the Divi backend builder and the Divi Front End Builder
* 🌍 **Universal compatibility** — PixelVault hooks into WordPress's native media modal, so it works with any theme or page builder

= 🏛️ Architecture: Virtual Taxonomy =

PixelVault stores folder assignments in WordPress's native taxonomy tables (`wp_term_relationships`). Physical files stay exactly where they are. Deleting a folder never deletes files.

= 👩‍💻 For Developers =

Full REST API and documented action/filter hooks.

**🔌 REST API** — Full CRUD at `/wp-json/rayetun-medianest/v1/folders`. Authenticate with `X-WP-Nonce`.

**⚙️ Action hooks:**

* `medianest_folder_created( $term_id, $folder_id, $args )`
* `medianest_folder_renamed( $term_id, $new_name )`
* `medianest_folder_deleted( $term_id, $folder_object )`
* `medianest_folders_reordered( $ordered_term_ids )`
* `medianest_attachment_assigned( $attachment_id, $term_ids )`

**🔧 Filter hooks:**

* `medianest_get_folders( $folders, $post_type )` — modify the folder list before it is returned

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Media → Library** — the folder panel appears on the left.

No configuration is required to get started.

== Frequently Asked Questions ==

= ❓ Will this break my media URLs or move my files? =

No. PixelVault uses virtual folders — it only stores a taxonomy relationship between your attachment and a folder label. Physical files are never moved. No URLs change.

= 🔗 Can one file appear in multiple folders? =

Yes. PixelVault supports many-to-many assignment — the same file can appear in as many folders as you like.

= 🗑️ What happens to my files if I delete a folder? =

Your files are completely unaffected. They remain in the media library; only the folder assignment is removed. Child folders are automatically moved up to the deleted folder's parent.

= ♻️ What happens if I uninstall PixelVault? =

All folder data is removed cleanly on uninstall: taxonomy terms, term relationships, and the plugin's custom table are deleted. Your media files are never touched.

= 🔌 Is there a REST API? =

Yes. Full CRUD is available at `/wp-json/rayetun-medianest/v1/folders`. Authenticate with a standard `X-WP-Nonce` header.

= 🚚 Can I import my folders from FileBird, Real Media Library, WP Media Folder, or Enhanced Media Library? =

Yes — all four are supported. Go to **Settings → Tools → Import from Another Plugin**, scan for importable plugins, and click Import. The original plugin's data is never modified or deleted.

= 🧩 Does it work with Elementor, Divi, and Gutenberg? =

Yes. The folder panel works inside Elementor's media modal, in both the Divi back-end and front-end builders, and the Gutenberg media uploader. There is also a dedicated PixelVault Gallery Gutenberg block.

= 💬 Get Support =

Post in the [WordPress.org support forum](https://wordpress.org/support/plugin/pixelvault/). We aim to respond within 24 hours on business days.

== Screenshots ==

1. Folder sidebar in the WordPress Media Library — unlimited nested folders with colour coding.
2. Right-click context menu — rename, delete, lock, download ZIP, import ZIP, and more.
3. Folder colour picker — visually organise at a glance.
4. Storage Analytics — per-folder breakdown, file-type chart, and top-10 largest files.
5. Import from another plugin — one-click migration from FileBird, Real Media Library, WP Media Folder, and Enhanced Media Library.
6. Media Usage Map — see every post and page that references a file.
7. Bulk Alt Text Editor — batch-edit missing alt text across your entire library.

== Changelog ==

= 🎉 1.0.0 =
* Initial release.
* Unlimited nested virtual folders with colour coding and drag-and-drop sidebar.
* Right-click context menu: rename, delete, lock/unlock, subfolder creation, gallery shortcode copy, ZIP import, usage map, bulk download.
* Keyboard shortcut: press n to create a new folder.
* Auto-assign new uploads to the selected folder.
* Search and sort controls (manual, A-Z, Z-A, file count).
* Role-based folder visibility and granular role permissions panel.
* Folder Locking with padlock badge.
* Bulk Download as ZIP — download any folder's contents as a zip archive.
* ZIP File Import — upload a zip and extract media directly into a folder.
* Media Usage Map — see every post and page that references any image.
* Import from other plugins — one-click migration from FileBird (v5 and v6), Real Media Library, WP Media Folder, and Enhanced Media Library.
* Auto-Categorise Existing Media — bulk-assign unorganised files into folders by year, month, or file type.
* Storage Analytics — overview dashboard, file-type chart, and per-folder size breakdown.
* Bulk Alt Text Editor — batch-edit missing alt text.
* Media Replace — swap any file in place, preserving its URL and attachment ID.
* Smart Filters — Missing Alt Text, Unused Files, Recent Uploads, Large Files.
* PixelVault Gallery Gutenberg block with lightbox support.
* [pixelvault_gallery] shortcode for classic editor and theme templates.
* Elementor media modal integration.
* Divi backend and frontend builder integration.
* REST API — full CRUD at /wp-json/rayetun-medianest/v1/folders.
* AJAX fallback for environments where the REST API is restricted.
* JSON Export and Import for folder structure backup and restore.
* RTL language support.
* Documented action and filter hooks for developers.
* No external services, no data collection.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
