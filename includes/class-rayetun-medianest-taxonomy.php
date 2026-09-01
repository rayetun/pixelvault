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
	 * The object types (post types) the folder taxonomy is registered for.
	 *
	 * Free ships media-only: the default is just 'attachment'. Add-ons (PixelVault
	 * Pro — F7 Content Foldering) extend this via the
	 * `rayetun_medianest_folder_object_types` filter to fold posts, pages,
	 * WooCommerce products, or any custom post type through the same folder engine.
	 * 'attachment' is always kept so media folders never break, no matter what a
	 * filter returns. The folder DATA layer is already object-type-aware (each folder
	 * row carries a post_type column and reads are scoped by it), so once the
	 * taxonomy is attached to a post type, wp_set_object_terms() works for it.
	 *
	 * @return string[] Sanitized, de-duplicated list of object types.
	 */
	public static function get_object_types(): array {
		/**
		 * Filter the object types the folder taxonomy is registered for.
		 *
		 * @param string[] $object_types Post types to attach folders to. Default ['attachment'].
		 */
		$types = (array) apply_filters( 'rayetun_medianest_folder_object_types', array( 'attachment' ) );
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
		if ( ! in_array( 'attachment', $types, true ) ) {
			array_unshift( $types, 'attachment' );
		}
		return $types;
	}

	/**
	 * Register the taxonomy. Hooked to 'init'.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			self::get_object_types(),
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
