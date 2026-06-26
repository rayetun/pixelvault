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
