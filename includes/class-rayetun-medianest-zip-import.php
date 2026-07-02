<?php
/**
 * ZIP Import — upload a .zip archive and extract all media files
 * directly into a MediaNest folder.
 *
 * REST endpoint: POST /rayetun-medianest/v1/zip-import
 *   Body (multipart/form-data):
 *     zip_file  — the ZIP archive
 *     folder_id — (integer) target MediaNest folder term ID (0 = no folder)
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Zip_Import {

	/** Maximum ZIP file size accepted (50 MB). */
	const MAX_ZIP_BYTES = 52428800;

	/** MIME type prefixes accepted for individual extracted files. */
	const ALLOWED_PREFIXES = array( 'image/', 'video/', 'audio/' );

	/** Exact MIME types accepted for individual extracted files. */
	const ALLOWED_EXACT = array( 'application/pdf' );

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'rayetun-medianest/v1', '/zip-import', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => function () { return current_user_can( 'upload_files' ); },
		) );
	}

	// ── Handler ───────────────────────────────────────────────────────────────

	public static function handle( WP_REST_Request $request ) {
		// ZipArchive extension is required.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'no_ziparchive',
				__( 'PHP ZipArchive extension is not available on this server.', 'pixelvault' ),
				array( 'status' => 500 )
			);
		}

		// Validate uploaded file.
		$files = $request->get_file_params();
		if ( empty( $files['zip_file'] ) || empty( $files['zip_file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No ZIP file received.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$file     = $files['zip_file'];
		$tmp_name = (string) $file['tmp_name'];

		// Size check.
		$file_size = isset( $file['size'] ) ? (int) $file['size'] : @filesize( $tmp_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $file_size > self::MAX_ZIP_BYTES ) {
			return new WP_Error(
				'file_too_large',
				/* translators: %s: maximum allowed size */
				sprintf( __( 'ZIP file exceeds the %s limit.', 'pixelvault' ), size_format( self::MAX_ZIP_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		// Extension check — must end in .zip.
		$original_name = sanitize_file_name( wp_unslash( $file['name'] ?? 'upload.zip' ) );
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( 'zip' !== $ext ) {
			return new WP_Error( 'not_a_zip', __( 'Uploaded file must be a .zip archive.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$folder_id = absint( $request->get_param( 'folder_id' ) );

		// Enforce target-folder permission and lock status before doing any work.
		if ( $folder_id > 0 ) {
			if ( ! RayetunMediaNest_Folder_Service::current_user_can_manage_folder( $folder_id ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to import into this folder.', 'pixelvault' ), array( 'status' => 403 ) );
			}
			if ( RayetunMediaNest_Folder_Service::is_folder_locked( $folder_id ) ) {
				return new WP_Error( 'folder_locked', __( 'The target folder is locked.', 'pixelvault' ), array( 'status' => 423 ) );
			}
		}

		// Extract to a temporary directory inside WP uploads.
		$upload_dir  = wp_upload_dir();
		$extract_dir = trailingslashit( $upload_dir['basedir'] ) . 'mn-zip-' . wp_unique_id();

		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create temporary directory for extraction.', 'pixelvault' ), array( 'status' => 500 ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_name ) ) {
			self::cleanup_dir( $extract_dir );
			return new WP_Error( 'zip_open_failed', __( 'Could not open the ZIP archive. The file may be corrupt.', 'pixelvault' ), array( 'status' => 422 ) );
		}
		$zip->extractTo( $extract_dir );
		$zip->close();

		// Sideload each extracted file.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( self::list_files( $extract_dir ) as $filepath ) {
			$basename = basename( $filepath );

			// Skip hidden files, macOS metadata, and directory entries.
			if ( '.' === $basename[0] || 0 === strpos( $basename, '__MACOSX' ) ) {
				$skipped++;
				continue;
			}

			// Only import allowed MIME types.
			$filetype = wp_check_filetype( $basename );
			$mime     = $filetype['type'] ?? '';
			if ( ! self::is_allowed_mime( $mime ) ) {
				$skipped++;
				continue;
			}

			// media_handle_sideload() with test_form:false accepts any file path.
			$attachment_id = media_handle_sideload(
				array( 'name' => $basename, 'tmp_name' => $filepath ),
				0  // post parent = none
			);

			if ( is_wp_error( $attachment_id ) ) {
				$errors++;
				continue;
			}

			// Assign to target folder.
			if ( $folder_id > 0 ) {
				$current = wp_get_object_terms( $attachment_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
				$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );
				if ( ! in_array( $folder_id, $current, true ) ) {
					$current[] = $folder_id;
					wp_set_object_terms( $attachment_id, $current, RayetunMediaNest_Taxonomy::TAXONOMY );
					clean_object_term_cache( array( $attachment_id ), RayetunMediaNest_Taxonomy::TAXONOMY );
				}
			}

			$imported++;
		}

		self::cleanup_dir( $extract_dir );

		if ( $imported > 0 ) {
			do_action( 'rayetun_medianest_media_changed' );
		}

		return rest_ensure_response( array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Determine whether a MIME type is allowed for import.
	 */
	private static function is_allowed_mime( string $mime ): bool {
		if ( empty( $mime ) ) { return false; }
		foreach ( self::ALLOWED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $mime, $prefix ) ) { return true; }
		}
		return in_array( $mime, self::ALLOWED_EXACT, true );
	}

	/**
	 * Recursively list all files under $dir, skipping directory entries.
	 *
	 * @param string $dir
	 * @return string[]
	 */
	private static function list_files( string $dir ): array {
		$result = array();
		if ( ! is_dir( $dir ) ) { return $result; }

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$result[] = $file->getRealPath();
			}
		}
		return $result;
	}

	/**
	 * Recursively remove a directory and all its contents using WP_Filesystem.
	 *
	 * @param string $dir Absolute path to directory to remove.
	 */
	private static function cleanup_dir( string $dir ) {
		if ( ! is_dir( $dir ) ) { return; }

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
