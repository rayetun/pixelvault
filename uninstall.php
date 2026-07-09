<?php
/**
 * Uninstall MediaNest.
 * Runs on plugin deletion — NOT on deactivation.
 *
 * SAFETY: folders and media-to-folder assignments are USER CONTENT, not plugin
 * settings. They are preserved by default so that deleting or reinstalling the
 * plugin can never destroy a site's organisation work. All data is removed only
 * when the site owner has explicitly enabled "Delete All Data on Uninstall" in
 * Settings. Capabilities are always revoked (harmless, re-granted on activation).
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

$rayetun_medianest_settings = (array) get_option( 'rayetun_medianest_settings', array() );

if ( ! empty( $rayetun_medianest_settings['delete_data_on_uninstall'] ) ) {
	// Explicit opt-in: erase everything, including folders and assignments.
	RayetunMediaNest_DB::drop_all();
	delete_metadata( 'user', 0, 'rayetun_mn_starred_folders', '', true );
	delete_option( 'rayetun_medianest_folder_templates' );
	delete_option( 'rayetun_medianest_settings' );
}
// Otherwise: keep folders, assignments, templates, and settings intact so a
// reinstall restores the site exactly as it was.
