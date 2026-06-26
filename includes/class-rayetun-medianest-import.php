<?php
/**
 * Import existing media by rule.
 * Auto-categorizes all unassigned (or all) attachments into folders
 * by year, month, or file type.
 *
 * Compatible: PHP 7.2+  WordPress 5.0+
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Import {

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = 'rayetun-medianest/v1';

		register_rest_route( $ns, '/import/preview', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'preview' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array(
				'rule'  => array( 'type' => 'string', 'required' => true,
					'enum' => array( 'by_year', 'by_month', 'by_type' ) ),
				'scope' => array( 'type' => 'string', 'default' => 'unassigned',
					'enum' => array( 'unassigned', 'all' ) ),
			),
		) );

		register_rest_route( $ns, '/import/apply', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'apply' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
			'args'                => array(
				'rule'  => array( 'type' => 'string', 'required' => true,
					'enum' => array( 'by_year', 'by_month', 'by_type' ) ),
				'scope' => array( 'type' => 'string', 'default' => 'unassigned',
					'enum' => array( 'unassigned', 'all' ) ),
			),
		) );
	}

	/**
	 * Bulk import reassigns all site attachments without per-attachment owner checks,
	 * so it requires administrator-level capability.
	 *
	 * @return bool True if the current user can manage options.
	 */
	public static function check_permission() {
		return current_user_can( 'manage_options' );
	}

	// ── Endpoints ──────────────────────────────────────────────────────────

	public static function preview( WP_REST_Request $request ) {
		$groups = self::group_attachments(
			$request->get_param( 'rule' ),
			$request->get_param( 'scope' )
		);
		return rest_ensure_response( array(
			'groups' => $groups,
			'rule'   => $request->get_param( 'rule' ),
			'scope'  => $request->get_param( 'scope' ),
		) );
	}

	public static function apply( WP_REST_Request $request ) {
		$groups = self::group_attachments(
			$request->get_param( 'rule' ),
			$request->get_param( 'scope' )
		);

		if ( empty( $groups ) ) {
			return rest_ensure_response( array( 'created' => 0, 'assigned' => 0 ) );
		}

		$created  = 0;
		$assigned = 0;

		foreach ( $groups as $group ) {
			$term_id = self::get_or_create_folder( $group['label'] );
			if ( ! $term_id ) { continue; }
			if ( $group['is_new'] ) { $created++; }

			foreach ( $group['ids'] as $att_id ) {
				$existing = wp_get_object_terms(
					$att_id,
					RayetunMediaNest_Taxonomy::TAXONOMY,
					array( 'fields' => 'ids' )
				);
				$existing = is_wp_error( $existing ) ? array() : array_map( 'intval', $existing );

				if ( ! in_array( $term_id, $existing, true ) ) {
					$existing[] = $term_id;
					wp_set_object_terms( $att_id, $existing, RayetunMediaNest_Taxonomy::TAXONOMY );
					clean_object_term_cache( array( $att_id ), RayetunMediaNest_Taxonomy::TAXONOMY );
					$assigned++;
				}
			}
		}

		do_action( 'rayetun_medianest_attachment_assigned' );
		return rest_ensure_response( array( 'created' => $created, 'assigned' => $assigned ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private static function group_attachments( string $rule, string $scope ) {
		/*
		 * Use WP_Query directly (not get_posts). Our pre_get_posts hook bails on
		 * non-main queries, so no filter interference here.
		 */
		$query_args = array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'fields'           => 'all', /* need post_date + post_mime_type */
			'no_found_rows'    => true,
		);

		if ( 'unassigned' === $scope ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => RayetunMediaNest_Taxonomy::TAXONOMY,
					'operator' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $query_args );

		if ( empty( $query->posts ) ) {
			return array();
		}

		$raw = array();
		foreach ( $query->posts as $post ) {
			$key = self::get_group_key( $post, $rule );
			if ( null === $key ) { continue; }
			$raw[ $key ][] = (int) $post->ID;
		}

		/* Check which folder names already exist */
		$existing_terms = get_terms( array(
			'taxonomy'   => RayetunMediaNest_Taxonomy::TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'names',
		) );
		$existing_lower = array();
		if ( ! is_wp_error( $existing_terms ) ) {
			foreach ( $existing_terms as $n ) {
				$existing_lower[] = strtolower( $n );
			}
		}

		ksort( $raw );
		$groups = array();
		foreach ( $raw as $key => $ids ) {
			$label    = self::key_to_label( $key, $rule );
			$groups[] = array(
				'label'  => $label,
				'count'  => count( $ids ),
				'ids'    => $ids,
				'is_new' => ! in_array( strtolower( $label ), $existing_lower, true ),
			);
		}
		return $groups;
	}

	/**
	 * Determine which group this attachment belongs to.
	 * Uses strpos() === 0 instead of str_starts_with() for PHP 7.2 compatibility.
	 */
	private static function get_group_key( WP_Post $post, string $rule ) {
		$date = strtotime( $post->post_date );
		$mime = $post->post_mime_type;

		switch ( $rule ) {
			case 'by_year':
				return gmdate( 'Y', $date );

			case 'by_month':
				return gmdate( 'Y-m', $date );

			case 'by_type':
				/* PHP 7.2-compatible mime checks — no str_starts_with() */
				if ( 0 === strpos( $mime, 'image/' ) )       { return 'Images'; }
				if ( 0 === strpos( $mime, 'video/' ) )       { return 'Videos'; }
				if ( 0 === strpos( $mime, 'audio/' ) )       { return 'Audio'; }
				if ( false !== strpos( $mime, 'pdf' ) )      { return 'PDFs'; }
				if ( false !== strpos( $mime, 'word' ) ||
				     false !== strpos( $mime, 'document' ) ) { return 'Documents'; }
				if ( false !== strpos( $mime, 'sheet' ) ||
				     false !== strpos( $mime, 'excel' ) )    { return 'Spreadsheets'; }
				return 'Other';
		}
		return null;
	}

	private static function key_to_label( string $key, string $rule ): string {
		if ( 'by_month' === $rule ) {
			$parts = explode( '-', $key );
			if ( 2 === count( $parts ) ) {
				return gmdate( 'F Y', mktime( 0, 0, 0, (int) $parts[1], 1, (int) $parts[0] ) );
			}
		}
		return $key;
	}

	/**
	 * Find or create a folder by name.
	 * Uses FolderService::create() so slug-collision retry logic is applied.
	 */
	private static function get_or_create_folder( string $name ): ?int {
		/* Check if already exists */
		$existing = get_term_by( 'name', $name, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		/* Create via FolderService — handles slug conflicts and DB row */
		$result = RayetunMediaNest_Folder_Service::create( $name, array( 'parent_id' => 0, 'color' => '' ) );
		if ( is_wp_error( $result ) ) {
			return null;
		}
		return isset( $result['term_id'] ) ? (int) $result['term_id'] : null;
	}
}
