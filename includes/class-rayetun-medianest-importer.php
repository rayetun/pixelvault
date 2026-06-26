<?php
/**
 * Competitor Import — migrate folder structures from FileBird, Real Media Library,
 * WP Media Folder, and Enhanced Media Library into MediaNest.
 *
 * Storage models (verified against actual plugin source):
 *
 *   FileBird v5/v6     — {prefix}fbv  (id, name, parent=0 for root, created_by, ord)
 *                        {prefix}fbv_attachment_folder  (folder_id, attachment_id)
 *
 *   Real Media Library — {prefix}realmedialibrary  (id, name, parent=-1 for root, type='0')
 *                        {prefix}realmedialibrary_posts  (attachment, fid)
 *
 *   WP Media Folder    — taxonomy 'wpmf-category'
 *
 *   Enhanced Media Lib — user-defined taxonomy slugs in option 'wpuxss_eml_taxonomies'
 *                        (default slug 'media_category'); eml_media=1 marks media taxonomies
 *
 * REST endpoints:
 *   GET  /rayetun-medianest/v1/migrate/detect  — list plugins that have data
 *   POST /rayetun-medianest/v1/migrate/run     — import one source
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Importer {

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = 'rayetun-medianest/v1';

		register_rest_route( $ns, '/migrate/detect', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'detect' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		) );

		register_rest_route( $ns, '/migrate/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'run' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'args'                => array(
				'source' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'filebird', 'rml', 'wpmf', 'eml' ),
				),
			),
		) );
	}

	// ── Detect ────────────────────────────────────────────────────────────────

	/**
	 * Scan for installed competitor plugins that have folder data.
	 */
	public static function detect( WP_REST_Request $request ): WP_REST_Response {
		$available = array();

		$check = self::detect_filebird();
		if ( $check ) { $available['filebird'] = $check; }

		$check = self::detect_rml();
		if ( $check ) { $available['rml'] = $check; }

		$check = self::detect_taxonomy( 'wpmf-category', 'WP Media Folder' );
		if ( $check ) { $available['wpmf'] = $check; }

		$check = self::detect_eml();
		if ( $check ) { $available['eml'] = $check; }

		return rest_ensure_response( $available );
	}

	// ── Run ───────────────────────────────────────────────────────────────────

	public static function run( WP_REST_Request $request ) {
		$source = $request->get_param( 'source' );

		switch ( $source ) {
			case 'filebird':
				$result = self::migrate_filebird();
				break;
			case 'rml':
				$result = self::migrate_rml();
				break;
			case 'wpmf':
				$result = self::migrate_taxonomy( 'wpmf-category' );
				break;
			case 'eml':
				$result = self::migrate_eml();
				break;
			default:
				return new WP_Error( 'invalid_source', __( 'Unknown source plugin.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}

	// ── Detection helpers ─────────────────────────────────────────────────────

	/**
	 * FileBird v5/v6 stores folders in {prefix}fbv (NOT a WordPress taxonomy).
	 *
	 * Table: wp_fbv — columns: id, name, parent (0 = root), type, created_by, ord
	 */
	private static function detect_filebird(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'fbv';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		return $count > 0 ? array( 'label' => 'FileBird', 'folders' => $count ) : null;
	}

	/**
	 * Real Media Library uses {prefix}realmedialibrary for folders (NOT a taxonomy).
	 *
	 * Table: wp_realmedialibrary — columns: id, name, parent (-1 = root), type ('0' = folder)
	 */
	private static function detect_rml(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'realmedialibrary';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		// Count only regular folders; type = '0' is the default for user-created folders.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `type` = '0'", $table )
		);

		return $count > 0 ? array( 'label' => 'Real Media Library', 'folders' => $count ) : null;
	}

	/**
	 * Enhanced Media Library stores media taxonomy slugs in the option 'wpuxss_eml_taxonomies'.
	 * The default slug is 'media_category' but it is entirely user-configurable.
	 */
	private static function detect_eml(): ?array {
		$configured = get_option( 'wpuxss_eml_taxonomies', array() );
		if ( empty( $configured ) || ! is_array( $configured ) ) {
			return null;
		}

		$total = 0;
		foreach ( $configured as $taxonomy => $params ) {
			if ( empty( $params['eml_media'] ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( ! is_wp_error( $count ) ) {
				$total += (int) $count;
			}
		}

		return $total > 0 ? array( 'label' => 'Enhanced Media Library', 'folders' => $total ) : null;
	}

	/**
	 * Generic taxonomy-based detection (WP Media Folder uses a standard WP taxonomy).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $label    Human-readable plugin name.
	 */
	private static function detect_taxonomy( string $taxonomy, string $label ): ?array {
		if ( ! taxonomy_exists( $taxonomy ) ) { return null; }

		$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );

		if ( is_wp_error( $count ) || (int) $count === 0 ) { return null; }

		return array( 'label' => $label, 'folders' => (int) $count );
	}

	// ── Migration: FileBird ───────────────────────────────────────────────────

	/**
	 * FileBird v5/v6 migration.
	 *
	 * Folders:       wp_fbv                   — id, name, parent (0 = root)
	 * Relationships: wp_fbv_attachment_folder  — folder_id, attachment_id
	 */
	private static function migrate_filebird(): array {
		global $wpdb;

		$folder_table = $wpdb->prefix . 'fbv';
		$rel_table    = $wpdb->prefix . 'fbv_attachment_folder';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $folder_table ) ) !== $folder_table ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		// Read all folders ordered parent-first so parent IDs exist when children are processed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT `id`, `name`, `parent` FROM %i ORDER BY `parent` ASC, `id` ASC', $folder_table )
		);

		if ( empty( $rows ) ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		$map             = array(); // fbv id => medianest term_id
		$folders_created = 0;
		$skipped         = 0;

		foreach ( $rows as $row ) {
			$fb_id     = (int) $row->id;
			$fb_parent = (int) $row->parent;
			$name      = sanitize_text_field( $row->name );

			if ( empty( $name ) ) { $skipped++; continue; }

			// parent = 0 means root-level in FileBird v5/v6.
			$mn_parent = ( $fb_parent > 0 && isset( $map[ $fb_parent ] ) ) ? $map[ $fb_parent ] : 0;

			$existing = get_terms( array(
				'taxonomy'   => RayetunMediaNest_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => $mn_parent,
				'number'     => 1,
			) );

			if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
				$map[ $fb_id ] = (int) $existing[0]->term_id;
				$skipped++;
				continue;
			}

			$result = RayetunMediaNest_Folder_Service::create( $name, array( 'parent_id' => $mn_parent ) );
			if ( is_wp_error( $result ) ) { $skipped++; continue; }

			$map[ $fb_id ] = (int) $result['term_id'];
			$folders_created++;
		}

		// ── Assign files ──────────────────────────────────────────────────────

		$files_assigned = 0;
		$mn_taxonomy    = RayetunMediaNest_Taxonomy::TAXONOMY;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_rel = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rel_table ) ) === $rel_table );

		if ( $has_rel ) {
			foreach ( $map as $fb_folder_id => $mn_term_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$att_ids = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT `attachment_id` FROM %i WHERE `folder_id` = %d',
						$rel_table,
						$fb_folder_id
					)
				);

				self::assign_files_to_term( $att_ids, $mn_term_id, $mn_taxonomy, $files_assigned );
			}
		}

		if ( $files_assigned > 0 ) {
			do_action( 'rayetun_medianest_attachment_assigned' );
		}

		return array(
			'folders_created' => $folders_created,
			'files_assigned'  => $files_assigned,
			'skipped'         => $skipped,
		);
	}

	// ── Migration: Real Media Library ─────────────────────────────────────────

	/**
	 * Real Media Library migration.
	 *
	 * Folders:       wp_realmedialibrary       — id, name, parent (-1 = root), type ('0' = folder)
	 * Relationships: wp_realmedialibrary_posts  — attachment (attach ID), fid (folder ID)
	 */
	private static function migrate_rml(): array {
		global $wpdb;

		$folder_table = $wpdb->prefix . 'realmedialibrary';
		$posts_table  = $wpdb->prefix . 'realmedialibrary_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_folder = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $folder_table ) ) === $folder_table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_posts  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $posts_table ) ) === $posts_table );

		if ( ! $has_folder || ! $has_posts ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		// type = '0' for regular user folders; skip collections/galleries.
		// parent = -1 for root-level folders in RML.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `id`, `name`, `parent` FROM %i WHERE `type` = '0' ORDER BY `parent` ASC, `id` ASC",
				$folder_table
			)
		);

		if ( empty( $rows ) ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		$map             = array();
		$folders_created = 0;
		$skipped         = 0;

		foreach ( $rows as $row ) {
			$rml_id     = (int) $row->id;
			$rml_parent = (int) $row->parent;
			$name       = sanitize_text_field( $row->name );

			if ( empty( $name ) ) { $skipped++; continue; }

			// RML uses -1 for root; treat anything negative or unmapped as root.
			$mn_parent = ( $rml_parent >= 0 && isset( $map[ $rml_parent ] ) ) ? $map[ $rml_parent ] : 0;

			$existing = get_terms( array(
				'taxonomy'   => RayetunMediaNest_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => $mn_parent,
				'number'     => 1,
			) );

			if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
				$map[ $rml_id ] = (int) $existing[0]->term_id;
				$skipped++;
				continue;
			}

			$result = RayetunMediaNest_Folder_Service::create( $name, array( 'parent_id' => $mn_parent ) );
			if ( is_wp_error( $result ) ) { $skipped++; continue; }

			$map[ $rml_id ] = (int) $result['term_id'];
			$folders_created++;
		}

		$files_assigned = 0;
		$mn_taxonomy    = RayetunMediaNest_Taxonomy::TAXONOMY;

		foreach ( $map as $rml_folder_id => $mn_term_id ) {
			// Columns: attachment = attachment post ID, fid = folder ID.
			// isShortcut = 0 excludes RML shortcut entries so we don't double-import.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$att_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT `attachment` FROM %i WHERE `fid` = %d AND `isShortcut` = 0',
					$posts_table,
					$rml_folder_id
				)
			);

			self::assign_files_to_term( $att_ids, $mn_term_id, $mn_taxonomy, $files_assigned );
		}

		if ( $files_assigned > 0 ) {
			do_action( 'rayetun_medianest_attachment_assigned' );
		}

		return array(
			'folders_created' => $folders_created,
			'files_assigned'  => $files_assigned,
			'skipped'         => $skipped,
		);
	}

	// ── Migration: Enhanced Media Library ────────────────────────────────────

	/**
	 * EML uses user-defined taxonomy slugs stored in the 'wpuxss_eml_taxonomies' option.
	 * We read all enabled taxonomies and run the generic taxonomy migration on each.
	 */
	private static function migrate_eml(): array {
		$configured = get_option( 'wpuxss_eml_taxonomies', array() );
		if ( empty( $configured ) || ! is_array( $configured ) ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		$total_created  = 0;
		$total_assigned = 0;
		$total_skipped  = 0;

		foreach ( $configured as $taxonomy => $params ) {
			if ( empty( $params['eml_media'] ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$result = self::migrate_taxonomy( $taxonomy );
			$total_created  += (int) $result['folders_created'];
			$total_assigned += (int) $result['files_assigned'];
			$total_skipped  += (int) $result['skipped'];
		}

		return array(
			'folders_created' => $total_created,
			'files_assigned'  => $total_assigned,
			'skipped'         => $total_skipped,
		);
	}

	// ── Migration: taxonomy-based plugins (WPMF, EML per-taxonomy) ───────────

	/**
	 * Generic migration for a standard WordPress taxonomy (WPMF uses 'wpmf-category').
	 *
	 * @param string $source_taxonomy The source plugin's taxonomy slug.
	 */
	private static function migrate_taxonomy( string $source_taxonomy ): array {
		if ( ! taxonomy_exists( $source_taxonomy ) ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		$source_terms = get_terms( array(
			'taxonomy'   => $source_taxonomy,
			'hide_empty' => false,
			'orderby'    => 'parent',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
			return array( 'folders_created' => 0, 'files_assigned' => 0, 'skipped' => 0 );
		}

		$map             = array();
		$folders_created = 0;
		$skipped         = 0;

		// Pass 1: create MediaNest folders preserving hierarchy.
		foreach ( $source_terms as $term ) {
			$src_id     = (int) $term->term_id;
			$src_parent = (int) $term->parent;
			$name       = sanitize_text_field( $term->name );

			if ( empty( $name ) ) { $skipped++; continue; }

			$mn_parent = isset( $map[ $src_parent ] ) ? $map[ $src_parent ] : 0;

			$existing = get_terms( array(
				'taxonomy'   => RayetunMediaNest_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => $mn_parent,
				'number'     => 1,
			) );

			if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
				$map[ $src_id ] = (int) $existing[0]->term_id;
				$skipped++;
				continue;
			}

			$result = RayetunMediaNest_Folder_Service::create( $name, array( 'parent_id' => $mn_parent ) );
			if ( is_wp_error( $result ) ) { $skipped++; continue; }

			$map[ $src_id ] = (int) $result['term_id'];
			$folders_created++;
		}

		// Pass 2: copy term_relationships.
		$files_assigned = 0;
		$mn_taxonomy    = RayetunMediaNest_Taxonomy::TAXONOMY;

		foreach ( $map as $src_term_id => $mn_term_id ) {
			$att_ids = get_objects_in_term( $src_term_id, $source_taxonomy );
			if ( is_wp_error( $att_ids ) || empty( $att_ids ) ) { continue; }
			self::assign_files_to_term( $att_ids, $mn_term_id, $mn_taxonomy, $files_assigned );
		}

		if ( $files_assigned > 0 ) {
			do_action( 'rayetun_medianest_attachment_assigned' );
		}

		return array(
			'folders_created' => $folders_created,
			'files_assigned'  => $files_assigned,
			'skipped'         => $skipped,
		);
	}

	// ── Shared helpers ────────────────────────────────────────────────────────

	/**
	 * Assign a list of attachment IDs to a MediaNest taxonomy term.
	 * Skips IDs already assigned. Updates $count by reference.
	 *
	 * @param array  $att_ids     Raw attachment IDs (strings or ints from DB).
	 * @param int    $mn_term_id  MediaNest term ID to assign to.
	 * @param string $mn_taxonomy MediaNest taxonomy slug.
	 * @param int    $count       Running assigned total — updated by reference.
	 */
	private static function assign_files_to_term( array $att_ids, int $mn_term_id, string $mn_taxonomy, int &$count ) {
		if ( empty( $att_ids ) ) { return; }

		foreach ( $att_ids as $att_id ) {
			$att_id = (int) $att_id;
			if ( $att_id <= 0 ) { continue; }
			if ( 'attachment' !== get_post_type( $att_id ) ) { continue; }

			$current = wp_get_object_terms( $att_id, $mn_taxonomy, array( 'fields' => 'ids' ) );
			$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

			if ( in_array( $mn_term_id, $current, true ) ) { continue; }

			$current[] = $mn_term_id;
			wp_set_object_terms( $att_id, $current, $mn_taxonomy );
			clean_object_term_cache( array( $att_id ), $mn_taxonomy );
			$count++;
		}
	}
}
