<?php
/**
 * Folder file counts for MediaNest.
 *
 * Provides accurate per-folder attachment counts via REST endpoint
 * and keeps the sidebar badge numbers in sync.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Counts {

	const CACHE_KEY   = 'rayetun_mn_counts';
	const CACHE_GROUP = 'rayetun_medianest';

	/**
	 * Register hooks.
	 */
	public static function register() {
		// Invalidate count cache when attachments are created/deleted or terms change.
		add_action( 'add_attachment',                       array( __CLASS__, 'flush_counts' ) );
		add_action( 'delete_attachment',                    array( __CLASS__, 'flush_counts' ) );
		add_action( 'set_object_terms',                     array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_folder_deleted',     array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_attachment_assigned', array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_attachments_removed', array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_media_changed',       array( __CLASS__, 'flush_counts' ) );

		// REST endpoint for counts.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
	}

	/**
	 * Register /counts REST endpoint.
	 */
	public static function register_rest_route() {
		register_rest_route( 'rayetun-medianest/v1', '/counts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_get_counts' ),
			'permission_callback' => static function () {
				return current_user_can( 'upload_files' );
			},
		) );
	}

	/**
	 * REST callback — return counts for all folders + uncategorized.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_get_counts( $request ) {
		return rest_ensure_response( self::get_counts() );
	}

	/**
	 * Get attachment counts keyed by term_id.
	 * Includes a special 'uncategorized' key for unassigned media.
	 *
	 * @return array  { term_id => int, ..., 'uncategorized' => int, 'total' => int }
	 */
	public static function get_counts() {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// Per-folder counts via term relationship table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached below with wp_cache_set; no WP API for bulk term counts on custom taxonomy.
		// COUNT is unaffected by the LEFT JOIN: the meta_key filter yields at most one
		// postmeta row per attachment, so each object is still counted once.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tt.term_id, COUNT( tr.object_id ) AS cnt,
				        COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 ) AS sz
				 FROM %i tt
				 JOIN %i tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 JOIN %i p  ON tr.object_id = p.ID
				 LEFT JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE tt.taxonomy = %s
				   AND p.post_type = %s
				   AND p.post_status = %s
				 GROUP BY tt.term_id',
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				$wpdb->posts,
				$wpdb->postmeta,
				RayetunMediaNest_Smart_Folders::FILESIZE_KEY,
				RayetunMediaNest_Taxonomy::TAXONOMY,
				'attachment',
				'inherit'
			)
		);

		$counts = array();
		$sizes  = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->cnt;
			$sizes[  (int) $row->term_id ] = (int) $row->sz;
		}

		// Total attachments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached below.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = %s',
				$wpdb->posts,
				'attachment',
				'inherit'
			)
		);

		// Uncategorized = total minus those assigned to at least one folder.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached below.
		$assigned = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( DISTINCT tr.object_id )
				 FROM %i tr
				 JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				 JOIN %i p ON tr.object_id = p.ID
				 WHERE tt.taxonomy = %s
				   AND p.post_type = %s
				   AND p.post_status = %s',
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->posts,
				RayetunMediaNest_Taxonomy::TAXONOMY,
				'attachment',
				'inherit'
			)
		);

		$counts['uncategorized'] = max( 0, $total - $assigned );
		$counts['total']         = $total;

		// Library-wide storage total (bytes) for the "All Files" hover readout.
		// Summed once from the cached _rmn_filesize postmeta so multi-folder files are
		// not double-counted (unlike the per-folder sizes, which mirror count semantics).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached below with wp_cache_set.
		$total_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 )
				 FROM %i pm
				 JOIN %i p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type = %s
				   AND p.post_status = %s',
				$wpdb->postmeta,
				$wpdb->posts,
				RayetunMediaNest_Smart_Folders::FILESIZE_KEY,
				'attachment',
				'inherit'
			)
		);

		// Parallel to the per-term counts; the sidebar reads these for the hover readout.
		// String keys never collide with the integer term_id keys above.
		$counts['sizes']      = $sizes;
		$counts['total_size'] = $total_size;

		wp_cache_set( self::CACHE_KEY, $counts, self::CACHE_GROUP, 120 );
		return $counts;
	}

	/**
	 * Flush the count cache.
	 */
	public static function flush_counts() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
