<?php
/**
 * Media Replace — swap a file in-place, keeping the same URL, post ID, and folder assignments.
 *
 * REST endpoint: POST /rayetun-medianest/v1/attachments/{id}/replace
 * Admin UI:      "Replace File" button injected into the attachment edit modal via
 *                attachment_fields_to_edit.
 *
 * Flow:
 *  1. User clicks "Choose Replacement File" in the media modal.
 *  2. JS sends the file as multipart/form-data to the REST endpoint.
 *  3. PHP validates permissions, checks locked folders, verifies MIME type.
 *  4. Old thumbnail sizes are deleted; the original file is overwritten in place.
 *  5. WP regenerates attachment metadata and thumbnails.
 *  6. _rmn_filesize postmeta is refreshed so Smart Folders counts stay accurate.
 *  7. Common page-cache and object-cache purge hooks are fired.
 *  8. REST response includes url_nocache — original URL + ?_mn_cb=<timestamp> — so
 *     the admin JS can immediately display the new image without a page reload.
 *     This parameter is NOT persisted anywhere; the canonical attachment URL stays clean.
 *
 * ── Why the URL does not change ──────────────────────────────────────────────
 * The attachment URL intentionally stays unchanged after a replace.  Appending a
 * permanent version query string (e.g. ?_v=…) via wp_get_attachment_url would:
 *  • Break hardcoded <img> src values stored in Gutenberg block attributes, Classic
 *    Editor content, widget HTML, theme templates, etc. — those reference the clean URL.
 *  • Not reliably bust CDN caches whose key ignores query strings (Cloudflare Page Rules,
 *    nginx proxy_cache_key $uri, etc.).
 *
 * If your host or CDN caches static files aggressively, purge the cache for the
 * attachment URL after replacing.  The file on the origin server is always current.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Media_Replace {

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init',             array( __CLASS__, 'register_routes' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_replace_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts',     array( __CLASS__, 'enqueue_assets' ) );
	}

	// ── REST route ────────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route(
			'rayetun-medianest/v1',
			'/attachments/(?P<attachment_id>[\d]+)/replace',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_replace' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['attachment_id'] );
				},
				'args'                => array(
					'attachment_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	// ── Handler ───────────────────────────────────────────────────────────────

	public static function handle_replace( WP_REST_Request $request ) {
		$attachment_id = (int) $request['attachment_id'];

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		// Refuse if the attachment lives in a locked folder.
		$term_ids = wp_get_object_terms( $attachment_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $term_ids ) ) {
			foreach ( $term_ids as $tid ) {
				$folder = RayetunMediaNest_DB::get_folder_by_term_id( (int) $tid );
				if ( $folder && ! empty( $folder->is_locked ) ) {
					return new WP_Error(
						'folder_locked',
						__( 'This file is in a locked folder and cannot be replaced.', 'pixelvault' ),
						array( 'status' => 423 )
					);
				}
			}
		}

		// Validate the uploaded file.
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== (int) $files['file']['error'] ) {
			return new WP_Error( 'no_file', __( 'No file uploaded or the upload failed.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$uploaded = $files['file'];

		// Use WP's content-aware MIME checker (reads file header, not just extension).
		$filetype = wp_check_filetype_and_ext( $uploaded['tmp_name'], $uploaded['name'] );
		if ( empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_mime', __( 'The uploaded file type is not allowed on this site.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$original_file = get_attached_file( $attachment_id );
		if ( ! $original_file ) {
			return new WP_Error( 'file_not_found', __( 'Original file path could not be determined.', 'pixelvault' ), array( 'status' => 500 ) );
		}

		// Delete old intermediate image sizes so no orphaned thumbnails remain.
		$existing_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $existing_meta['sizes'] ) ) {
			$dir = dirname( $original_file );
			foreach ( $existing_meta['sizes'] as $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$thumb_path = $dir . DIRECTORY_SEPARATOR . $size_info['file'];
					if ( file_exists( $thumb_path ) ) {
						wp_delete_file( $thumb_path );
					}
				}
			}
		}

		// Overwrite the original file with the uploaded replacement using WP Filesystem API.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading binary upload temp file; no WP equivalent for tmp_name reads
		$file_content = file_get_contents( $uploaded['tmp_name'] );
		if ( false === $file_content || ! $wp_filesystem->put_contents( $original_file, $file_content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'save_failed', __( 'Failed to save the replacement file. Check folder permissions.', 'pixelvault' ), array( 'status' => 500 ) );
		}

		// Update the stored MIME type if it changed (e.g. jpg → webp).
		if ( $filetype['type'] !== get_post_mime_type( $attachment_id ) ) {
			wp_update_post( array(
				'ID'             => $attachment_id,
				'post_mime_type' => sanitize_mime_type( $filetype['type'] ),
			) );
		}

		// Regenerate thumbnails and WP attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $original_file );
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		// Refresh cached file size for Smart Folders "Large Files" count.
		update_post_meta( $attachment_id, RayetunMediaNest_Smart_Folders::FILESIZE_KEY, (int) filesize( $original_file ) );

		// Purge page-level and object caches (does not affect CDN static-file caches).
		self::purge_caches( $attachment_id );

		do_action( 'rayetun_medianest_media_replaced', $attachment_id, $original_file, $filetype['type'] );

		$base_url = wp_get_attachment_url( $attachment_id );

		return rest_ensure_response( array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			/*
			 * url           — the unchanged canonical attachment URL.
			 *                 Use this for display after any CDN cache has expired.
			 * url_nocache   — url + ?_mn_cb=<timestamp> for the JS to swap img src
			 *                 in the admin immediately after replace.  One-shot only;
			 *                 not stored anywhere permanently.
			 */
			'url'           => $base_url,
			'url_nocache'   => add_query_arg( '_mn_cb', time(), $base_url ),
			'mime_type'     => get_post_mime_type( $attachment_id ),
		) );
	}

	// ── Cache purging ─────────────────────────────────────────────────────────

	/**
	 * Fire every page-cache / object-cache clear hook that common plugins register.
	 * Note: this clears WP-level caches and page HTML caches but cannot clear a CDN
	 * static-file cache (Cloudflare, BunnyCDN, etc.) without that CDN's API key.
	 * If images still show as stale via a CDN, purge the attachment URL in your CDN
	 * dashboard or enable automatic purging in your CDN plugin's settings.
	 *
	 * @param int $attachment_id
	 */
	private static function purge_caches( int $attachment_id ) {
		// WP core object/attachment cache.
		clean_attachment_cache( $attachment_id );
		clean_post_cache( $attachment_id );

		// WP Rocket — purge the attachment page URL.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( get_permalink( $attachment_id ) ) );
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $attachment_id );
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		}
		if ( function_exists( 'w3tc_objectcache_flush' ) ) {
			w3tc_objectcache_flush();
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_post', $attachment_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party cache hook

		// Autoptimize.
		do_action( 'autoptimize_action_cachepurge' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party cache hook

		// Cloudflare plugin (official WP plugin fires this action).
		do_action( 'cloudflare_purge_by_url', array( get_the_guid( $attachment_id ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party cache hook

		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party cache hook

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Kinsta MU plugin.
		if ( isset( $GLOBALS['kinsta_cache'] ) && method_exists( $GLOBALS['kinsta_cache'], 'kinsta_clear_full_cache' ) ) {
			$GLOBALS['kinsta_cache']->kinsta_clear_full_cache();
		}
	}

	// ── Admin field ───────────────────────────────────────────────────────────

	public static function add_replace_field( array $form_fields, WP_Post $post ) {
		// Bail if feature disabled in Settings.
		if ( ! (int) RayetunMediaNest_Settings::get( 'replace_enabled', 1 ) ) {
			return $form_fields;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $form_fields;
		}

		$form_fields['rayetun_mn_replace'] = array(
			'label' => __( 'Replace File', 'pixelvault' ),
			'input' => 'html',
			'html'  => '<button type="button" class="button rayetun-mn-replace-btn" data-id="' . esc_attr( $post->ID ) . '">'
			         . esc_html__( 'Choose Replacement File', 'pixelvault' )
			         . '</button>'
			         . '<input type="file" class="rayetun-mn-replace-file" style="display:none">'
			         . '<span class="rayetun-mn-replace-status" aria-live="polite"></span>',
			'helps' => __( 'Replace the file while keeping the same URL, post ID, and folder assignments. Thumbnails are regenerated automatically.', 'pixelvault' ),
		);

		return $form_fields;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ) {
		// Bail if feature disabled in Settings.
		if ( ! (int) RayetunMediaNest_Settings::get( 'replace_enabled', 1 ) ) {
			return;
		}
		if ( ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'rayetun-medianest-media-replace',
			RAYETUN_MEDIANEST_URL . 'admin/js/rayetun-medianest-media-replace.js',
			array( 'jquery' ),
			RAYETUN_MEDIANEST_VERSION,
			true
		);

		wp_localize_script(
			'rayetun-medianest-media-replace',
			'rayetunMNReplace',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'rayetun-medianest/v1' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'strings'   => array(
					'replacing'    => __( 'Replacing…', 'pixelvault' ),
					'replaced'     => __( 'File replaced successfully.', 'pixelvault' ),
					'errorGeneric' => __( 'Something went wrong. Please try again.', 'pixelvault' ),
				),
			)
		);
	}
}
