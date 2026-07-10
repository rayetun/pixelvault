<?php
/**
 * ZIP Import — upload a .zip archive and extract all media files
 * directly into a MediaNest folder.
 *
 * Two-step, CHUNKED flow so large archives (hundreds/thousands of files) can
 * never time out in a single request:
 *
 *   1. POST /rayetun-medianest/v1/zip-import/start
 *        Body (multipart/form-data): zip_file, folder_id
 *        → validates + extracts the archive once, builds a manifest of the
 *          importable files, stores it in a per-user transient, and returns
 *          { session, total, skipped }.
 *
 *   2. POST /rayetun-medianest/v1/zip-import/batch
 *        Body (JSON): { session, offset }
 *        → sideloads the next BATCH_SIZE files and returns running counts +
 *          { next_offset, done }. The client loops until done. On the final
 *          batch the temp directory is removed and the transient deleted.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Zip_Import {

	/** Maximum ZIP file size accepted (50 MB). */
	const MAX_ZIP_BYTES = 52428800;

	/** Maximum TOTAL uncompressed size allowed (zip-bomb guard, 500 MB). */
	const MAX_UNCOMPRESSED_BYTES = 524288000;

	/** Hard cap on the number of entries in the archive (zip-bomb guard). */
	const MAX_ENTRIES = 3000;

	/** Files sideloaded per /batch request — kept small to stay well under any timeout. */
	const BATCH_SIZE = 12;

	/** How long an import session (temp dir + manifest) lives before it's abandoned. */
	const SESSION_TTL = HOUR_IN_SECONDS;

	/** MIME type prefixes accepted for individual extracted files. */
	const ALLOWED_PREFIXES = array( 'image/', 'video/', 'audio/' );

	/** Exact MIME types accepted for individual extracted files. */
	const ALLOWED_EXACT = array( 'application/pdf' );

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$perm = function () { return current_user_can( 'upload_files' ); };

		register_rest_route( 'rayetun-medianest/v1', '/zip-import/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_start' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( 'rayetun-medianest/v1', '/zip-import/batch', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_batch' ),
			'permission_callback' => $perm,
		) );
	}

	// ── Step 1: upload + extract + manifest ─────────────────────────────────────

	public static function handle_start( WP_REST_Request $request ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'PHP ZipArchive extension is not available on this server.', 'pixelvault' ), array( 'status' => 500 ) );
		}

		// Clear any abandoned import directories from previous sessions.
		self::gc_stale_dirs();

		// Validate uploaded file.
		$files = $request->get_file_params();

		if ( empty( $files['zip_file'] ) ) {
			/* PHP silently discards the whole POST (so $_FILES is empty) when the
			   request body exceeds post_max_size. Detect that so we can show the
			   real reason instead of a misleading "no file" message. */
			$content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
			$server_max     = self::server_max_upload_bytes();
			if ( $content_length > 0 && $server_max > 0 && $content_length > $server_max ) {
				return new WP_Error(
					'file_too_large',
					/* translators: %s: server's maximum upload size */
					sprintf( __( 'The ZIP is larger than this server accepts (max %s). Ask your host to raise the upload limit, or split the archive into smaller ones.', 'pixelvault' ), size_format( $server_max ) ),
					array( 'status' => 400 )
				);
			}
			return new WP_Error( 'no_file', __( 'No ZIP file received.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$file = $files['zip_file'];

		// Surface PHP upload error codes with a clear message.
		$upload_err = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_OK !== $upload_err ) {
			if ( UPLOAD_ERR_INI_SIZE === $upload_err || UPLOAD_ERR_FORM_SIZE === $upload_err ) {
				$server_max = self::server_max_upload_bytes();
				return new WP_Error(
					'file_too_large',
					/* translators: %s: server's maximum upload size */
					sprintf( __( 'The ZIP is larger than this server accepts (max %s). Ask your host to raise the upload limit, or split the archive.', 'pixelvault' ), size_format( $server_max ) ),
					array( 'status' => 400 )
				);
			}
			return new WP_Error( 'upload_incomplete', __( 'The ZIP upload did not complete. Please try again.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No ZIP file received.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$tmp_name = (string) $file['tmp_name'];

		$file_size = isset( $file['size'] ) ? (int) $file['size'] : @filesize( $tmp_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $file_size > self::MAX_ZIP_BYTES ) {
			return new WP_Error(
				'file_too_large',
				/* translators: %s: maximum allowed size */
				sprintf( __( 'ZIP file exceeds the %s limit.', 'pixelvault' ), size_format( self::MAX_ZIP_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		$original_name = sanitize_file_name( wp_unslash( $file['name'] ?? 'upload.zip' ) );
		if ( 'zip' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'not_a_zip', __( 'Uploaded file must be a .zip archive.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$folder_id = absint( $request->get_param( 'folder_id' ) );
		if ( $folder_id > 0 ) {
			if ( ! RayetunMediaNest_Folder_Service::current_user_can_manage_folder( $folder_id ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to import into this folder.', 'pixelvault' ), array( 'status' => 403 ) );
			}
			if ( RayetunMediaNest_Folder_Service::is_folder_locked( $folder_id ) ) {
				return new WP_Error( 'folder_locked', __( 'The target folder is locked.', 'pixelvault' ), array( 'status' => 423 ) );
			}
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_name ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open the ZIP archive. The file may be corrupt.', 'pixelvault' ), array( 'status' => 422 ) );
		}

		// ── Zip-bomb guard: check entry count + total uncompressed size BEFORE extracting.
		$num_entries = $zip->numFiles;
		if ( $num_entries > self::MAX_ENTRIES ) {
			$zip->close();
			return new WP_Error(
				'too_many_files',
				/* translators: %d: maximum number of files */
				sprintf( __( 'This archive has too many files. The limit is %d per import.', 'pixelvault' ), self::MAX_ENTRIES ),
				array( 'status' => 400 )
			);
		}
		$total_uncompressed = 0;
		for ( $i = 0; $i < $num_entries; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false !== $stat ) {
				$total_uncompressed += (int) ( $stat['size'] ?? 0 );
			}
		}
		if ( $total_uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
			$zip->close();
			return new WP_Error(
				'uncompressed_too_large',
				/* translators: %s: maximum uncompressed size */
				sprintf( __( 'The archive is too large once unpacked (limit %s). It may be a compression bomb.', 'pixelvault' ), size_format( self::MAX_UNCOMPRESSED_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		// Extract to a unique temporary directory inside WP uploads.
		$upload_dir  = wp_upload_dir();
		$extract_dir = trailingslashit( $upload_dir['basedir'] ) . 'mn-zip-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			$zip->close();
			return new WP_Error( 'mkdir_failed', __( 'Could not create temporary directory for extraction.', 'pixelvault' ), array( 'status' => 500 ) );
		}
		$zip->extractTo( $extract_dir );
		$zip->close();

		// Build the manifest of importable files; count junk/disallowed as skipped now.
		$importable = array();
		$skipped    = 0;
		foreach ( self::list_files( $extract_dir ) as $filepath ) {
			$basename = basename( $filepath );
			if ( '' === $basename || '.' === $basename[0] || 0 === strpos( $basename, '__MACOSX' ) ) {
				$skipped++;
				continue;
			}
			$filetype = wp_check_filetype( $basename );
			if ( ! self::is_allowed_mime( (string) ( $filetype['type'] ?? '' ) ) ) {
				$skipped++;
				continue;
			}
			$importable[] = $filepath;
		}

		// Nothing to do — clean up immediately.
		if ( empty( $importable ) ) {
			self::cleanup_dir( $extract_dir );
			return rest_ensure_response( array(
				'session'  => '',
				'total'    => 0,
				'skipped'  => $skipped,
			) );
		}

		$session = wp_generate_password( 24, false );
		set_transient( 'rayetun_mn_zip_' . $session, array(
			'dir'       => $extract_dir,
			'files'     => array_values( $importable ),
			'folder_id' => $folder_id,
			'user_id'   => get_current_user_id(),
			'created'   => time(),
		), self::SESSION_TTL );

		return rest_ensure_response( array(
			'session' => $session,
			'total'   => count( $importable ),
			'skipped' => $skipped,
		) );
	}

	// ── Step 2: process one batch ───────────────────────────────────────────────

	public static function handle_batch( WP_REST_Request $request ) {
		$session = sanitize_text_field( (string) $request->get_param( 'session' ) );
		$offset  = max( 0, (int) $request->get_param( 'offset' ) );

		$key   = 'rayetun_mn_zip_' . $session;
		$state = $session ? get_transient( $key ) : false;

		if ( ! is_array( $state ) || empty( $state['dir'] ) || ! isset( $state['files'] ) ) {
			return new WP_Error( 'invalid_session', __( 'This import session has expired. Please start again.', 'pixelvault' ), array( 'status' => 410 ) );
		}
		// Sessions are strictly per-user.
		if ( (int) ( $state['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'forbidden', __( 'You do not have access to this import session.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		// Give the heavy image work room to run.
		if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'image' ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$files     = (array) $state['files'];
		$total     = count( $files );
		$folder_id = (int) ( $state['folder_id'] ?? 0 );
		$slice     = array_slice( $files, $offset, self::BATCH_SIZE );

		$imported = 0;
		$errors   = 0;

		foreach ( $slice as $filepath ) {
			if ( ! is_string( $filepath ) || ! is_file( $filepath ) ) {
				$errors++;
				continue;
			}
			$attachment_id = media_handle_sideload(
				array( 'name' => basename( $filepath ), 'tmp_name' => $filepath ),
				0
			);
			if ( is_wp_error( $attachment_id ) ) {
				$errors++;
				continue;
			}
			if ( $folder_id > 0 ) {
				self::assign_to_folder( (int) $attachment_id, $folder_id );
			}
			$imported++;
		}

		$next_offset = $offset + count( $slice );
		$done        = ( $next_offset >= $total ) || empty( $slice );

		if ( $done ) {
			self::cleanup_dir( (string) $state['dir'] );
			delete_transient( $key );
			if ( $imported > 0 || $offset > 0 ) {
				do_action( 'rayetun_medianest_media_changed' );
			}
		}

		return rest_ensure_response( array(
			'imported'    => $imported,
			'skipped'     => 0,
			'errors'      => $errors,
			'next_offset' => $next_offset,
			'total'       => $total,
			'done'        => $done,
		) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Assign an attachment to a target folder (idempotent).
	 */
	private static function assign_to_folder( int $attachment_id, int $folder_id ) {
		$current = wp_get_object_terms( $attachment_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );
		if ( ! in_array( $folder_id, $current, true ) ) {
			$current[] = $folder_id;
			wp_set_object_terms( $attachment_id, $current, RayetunMediaNest_Taxonomy::TAXONOMY );
			clean_object_term_cache( array( $attachment_id ), RayetunMediaNest_Taxonomy::TAXONOMY );
		}
	}

	/**
	 * The effective maximum upload size the server will accept — the smaller of
	 * upload_max_filesize and post_max_size (as computed by WordPress core).
	 *
	 * @return int Bytes, or 0 if it can't be determined.
	 */
	private static function server_max_upload_bytes(): int {
		if ( function_exists( 'wp_max_upload_size' ) ) {
			$max = (int) wp_max_upload_size();
			if ( $max > 0 ) { return $max; }
		}
		return 0;
	}

	/**
	 * Determine whether a MIME type is allowed for import.
	 */
	private static function is_allowed_mime( string $mime ): bool {
		if ( '' === $mime ) { return false; }
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
	 * Remove any leftover mn-zip-* extraction directories older than the session
	 * TTL. Guards against temp dirs leaking when an import is abandoned or a
	 * request dies mid-way.
	 */
	private static function gc_stale_dirs() {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );
		$cutoff     = time() - ( 2 * self::SESSION_TTL );

		foreach ( (array) glob( $base . 'mn-zip-*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_dir( $dir ) && (int) filemtime( $dir ) < $cutoff ) {
				self::cleanup_dir( $dir );
			}
		}
	}

	/**
	 * Recursively remove a directory and all its contents using WP_Filesystem.
	 *
	 * @param string $dir Absolute path to directory to remove.
	 */
	private static function cleanup_dir( string $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) { return; }

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
