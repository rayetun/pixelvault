<?php
/**
 * Role-Based Permissions for MediaNest.
 *
 * Defines the four custom capabilities, grants default caps on activation,
 * and revokes them on uninstall.  The settings UI lives in
 * RayetunMediaNest_Settings (Permissions tab).
 *
 * Capabilities:
 *   medianest_manage_folders    — Access the Settings page
 *   medianest_create_folders    — Create new folders
 *   medianest_edit_any_folder   — Rename / move / recolor folders owned by others
 *   medianest_delete_any_folder — Delete folders owned by others
 *
 * Administrators always receive all four capabilities on activation.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Permissions {

	const CAPS = array(
		'medianest_manage_folders',
		'medianest_create_folders',
		'medianest_edit_any_folder',
		'medianest_delete_any_folder',
	);

	// ── Registration ──────────────────────────────────────────────────────────

	/**
	 * Nothing to register here — the settings UI is owned by RayetunMediaNest_Settings.
	 * Kept for consistency; may be used for future capability-related hooks.
	 */
	public static function register() {}

	// ── Capability management ─────────────────────────────────────────────────

	/**
	 * Grant default capabilities to roles.
	 * Called on plugin activation and during schema upgrades.
	 */
	public static function grant_defaults() {
		$defaults = array(
			'administrator' => self::CAPS,
			'editor'        => array( 'medianest_create_folders', 'medianest_edit_any_folder', 'medianest_delete_any_folder' ),
			'author'        => array( 'medianest_create_folders' ),
		);

		foreach ( $defaults as $slug => $caps ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove all plugin capabilities from every role.
	 * Called on uninstall so no capability cruft is left in the database.
	 */
	public static function revoke_all() {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
