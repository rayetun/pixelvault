<?php
/**
 * Registers the rayetun_medianest_folder taxonomy.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Taxonomy {

	const TAXONOMY = 'rayetun_medianest_folder';

	/**
	 * Register the taxonomy. Hooked to 'init'.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			array( 'attachment' ),
			array(
				'labels'             => array(
					'name'          => __( 'Media Folders', 'pixelvault' ),
					'singular_name' => __( 'Media Folder', 'pixelvault' ),
					'search_items'  => __( 'Search Folders', 'pixelvault' ),
					'all_items'     => __( 'All Folders', 'pixelvault' ),
					'edit_item'     => __( 'Edit Folder', 'pixelvault' ),
					'update_item'   => __( 'Update Folder', 'pixelvault' ),
					'add_new_item'  => __( 'Add New Folder', 'pixelvault' ),
					'new_item_name' => __( 'New Folder Name', 'pixelvault' ),
					'menu_name'     => __( 'Media Folders', 'pixelvault' ),
				),
				// Never expose on frontend — virtual folders only.
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				// Required for Gutenberg and REST-based media queries.
				'show_in_rest'       => true,
				'rest_base'          => 'rayetun_medianest_folder',
				// Hierarchical so WP's parent/child internals work correctly.
				'hierarchical'       => true,
				// No frontend rewrites — pure virtual taxonomy.
				'rewrite'            => false,
				'query_var'          => false,
			)
		);

		// Register our cache group so persistent object caches know it's non-persistent.
		wp_cache_add_non_persistent_groups( array( RayetunMediaNest_DB::CACHE_GROUP ) );
	}
}
