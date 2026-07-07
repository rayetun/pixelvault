<?php
/**
 * Admin integration for MediaNest.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Admin {

	/**
	 * Enqueue admin assets on Media Library and editor screens.
	 * Hooked to 'admin_enqueue_scripts'.
	 *
	 * @param string $hook  Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ) {
		$is_media_library = ( 'upload.php' === $hook );
		$is_editor        = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		/*
		 * Elementor editor pages use post.php with action=elementor.
		 * They are already covered by $is_editor above (hook = post.php).
		 * We detect Elementor separately to pass the flag to JS, so the
		 * integration code knows to patch Elementor media controls.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_elementor = $is_editor && isset( $_GET['action'] ) && 'elementor' === sanitize_key( wp_unslash( $_GET['action'] ) );

		if ( ! $is_media_library && ! $is_editor ) {
			return;
		}

		$mn_admin_css = RAYETUN_MEDIANEST_DIR . 'admin/css/rayetun-medianest-admin.css';
		wp_enqueue_style(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/css/rayetun-medianest-admin.css',
			array(),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_css ) ? '.' . filemtime( $mn_admin_css ) : '' )
		);

		$mn_admin_js = RAYETUN_MEDIANEST_DIR . 'admin/js/rayetun-medianest-admin.js';
		wp_enqueue_script(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/js/rayetun-medianest-admin.js',
			array( 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-hooks' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_js ) ? '.' . filemtime( $mn_admin_js ) : '' ),
			true
		);

		wp_localize_script(
			'rayetun-medianest-admin',
			'rayetunMediaNestData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'restUrl'        => esc_url_raw( rest_url( 'rayetun-medianest/v1' ) ),
				'nonce'          => wp_create_nonce( 'rayetun_medianest_ajax' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'version'        => RAYETUN_MEDIANEST_VERSION,
				'isMediaLibrary' => $is_media_library,
				'isElementor'    => $is_elementor,
				'caps'           => array(
					'createFolders'   => current_user_can( 'medianest_create_folders' ),
					'editAnyFolder'   => current_user_can( 'medianest_edit_any_folder' ),
					'deleteAnyFolder' => current_user_can( 'medianest_delete_any_folder' ),
					'lockFolders'     => current_user_can( 'manage_options' ),
					'editOthersMedia' => current_user_can( 'edit_others_posts' ),
					'reorderFolders'  => current_user_can( 'manage_options' ) || current_user_can( 'medianest_edit_any_folder' ),
				),
				'starred'        => RayetunMediaNest_Starred::get_starred(),
				'settings'       => array(
					'showCounts'       => (bool) RayetunMediaNest_Settings::get( 'show_counts', 1 ),
					'defaultSort'      => (string) RayetunMediaNest_Settings::get( 'default_sort', 'manual' ),
					'altEditorEnabled' => (bool) RayetunMediaNest_Settings::get( 'alt_editor_enabled', 1 ),
					'altPerPage'       => (int) RayetunMediaNest_Settings::get( 'alt_per_page', 20 ),
				),
				'features'       => array(
					'smartFilters'     => (bool) RayetunMediaNest_Settings::get( 'feature_smart_filters', 1 ),
					'autoAssign'       => (bool) RayetunMediaNest_Settings::get( 'feature_auto_assign', 1 ),
					'folderLocking'    => (bool) RayetunMediaNest_Settings::get( 'feature_folder_locking', 1 ),
					'bulkAltText'      => (bool) RayetunMediaNest_Settings::get( 'feature_bulk_alt_text', 1 ),
					'zipImport'        => (bool) RayetunMediaNest_Settings::get( 'feature_zip_import', 1 ),
					'mediaUsageMap'    => (bool) RayetunMediaNest_Settings::get( 'feature_media_usage_map', 1 ),
					'importExport'     => (bool) RayetunMediaNest_Settings::get( 'feature_import_export', 1 ),
					'competitorImport' => (bool) RayetunMediaNest_Settings::get( 'feature_competitor_import', 1 ),
				),
				'strings'        => array(
					'newFolder'     => __( 'New Folder', 'pixelvault' ),
					'renameFolder'  => __( 'Rename', 'pixelvault' ),
					'deleteFolder'  => __( 'Delete Folder', 'pixelvault' ),
					'confirmDelete' => __( 'Delete this folder? Files will not be deleted.', 'pixelvault' ),
					'lockFolder'    => __( 'Lock Folder', 'pixelvault' ),
					'unlockFolder'  => __( 'Unlock Folder', 'pixelvault' ),
					'folderLocked'  => __( 'This folder is locked.', 'pixelvault' ),
					'allFiles'      => __( 'All Files', 'pixelvault' ),
					'uncategorized' => __( 'Uncategorized', 'pixelvault' ),
					'errorGeneric'  => __( 'Something went wrong. Please try again.', 'pixelvault' ),
					'addToFolder'   => __( 'Add to folder\u2026', 'pixelvault' ),
				),
			)
		);

		wp_set_script_translations( 'rayetun-medianest-admin', 'pixelvault', RAYETUN_MEDIANEST_DIR . 'languages/' );
	}

	/**
	 * Enqueue assets inside the Elementor editor.
	 *
	 * Elementor hijacks the admin page before admin_enqueue_scripts fires,
	 * so our normal enqueue_assets() never runs on action=elementor pages.
	 * This method hooks into Elementor's own enqueue pipeline.
	 *
	 * Hooked to 'elementor/editor/after_enqueue_scripts'.
	 */
	public static function enqueue_elementor_assets() {
		$mn_admin_css = RAYETUN_MEDIANEST_DIR . 'admin/css/rayetun-medianest-admin.css';
		wp_enqueue_style(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/css/rayetun-medianest-admin.css',
			array(),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_css ) ? '.' . filemtime( $mn_admin_css ) : '' )
		);

		$mn_admin_js = RAYETUN_MEDIANEST_DIR . 'admin/js/rayetun-medianest-admin.js';
		wp_enqueue_script(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/js/rayetun-medianest-admin.js',
			array( 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-hooks' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_js ) ? '.' . filemtime( $mn_admin_js ) : '' ),
			true
		);

		wp_localize_script(
			'rayetun-medianest-admin',
			'rayetunMediaNestData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'restUrl'        => esc_url_raw( rest_url( 'rayetun-medianest/v1' ) ),
				'nonce'          => wp_create_nonce( 'rayetun_medianest_ajax' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'version'        => RAYETUN_MEDIANEST_VERSION,
				'isMediaLibrary' => false,
				'isElementor'    => true,
				'caps'           => array(
					'createFolders'   => current_user_can( 'medianest_create_folders' ),
					'editAnyFolder'   => current_user_can( 'medianest_edit_any_folder' ),
					'deleteAnyFolder' => current_user_can( 'medianest_delete_any_folder' ),
					'lockFolders'     => current_user_can( 'manage_options' ),
					'editOthersMedia' => current_user_can( 'edit_others_posts' ),
					'reorderFolders'  => current_user_can( 'manage_options' ) || current_user_can( 'medianest_edit_any_folder' ),
				),
				'starred'        => RayetunMediaNest_Starred::get_starred(),
				'settings'       => array(
					'showCounts'       => (bool) RayetunMediaNest_Settings::get( 'show_counts', 1 ),
					'defaultSort'      => (string) RayetunMediaNest_Settings::get( 'default_sort', 'manual' ),
					'altEditorEnabled' => (bool) RayetunMediaNest_Settings::get( 'alt_editor_enabled', 1 ),
					'altPerPage'       => (int) RayetunMediaNest_Settings::get( 'alt_per_page', 20 ),
				),
				'strings'        => array(
					'newFolder'     => __( 'New Folder', 'pixelvault' ),
					'renameFolder'  => __( 'Rename', 'pixelvault' ),
					'deleteFolder'  => __( 'Delete Folder', 'pixelvault' ),
					'confirmDelete' => __( 'Delete this folder? Files will not be deleted.', 'pixelvault' ),
					'lockFolder'    => __( 'Lock Folder', 'pixelvault' ),
					'unlockFolder'  => __( 'Unlock Folder', 'pixelvault' ),
					'folderLocked'  => __( 'This folder is locked.', 'pixelvault' ),
					'allFiles'      => __( 'All Files', 'pixelvault' ),
					'uncategorized' => __( 'Uncategorized', 'pixelvault' ),
					'errorGeneric'  => __( 'Something went wrong. Please try again.', 'pixelvault' ),
					'addToFolder'   => __( 'Add to folder…', 'pixelvault' ),
				),
			)
		);

		wp_set_script_translations( 'rayetun-medianest-admin', 'pixelvault', RAYETUN_MEDIANEST_DIR . 'languages/' );
	}
	/**
	 * Enqueue assets for the Divi Frontend Builder (?et_fb=1).
	 * Divi's frontend builder runs on the public site URL — admin_enqueue_scripts
	 * does not fire there, so we hook into wp_enqueue_scripts with a capability check.
	 */
	public static function enqueue_divi_frontend_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['et_fb'] ) || '1' !== sanitize_key( wp_unslash( $_GET['et_fb'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$mn_admin_css = RAYETUN_MEDIANEST_DIR . 'admin/css/rayetun-medianest-admin.css';
		wp_enqueue_style(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/css/rayetun-medianest-admin.css',
			array(),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_css ) ? '.' . filemtime( $mn_admin_css ) : '' )
		);

		$mn_admin_js = RAYETUN_MEDIANEST_DIR . 'admin/js/rayetun-medianest-admin.js';
		wp_enqueue_script(
			'rayetun-medianest-admin',
			RAYETUN_MEDIANEST_URL . 'admin/js/rayetun-medianest-admin.js',
			array( 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-hooks' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( $mn_admin_js ) ? '.' . filemtime( $mn_admin_js ) : '' ),
			true
		);

		wp_localize_script(
			'rayetun-medianest-admin',
			'rayetunMediaNestData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'restUrl'        => esc_url_raw( rest_url( 'rayetun-medianest/v1' ) ),
				'nonce'          => wp_create_nonce( 'rayetun_medianest_ajax' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'version'        => RAYETUN_MEDIANEST_VERSION,
				'isMediaLibrary' => false,
				'isElementor'    => false,
				'caps'           => array(
					'createFolders'   => current_user_can( 'medianest_create_folders' ),
					'editAnyFolder'   => current_user_can( 'medianest_edit_any_folder' ),
					'deleteAnyFolder' => current_user_can( 'medianest_delete_any_folder' ),
					'lockFolders'     => current_user_can( 'manage_options' ),
					'editOthersMedia' => current_user_can( 'edit_others_posts' ),
					'reorderFolders'  => current_user_can( 'manage_options' ) || current_user_can( 'medianest_edit_any_folder' ),
				),
				'starred'        => RayetunMediaNest_Starred::get_starred(),
				'settings'       => array(
					'showCounts'       => (bool) RayetunMediaNest_Settings::get( 'show_counts', 1 ),
					'defaultSort'      => (string) RayetunMediaNest_Settings::get( 'default_sort', 'manual' ),
					'altEditorEnabled' => (bool) RayetunMediaNest_Settings::get( 'alt_editor_enabled', 1 ),
					'altPerPage'       => (int) RayetunMediaNest_Settings::get( 'alt_per_page', 20 ),
				),
				'strings'        => array(
					'newFolder'     => __( 'New Folder', 'pixelvault' ),
					'allFiles'      => __( 'All Files', 'pixelvault' ),
					'errorGeneric'  => __( 'Something went wrong. Please try again.', 'pixelvault' ),
				),
			)
		);
	}

}

