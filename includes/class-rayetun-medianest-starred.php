<?php
/**
 * Starred (favourite) folders — per-user.
 *
 * Each user can star folders; starred folders are pinned to the top of the
 * media library sidebar for quick access. Stored in user meta.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Starred {

	const META_KEY = 'rayetun_mn_starred_folders';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Clean a folder out of every user's starred list when it is deleted.
		add_action( 'rayetun_medianest_folder_deleted', array( __CLASS__, 'purge_folder' ), 10, 1 );
	}

	/**
	 * Get the current (or given) user's starred folder term IDs.
	 *
	 * @param int $user_id Optional user ID; defaults to the current user.
	 * @return int[]
	 */
	public static function get_starred( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$ids     = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();
	}

	public static function register_routes() {
		register_rest_route(
			'rayetun-medianest/v1',
			'/folders/(?P<term_id>[\d]+)/star',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'toggle_star' ),
				'permission_callback' => function () { return current_user_can( 'upload_files' ); },
				'args'                => array(
					'term_id' => array( 'type' => 'integer', 'required' => true ),
					'starred' => array( 'type' => 'boolean', 'required' => true ),
				),
			)
		);
	}

	/**
	 * Star or unstar a folder for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function toggle_star( WP_REST_Request $request ) {
		$term_id = (int) $request['term_id'];
		$starred = (bool) $request->get_param( 'starred' );
		$user_id = get_current_user_id();

		// The folder must exist and be visible to this user.
		$term = get_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		$ids = self::get_starred( $user_id );

		if ( $starred ) {
			if ( ! in_array( $term_id, $ids, true ) ) {
				$ids[] = $term_id;
			}
		} else {
			$ids = array_values( array_diff( $ids, array( $term_id ) ) );
		}

		update_user_meta( $user_id, self::META_KEY, $ids );

		return rest_ensure_response(
			array(
				'starred' => $starred,
				'ids'     => array_map( 'absint', $ids ),
			)
		);
	}

	/**
	 * Remove a deleted folder from every user's starred list.
	 *
	 * @param int $term_id Deleted folder term ID.
	 */
	public static function purge_folder( int $term_id ) {
		global $wpdb;
		// Find users who have this folder starred, then rewrite their meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on folder deletion; no cache layer for user_meta lookup by value.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::META_KEY,
				'%' . $wpdb->esc_like( (string) $term_id ) . '%'
			)
		);

		foreach ( (array) $user_ids as $uid ) {
			$ids = self::get_starred( (int) $uid );
			$new = array_values( array_diff( $ids, array( $term_id ) ) );
			if ( $new !== $ids ) {
				update_user_meta( (int) $uid, self::META_KEY, $new );
			}
		}
	}
}
