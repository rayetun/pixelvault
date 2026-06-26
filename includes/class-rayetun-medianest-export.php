<?php
/**
 * Folder ZIP export via admin-ajax.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Export {

	public static function register() {
		add_action( 'wp_ajax_rayetun_medianest_export_folder',    array( __CLASS__, 'export_folder' ) );
		add_action( 'wp_ajax_rayetun_medianest_export_structure', array( __CLASS__, 'export_structure' ) );
		add_action( 'wp_ajax_rayetun_mn_import_structure',        array( __CLASS__, 'import_structure' ) );
	}

	/**
	 * Export the entire folder structure as a downloadable JSON file.
	 * Streams a JSON attachment; does not touch any media files.
	 */
	public static function export_structure() {
		if ( ! check_ajax_referer( 'rayetun_medianest_ajax', 'nonce', false ) ) {
			wp_die( 'Invalid nonce.', 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', 403 );
		}

		$folders = RayetunMediaNest_DB::get_visible_folders( get_current_user_id(), 'attachment' );

		$rows = array();
		foreach ( (array) $folders as $f ) {
			$rows[] = array(
				'ref'        => (int) $f->term_id,
				'parent_ref' => (int) $f->parent_id,
				'name'       => (string) $f->name,
				'color'      => (string) $f->color,
				'icon'       => (string) $f->icon,
				'sort_order' => (int) $f->sort_order,
			);
		}

		$payload = array(
			'plugin'   => 'pixelvault',
			'version'  => RAYETUN_MEDIANEST_VERSION,
			'exported' => current_time( 'mysql' ),
			'folders'  => $rows,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pixelvault-folders-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Import a folder structure from a previously-exported JSON file.
	 * Recreates folders (name, parent, colour, icon, order). Never touches media.
	 * Folders whose name already exists under the same parent are skipped.
	 */
	public static function import_structure() {
		check_ajax_referer( 'rayetun_mn_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelvault' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated by json_decode below; never used as SQL/HTML directly.
		$raw     = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$decoded = json_decode( (string) $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || empty( $decoded['folders'] ) || ! is_array( $decoded['folders'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or empty PixelVault export file.', 'pixelvault' ) ), 400 );
		}

		$pending = $decoded['folders'];
		$map     = array( 0 => 0 ); // ref → new term_id; ref 0 = root.
		$created = 0;
		$skipped = 0;
		$guard   = 0;

		// Iterative passes: create a folder only once its parent ref is resolved.
		// Guard against malformed data with a hard cap of (count * 2) passes.
		$max_passes = ( count( $pending ) * 2 ) + 2;

		while ( ! empty( $pending ) && $guard < $max_passes ) {
			$guard++;
			$still_pending = array();

			foreach ( $pending as $row ) {
				$ref        = isset( $row['ref'] ) ? (int) $row['ref'] : 0;
				$parent_ref = isset( $row['parent_ref'] ) ? (int) $row['parent_ref'] : 0;
				$name       = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

				if ( '' === $name ) {
					$skipped++;
					continue;
				}

				// Parent not yet created → defer to a later pass.
				if ( ! array_key_exists( $parent_ref, $map ) ) {
					$still_pending[] = $row;
					continue;
				}

				$new_parent = (int) $map[ $parent_ref ];

				$result = RayetunMediaNest_Folder_Service::create(
					$name,
					array(
						'parent_id'  => $new_parent,
						'color'      => isset( $row['color'] ) ? sanitize_hex_color( $row['color'] ) : '',
						'icon'       => isset( $row['icon'] ) ? sanitize_key( $row['icon'] ) : '',
						'sort_order' => isset( $row['sort_order'] ) ? absint( $row['sort_order'] ) : 0,
						'post_type'  => 'attachment',
					)
				);

				if ( is_wp_error( $result ) ) {
					$skipped++;
					// Still record a mapping so children can attach to the intended parent
					// if the failure was a duplicate (folder already exists).
					$map[ $ref ] = $new_parent;
					continue;
				}

				$map[ $ref ] = (int) $result['term_id'];
				$created++;
			}

			// No progress this pass → remaining rows are orphaned; stop.
			if ( count( $still_pending ) === count( $pending ) ) {
				break;
			}
			$pending = $still_pending;
		}

		wp_send_json_success(
			array(
				'created' => $created,
				'skipped' => $skipped,
				/* translators: 1: number of folders created, 2: number skipped */
				'message' => sprintf( __( '%1$d folders imported, %2$d skipped.', 'pixelvault' ), $created, $skipped ),
			)
		);
	}

	public static function export_folder() {
		/* Verify nonce and capability */
		if ( ! check_ajax_referer( 'rayetun_medianest_ajax', 'nonce', false ) ) {
			wp_die( 'Invalid nonce.', 403 );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( 'Insufficient permissions.', 403 );
		}

		$term_id = absint( $_GET['folder_id'] ?? 0 );
		if ( $term_id < 1 ) { wp_die( 'Invalid folder.', 400 ); }

		$term = get_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) { wp_die( 'Folder not found.', 404 ); }

		/* Fetch all attachments in the folder */
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => array(
				array(
					'taxonomy' => RayetunMediaNest_Taxonomy::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => array( $term_id ),
					'operator' => 'IN',
				),
			),
		) );

		if ( empty( $query->posts ) ) {
			wp_die( 'This folder is empty.', 404 );
		}

		/* Require ZipArchive */
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( 'ZIP export requires the PHP ZipArchive extension. Please ask your host to enable it.', 500 );
		}

		$zip      = new ZipArchive();
		$tmp_file = tempnam( sys_get_temp_dir(), 'mn_export_' ) . '.zip';

		if ( true !== $zip->open( $tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( 'Could not create ZIP file.', 500 );
		}

		$seen = array(); /* Avoid duplicate filenames */

		foreach ( $query->posts as $att_id ) {
			$file_path = get_attached_file( $att_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) { continue; }

			$base = basename( $file_path );
			/* Deduplicate filenames */
			if ( isset( $seen[ $base ] ) ) {
				$seen[ $base ]++;
				$info  = pathinfo( $base );
				$base  = $info['filename'] . '-' . $seen[ $base ] . '.' . ( $info['extension'] ?? '' );
			} else {
				$seen[ $base ] = 0;
			}

			$zip->addFile( $file_path, $base );
		}

		$zip->close();

		$folder_name = sanitize_file_name( $term->name );
		$filename    = $folder_name . '-medianest-export.zip';

		/* Stream the ZIP */
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		unlink( $tmp_file );   // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		exit;
	}
}
