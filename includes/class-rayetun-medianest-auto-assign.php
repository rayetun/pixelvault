<?php
/**
 * Auto-assign newly uploaded attachments to the active folder.
 *
 * When a user uploads while a folder is active (cookie set), every new
 * attachment is automatically placed in that folder. Works for:
 *   - Upload via "Add Media File" button on upload.php
 *   - Upload via the editor media modal (when folder is selected)
 *   - Programmatic uploads that trigger add_attachment
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_AutoAssign {

	const COOKIE_NAME = 'rayetun_mn_folder';

	public static function register() {
		add_action( 'add_attachment', array( __CLASS__, 'assign_on_upload' ), 10, 1 );
	}

	/**
	 * Fires after a new attachment is created.
	 * Reads the active folder from the cookie and assigns the attachment.
	 *
	 * @param int $attachment_id
	 */
	public static function assign_on_upload( int $attachment_id ) {
		// Bail early if auto-assign is disabled in Settings.
		if ( ! (int) RayetunMediaNest_Settings::get( 'auto_assign', 1 ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitized below.
		$cookie_val = isset( $_COOKIE[ self::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';

		if ( empty( $cookie_val ) || 'uncategorized' === $cookie_val ) {
			return; // No active folder — leave uncategorized.
		}

		$term_id = absint( $cookie_val );
		if ( $term_id < 1 ) {
			return;
		}

		// Verify the folder/term exists in our taxonomy.
		$term = get_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		// Assign — append to any existing terms (don't replace).
		$existing = wp_get_object_terms( $attachment_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'fields' => 'ids' ) );
		$existing = is_wp_error( $existing ) ? array() : array_map( 'intval', $existing );

		if ( ! in_array( $term_id, $existing, true ) ) {
			$existing[] = $term_id;
			wp_set_object_terms( $attachment_id, $existing, RayetunMediaNest_Taxonomy::TAXONOMY );
		}

		do_action( 'rayetun_medianest_attachment_assigned', $attachment_id, array( $term_id ) );
	}
}
