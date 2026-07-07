<?php
/**
 * Plugin Name:       PixelVault — Media Library Folders
 * Plugin URI:        https://wordpress.org/plugins/pixelvault/
 * Description:       Unlimited nested media folders for WordPress. Colour-coded folders, drag and drop, bulk ZIP download, analytics, role permissions, and one-click plugin migration.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Md Rayhan Uddin
 * Author URI:        https://rayetun.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixelvault
 * Domain Path:       /languages
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAYETUN_MEDIANEST_VERSION',  '1.1.1' );
define( 'RAYETUN_MEDIANEST_DIR',      plugin_dir_path( __FILE__ ) );
define( 'RAYETUN_MEDIANEST_URL',      plugin_dir_url( __FILE__ ) );
define( 'RAYETUN_MEDIANEST_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load all class files.
 * Standalone function so it can be called from both the activation hook
 * and the normal bootstrap. require_once makes repeated calls safe.
 */
function rayetun_medianest_load_classes() {
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-db.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-taxonomy.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-folder-service.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-rest-controller.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-ajax.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-admin.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-counts.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-bulk.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-query.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-auto-assign.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-export.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-import.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-gallery.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-smart-folders.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-importer.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-zip-import.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-usage.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-starred.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-templates.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-permissions.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-settings.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-analytics.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-media-replace.php';
	require_once RAYETUN_MEDIANEST_DIR . 'includes/class-rayetun-medianest-alt-editor.php';
}

/**
 * Plugin activation callback.
 */
function rayetun_medianest_activate() {
	rayetun_medianest_load_classes();
	RayetunMediaNest_DB::create_tables();
	RayetunMediaNest_Taxonomy::register();
	RayetunMediaNest_Permissions::grant_defaults();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'rayetun_medianest_activate' );

/**
 * Main plugin bootstrap — singleton.
 *
 * @package RayetunMediaNest
 */
final class RayetunMediaNest {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		rayetun_medianest_load_classes();
		$this->init_hooks();
	}

	private function init_hooks() {
		add_action( 'plugins_loaded',        array( 'RayetunMediaNest_DB', 'maybe_upgrade' ) );
		add_action( 'init',                  array( 'RayetunMediaNest_Taxonomy', 'register' ) );
		/*
		 * Belt-and-braces: ensure the custom DB row is always removed when a folder
		 * taxonomy term is deleted — regardless of whether deletion went through
		 * Folder_Service::delete() or some other code path (WP admin, WP-CLI, etc.).
		 */
		add_action( 'deleted_term',          array( __CLASS__, 'on_term_deleted' ), 10, 3 );
		add_action( 'rest_api_init',         array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( 'RayetunMediaNest_Admin', 'enqueue_assets' ) );
		/* Elementor hijacks the admin page before admin_enqueue_scripts fires.
		   We must also hook into Elementor's own enqueue action. */
		add_action( 'elementor/editor/after_enqueue_scripts', array( 'RayetunMediaNest_Admin', 'enqueue_elementor_assets' ) );
		/* Divi Frontend Builder — runs on frontend with ?et_fb=1 */
		add_action( 'wp_enqueue_scripts', array( 'RayetunMediaNest_Admin', 'enqueue_divi_frontend_assets' ) );
		new RayetunMediaNest_Ajax();
		RayetunMediaNest_Counts::register();
		RayetunMediaNest_Bulk::register();
		RayetunMediaNest_Query::register();
		RayetunMediaNest_Starred::register();
		RayetunMediaNest_Templates::register();
		RayetunMediaNest_Permissions::register();
		// Settings must register before feature checks below.
		RayetunMediaNest_Settings::register();

		// Auto-assign always registers its add_attachment listener so the
		// rayetun_medianest_auto_assign_target filter is always available to add-ons.
		// The free active-folder behaviour is gated INSIDE the handler by the
		// feature_auto_assign + auto_assign settings.
		RayetunMediaNest_AutoAssign::register();
		if ( RayetunMediaNest_Settings::get( 'feature_import_export', 1 ) ) {
			RayetunMediaNest_Export::register();
			RayetunMediaNest_Import::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_smart_filters', 1 ) ) {
			RayetunMediaNest_Smart_Folders::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_competitor_import', 1 ) ) {
			RayetunMediaNest_Importer::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_zip_import', 1 ) ) {
			RayetunMediaNest_Zip_Import::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_media_usage_map', 1 ) ) {
			RayetunMediaNest_Usage::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_gallery', 1 ) ) {
			RayetunMediaNest_Gallery::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_analytics', 1 ) ) {
			RayetunMediaNest_Analytics::register();
		}
		if ( RayetunMediaNest_Settings::get( 'feature_media_replace', 1 ) ) {
			RayetunMediaNest_Media_Replace::register();
		}
		if ( RayetunMediaNest_Settings::get( 'alt_editor_enabled', 1 ) ) {
			RayetunMediaNest_Alt_Editor::register();
		}
	}

	public function register_rest_routes() {
		$controller = new RayetunMediaNest_REST_Controller();
		$controller->register_routes();
	}

	/**
	 * Fired by the WordPress `deleted_term` action.
	 * Removes the orphaned custom-table row so stale folder entries never appear
	 * in the sidebar after the term has been deleted by any mechanism.
	 *
	 * @param int    $term_id  The term ID that was just deleted.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
		if ( RayetunMediaNest_Taxonomy::TAXONOMY === $taxonomy ) {
			RayetunMediaNest_DB::delete_folder_row( (int) $term_id );
		}
	}
}

RayetunMediaNest::get_instance();
