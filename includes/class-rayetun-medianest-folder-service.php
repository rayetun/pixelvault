<?php
/**
 * Folder service layer — all business logic, zero HTTP knowledge.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Folder_Service {

	// -------------------------------------------------------------------------
	// CREATE
	// -------------------------------------------------------------------------

	public static function create( string $name, array $args = array() ) {
		if ( ! current_user_can( 'medianest_create_folders' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to create folders.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		$name = sanitize_text_field( $name );

		if ( empty( $name ) ) {
			return new WP_Error( 'invalid_name', __( 'Folder name cannot be empty.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $name ) > 200 ) {
			return new WP_Error( 'name_too_long', __( 'Folder name cannot exceed 200 characters.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$parent_id = absint( $args['parent_id'] ?? 0 );

		if ( $parent_id > 0 && ! self::folder_exists( $parent_id ) ) {
			return new WP_Error( 'invalid_parent', __( 'Parent folder does not exist.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$result = wp_insert_term(
			$name,
			RayetunMediaNest_Taxonomy::TAXONOMY,
			array( 'parent' => $parent_id )
		);

		// 'term_exists' can happen when a previously-deleted folder had the same slug.
		// WP may keep a slug lock in cache. Retry with a unique slug so the user
		// can always re-create a folder with any name.
		if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
			$unique_slug = sanitize_title( $name ) . '-' . substr( uniqid(), -6 );
			$result      = wp_insert_term(
				$name,
				RayetunMediaNest_Taxonomy::TAXONOMY,
				array( 'parent' => $parent_id, 'slug' => $unique_slug )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = absint( $result['term_id'] );

		$folder_id = RayetunMediaNest_DB::insert_folder( $term_id, array_merge( $args, array(
			'parent_id' => $parent_id,
			'owner_id'  => get_current_user_id(),
		) ) );

		if ( false === $folder_id ) {
			wp_delete_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
			return new WP_Error( 'db_insert_failed', __( 'Failed to save folder metadata. Please try again.', 'pixelvault' ), array( 'status' => 500 ) );
		}

		do_action( 'rayetun_medianest_folder_created', $term_id, $folder_id, $args );

		return array( 'term_id' => $term_id, 'folder_id' => $folder_id );
	}

	// -------------------------------------------------------------------------
	// READ
	// -------------------------------------------------------------------------

	public static function get_flat( string $post_type = 'attachment' ) {
		return apply_filters(
			'rayetun_medianest_get_folders',
			RayetunMediaNest_DB::get_visible_folders( get_current_user_id(), sanitize_key( $post_type ) ),
			$post_type
		);
	}

	public static function get_tree( string $post_type = 'attachment' ) {
		return self::build_tree( self::get_flat( $post_type ) );
	}

	public static function get_one( int $term_id ) {
		return RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
	}

	// -------------------------------------------------------------------------
	// UPDATE
	// -------------------------------------------------------------------------

	public static function rename( int $term_id, string $new_name ) {
		if ( self::is_locked( $term_id ) ) {
			return new WP_Error( 'folder_locked', __( 'This folder is locked and cannot be modified.', 'pixelvault' ), array( 'status' => 423 ) );
		}
		if ( ! self::current_user_can_edit( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to rename this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		$new_name = sanitize_text_field( $new_name );

		if ( empty( $new_name ) ) {
			return new WP_Error( 'invalid_name', __( 'Folder name cannot be empty.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$result = wp_update_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'name' => $new_name ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		RayetunMediaNest_DB::touch_folder( $term_id );
		do_action( 'rayetun_medianest_folder_renamed', $term_id, $new_name );

		return true;
	}

	public static function update_meta( int $term_id, array $data ) {
		// is_locked changes are handled by set_lock() and bypass the lock guard.
		$is_lock_change = array_key_exists( 'is_locked', $data ) && count( $data ) === 1;
		if ( ! $is_lock_change && self::is_locked( $term_id ) ) {
			return new WP_Error( 'folder_locked', __( 'This folder is locked and cannot be modified.', 'pixelvault' ), array( 'status' => 423 ) );
		}
		if ( ! self::current_user_can_edit( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to update this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		$sanitized = array();

		if ( array_key_exists( 'color', $data ) ) {
			$color = sanitize_hex_color( $data['color'] );
			if ( null === $color && ! empty( $data['color'] ) ) {
				return new WP_Error( 'invalid_color', __( 'Invalid color value.', 'pixelvault' ), array( 'status' => 400 ) );
			}
			$sanitized['color'] = $color ?? '';
		}

		if ( array_key_exists( 'icon', $data ) ) {
			$sanitized['icon'] = sanitize_key( $data['icon'] );
		}

		if ( array_key_exists( 'visibility', $data ) ) {
			$allowed = array( 'all', 'roles', 'owner' );
			if ( ! in_array( $data['visibility'], $allowed, true ) ) {
				return new WP_Error( 'invalid_visibility', __( 'Invalid visibility value.', 'pixelvault' ), array( 'status' => 400 ) );
			}
			$sanitized['visibility'] = $data['visibility'];
		}

		if ( array_key_exists( 'allowed_roles', $data ) ) {
			$valid_roles = array_keys( wp_roles()->roles );
			$roles       = array_values( array_filter(
				(array) $data['allowed_roles'],
				static function ( $r ) use ( $valid_roles ) {
					return in_array( $r, $valid_roles, true );
				}
			) );
			$sanitized['allowed_roles'] = wp_json_encode( $roles );
		}

		if ( array_key_exists( 'write_roles', $data ) ) {
			$valid_roles = array_keys( wp_roles()->roles );
			$roles       = array_values( array_filter(
				(array) $data['write_roles'],
				static function ( $r ) use ( $valid_roles ) {
					return in_array( $r, $valid_roles, true );
				}
			) );
			$sanitized['write_roles'] = wp_json_encode( $roles );
		}

		if ( empty( $sanitized ) ) {
			return new WP_Error( 'no_data', __( 'No valid fields to update.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$result = RayetunMediaNest_DB::update_folder_meta( $term_id, $sanitized );

		if ( ! is_wp_error( $result ) ) {
			/**
			 * Fires after a folder's metadata (colour, icon, visibility, roles) is updated.
			 *
			 * @param int   $term_id   Folder term ID.
			 * @param array $sanitized The sanitized fields that were written.
			 */
			do_action( 'rayetun_medianest_folder_meta_updated', $term_id, $sanitized );
		}

		return $result;
	}

	public static function move( int $term_id, int $new_parent_id ) {
		if ( self::is_locked( $term_id ) ) {
			return new WP_Error( 'folder_locked', __( 'This folder is locked and cannot be moved.', 'pixelvault' ), array( 'status' => 423 ) );
		}
		if ( ! self::current_user_can_edit( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to move this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		if ( $new_parent_id === $term_id ) {
			return new WP_Error( 'circular_ref', __( 'A folder cannot be its own parent.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		if ( $new_parent_id > 0 && self::is_descendant( $new_parent_id, $term_id ) ) {
			return new WP_Error( 'circular_ref', __( 'Cannot move a folder into one of its own descendants.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		if ( $new_parent_id > 0 && ! self::folder_exists( $new_parent_id ) ) {
			return new WP_Error( 'invalid_parent', __( 'Target parent folder does not exist.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$existing   = RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
		$old_parent = $existing ? (int) $existing->parent_id : 0;

		$result = wp_update_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY, array( 'parent' => $new_parent_id ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$meta_result = RayetunMediaNest_DB::update_folder_meta( $term_id, array(
			'parent_id'  => $new_parent_id,
			'updated_at' => current_time( 'mysql' ),
		) );

		if ( ! is_wp_error( $meta_result ) ) {
			/**
			 * Fires after a folder is moved to a new parent.
			 *
			 * @param int $term_id    Folder term ID.
			 * @param int $new_parent New parent term ID (0 = root).
			 * @param int $old_parent Previous parent term ID (0 = root).
			 */
			do_action( 'rayetun_medianest_folder_moved', $term_id, $new_parent_id, $old_parent );
		}

		return $meta_result;
	}

	public static function reorder( array $ordered_term_ids ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'medianest_edit_any_folder' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to reorder folders.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		foreach ( $ordered_term_ids as $sort_order => $term_id ) {
			RayetunMediaNest_DB::update_folder_meta( absint( $term_id ), array(
				'sort_order' => absint( $sort_order ),
				'updated_at' => current_time( 'mysql' ),
			) );
		}

		do_action( 'rayetun_medianest_folders_reordered', $ordered_term_ids );
		return true;
	}

	// -------------------------------------------------------------------------
	// DELETE
	// -------------------------------------------------------------------------

	public static function delete( int $term_id ) {
		if ( self::is_locked( $term_id ) ) {
			return new WP_Error( 'folder_locked', __( 'This folder is locked and cannot be deleted.', 'pixelvault' ), array( 'status' => 423 ) );
		}
		if ( ! self::current_user_can_delete( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		$folder = RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
		if ( ! $folder ) {
			return new WP_Error( 'not_found', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		RayetunMediaNest_DB::reparent_children( $term_id, absint( $folder->parent_id ) );

		// Capture attachment IDs before deletion so we can clean per-object term cache.
		// wp_delete_term() removes rows from wp_term_relationships, but persistent
		// object caches may still hold stale term data per attachment.
		$attachment_ids = get_objects_in_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );

		$result = wp_delete_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clear per-attachment term cache so uncategorized count is immediately correct.
		if ( ! is_wp_error( $attachment_ids ) && ! empty( $attachment_ids ) ) {
			clean_object_term_cache( array_map( 'intval', $attachment_ids ), RayetunMediaNest_Taxonomy::TAXONOMY );
		}

		RayetunMediaNest_DB::delete_folder_row( $term_id );
		do_action( 'rayetun_medianest_folder_deleted', $term_id, $folder );

		return true;
	}

	// -------------------------------------------------------------------------
	// LOCK / UNLOCK
	// -------------------------------------------------------------------------

	/**
	 * Lock or unlock a folder. Only administrators may call this.
	 *
	 * @param int  $term_id
	 * @param bool $locked  true = lock, false = unlock.
	 * @return true|WP_Error
	 */
	public static function set_lock( int $term_id, bool $locked ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only administrators can lock or unlock folders.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		if ( ! self::folder_exists( $term_id ) ) {
			return new WP_Error( 'not_found', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		$result = RayetunMediaNest_DB::update_folder_meta( $term_id, array( 'is_locked' => $locked ? 1 : 0 ) );
		do_action( 'rayetun_medianest_folder_lock_changed', $term_id, $locked );
		return $result;
	}

	// -------------------------------------------------------------------------
	// ASSIGNMENT
	// -------------------------------------------------------------------------

	public static function assign_attachment( int $attachment_id, array $term_ids ) {
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to move this file.', 'pixelvault' ), array( 'status' => 403 ) );
		}
		foreach ( $term_ids as $term_id ) {
			$tid = absint( $term_id );
			if ( ! self::current_user_can_edit( $tid ) ) {
				return new WP_Error( 'forbidden', __( 'You do not have permission to assign files to one or more target folders.', 'pixelvault' ), array( 'status' => 403 ) );
			}
			if ( self::is_locked( $tid ) ) {
				return new WP_Error( 'folder_locked', __( 'One or more target folders are locked.', 'pixelvault' ), array( 'status' => 423 ) );
			}
		}

		$term_ids = array_map( 'absint', $term_ids );
		$result   = wp_set_object_terms( $attachment_id, $term_ids, RayetunMediaNest_Taxonomy::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after an attachment is assigned to one or more folders.
		 *
		 * Canonical signature (always passed): attachment ID, target term IDs, context string.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param int[]  $term_ids      Folder term IDs the attachment now belongs to.
		 * @param string $context       Where the assignment came from (e.g. 'manual', 'auto_assign').
		 */
		do_action( 'rayetun_medianest_attachment_assigned', $attachment_id, $term_ids, 'manual' );
		return true;
	}

	// -------------------------------------------------------------------------
	// PRIVATE HELPERS
	// -------------------------------------------------------------------------

	private static function build_tree( array $flat, int $parent_id = 0 ) {
		$tree = array();
		foreach ( $flat as $folder ) {
			if ( (int) $folder->parent_id === $parent_id ) {
				$folder->children = self::build_tree( $flat, (int) $folder->term_id );
				$tree[]           = $folder;
			}
		}
		return $tree;
	}

	private static function folder_exists( int $term_id ) {
		return null !== RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
	}

	private static function is_locked( int $term_id ): bool {
		$folder = RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
		return $folder && ! empty( $folder->is_locked );
	}

	/**
	 * Public wrapper for lock status, for use by other classes (e.g. bulk assign).
	 *
	 * @param int $term_id Folder term ID.
	 * @return bool
	 */
	public static function is_folder_locked( int $term_id ): bool {
		return self::is_locked( $term_id );
	}

	/**
	 * Public wrapper: whether the current user may write to (manage assignments in)
	 * a folder. Used by other classes such as the bulk-assign controller.
	 *
	 * @param int $term_id Folder term ID.
	 * @return bool
	 */
	public static function current_user_can_manage_folder( int $term_id ): bool {
		return self::current_user_can_edit( $term_id );
	}

	private static function is_descendant( int $maybe_descendant, int $ancestor_term_id ) {
		$all     = RayetunMediaNest_DB::get_all_folders_raw();
		$checked = array();
		$current = $maybe_descendant;

		while ( $current > 0 ) {
			if ( isset( $checked[ $current ] ) ) {
				break;
			}
			$checked[ $current ] = true;

			foreach ( $all as $row ) {
				if ( (int) $row->term_id === $current ) {
					$current = (int) $row->parent_id;
					if ( $current === $ancestor_term_id ) {
						return true;
					}
					break;
				}
			}
		}

		return false;
	}

	private static function current_user_can_edit( int $term_id ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'medianest_edit_any_folder' ) ) {
			return true;
		}

		$folder = RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
		if ( ! $folder ) {
			return false;
		}

		if ( (int) $folder->owner_id === get_current_user_id() ) {
			return true;
		}

		// Per-folder write_roles: specific roles can edit even without global cap.
		$write_roles = json_decode( $folder->write_roles ?? '[]', true );
		if ( ! empty( $write_roles ) && is_array( $write_roles ) ) {
			$user = wp_get_current_user();
			if ( $user->exists() && array_intersect( (array) $user->roles, $write_roles ) ) {
				return true;
			}
		}

		return false;
	}

	private static function current_user_can_delete( int $term_id ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'medianest_delete_any_folder' ) ) {
			return true;
		}

		$folder = RayetunMediaNest_DB::get_folder_by_term_id( $term_id );
		return $folder && (int) $folder->owner_id === get_current_user_id();
	}

	// -------------------------------------------------------------------------
	// REMOVE FROM SPECIFIC FOLDER
	// -------------------------------------------------------------------------

	/**
	 * Remove one or more attachments from a specific folder only.
	 * Other folder assignments are preserved.
	 *
	 * @param int   $term_id
	 * @param array $attachment_ids
	 * @return array|WP_Error
	 */
	public static function remove_attachments( int $term_id, array $attachment_ids ) {
		if ( ! self::folder_exists( $term_id ) ) {
			return new WP_Error( 'not_found', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		// The user must be allowed to manage this folder.
		if ( ! self::current_user_can_edit( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		// A locked folder cannot have its assignments changed.
		if ( self::is_locked( $term_id ) ) {
			return new WP_Error( 'folder_locked', __( 'This folder is locked.', 'pixelvault' ), array( 'status' => 423 ) );
		}

		$attachment_ids = array_map( 'absint', array_filter( $attachment_ids ) );
		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'no_attachments', __( 'No attachment IDs provided.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$processed = 0;
		$errors    = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$errors++;
				continue;
			}
			$result = wp_remove_object_terms( $attachment_id, $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
			if ( is_wp_error( $result ) || false === $result ) {
				$errors++;
			} else {
				clean_object_term_cache( array( $attachment_id ), RayetunMediaNest_Taxonomy::TAXONOMY );
				$processed++;
			}
		}

		/**
		 * Fires after one or more attachments are removed from a specific folder.
		 *
		 * @param int   $term_id        Folder term ID they were removed from.
		 * @param int[] $attachment_ids Attachment IDs that were processed.
		 */
		do_action( 'rayetun_medianest_attachments_removed', $term_id, $attachment_ids );

		// Generic cache-flush signal (counts, smart folders, analytics).
		do_action( 'rayetun_medianest_media_changed' );

		return array( 'processed' => $processed, 'errors' => $errors );
	}

}
