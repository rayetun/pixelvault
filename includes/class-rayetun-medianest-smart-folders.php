<?php
/**
 * Smart Folders — Dynamic virtual folders that auto-filter the media library.
 *
 * Four built-in smart folders (no user setup required):
 *   missing_alt  — Images with empty or missing alt text (SEO)
 *   unused       — Attachments not linked to any post/page
 *   recent       — Uploaded within the last 30 days
 *   large        — Files larger than 1 MB
 *
 * Cookie value format: "smart:{slug}" e.g. "smart:missing_alt"
 * The Query class detects this prefix and calls apply_query_args().
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Smart_Folders {

	const SLUG_PREFIX  = 'smart:';
	const LARGE_BYTES  = 1048576; // 1 MB
	const RECENT_DAYS  = 30;
	const CACHE_GROUP  = 'rayetun_medianest';
	const CACHE_KEY    = 'smart_counts';
	const CACHE_TTL    = 5 * MINUTE_IN_SECONDS;
	const FILESIZE_KEY = '_rmn_filesize'; /* postmeta key for cached file size */

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init',    array( __CLASS__, 'register_routes' ) );
		add_action( 'add_attachment',   array( __CLASS__, 'cache_filesize' ) );
		add_action( 'edit_attachment',  array( __CLASS__, 'cache_filesize' ) );
		add_action( 'admin_init',       array( __CLASS__, 'maybe_backfill_filesizes' ) );
		/* Flush counts whenever attachments are added, assigned, moved, or deleted */
		add_action( 'add_attachment',    array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_attachment_assigned', array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_attachments_removed', array( __CLASS__, 'flush_counts' ) );
		add_action( 'rayetun_medianest_media_changed',       array( __CLASS__, 'flush_counts' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'flush_counts' ) );
	}

	// ── Folder definitions ────────────────────────────────────────────────────

	public static function get_definitions(): array {
		$defs = array(
			'missing_alt' => array(
				'label'       => __( 'Missing Alt Text', 'pixelvault' ),
				'icon'        => '&#9888;',   /* ⚠ warning sign */
				'description' => __( 'Images without alt text — important for SEO and accessibility.', 'pixelvault' ),
			),
			'unused' => array(
				'label'       => __( 'Unused Images', 'pixelvault' ),
				'icon'        => '&#128279;', /* 🔗 link */
				'description' => __( 'Attachments not linked to any post or page.', 'pixelvault' ),
			),
			'recent' => array(
				'label'       => __( 'Recent Uploads', 'pixelvault' ),
				'icon'        => '&#128197;', /* 📅 calendar */
				'description' => sprintf(
					/* translators: %d: number of days */
					__( 'Uploaded in the last %d days.', 'pixelvault' ),
					self::RECENT_DAYS
				),
			),
			'large' => array(
				'label'       => __( 'Large Files', 'pixelvault' ),
				'icon'        => '&#128230;', /* 📦 box */
				'description' => __( 'Files larger than 1 MB — consider optimising for web.', 'pixelvault' ),
			),
		);

		/**
		 * Filter the registered smart (dynamic) folders.
		 *
		 * Add-ons register a custom smart folder by adding an entry keyed by its type slug,
		 * with 'label', 'icon', and 'description'. To make it functional they must also hook
		 * `rayetun_medianest_smart_folder_counts` (to provide its count) and
		 * `rayetun_medianest_smart_folder_query_args` (to filter the media grid when active).
		 *
		 * @param array $defs Smart folder definitions keyed by type slug.
		 */
		return (array) apply_filters( 'rayetun_medianest_smart_folders', $defs );
	}

	public static function is_smart_slug( string $value ): bool {
		return str_starts_with( $value, self::SLUG_PREFIX );
	}

	public static function extract_type( string $value ): string {
		return sanitize_key( substr( $value, strlen( self::SLUG_PREFIX ) ) );
	}

	// ── REST endpoint: GET /smart-folders ─────────────────────────────────────

	public static function register_routes() {
		register_rest_route( 'rayetun-medianest/v1', '/smart-folders', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_get_smart_folders' ),
			'permission_callback' => function() { return current_user_can( 'upload_files' ); },
		) );
	}

	public static function rest_get_smart_folders(): WP_REST_Response {
		$defs   = self::get_definitions();
		$counts = self::get_cached_counts();
		$result = array();

		// Settings keys that gate each smart folder.
		$sf_setting_keys = array(
			'missing_alt' => 'sf_missing_alt',
			'unused'      => 'sf_unused',
			'recent'      => 'sf_recent',
			'large'       => 'sf_large',
		);

		foreach ( $defs as $slug => $def ) {
			// Skip if disabled in Settings.
			$setting_key = $sf_setting_keys[ $slug ] ?? '';
			if ( $setting_key && ! (int) RayetunMediaNest_Settings::get( $setting_key, 1 ) ) {
				continue;
			}

			$result[] = array(
				'slug'        => $slug,
				'label'       => $def['label'],
				'icon'        => $def['icon'],
				'description' => $def['description'],
				'count'       => $counts[ $slug ] ?? 0,
			);
		}

		return rest_ensure_response( $result );
	}

	// ── Count helpers ─────────────────────────────────────────────────────────

	private static function get_cached_counts(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$counts = array(
			'missing_alt' => self::count_missing_alt(),
			'unused'      => self::count_unused(),
			'recent'      => self::count_recent(),
			'large'       => self::count_large(),
		);

		/**
		 * Filter smart-folder counts. Add-ons provide counts for their custom types
		 * keyed by the same type slug they registered via `rayetun_medianest_smart_folders`.
		 *
		 * @param array $counts Counts keyed by smart folder type slug.
		 */
		$counts = (array) apply_filters( 'rayetun_medianest_smart_folder_counts', $counts );

		wp_cache_set( self::CACHE_KEY, $counts, self::CACHE_GROUP, self::CACHE_TTL );
		return $counts;
	}

	/** Images (only) whose _wp_attachment_image_alt meta is missing or blank. */
	private static function count_missing_alt(): int {
		return ( new WP_Query( array_merge(
			self::base_query_args(),
			array(
				'post_mime_type' => 'image',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				),
			)
		) ) )->found_posts;
	}

	/** Attachments with no post_parent and not set as a featured image anywhere. */
	private static function count_unused(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- result cached by get_cached_counts().
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_parent = 0
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->postmeta} pm
			       WHERE pm.meta_key = '_thumbnail_id'
			         AND pm.meta_value = p.ID
			   )"
		);
	}

	/** Attachments uploaded within the last recent_days setting days. */
	private static function count_recent(): int {
		$days = (int) RayetunMediaNest_Settings::get( 'recent_days', self::RECENT_DAYS );
		if ( $days < 1 ) { $days = self::RECENT_DAYS; }
		return ( new WP_Query( array_merge(
			self::base_query_args(),
			array(
				'date_query' => array(
					array(
						'after'     => gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
						'inclusive' => true,
					),
				),
			)
		) ) )->found_posts;
	}

	/** Attachments whose cached filesize >= large_file_mb setting in bytes. */
	private static function count_large(): int {
		$mb    = (float) RayetunMediaNest_Settings::get( 'large_file_mb', 1 );
		$bytes = (int) max( 1, $mb * 1048576 );
		return ( new WP_Query( array_merge(
			self::base_query_args(),
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'     => self::FILESIZE_KEY,
						'value'   => $bytes,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		) ) )->found_posts;
	}

	private static function base_query_args(): array {
		return array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
	}

	// ── Query filter: called by RayetunMediaNest_Query ────────────────────────

	/**
	 * Apply the smart folder filter to a WP_Query $args array.
	 * Returns the modified args.
	 */
	public static function apply_query_args( array $args, string $type ): array {
		switch ( $type ) {
			case 'missing_alt':
				$args['post_mime_type'] = 'image';
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$args['meta_query'] = array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				);
				break;

			case 'unused':
				$featured_ids        = self::get_featured_image_ids();
				$args['post_parent'] = 0;
				if ( ! empty( $featured_ids ) ) {
					$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
					$args['post__not_in'] = array_unique( array_merge( $existing, $featured_ids ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- intentional exclusion of featured images from Unused filter
				}
				break;

			case 'recent':
				$recent_days = (int) RayetunMediaNest_Settings::get( 'recent_days', self::RECENT_DAYS );
				if ( $recent_days < 1 ) { $recent_days = self::RECENT_DAYS; }
				$args['date_query'] = array(
					array(
						'after'     => gmdate( 'Y-m-d', strtotime( '-' . $recent_days . ' days' ) ),
						'inclusive' => true,
					),
				);
				break;

			case 'large':
				$large_mb    = (float) RayetunMediaNest_Settings::get( 'large_file_mb', 1 );
				$large_bytes = (int) max( 1, $large_mb * 1048576 );
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$args['meta_query'] = array(
					array(
						'key'     => self::FILESIZE_KEY,
						'value'   => $large_bytes,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				);
				break;
		}

		/**
		 * Filter the WP_Query args applied when a smart folder is active. Add-ons use this
		 * to filter the media grid for their custom smart folder types.
		 *
		 * @param array  $args Query args assembled so far.
		 * @param string $type Smart folder type slug currently active.
		 */
		return (array) apply_filters( 'rayetun_medianest_smart_folder_query_args', $args, $type );
	}

	// ── Filesize caching ──────────────────────────────────────────────────────

	/** Store file size in postmeta when an attachment is added or updated. */
	public static function cache_filesize( int $id ) {
		$file = get_attached_file( $id );
		if ( $file && file_exists( $file ) ) {
			update_post_meta( $id, self::FILESIZE_KEY, (int) filesize( $file ) );
		}
	}

	/**
	 * Backfill _rmn_filesize for attachments that were uploaded before this
	 * plugin was installed. Processes $limit items per call to avoid timeout.
	 */
	private static function backfill_filesizes( int $limit = 100 ) {
		$ids = ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => $limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array( 'key' => self::FILESIZE_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) ) )->posts;

		foreach ( $ids as $id ) {
			self::cache_filesize( (int) $id );
		}
	}

	/**
	 * Return all four smart-folder counts as a public array.
	 * Used by the settings dashboard stats.
	 */
	public static function get_all_counts(): array {
		return self::get_cached_counts();
	}

	/** Flush smart counts from object cache (called on attachment operations). */
	public static function flush_counts() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		wp_cache_delete( 'featured_image_ids', self::CACHE_GROUP );
	}

	// ── Featured image ID list ────────────────────────────────────────────────

	/**
	 * Return all attachment IDs currently used as a featured image on any post.
	 * Cached for CACHE_TTL seconds; flushed by flush_counts().
	 */
	private static function get_featured_image_ids(): array {
		$cached = wp_cache_get( 'featured_image_ids', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above with wp_cache_get/set.
		$raw = $wpdb->get_col(
			"SELECT DISTINCT CAST(meta_value AS UNSIGNED)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id'
			   AND meta_value != ''
			   AND CAST(meta_value AS UNSIGNED) > 0"
		);

		$ids = array_map( 'absint', (array) $raw );
		wp_cache_set( 'featured_image_ids', $ids, self::CACHE_GROUP, self::CACHE_TTL );
		return $ids;
	}

	// ── Filesize backfill ─────────────────────────────────────────────────────

	/**
	 * Run once per site on admin_init until all attachments have a cached filesize.
	 * Sets a persistent option flag when complete so subsequent requests are instant.
	 */
	public static function maybe_backfill_filesizes() {
		if ( get_option( 'rayetun_medianest_filesize_backfill_done' ) ) {
			return;
		}

		self::backfill_filesizes( 200 );

		// Check whether any attachments still lack a filesize entry.
		$remaining = ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array( 'key' => self::FILESIZE_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) ) )->posts;

		if ( empty( $remaining ) ) {
			// autoload=false: only needed on admin_init, not every page load.
			update_option( 'rayetun_medianest_filesize_backfill_done', '1', false );
		}
	}
}
