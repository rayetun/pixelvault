<?php
/**
 * Database layer for MediaNest.
 *
 * All SQL lives here. No HTTP, no business logic.
 * Caching applied to all read queries per WP.org WPCS requirements.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_DB {

	const TABLE      = 'rayetun_medianest_folders';
	const DB_VERSION = '1.4';
	const DB_OPT_KEY = 'rayetun_medianest_db_version';

	// Cache group — registered in taxonomy class on init.
	const CACHE_GROUP = 'rayetun_medianest';

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	public static function get_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_tables() {
		global $wpdb;
		$table   = self::get_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_id       bigint(20) unsigned NOT NULL,
			parent_id     bigint(20) unsigned NOT NULL DEFAULT 0,
			sort_order    int(11)             NOT NULL DEFAULT 0,
			color         varchar(7)          NOT NULL DEFAULT '',
			icon          varchar(50)         NOT NULL DEFAULT '',
			owner_id      bigint(20) unsigned NOT NULL DEFAULT 0,
			visibility    varchar(20)         NOT NULL DEFAULT 'all',
			allowed_roles longtext            NOT NULL,
			write_roles   longtext            NOT NULL,
			is_locked     tinyint(1)          NOT NULL DEFAULT 0,
			post_type     varchar(20)         NOT NULL DEFAULT 'attachment',
			created_at    datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at    datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY   term_id (term_id),
			KEY          parent_id (parent_id),
			KEY          owner_id (owner_id),
			KEY          post_type (post_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta is the WP-approved method for schema changes.

		update_option( self::DB_OPT_KEY, self::DB_VERSION );
	}

	/**
	 * Run on plugins_loaded — safe for auto-updates that skip activation hook.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_OPT_KEY, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			// Grant default capabilities to roles on upgrade so existing installs
			// get the permissions without needing a deactivate/reactivate cycle.
			if ( class_exists( 'RayetunMediaNest_Permissions' ) ) {
				RayetunMediaNest_Permissions::grant_defaults();
			}
		}
	}

	// -------------------------------------------------------------------------
	// Cache helpers
	// -------------------------------------------------------------------------

	private static function flush_folder_cache( int $term_id = 0 ) {
		wp_cache_delete( 'folder_' . $term_id, self::CACHE_GROUP );
		wp_cache_delete( 'folders_admin', self::CACHE_GROUP );
		wp_cache_delete( 'folders_all_raw', self::CACHE_GROUP );
		// Flush per-user caches by incrementing a generation key.
		$gen = (int) wp_cache_get( 'folder_list_gen', self::CACHE_GROUP );
		wp_cache_set( 'folder_list_gen', $gen + 1, self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Insert a folder metadata row after the WP term has been created.
	 *
	 * @param int   $term_id
	 * @param array $args
	 * @return int|false  Insert ID or false on failure.
	 */
	public static function insert_folder( int $term_id, array $args ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert_folder is the only correct way to add a row; no WP API wraps custom table inserts.
			self::get_table(),
			array(
				'term_id'       => $term_id,
				'parent_id'     => absint( $args['parent_id'] ?? 0 ),
				'sort_order'    => absint( $args['sort_order'] ?? 0 ),
				'color'         => sanitize_hex_color( $args['color'] ?? '' ) ?? '',
				'icon'          => sanitize_key( $args['icon'] ?? '' ),
				'owner_id'      => absint( $args['owner_id'] ?? 0 ),
				'visibility'    => sanitize_key( $args['visibility'] ?? 'all' ),
				'allowed_roles' => wp_json_encode( (array) ( $args['allowed_roles'] ?? array() ) ),
				'write_roles'   => wp_json_encode( (array) ( $args['write_roles'] ?? array() ) ),
				'is_locked'     => 0,
				'post_type'     => sanitize_key( $args['post_type'] ?? 'attachment' ),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $wpdb->last_error ) {
			return false;
		}

		self::flush_folder_cache( $term_id );
		return $wpdb->insert_id;
	}

	/**
	 * Update arbitrary columns on a folder row.
	 *
	 * @param int   $term_id
	 * @param array $data
	 * @return true|WP_Error
	 */
	public static function update_folder_meta( int $term_id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cache flushed below; no WP API wraps custom table updates.
			self::get_table(),
			$data,
			array( 'term_id' => $term_id )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', $wpdb->last_error );
		}

		self::flush_folder_cache( $term_id );
		return true;
	}

	/**
	 * Re-parent children in metadata table when a parent folder is deleted.
	 *
	 * @param int $old_parent_id
	 * @param int $new_parent_id
	 */
	public static function reparent_children( int $old_parent_id, int $new_parent_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cache flushed below; bulk reparent has no WP API equivalent.
			self::get_table(),
			array( 'parent_id' => $new_parent_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'parent_id' => $old_parent_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		self::flush_folder_cache();
	}

	/**
	 * Delete a single folder metadata row.
	 *
	 * @param int $term_id
	 */
	public static function delete_folder_row( int $term_id ) {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cache flushed below; $wpdb->delete is the correct method for custom tables.
			self::get_table(),
			array( 'term_id' => $term_id ),
			array( '%d' )
		);

		self::flush_folder_cache( $term_id );
	}

	/**
	 * Touch updated_at timestamp.
	 *
	 * @param int $term_id
	 */
	public static function touch_folder( int $term_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cache flushed below; timestamp touch has no WP API equivalent.
			self::get_table(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'term_id'    => $term_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::flush_folder_cache( $term_id );
	}

	// -------------------------------------------------------------------------
	// Read — all with wp_cache_get / wp_cache_set
	// -------------------------------------------------------------------------

	/**
	 * Get a single folder by term_id. Joins wp_terms for name/slug.
	 *
	 * @param int $term_id
	 * @return object|null
	 */
	public static function get_folder_by_term_id( int $term_id ) {
		global $wpdb;

		$cache_key = 'folder_' . $term_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null; // empty string stored for "not found".
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above with wp_cache_get/set.
			$wpdb->prepare(
				'SELECT f.*, t.name, t.slug
				 FROM %i f
				 JOIN %i t ON f.term_id = t.term_id
				 WHERE f.term_id = %d
				 LIMIT 1',
				self::get_table(),
				$wpdb->terms,
				$term_id
			)
		);

		// Store empty string (falsy but cacheable) when not found.
		wp_cache_set( $cache_key, $row ? $row : '', self::CACHE_GROUP, 300 );
		return $row;
	}

	/**
	 * Get all folders visible to a specific user.
	 *
	 * @param int    $user_id
	 * @param string $post_type
	 * @return array
	 */
	public static function get_visible_folders( int $user_id, string $post_type = 'attachment' ) {
		global $wpdb;

		$is_admin  = user_can( $user_id, 'manage_options' );
		$user      = get_userdata( $user_id );
		$user_role = ( $user && ! empty( $user->roles ) ) ? $user->roles[0] : '';

		$gen       = (int) wp_cache_get( 'folder_list_gen', self::CACHE_GROUP );
		$cache_key = $is_admin
			? 'folders_admin_' . $post_type . '_' . $gen
			: 'folders_user_' . $user_id . '_' . $post_type . '_' . $gen;

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( $is_admin ) {
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above with wp_cache_get/set.
				$wpdb->prepare(
					'SELECT f.*, t.name, t.slug
					 FROM %i f
					 JOIN %i t ON f.term_id = t.term_id
					 WHERE f.post_type = %s
					 ORDER BY f.parent_id ASC, f.sort_order ASC, t.name ASC',
					self::get_table(),
					$wpdb->terms,
					$post_type
				)
			);
		} else {
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above with wp_cache_get/set.
				$wpdb->prepare(
					'SELECT f.*, t.name, t.slug
					 FROM %i f
					 JOIN %i t ON f.term_id = t.term_id
					 WHERE f.post_type = %s
					   AND (
					       f.visibility = %s
					       OR f.owner_id = %d
					       OR ( f.visibility = %s AND f.allowed_roles LIKE %s )
					   )
					 ORDER BY f.parent_id ASC, f.sort_order ASC, t.name ASC',
					self::get_table(),
					$wpdb->terms,
					$post_type,
					'all',
					$user_id,
					'roles',
					'%"' . $wpdb->esc_like( $user_role ) . '"%'
				)
			);
		}

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 60 );
		return $results;
	}

	/**
	 * Get all folder rows (no visibility filter). Used for tree traversal only.
	 *
	 * @return array
	 */
	public static function get_all_folders_raw() {
		global $wpdb;

		$cache_key = 'folders_all_raw';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above with wp_cache_get/set.
			$wpdb->prepare(
				'SELECT f.term_id, f.parent_id FROM %i f',
				self::get_table()
			)
		);

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 300 );
		return $results;
	}

	// -------------------------------------------------------------------------
	// Uninstall helper
	// -------------------------------------------------------------------------

	/**
	 * Drop all plugin data. Called from uninstall.php only.
	 */
	public static function drop_all() {
		global $wpdb;

		$term_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall context, one-time operation, no caching needed.
			$wpdb->prepare(
				'SELECT term_id FROM %i WHERE taxonomy = %s',
				$wpdb->term_taxonomy,
				RayetunMediaNest_Taxonomy::TAXONOMY
			)
		);

		if ( ! empty( $term_ids ) ) {
			foreach ( $term_ids as $tid ) {
				wp_delete_term( (int) $tid, RayetunMediaNest_Taxonomy::TAXONOMY );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall context; one-time operation; no WP API wraps DROP TABLE.
		$wpdb->query(
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::get_table() ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional uninstall cleanup; WP_UNINSTALL_PLUGIN guard confirmed above.
		);

		delete_option( self::DB_OPT_KEY );
		delete_option( 'rayetun_medianest_settings' );
		delete_option( 'rayetun_medianest_filesize_backfill_done' );
	}

}
