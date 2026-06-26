<?php
/**
 * Settings — Premium SaaS-style dashboard for MediaNest.
 *
 * Registers Media → MediaNest settings page, stores plugin options,
 * and handles AJAX saves for both settings and permissions.
 *
 * Option key:  rayetun_medianest_settings
 * Body class:  rayetun-mn-settings-page
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Settings {

	const OPTION_KEY = 'rayetun_medianest_settings';

	/** Holds the hook suffix returned by add_submenu_page(). */
	private static $page_hook = '';

	const DEFAULTS = array(
		'auto_assign'        => 1,
		'show_counts'        => 1,
		'default_sort'       => 'manual',
		'sf_missing_alt'     => 1,
		'sf_unused'          => 1,
		'sf_recent'          => 1,
		'sf_large'           => 1,
		'recent_days'        => 30,
		'large_file_mb'      => 1,
		'replace_enabled'    => 1,
		'replace_cdn_notice' => 1,
		'alt_editor_enabled' => 1,
		'alt_per_page'       => 20,
		// Feature on/off toggles
		'feature_smart_filters'     => 1,
		'feature_auto_assign'       => 1,
		'feature_media_replace'     => 1,
		'feature_bulk_alt_text'     => 1,
		'feature_folder_locking'    => 1,
		'feature_analytics'         => 1,
		'feature_import_export'     => 1,
		'feature_competitor_import' => 1,
		'feature_zip_import'        => 1,
		'feature_media_usage_map'   => 1,
		'feature_gallery'           => 1,
	);

	// ── Public getter ─────────────────────────────────────────────────────────

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback if key not found (null uses built-in default).
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$settings = wp_parse_args(
			(array) get_option( self::OPTION_KEY, array() ),
			self::DEFAULTS
		);
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return null !== $fallback ? $fallback : ( self::DEFAULTS[ $key ] ?? null );
	}

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'admin_menu',            array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_body_class',      array( __CLASS__, 'add_body_class' ) );
		// Strip third-party admin notices on our page. in_admin_header fires BEFORE
		// the admin_notices / all_admin_notices hooks output, so removal here works
		// (whereas removing inside the page callback is too late).
		add_action( 'in_admin_header', array( __CLASS__, 'suppress_admin_notices' ), 1000 );
		add_action( 'wp_ajax_rayetun_mn_save_settings',    array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_rayetun_mn_save_permissions', array( __CLASS__, 'handle_save_permissions' ) );
		add_action( 'wp_ajax_rayetun_mn_get_stats',        array( __CLASS__, 'handle_get_stats' ) );
		add_action( 'wp_ajax_rayetun_mn_toggle_feature',   array( __CLASS__, 'handle_toggle_feature' ) );
	}

	// ── Menu page ─────────────────────────────────────────────────────────────

	public static function add_page() {
		self::$page_hook = (string) add_submenu_page(
			'upload.php',
			__( 'MediaNest Settings', 'pixelvault' ),
			__( 'pixelvault', 'pixelvault' ),
			'medianest_manage_folders',
			'rayetun-medianest',
			array( __CLASS__, 'render_page' )
		);
	}

	// ── Body class ────────────────────────────────────────────────────────────

	public static function add_body_class( string $classes ): string {
		if ( self::is_our_page() ) {
			$classes .= ' rayetun-mn-settings-page';
		}
		return $classes;
	}

	/**
	 * True when the current admin request is our settings page.
	 * Uses $_GET['page'] — reliable regardless of WP screen-ID naming.
	 *
	 * @return bool
	 */
	private static function is_our_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
		return isset( $_GET['page'] ) && 'rayetun-medianest' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Remove all third-party admin notices on the PixelVault settings page so the
	 * dashboard stays clean. Hooked to in_admin_header (priority 1000), which runs
	 * before the admin_notices / all_admin_notices hooks emit any output.
	 */
	public static function suppress_admin_notices() {
		if ( ! self::is_our_page() ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	// ── Asset enqueueing ──────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ) {
		// Compare against the hook suffix returned by add_submenu_page() — the only
		// guaranteed-correct way to detect this page regardless of WP version.
		if ( empty( self::$page_hook ) || $hook !== self::$page_hook ) {
			return;
		}

		wp_enqueue_style(
			'rayetun-medianest-settings',
			RAYETUN_MEDIANEST_URL . 'admin/css/rayetun-medianest-settings.css',
			array(),
			RAYETUN_MEDIANEST_VERSION
		);

		wp_enqueue_script(
			'rayetun-medianest-settings',
			RAYETUN_MEDIANEST_URL . 'admin/js/rayetun-medianest-settings.js',
			array( 'jquery' ),
			RAYETUN_MEDIANEST_VERSION,
			true
		);

		wp_localize_script(
			'rayetun-medianest-settings',
			'rayetunMNSettings',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => esc_url_raw( rest_url( 'rayetun-medianest/v1' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'nonce'       => wp_create_nonce( 'rayetun_mn_settings' ),
				'exportNonce' => wp_create_nonce( 'rayetun_medianest_ajax' ),
				'settings'    => wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::DEFAULTS ),
				'strings'   => array(
					'saving'          => __( 'Saving…', 'pixelvault' ),
					'saved'           => __( 'Saved!', 'pixelvault' ),
					'error'           => __( 'Save failed. Please try again.', 'pixelvault' ),
					'detecting'       => __( 'Scanning…', 'pixelvault' ),
					'noPlugins'       => __( 'No compatible plugins detected. Supported sources: FileBird, Real Media Library, WP Media Folder, Enhanced Media Library.', 'pixelvault' ),
					'detectError'     => __( 'Could not scan for plugins. Please refresh and try again.', 'pixelvault' ),
					'importing'       => __( 'Importing…', 'pixelvault' ),
					'importDone'      => __( 'Done', 'pixelvault' ),
					'importError'     => __( 'Import failed. Please try again.', 'pixelvault' ),
					'foldersCreated'  => __( 'folders created', 'pixelvault' ),
					'filesAssigned'   => __( 'files assigned', 'pixelvault' ),
					'alreadyExisted'  => __( 'already existed (skipped)', 'pixelvault' ),
				),
			)
		);
	}

	// ── AJAX: save settings ───────────────────────────────────────────────────

	public static function handle_save_settings() {
		check_ajax_referer( 'rayetun_mn_settings', 'nonce' );
		if ( ! current_user_can( 'medianest_manage_folders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelvault' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is sanitized individually in the $clean array below
		$raw = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();

		/*
		 * Feature flags are managed exclusively by handle_toggle_feature (instant AJAX toggle).
		 * The settings JS never submits feature_ keys, so we must read them from the DB
		 * and write them back unchanged — otherwise every Save Changes call resets them to 0.
		 */
		$saved = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::DEFAULTS );

		$clean = array(
			'auto_assign'        => ! empty( $raw['auto_assign'] ) ? 1 : 0,
			'show_counts'        => ! empty( $raw['show_counts'] ) ? 1 : 0,
			'default_sort'       => in_array( $raw['default_sort'] ?? '', array( 'manual', 'name', 'date', 'count' ), true )
			                            ? sanitize_key( $raw['default_sort'] ) : 'manual',
			'sf_missing_alt'     => ! empty( $raw['sf_missing_alt'] ) ? 1 : 0,
			'sf_unused'          => ! empty( $raw['sf_unused'] ) ? 1 : 0,
			'sf_recent'          => ! empty( $raw['sf_recent'] ) ? 1 : 0,
			'sf_large'           => ! empty( $raw['sf_large'] ) ? 1 : 0,
			'recent_days'        => min( 365, max( 1, absint( $raw['recent_days'] ?? 30 ) ) ),
			'large_file_mb'      => min( 500, max( 1, absint( $raw['large_file_mb'] ?? 1 ) ) ),
			'replace_enabled'    => ! empty( $raw['replace_enabled'] ) ? 1 : 0,
			'replace_cdn_notice' => ! empty( $raw['replace_cdn_notice'] ) ? 1 : 0,
			'alt_editor_enabled' => ! empty( $raw['alt_editor_enabled'] ) ? 1 : 0,
			'alt_per_page'       => min( 100, max( 5, absint( $raw['alt_per_page'] ?? 20 ) ) ),
			// Feature flags: always carry forward from DB — never derive from POST.
			'feature_smart_filters'     => (int) $saved['feature_smart_filters'],
			'feature_auto_assign'       => (int) $saved['feature_auto_assign'],
			'feature_media_replace'     => (int) $saved['feature_media_replace'],
			'feature_bulk_alt_text'     => (int) $saved['feature_bulk_alt_text'],
			'feature_folder_locking'    => (int) $saved['feature_folder_locking'],
			'feature_analytics'         => (int) $saved['feature_analytics'],
			'feature_import_export'     => (int) $saved['feature_import_export'],
			'feature_competitor_import' => (int) $saved['feature_competitor_import'],
			'feature_zip_import'        => (int) $saved['feature_zip_import'],
			'feature_media_usage_map'   => (int) $saved['feature_media_usage_map'],
			'feature_gallery'           => (int) $saved['feature_gallery'],
		);

		update_option( self::OPTION_KEY, $clean );
		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'pixelvault' ) ) );
	}

	// ── AJAX: save permissions ────────────────────────────────────────────────

	public static function handle_save_permissions() {
		check_ajax_referer( 'rayetun_mn_settings', 'nonce' );
		if ( ! current_user_can( 'medianest_manage_folders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelvault' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only in empty() boolean checks; role keys are validated against wp_roles() below
		$submitted = isset( $_POST['caps'] ) ? (array) wp_unslash( $_POST['caps'] ) : array();

		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( RayetunMediaNest_Permissions::CAPS as $cap ) {
				if ( ! empty( $submitted[ $slug ][ $cap ] ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		wp_send_json_success( array( 'message' => __( 'Permissions saved.', 'pixelvault' ) ) );
	}

	// ── AJAX: get live stats ──────────────────────────────────────────────────

	public static function handle_get_stats() {
		check_ajax_referer( 'rayetun_mn_settings', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		wp_send_json_success( self::get_stats() );
	}

	// ── AJAX: instant feature toggle ─────────────────────────────────────────

	public static function handle_toggle_feature() {
		check_ajax_referer( 'rayetun_mn_settings', 'nonce' );
		if ( ! current_user_can( 'medianest_manage_folders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelvault' ) ), 403 );
		}
		$key   = sanitize_key( wp_unslash( $_POST['feature'] ?? '' ) );
		$value = ! empty( $_POST['value'] ) ? 1 : 0;
		// Only accept known feature_ keys
		if ( ! isset( self::DEFAULTS[ $key ] ) || 0 !== strpos( $key, 'feature_' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid feature key.', 'pixelvault' ) ), 400 );
		}
		$settings         = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::DEFAULTS );
		$settings[ $key ] = $value;
		update_option( self::OPTION_KEY, $settings );
		wp_send_json_success( array( 'feature' => $key, 'value' => $value ) );
	}

	// ── Stats helper ──────────────────────────────────────────────────────────

	private static function get_stats(): array {
		global $wpdb;

		$total_media = (int) wp_count_posts( 'attachment' )->inherit;

		$total_folders = (int) wp_count_terms( array(
			'taxonomy'   => RayetunMediaNest_Taxonomy::TAXONOMY,
			'hide_empty' => false,
		) );

		$missing_alt = (int) ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => 1, // phpcs:ignore
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
			),
		) ) )->found_posts;

		$large_bytes = (int) self::get( 'large_file_mb', 1 ) * 1048576;
		$large_files = (int) ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1, // phpcs:ignore
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'     => RayetunMediaNest_Smart_Folders::FILESIZE_KEY,
					'value'   => $large_bytes,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		) ) )->found_posts;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$unused = (int) $wpdb->get_var(
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

		return array(
			'total_media'   => $total_media,
			'total_folders' => $total_folders,
			'missing_alt'   => $missing_alt,
			'large_files'   => $large_files,
			'unused'        => $unused,
		);
	}

	// ── Page render ───────────────────────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( 'medianest_manage_folders' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage MediaNest settings.', 'pixelvault' ) );
		}

		// Suppress all admin notices on this page.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );

		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::DEFAULTS );

		// Defensive: get_stats() runs DB queries — catch any unexpected error
		// so a failed query never produces a blank page.
		try {
			$stats = self::get_stats();
		} catch ( \Throwable $e ) {
			$stats = array(
				'total_media'   => 0,
				'total_folders' => 0,
				'missing_alt'   => 0,
				'large_files'   => 0,
				'unused'        => 0,
			);
		}

		$roles    = wp_roles()->roles;
		$cap_labels = array(
			'medianest_manage_folders'    => __( 'Manage Settings', 'pixelvault' ),
			'medianest_create_folders'    => __( 'Create Folders', 'pixelvault' ),
			'medianest_edit_any_folder'   => __( 'Edit Any Folder', 'pixelvault' ),
			'medianest_delete_any_folder' => __( 'Delete Any Folder', 'pixelvault' ),
		);
		?>
		<div class="rayetun-mn-page">

			<!-- ── Top Bar ─────────────────────────────────────────────────── -->
			<div class="rayetun-mn-topbar">
				<div class="rayetun-mn-topbar-brand">
					<span class="rayetun-mn-topbar-logo"><?php echo wp_kses( self::svg_icon( 'logo' ), self::svg_allowed_tags() );?></span>
					<span class="rayetun-mn-topbar-name"><?php esc_html_e( 'PixelVault', 'pixelvault' ); ?></span>
					<span class="rayetun-mn-topbar-version">v<?php echo esc_html( RAYETUN_MEDIANEST_VERSION ); ?></span>
				</div>
				<div class="rayetun-mn-topbar-right">
					<a href="https://wordpress.org/support/plugin/pixelvault/" target="_blank" rel="noopener" class="rayetun-mn-topbar-btn"><?php esc_html_e( 'Get Support', 'pixelvault' ); ?></a>
				</div>
			</div>

			<!-- ── Layout ──────────────────────────────────────────────────── -->
			<div class="rayetun-mn-layout">

				<!-- Sidebar -->
				<nav class="rayetun-mn-sidebar">
					<?php
					$tabs = array(
						'dashboard'   => array( 'icon' => 'dashboard',   'label' => __( 'Dashboard', 'pixelvault' ),       'feature' => null ),
						'general'     => array( 'icon' => 'general',     'label' => __( 'General', 'pixelvault' ),         'feature' => null ),
						'filters'     => array( 'icon' => 'filters',     'label' => __( 'Quick Filters', 'pixelvault' ),   'feature' => 'feature_smart_filters' ),
						'auto_assign' => array( 'icon' => 'auto_assign', 'label' => __( 'Auto Assign', 'pixelvault' ),     'feature' => 'feature_auto_assign' ),
						'replace'     => array( 'icon' => 'replace',     'label' => __( 'Media Replace', 'pixelvault' ),   'feature' => 'feature_media_replace' ),
						'locking'     => array( 'icon' => 'locking',     'label' => __( 'Folder Locking', 'pixelvault' ),  'feature' => 'feature_folder_locking' ),
						'analytics'   => array( 'icon' => 'analytics',   'label' => __( 'Analytics', 'pixelvault' ),       'feature' => 'feature_analytics' ),
						'tools'       => array( 'icon' => 'tools',       'label' => __( 'Tools', 'pixelvault' ),           'feature' => null ),
						'permissions' => array( 'icon' => 'permissions', 'label' => __( 'Permissions', 'pixelvault' ),     'feature' => null ),
					);
					foreach ( $tabs as $id => $tab ) :
						$is_hidden = $tab['feature'] && empty( $settings[ $tab['feature'] ] );
						?>
						<button class="rayetun-mn-nav-item" data-tab="<?php echo esc_attr( $id ); ?>"<?php echo $is_hidden ? ' style="display:none"' : ''; ?>>
							<span class="rayetun-mn-nav-icon"><?php echo wp_kses( self::svg_icon( $tab['icon'] ), self::svg_allowed_tags() );?></span>
							<span class="rayetun-mn-nav-label"><?php echo esc_html( $tab['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>

				<!-- Main content -->
				<div class="rayetun-mn-content">

					<!-- ── Dashboard Tab ───────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="dashboard">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Dashboard', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Overview of your media library and active features.', 'pixelvault' ); ?></p>
						</div>

						<!-- Stat cards -->
						<div class="rayetun-mn-stats-grid">
							<div class="rayetun-mn-stat-card">
								<div class="rayetun-mn-stat-icon" style="--card-color:#4f46e5"><?php echo wp_kses( self::svg_icon( 'media' ), self::svg_allowed_tags() );?></div>
								<div class="rayetun-mn-stat-body">
									<div class="rayetun-mn-stat-number" id="mn-stat-total-media"><?php echo esc_html( number_format_i18n( $stats['total_media'] ) ); ?></div>
									<div class="rayetun-mn-stat-label"><?php esc_html_e( 'Total Media', 'pixelvault' ); ?></div>
								</div>
							</div>
							<div class="rayetun-mn-stat-card">
								<div class="rayetun-mn-stat-icon" style="--card-color:#0ea5e9"><?php echo wp_kses( self::svg_icon( 'folder' ), self::svg_allowed_tags() );?></div>
								<div class="rayetun-mn-stat-body">
									<div class="rayetun-mn-stat-number" id="mn-stat-folders"><?php echo esc_html( number_format_i18n( $stats['total_folders'] ) ); ?></div>
									<div class="rayetun-mn-stat-label"><?php esc_html_e( 'Folders', 'pixelvault' ); ?></div>
								</div>
							</div>
							<div class="rayetun-mn-stat-card">
								<div class="rayetun-mn-stat-icon" style="--card-color:#f59e0b"><?php echo wp_kses( self::svg_icon( 'alt' ), self::svg_allowed_tags() );?></div>
								<div class="rayetun-mn-stat-body">
									<div class="rayetun-mn-stat-number" id="mn-stat-missing-alt"><?php echo esc_html( number_format_i18n( $stats['missing_alt'] ) ); ?></div>
									<div class="rayetun-mn-stat-label"><?php esc_html_e( 'Missing Alt Text', 'pixelvault' ); ?></div>
								</div>
							</div>
							<div class="rayetun-mn-stat-card">
								<div class="rayetun-mn-stat-icon" style="--card-color:#ef4444"><?php echo wp_kses( self::svg_icon( 'large' ), self::svg_allowed_tags() );?></div>
								<div class="rayetun-mn-stat-body">
									<div class="rayetun-mn-stat-number" id="mn-stat-large"><?php echo esc_html( number_format_i18n( $stats['large_files'] ) ); ?></div>
									<div class="rayetun-mn-stat-label"><?php esc_html_e( 'Large Files', 'pixelvault' ); ?></div>
								</div>
							</div>
							<div class="rayetun-mn-stat-card">
								<div class="rayetun-mn-stat-icon" style="--card-color:#8b5cf6"><?php echo wp_kses( self::svg_icon( 'unused' ), self::svg_allowed_tags() );?></div>
								<div class="rayetun-mn-stat-body">
									<div class="rayetun-mn-stat-number" id="mn-stat-unused"><?php echo esc_html( number_format_i18n( $stats['unused'] ) ); ?></div>
									<div class="rayetun-mn-stat-label"><?php esc_html_e( 'Unused Files', 'pixelvault' ); ?></div>
								</div>
							</div>
						</div>

						<!-- Feature status -->
							<h3 class="rayetun-mn-section-title"><?php esc_html_e( 'Active Features', 'pixelvault' ); ?></h3>
							<div class="rayetun-mn-feature-grid">
								<?php
								// key=null → always-on core feature, no toggle shown.
								$features = array(
									array( 'key' => null,                        'tab' => null,          'label' => __( 'Virtual Folders', 'pixelvault' ),    'active' => true,                                              'desc' => __( 'Unlimited nested folders for every media file.', 'pixelvault' ) ),
									array( 'key' => 'feature_smart_filters',     'tab' => 'filters',     'label' => __( 'Smart Filters', 'pixelvault' ),      'active' => (bool) $settings['feature_smart_filters'],         'desc' => __( 'Dynamic sidebar views: Missing Alt, Unused, Recent, Large Files.', 'pixelvault' ) ),
									array( 'key' => 'feature_auto_assign',       'tab' => 'auto_assign', 'label' => __( 'Auto Assign', 'pixelvault' ),        'active' => (bool) $settings['feature_auto_assign'],           'desc' => __( 'Automatically places new uploads into the active folder.', 'pixelvault' ) ),
									array( 'key' => 'feature_media_replace',     'tab' => 'replace',     'label' => __( 'Media Replace', 'pixelvault' ),      'active' => (bool) $settings['feature_media_replace'],         'desc' => __( 'Swap any file in-place keeping the same URL.', 'pixelvault' ) ),
									array( 'key' => 'feature_folder_locking',    'tab' => 'locking',     'label' => __( 'Folder Locking', 'pixelvault' ),     'active' => (bool) $settings['feature_folder_locking'],        'desc' => __( 'Lock folders to prevent accidental changes.', 'pixelvault' ) ),
									array( 'key' => 'feature_analytics',         'tab' => 'analytics',   'label' => __( 'Storage Analytics', 'pixelvault' ),  'active' => (bool) $settings['feature_analytics'],             'desc' => __( 'Per-folder storage breakdown, top files, and file type chart.', 'pixelvault' ) ),
									// Tools group — tab='' so the Tools sidebar tab never hides when these are toggled.
									// The tab itself is always visible; individual sections inside it reflect feature state.
									array( 'key' => 'feature_import_export',     'tab' => '',            'label' => __( 'Import / Export', 'pixelvault' ),    'active' => (bool) $settings['feature_import_export'],         'desc' => __( 'Export folder structure as JSON and reimport it on any site.', 'pixelvault' ) ),
									array( 'key' => 'feature_competitor_import', 'tab' => '',            'label' => __( 'Competitor Import', 'pixelvault' ),  'active' => (bool) $settings['feature_competitor_import'],     'desc' => __( 'One-click migration from FileBird, Real Media Library, and WP Media Folder.', 'pixelvault' ) ),
									array( 'key' => 'feature_zip_import',        'tab' => '',            'label' => __( 'ZIP File Import', 'pixelvault' ),    'active' => (bool) $settings['feature_zip_import'],            'desc' => __( 'Upload a .zip archive — all media inside is extracted into a folder.', 'pixelvault' ) ),
									array( 'key' => 'feature_media_usage_map',   'tab' => '',            'label' => __( 'Media Usage Map', 'pixelvault' ),    'active' => (bool) $settings['feature_media_usage_map'],       'desc' => __( 'See every post and page that references any media file.', 'pixelvault' ) ),
									array( 'key' => 'feature_gallery',           'tab' => '',            'label' => __( 'Folder Gallery', 'pixelvault' ),     'active' => (bool) $settings['feature_gallery'],               'desc' => __( 'Display any folder as a responsive gallery via Gutenberg block or shortcode.', 'pixelvault' ) ),
									array( 'key' => null,                        'tab' => 'permissions', 'label' => __( 'Role Permissions', 'pixelvault' ),   'active' => true,                                              'desc' => __( 'Control folder access per user role.', 'pixelvault' ) ),
								);
								foreach ( $features as $f ) :
									$can_toggle = ! empty( $f['key'] );
									$is_active  = $f['active'];
									?>
									<div class="rayetun-mn-feature-card <?php echo esc_attr( $is_active ? 'is-active' : 'is-inactive' ); ?>"
										<?php if ( $can_toggle ) : ?>
										data-feature="<?php echo esc_attr( $f['key'] ); ?>"
										data-tab="<?php echo esc_attr( $f['tab'] ?? '' ); ?>"
										<?php endif; ?>>
										<div class="rayetun-mn-feature-status">
											<?php if ( $can_toggle ) : ?>
												<label class="rayetun-mn-toggle">
													<input type="checkbox" class="rayetun-mn-feature-chk"
														data-feature="<?php echo esc_attr( $f['key'] ); ?>"
														data-tab="<?php echo esc_attr( $f['tab'] ?? '' ); ?>"
														<?php checked( $is_active ); ?>>
													<span class="rayetun-mn-toggle-track">
														<span class="rayetun-mn-toggle-thumb"></span>
													</span>
												</label>
											<?php else : ?>
												<?php echo wp_kses( self::svg_icon( 'check' ), self::svg_allowed_tags() );?>
											<?php endif; ?>
										</div>
										<div class="rayetun-mn-feature-info">
											<div class="rayetun-mn-feature-name"><?php echo esc_html( $f['label'] ); ?></div>
											<div class="rayetun-mn-feature-desc"><?php echo esc_html( $f['desc'] ); ?></div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<!-- Quick Actions -->
						<h3 class="rayetun-mn-section-title" style="margin-top:32px"><?php esc_html_e( 'Quick Actions', 'pixelvault' ); ?></h3>
						<div class="rayetun-mn-qa-grid">
							<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="rayetun-mn-qa-card" style="--qa:#4f46e5">
								<span class="rayetun-mn-qa-icon"><?php echo wp_kses( self::svg_icon( 'folder' ), self::svg_allowed_tags() );?></span>
								<span class="rayetun-mn-qa-body">
									<span class="rayetun-mn-qa-title"><?php esc_html_e( 'Organize Media', 'pixelvault' ); ?></span>
									<span class="rayetun-mn-qa-desc"><?php esc_html_e( 'Open the Media Library and sort files into folders.', 'pixelvault' ); ?></span>
								</span>
								<span class="rayetun-mn-qa-arrow">&rarr;</span>
							</a>
							<button type="button" class="rayetun-mn-qa-card" data-qa-tab="tools" style="--qa:#0ea5e9">
								<span class="rayetun-mn-qa-icon"><?php echo wp_kses( self::svg_icon( 'tools' ), self::svg_allowed_tags() );?></span>
								<span class="rayetun-mn-qa-body">
									<span class="rayetun-mn-qa-title"><?php esc_html_e( 'Import & Migrate', 'pixelvault' ); ?></span>
									<span class="rayetun-mn-qa-desc"><?php esc_html_e( 'Migrate from another plugin or restore a backup.', 'pixelvault' ); ?></span>
								</span>
								<span class="rayetun-mn-qa-arrow">&rarr;</span>
							</button>
							<button type="button" class="rayetun-mn-qa-card" data-qa-tab="analytics" style="--qa:#10b981">
								<span class="rayetun-mn-qa-icon"><?php echo wp_kses( self::svg_icon( 'analytics' ), self::svg_allowed_tags() );?></span>
								<span class="rayetun-mn-qa-body">
									<span class="rayetun-mn-qa-title"><?php esc_html_e( 'View Analytics', 'pixelvault' ); ?></span>
									<span class="rayetun-mn-qa-desc"><?php esc_html_e( 'Storage breakdown, largest files, and file types.', 'pixelvault' ); ?></span>
								</span>
								<span class="rayetun-mn-qa-arrow">&rarr;</span>
							</button>
							<button type="button" class="rayetun-mn-qa-card" data-qa-tab="permissions" style="--qa:#f59e0b">
								<span class="rayetun-mn-qa-icon"><?php echo wp_kses( self::svg_icon( 'permissions' ), self::svg_allowed_tags() );?></span>
								<span class="rayetun-mn-qa-body">
									<span class="rayetun-mn-qa-title"><?php esc_html_e( 'Set Permissions', 'pixelvault' ); ?></span>
									<span class="rayetun-mn-qa-desc"><?php esc_html_e( 'Control which roles can manage folders.', 'pixelvault' ); ?></span>
								</span>
								<span class="rayetun-mn-qa-arrow">&rarr;</span>
							</button>
						</div>

						<!-- Support / Review card -->
						<div class="rayetun-mn-support-card">
							<div class="rayetun-mn-support-icon">💜</div>
							<div class="rayetun-mn-support-text">
								<div class="rayetun-mn-support-title"><?php esc_html_e( 'Enjoying PixelVault?', 'pixelvault' ); ?></div>
								<p><?php esc_html_e( 'PixelVault is free and built by an independent developer. A quick review or a small donation helps keep it maintained and updated. Thank you for your support!', 'pixelvault' ); ?></p>
							</div>
							<div class="rayetun-mn-support-actions">
								<a href="https://wordpress.org/support/plugin/pixelvault/reviews/#new-post" target="_blank" rel="noopener" class="rayetun-mn-support-btn rayetun-mn-support-btn-star">
									<?php esc_html_e( '★ Leave a Review', 'pixelvault' ); ?>
								</a>
								<a href="https://wise.com/pay/me/mdrayhanu2" target="_blank" rel="noopener" class="rayetun-mn-support-btn rayetun-mn-support-btn-donate">
									<?php esc_html_e( '☕ Donate', 'pixelvault' ); ?>
								</a>
								<a href="https://wordpress.org/support/plugin/pixelvault/" target="_blank" rel="noopener" class="rayetun-mn-support-btn rayetun-mn-support-btn-ghost">
									<?php esc_html_e( 'Get Support', 'pixelvault' ); ?>
								</a>
							</div>
						</div>
					</div><!-- /dashboard -->

					<!-- ── General Tab ─────────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="general" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'General Settings', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Core folder behaviour and display options.', 'pixelvault' ); ?></p>
						</div>
						<div class="rayetun-mn-settings-list">
							<?php self::render_toggle( 'auto_assign', $settings['auto_assign'],
								__( 'Auto-assign New Uploads', 'pixelvault' ),
								__( 'When a folder is selected in the Media Library, new uploads are automatically placed in that folder.', 'pixelvault' )
							); ?>
							<?php self::render_toggle( 'show_counts', $settings['show_counts'],
								__( 'Show File Counts', 'pixelvault' ),
								__( 'Display the number of files next to each folder name in the sidebar.', 'pixelvault' )
							); ?>
							<?php self::render_select( 'default_sort', $settings['default_sort'],
								__( 'Default Folder Sort', 'pixelvault' ),
								__( 'The order in which folders appear in the media library sidebar.', 'pixelvault' ),
								array(
									'manual' => __( 'Manual (drag to reorder)', 'pixelvault' ),
									'name'   => __( 'Alphabetical (A → Z)', 'pixelvault' ),
									'date'   => __( 'Date Created (newest first)', 'pixelvault' ),
									'count'  => __( 'File Count (most files first)', 'pixelvault' ),
								)
							); ?>
						</div>
						<?php self::render_save_bar( 'settings' ); ?>
					</div><!-- /general -->

					<!-- ── Auto Assign Tab ─────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="auto_assign" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Auto Assign', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Automatically place new uploads into the currently selected folder.', 'pixelvault' ); ?></p>
						</div>
						<div class="rayetun-mn-settings-list">
							<?php self::render_toggle( 'auto_assign', $settings['auto_assign'],
								__( 'Auto-assign New Uploads', 'pixelvault' ),
								__( 'When a folder is selected in the Media Library, new uploads are automatically placed in that folder.', 'pixelvault' )
							); ?>
						</div>
						<div class="rayetun-mn-info-box" style="margin-top:24px">
							<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
							<div>
								<strong><?php esc_html_e( 'How it works', 'pixelvault' ); ?></strong>
								<p><?php esc_html_e( 'Select any folder in the Media Library sidebar before uploading. New files are automatically assigned to that folder. Deselect the folder (click it again) to return to uploading without auto-assignment.', 'pixelvault' ); ?></p>
							</div>
						</div>
						<?php self::render_save_bar( 'settings' ); ?>
					</div><!-- /auto_assign -->

					<!-- ── Quick Filters Tab ───────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="filters" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Quick Filters', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Control which smart filter folders appear in the media library sidebar.', 'pixelvault' ); ?></p>
						</div>
						<div class="rayetun-mn-settings-list">
							<?php self::render_toggle( 'sf_missing_alt', $settings['sf_missing_alt'],
								__( 'Missing Alt Text', 'pixelvault' ),
								__( 'Shows all images missing alt text — important for SEO and accessibility.', 'pixelvault' )
							); ?>
							<?php self::render_toggle( 'sf_unused', $settings['sf_unused'],
								__( 'Unused Files', 'pixelvault' ),
								__( 'Attachments not linked to any post or page — safe candidates for cleanup.', 'pixelvault' )
							); ?>
							<?php self::render_toggle( 'sf_recent', $settings['sf_recent'],
								__( 'Recent Uploads', 'pixelvault' ),
								sprintf(
									/* translators: %d: days */
									__( 'Files uploaded within the last %d days.', 'pixelvault' ),
									(int) $settings['recent_days']
								)
							); ?>
							<?php self::render_number( 'recent_days', $settings['recent_days'],
								__( 'Recent Uploads — Day Range', 'pixelvault' ),
								__( 'How many days back to consider a file "recent".', 'pixelvault' ),
								1, 365
							); ?>
							<?php self::render_toggle( 'sf_large', $settings['sf_large'],
								__( 'Large Files', 'pixelvault' ),
								__( 'Files above the threshold size — consider optimising for web.', 'pixelvault' )
							); ?>
							<?php self::render_number( 'large_file_mb', $settings['large_file_mb'],
								__( 'Large File Threshold (MB)', 'pixelvault' ),
								__( 'Files larger than this size are shown in the "Large Files" filter.', 'pixelvault' ),
								1, 500
							); ?>
						<!-- ── Bulk Alt Text Editor (sub-feature of Smart Filters) ─────── -->
						<?php self::render_toggle( 'alt_editor_enabled', $settings['alt_editor_enabled'],
							__( 'Bulk Alt Text Editor', 'pixelvault' ),
							__( 'Adds a ✏️ button next to the "Missing Alt Text" filter to batch-edit images missing alt text.', 'pixelvault' )
						); ?>
						<?php self::render_number( 'alt_per_page', $settings['alt_per_page'],
							__( 'Images per Page (Bulk Editor)', 'pixelvault' ),
							__( 'How many images to show per page in the bulk editor modal.', 'pixelvault' ),
							5, 100
						); ?>
						</div>
						<?php self::render_save_bar( 'settings' ); ?>
					</div><!-- /filters -->

					<!-- ── Media Replace Tab ───────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="replace" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Media Replace', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Swap any media file in-place, keeping the same URL and post ID.', 'pixelvault' ); ?></p>
						</div>
						<div class="rayetun-mn-settings-list">
							<?php self::render_toggle( 'replace_enabled', $settings['replace_enabled'],
								__( 'Enable Media Replace', 'pixelvault' ),
								__( 'Adds a "Replace File" button inside the media attachment editor.', 'pixelvault' )
							); ?>
							<?php self::render_toggle( 'replace_cdn_notice', $settings['replace_cdn_notice'],
								__( 'Show CDN Cache Notice', 'pixelvault' ),
								__( 'Display a reminder on the settings page about CDN/cache behaviour after replacing a file.', 'pixelvault' )
							); ?>
						</div>

						<div class="rayetun-mn-info-box" style="margin-top:24px">
							<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
							<div>
								<strong><?php esc_html_e( 'How it works', 'pixelvault' ); ?></strong>
								<p><?php esc_html_e( 'The replacement file is written to the same path on the server, overwriting the original. The attachment URL, post ID, and all folder assignments remain unchanged. Thumbnails are regenerated automatically. Page caches are purged via standard WordPress hooks.', 'pixelvault' ); ?></p>
								<p><?php esc_html_e( 'CDN static-file caches (Cloudflare, BunnyCDN, etc.) cache the file by URL — they cannot be purged without that CDN\'s API key. If the old image still appears via a direct URL after replacing, purge the image URL in your CDN dashboard.', 'pixelvault' ); ?></p>
							</div>
						</div>

						<?php self::render_save_bar( 'settings' ); ?>
					</div><!-- /replace -->

					<!-- ── Analytics Tab ──────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="analytics" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Storage Analytics', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Media library usage breakdown — storage used, file types, and largest files.', 'pixelvault' ); ?></p>
						</div>
						<div id="mn-analytics-inner">
							<div class="mn-analytics-loading">
								<span class="mn-spin">&#8635;</span>
								<?php esc_html_e( 'Loading analytics…', 'pixelvault' ); ?>
							</div>
						</div>
					</div><!-- /analytics -->

					<!-- ── Folder Locking Tab ──────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="locking" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Folder Locking', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Prevent accidental changes to important folders.', 'pixelvault' ); ?></p>
						</div>
						<div class="rayetun-mn-info-box">
							<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
							<div>
								<strong><?php esc_html_e( 'How it works', 'pixelvault' ); ?></strong>
								<p><?php esc_html_e( 'Right-click any folder in the Media Library sidebar and choose "Lock Folder". Locked folders display a padlock badge and cannot be renamed, deleted, moved, or have files reassigned until explicitly unlocked.', 'pixelvault' ); ?></p>
								<p><?php esc_html_e( 'Only users with the "Manage Settings" capability can lock or unlock folders.', 'pixelvault' ); ?></p>
							</div>
						</div>
					</div><!-- /locking -->

					<!-- ── Tools Tab ───────────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="tools" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Tools', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Import, migration, and utility tools for your media library.', 'pixelvault' ); ?></p>
						</div>

						<?php
						$any_tool = $settings['feature_import_export'] || $settings['feature_competitor_import']
							|| $settings['feature_zip_import'] || $settings['feature_media_usage_map'];
						?>
						<div class="rayetun-mn-tools-empty" <?php echo $any_tool ? 'style="display:none"' : ''; ?>>
							<div class="rayetun-mn-info-box">
								<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
								<div><p><?php esc_html_e( 'All tools are currently disabled. Enable them from the Dashboard to see options here.', 'pixelvault' ); ?></p></div>
							</div>
						</div>

						<!-- ── Folder Structure Export / Import (JSON) ── -->
						<div class="rayetun-mn-tools-section" data-feature-section="feature_import_export" <?php echo $settings['feature_import_export'] ? '' : 'style="display:none"'; ?>>
							<h3 class="rayetun-mn-section-title"><?php esc_html_e( 'Folder Structure Backup', 'pixelvault' ); ?></h3>
							<div class="rayetun-mn-info-box" style="margin-bottom:16px">
								<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
								<div>
									<p><?php esc_html_e( 'Export your entire folder structure (names, hierarchy, colours, and icons) as a JSON file. Restore it on another site or keep it as a backup. Your media files are never included or modified.', 'pixelvault' ); ?></p>
								</div>
							</div>
							<div class="rayetun-mn-tools-actions">
								<button type="button" class="rayetun-mn-save-btn" id="mn-export-structure-btn"><?php esc_html_e( 'Export Folders (JSON)', 'pixelvault' ); ?></button>
								<label class="rayetun-mn-save-btn rayetun-mn-btn-secondary" for="mn-import-structure-file"><?php esc_html_e( 'Import Folders (JSON)', 'pixelvault' ); ?></label>
								<input type="file" id="mn-import-structure-file" accept="application/json,.json" style="display:none">
								<span class="rayetun-mn-save-status" id="mn-import-structure-status" aria-live="polite"></span>
							</div>
						</div>

						<!-- ── Import from competitors ── -->
						<div class="rayetun-mn-tools-section" data-feature-section="feature_competitor_import" <?php echo $settings['feature_competitor_import'] ? '' : 'style="display:none"'; ?>>
							<h3 class="rayetun-mn-section-title" style="margin-top:32px"><?php esc_html_e( 'Import from Another Plugin', 'pixelvault' ); ?></h3>
							<div class="rayetun-mn-info-box" style="margin-bottom:20px">
								<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
								<div>
									<p><?php esc_html_e( 'If you are switching from FileBird, Real Media Library, WP Media Folder, or Enhanced Media Library, PixelVault can copy your existing folder structure and file assignments automatically. Your original plugin data is not modified.', 'pixelvault' ); ?></p>
								</div>
							</div>
							<div id="mn-tools-migrate-area">
								<button type="button" class="rayetun-mn-save-btn" id="mn-tools-detect-btn"><?php esc_html_e( 'Scan for Importable Plugins', 'pixelvault' ); ?></button>
							</div>
						</div>

						<!-- ── ZIP Import info ── -->
						<div class="rayetun-mn-tools-section" data-feature-section="feature_zip_import" <?php echo $settings['feature_zip_import'] ? '' : 'style="display:none"'; ?>>
							<h3 class="rayetun-mn-section-title" style="margin-top:32px"><?php esc_html_e( 'ZIP File Import', 'pixelvault' ); ?></h3>
							<div class="rayetun-mn-info-box">
								<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
								<div>
									<p><?php esc_html_e( 'To import a ZIP archive into a folder, right-click any folder in the Media Library sidebar and choose "Import ZIP to folder". All images, videos, audio files, and PDFs inside the archive will be extracted and added to that folder automatically.', 'pixelvault' ); ?></p>
								</div>
							</div>
						</div>

						<!-- ── Media Usage info ── -->
						<div class="rayetun-mn-tools-section" data-feature-section="feature_media_usage_map" <?php echo $settings['feature_media_usage_map'] ? '' : 'style="display:none"'; ?>>
							<h3 class="rayetun-mn-section-title" style="margin-top:32px"><?php esc_html_e( 'Media Usage Map', 'pixelvault' ); ?></h3>
							<div class="rayetun-mn-info-box">
								<div class="rayetun-mn-info-box-icon"><?php echo wp_kses( self::svg_icon( 'info' ), self::svg_allowed_tags() );?></div>
								<div>
									<p><?php esc_html_e( 'To find where any file is used, right-click an image in the Media Library and choose "Where is this used?". PixelVault will show you every post, page, or custom post type that references the file — as a featured image or in the content.', 'pixelvault' ); ?></p>
								</div>
							</div>
						</div>
					</div><!-- /tools -->

					<!-- ── Permissions Tab ─────────────────────────────────── -->
					<div class="rayetun-mn-panel" data-panel="permissions" style="display:none">
						<div class="rayetun-mn-panel-header">
							<h2><?php esc_html_e( 'Folder Permissions', 'pixelvault' ); ?></h2>
							<p><?php esc_html_e( 'Control which roles can perform folder actions. Administrators always have full access.', 'pixelvault' ); ?></p>
						</div>

						<div class="rayetun-mn-perms-wrap">
							<table class="rayetun-mn-perms-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Role', 'pixelvault' ); ?></th>
										<?php foreach ( $cap_labels as $cap => $label ) : ?>
											<th><?php echo esc_html( $label ); ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $roles as $slug => $data ) :
										$role     = get_role( $slug );
										$is_admin = ( 'administrator' === $slug );
										if ( ! $role ) continue;
										?>
										<tr>
											<td class="rayetun-mn-perms-role">
												<strong><?php echo esc_html( translate_user_role( $data['name'] ) ); ?></strong>
												<?php if ( $is_admin ) : ?>
													<span class="rayetun-mn-perms-badge"><?php esc_html_e( 'Full Access', 'pixelvault' ); ?></span>
												<?php endif; ?>
											</td>
											<?php foreach ( array_keys( $cap_labels ) as $cap ) :
												$checked = $is_admin || ! empty( $role->capabilities[ $cap ] );
												?>
												<td>
													<label class="rayetun-mn-perms-toggle">
														<input
															type="checkbox"
															name="rayetun_mn_caps[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $cap ); ?>]"
															value="1"
															<?php checked( $checked ); ?>
															<?php disabled( $is_admin ); ?>
														>
														<span class="rayetun-mn-perms-switch"></span>
													</label>
												</td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php self::render_save_bar( 'permissions' ); ?>
					</div><!-- /permissions -->

				</div><!-- /content -->
			</div><!-- /layout -->
		</div><!-- /page -->
		<?php
	}

	// ── Render helpers ────────────────────────────────────────────────────────

	private static function render_toggle( string $key, $value, string $label, string $desc ) {
		?>
		<div class="rayetun-mn-setting-row">
			<div class="rayetun-mn-setting-info">
				<div class="rayetun-mn-setting-label"><?php echo esc_html( $label ); ?></div>
				<div class="rayetun-mn-setting-desc"><?php echo esc_html( $desc ); ?></div>
			</div>
			<label class="rayetun-mn-toggle">
				<input type="checkbox" data-setting="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( (bool) $value ); ?>>
				<span class="rayetun-mn-toggle-track">
					<span class="rayetun-mn-toggle-thumb"></span>
				</span>
			</label>
		</div>
		<?php
	}

	private static function render_select( string $key, $value, string $label, string $desc, array $options ) {
		?>
		<div class="rayetun-mn-setting-row">
			<div class="rayetun-mn-setting-info">
				<div class="rayetun-mn-setting-label"><?php echo esc_html( $label ); ?></div>
				<div class="rayetun-mn-setting-desc"><?php echo esc_html( $desc ); ?></div>
			</div>
			<select class="rayetun-mn-select" data-setting="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $options as $opt_val => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	private static function render_number( string $key, $value, string $label, string $desc, int $min, int $max ) {
		?>
		<div class="rayetun-mn-setting-row">
			<div class="rayetun-mn-setting-info">
				<div class="rayetun-mn-setting-label"><?php echo esc_html( $label ); ?></div>
				<div class="rayetun-mn-setting-desc"><?php echo esc_html( $desc ); ?></div>
			</div>
			<div class="rayetun-mn-number-wrap">
				<button type="button" class="rayetun-mn-num-btn" data-dir="-1">−</button>
				<input
					type="number"
					class="rayetun-mn-number-input"
					data-setting="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( (int) $value ); ?>"
					min="<?php echo esc_attr( $min ); ?>"
					max="<?php echo esc_attr( $max ); ?>"
				>
				<button type="button" class="rayetun-mn-num-btn" data-dir="1">+</button>
			</div>
		</div>
		<?php
	}

	private static function render_save_bar( string $type ) {
		?>
		<div class="rayetun-mn-save-bar">
			<button type="button" class="rayetun-mn-save-btn" data-save-type="<?php echo esc_attr( $type ); ?>">
				<?php esc_html_e( 'Save Changes', 'pixelvault' ); ?>
			</button>
			<span class="rayetun-mn-save-status" aria-live="polite"></span>
		</div>
		<?php
	}

	// ── SVG icons ─────────────────────────────────────────────────────────────

	private static function svg_icon( string $name ): string {
		$icons = array(
			'logo'        => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>',
			'dashboard'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
			'general'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93A10 10 0 1 0 4.93 19.07 10 10 0 0 0 19.07 4.93z"/></svg>',
			'filters'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
			'replace'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
			'alt'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
			'permissions' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
			'media'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
			'folder'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
			'large'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
			'unused'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
			'check'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
			'off'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
			'info'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
			'analytics'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
			'tools'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
			'auto_assign' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="12 11 12 17"/><polyline points="9 14 12 17 15 14"/></svg>',
			'locking'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
		);
		return $icons[ $name ] ?? '';
	}

	/**
	 * Allowed SVG tags/attributes for escaping icon markup with wp_kses().
	 * The icons are hardcoded above, but we still escape on output as a
	 * defence-in-depth measure and to satisfy escaping requirements.
	 *
	 * @return array
	 */
	private static function svg_allowed_tags(): array {
		$attr = array(
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'class'           => true,
			'xmlns'           => true,
			'aria-hidden'     => true,
			'style'           => true,
		);
		return array(
			'svg'      => $attr,
			'g'        => $attr,
			'path'     => array_merge( $attr, array( 'd' => true ) ),
			'rect'     => array_merge( $attr, array( 'x' => true, 'y' => true, 'rx' => true, 'ry' => true ) ),
			'circle'   => array_merge( $attr, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
			'line'     => array_merge( $attr, array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ) ),
			'polyline' => array_merge( $attr, array( 'points' => true ) ),
			'polygon'  => array_merge( $attr, array( 'points' => true ) ),
		);
	}
}
