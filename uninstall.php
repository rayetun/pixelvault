<?php
/**
 * Uninstall MediaNest.
 * Runs on plugin deletion — NOT on deactivation.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-rayetun-medianest-taxonomy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-rayetun-medianest-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-rayetun-medianest-permissions.php';

RayetunMediaNest_Permissions::revoke_all();
RayetunMediaNest_DB::drop_all();

// Remove per-user starred-folder meta and folder-template + settings options.
delete_metadata( 'user', 0, 'rayetun_mn_starred_folders', '', true );
delete_option( 'rayetun_medianest_folder_templates' );
delete_option( 'rayetun_medianest_settings' );
