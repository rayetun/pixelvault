<?php
/**
 * AJAX transport layer for MediaNest.
 *
 * Thin wrapper only - zero business logic.
 * All operations delegate to RayetunMediaNest_Folder_Service.
 *
 * Nonce: 'rayetun_medianest_ajax' verified via check_ajax_referer() in verify().
 * The phpcs:ignore directives below suppress false-positive NonceVerification
 * warnings: WPCS cannot statically trace verify() as a nonce check, but
 * check_ajax_referer() inside it will wp_die() on failure before any $_POST
 * access occurs.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Ajax {

	public function __construct() {
		$actions = array(
			'rayetun_medianest_get_folders',
			'rayetun_medianest_create_folder',
			'rayetun_medianest_update_folder',
			'rayetun_medianest_delete_folder',
			'rayetun_medianest_reorder_folders',
			'rayetun_medianest_assign_attachment',
		);

		foreach ( $actions as $action ) {
			$method = str_replace( 'rayetun_medianest_', '', $action );
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify nonce and capability. Dies on failure via check_ajax_referer().
	 * All $_POST access in handler methods is safe after this call returns.
	 */
	private function verify() {
		check_ajax_referer( 'rayetun_medianest_ajax', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage folders.', 'pixelvault' ) ),
				403
			);
		}
	}

	/**
	 * Send a service result. Handles WP_Error automatically.
	 *
	 * @param mixed $result
	 * @param int   $success_code
	 */
	private function send( $result, int $success_code = 200 ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}
		wp_send_json_success( $result, $success_code );
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// Nonce verified via verify() / check_ajax_referer() before any $_POST access.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	// -------------------------------------------------------------------------

	public function get_folders() {
		$this->verify();

		$post_type = sanitize_key( $_POST['post_type'] ?? 'attachment' );
		$format    = sanitize_key( $_POST['format'] ?? 'tree' );

		$data = ( 'flat' === $format )
			? RayetunMediaNest_Folder_Service::get_flat( $post_type )
			: RayetunMediaNest_Folder_Service::get_tree( $post_type );

		$this->send( $data );
	}

	public function create_folder() {
		$this->verify();

		$result = RayetunMediaNest_Folder_Service::create(
			sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			array(
				'parent_id'     => absint( $_POST['parent_id'] ?? 0 ),
				'color'         => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '' ) ) ?? '',
				'icon'          => sanitize_key( $_POST['icon'] ?? '' ),
				'visibility'    => sanitize_key( $_POST['visibility'] ?? 'all' ),
				'allowed_roles' => array_map( 'sanitize_key', (array) ( $_POST['allowed_roles'] ?? array() ) ),
				'post_type'     => sanitize_key( $_POST['post_type'] ?? 'attachment' ),
			)
		);

		$this->send( $result, 201 );
	}

	public function update_folder() {
		$this->verify();

		$term_id = absint( $_POST['term_id'] ?? 0 );

		if ( ! empty( $_POST['name'] ) ) {
			$r = RayetunMediaNest_Folder_Service::rename(
				$term_id,
				sanitize_text_field( wp_unslash( $_POST['name'] ) )
			);
			if ( is_wp_error( $r ) ) {
				$this->send( $r );
			}
		}

		if ( isset( $_POST['parent_id'] ) ) {
			$r = RayetunMediaNest_Folder_Service::move( $term_id, absint( $_POST['parent_id'] ) );
			if ( is_wp_error( $r ) ) {
				$this->send( $r );
			}
		}

		$meta = array();

		if ( isset( $_POST['color'] ) ) {
			$meta['color'] = sanitize_hex_color( wp_unslash( $_POST['color'] ) ) ?? '';
		}
		if ( isset( $_POST['icon'] ) ) {
			$meta['icon'] = sanitize_key( $_POST['icon'] );
		}
		if ( isset( $_POST['visibility'] ) ) {
			$meta['visibility'] = sanitize_key( $_POST['visibility'] );
		}
		if ( isset( $_POST['allowed_roles'] ) ) {
			$meta['allowed_roles'] = array_map( 'sanitize_key', (array) $_POST['allowed_roles'] );
		}

		if ( ! empty( $meta ) ) {
			$r = RayetunMediaNest_Folder_Service::update_meta( $term_id, $meta );
			if ( is_wp_error( $r ) ) {
				$this->send( $r );
			}
		}

		$this->send( RayetunMediaNest_Folder_Service::get_one( $term_id ) );
	}

	public function delete_folder() {
		$this->verify();

		$result = RayetunMediaNest_Folder_Service::delete( absint( $_POST['term_id'] ?? 0 ) );
		$this->send( $result );
	}

	public function reorder_folders() {
		$this->verify();

		$order  = array_map( 'absint', (array) ( $_POST['order'] ?? array() ) );
		$result = RayetunMediaNest_Folder_Service::reorder( $order );
		$this->send( $result );
	}

	public function assign_attachment() {
		$this->verify();

		$result = RayetunMediaNest_Folder_Service::assign_attachment(
			absint( $_POST['attachment_id'] ?? 0 ),
			array_map( 'absint', (array) ( $_POST['term_ids'] ?? array() ) )
		);
		$this->send( $result );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
