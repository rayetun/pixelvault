<?php
/**
 * Bulk assignment handler for MediaNest.
 *
 * Handles assigning multiple attachments to a folder in one request.
 * Both REST and AJAX transports.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Bulk {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'rest_api_init',      array( __CLASS__, 'register_rest_route' ) );
		add_action( 'wp_ajax_rayetun_medianest_bulk_assign', array( __CLASS__, 'ajax_bulk_assign' ) );
		add_action( 'wp_ajax_rayetun_medianest_bulk_delete', array( __CLASS__, 'ajax_bulk_delete' ) );
	}

	/**
	 * REST: POST /wp-json/rayetun-medianest/v1/bulk-assign
	 */
	public static function register_rest_route() {
		register_rest_route( 'rayetun-medianest/v1', '/bulk-delete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_bulk_delete' ),
			'permission_callback' => function() { return current_user_can( 'delete_posts' ); },
			'args'                => array(
				'attachment_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		register_rest_route( 'rayetun-medianest/v1', '/bulk-assign', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_bulk_assign' ),
			'permission_callback' => static function () {
				return current_user_can( 'upload_files' );
			},
			'args'                => array(
				'attachment_ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
				'term_ids'       => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
				'replace'        => array(
					'default' => true,
					'type'    => 'boolean',
				),
			),
		) );
	}

	/**
	 * REST callback.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_bulk_assign( $request ) {
		$result = self::bulk_assign(
			(array)  $request->get_param( 'attachment_ids' ),
			(array)  $request->get_param( 'term_ids' ),
			(bool)   $request->get_param( 'replace' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * AJAX callback.
	 * Nonce verified via check_ajax_referer — phpcs:disable below is justified.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	public static function ajax_bulk_assign() {
		check_ajax_referer( 'rayetun_medianest_ajax', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'pixelvault' ) ), 403 );
		}

		$attachment_ids = array_map( 'absint', (array) ( $_POST['attachment_ids'] ?? array() ) );
		$term_ids       = array_map( 'absint', (array) ( $_POST['term_ids'] ?? array() ) );
		// wp_validate_boolean() is always available (rest_sanitize_boolean is REST-only, not always loaded).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_validate_boolean() is the appropriate sanitizer for booleans; wp_unslash applied below.
		$replace        = wp_validate_boolean( wp_unslash( (string) ( $_POST['replace'] ?? 'true' ) ) );

		$result = self::bulk_assign( $attachment_ids, $term_ids, $replace );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// -------------------------------------------------------------------------
	// Core logic
	// -------------------------------------------------------------------------

	/**
	 * Assign multiple attachments to one or more folders.
	 *
	 * @param array $attachment_ids
	 * @param array $term_ids        Empty array removes from all folders.
	 * @param bool  $replace         True = replace existing assignments. False = append.
	 * @return array|WP_Error        { processed: int, errors: int }
	 */
	public static function bulk_assign( array $attachment_ids, array $term_ids, bool $replace = true ) {
		$attachment_ids = array_map( 'absint', array_filter( $attachment_ids ) );
		$term_ids       = array_map( 'absint', array_filter( $term_ids ) );

		if ( empty( $attachment_ids ) ) {
			return new WP_Error(
				'no_attachments',
				__( 'No attachment IDs provided.', 'pixelvault' ),
				array( 'status' => 400 )
			);
		}

		// Enforce per-folder write permission and lock status on every target folder.
		foreach ( $term_ids as $target_id ) {
			if ( ! RayetunMediaNest_Folder_Service::current_user_can_manage_folder( $target_id ) ) {
				return new WP_Error(
					'forbidden',
					__( 'You do not have permission to assign files to one or more target folders.', 'pixelvault' ),
					array( 'status' => 403 )
				);
			}
			if ( RayetunMediaNest_Folder_Service::is_folder_locked( $target_id ) ) {
				return new WP_Error(
					'folder_locked',
					__( 'One or more target folders are locked.', 'pixelvault' ),
					array( 'status' => 423 )
				);
			}
		}

		$processed = 0;
		$errors    = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$errors++;
				continue;
			}

			if ( ! $replace ) {
				// Append mode — merge with existing terms.
				$existing = wp_get_object_terms( $attachment_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $existing ) ) {
					$term_ids = array_unique( array_merge( $existing, $term_ids ) );
				}
			}

			$result = wp_set_object_terms( $attachment_id, $term_ids, RayetunMediaNest_Taxonomy::TAXONOMY );

			if ( is_wp_error( $result ) ) {
				$errors++;
			} else {
				$processed++;
			}
		}

		do_action( 'rayetun_medianest_bulk_assigned', $attachment_ids, $term_ids );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'total'     => count( $attachment_ids ),
		);
	}

	/* ── Bulk Delete ─────────────────────────────────────── */

	public static function rest_bulk_delete( WP_REST_Request $request ) {
		$ids     = array_map( 'absint', (array) $request->get_param( 'attachment_ids' ) );
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'delete_post', $id ) ) { continue; }
			if ( wp_delete_attachment( $id, true ) ) { $deleted++; }
		}
		do_action( 'rayetun_medianest_attachment_assigned' );
		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	public static function ajax_bulk_delete() {
		check_ajax_referer( 'rayetun_medianest_ajax', 'nonce' );
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via absint below
		$ids     = array_map( 'absint', (array) ( wp_unslash( $_POST['ids'] ?? array() ) ) );
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'delete_post', $id ) ) { continue; }
			if ( wp_delete_attachment( $id, true ) ) { $deleted++; }
		}
		do_action( 'rayetun_medianest_attachment_assigned' );
		wp_send_json_success( array( 'deleted' => $deleted ) );
	}
}
