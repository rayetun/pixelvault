<?php
/**
 * Query filtering for MediaNest.
 *
 * Reads the active folder from a cookie set by the JS sidebar.
 * The cookie approach is version-agnostic: it works regardless of
 * which internal WP Backbone method fires the AJAX query, because
 * cookies are sent with every HTTP request automatically.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Query {

	/** Cookie name used by both PHP and JS. */
	const COOKIE_NAME = 'rayetun_mn_folder';

	public static function register() {
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_ajax_query' ) );
		add_action( 'pre_get_posts',               array( __CLASS__, 'filter_pre_get_posts' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX grid / modal filter
	// -------------------------------------------------------------------------

	/**
	 * Filter the query-attachments AJAX request.
	 *
	 * Priority of folder sources:
	 *   1. JS-injected prop in $query (wp.ajax.post patch or modal)
	 *   2. Cookie set by the JS sidebar when user clicks a folder
	 *
	 * @param array $query
	 * @return array
	 */
	public static function filter_ajax_query( array $query ) {
		$folder_param = '';

		// Source 1: prop injected by our wp.ajax.post patch or modal filter.
		if ( isset( $query['rayetun_medianest_folder'] ) ) {
			$folder_param = (string) $query['rayetun_medianest_folder'];
			unset( $query['rayetun_medianest_folder'] );
		}

		// Source 2: cookie set by JS sidebar — works for ALL AJAX calls regardless
		// of WP version or which internal Query method fires (args vs props).
		if ( '' === $folder_param && isset( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$folder_param = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}

		if ( '' === $folder_param ) {
			return $query;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $query;
		}

		/* Smart folder: cookie value starts with "smart:" */
		if ( RayetunMediaNest_Smart_Folders::is_smart_slug( $folder_param ) ) {
			$type = RayetunMediaNest_Smart_Folders::extract_type( $folder_param );
			return RayetunMediaNest_Smart_Folders::apply_query_args( $query, $type );
		}

		return self::apply_folder_filter( $query, $folder_param );
	}

	// -------------------------------------------------------------------------
	// List-view filter (pre_get_posts on upload.php)
	// -------------------------------------------------------------------------

	public static function filter_pre_get_posts( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $q->get( 'post_type' ) ) {
			return;
		}

		$folder_param = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a read-only pre_get_posts filter applied to the attachment query on upload.php. The URL param is a convenience shortcut; primary filter source is the cookie. No state changes are made here.
		if ( isset( $_GET['rayetun_folder'] ) ) {
			$folder_param = sanitize_text_field( wp_unslash( $_GET['rayetun_folder'] ) );
		} elseif ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Cookie sanitized immediately below with sanitize_text_field.
			$folder_param = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $folder_param || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		/* Smart folder in list view */
		if ( RayetunMediaNest_Smart_Folders::is_smart_slug( $folder_param ) ) {
			$type = RayetunMediaNest_Smart_Folders::extract_type( $folder_param );
			$args = RayetunMediaNest_Smart_Folders::apply_query_args( array(), $type );
			foreach ( $args as $key => $value ) {
				$q->set( $key, $value );
			}
			return;
		}

		$tax_query = self::build_tax_query( $folder_param );
		if ( ! empty( $tax_query ) ) {
			$existing   = $q->get( 'tax_query' );
			$existing   = is_array( $existing ) ? $existing : array();
			$existing[] = $tax_query;
			$q->set( 'tax_query', $existing ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- intentional folder filter; taxonomy is indexed.
		}
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	private static function apply_folder_filter( array $query, string $folder_param ) {
		$tax_query = self::build_tax_query( $folder_param );
		if ( empty( $tax_query ) ) {
			return $query;
		}
		$existing             = isset( $query['tax_query'] ) && is_array( $query['tax_query'] )
			? $query['tax_query'] : array();
		$existing[]           = $tax_query;
		$query['tax_query']   = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- tax_query is required for folder filtering; the taxonomy is indexed and queries are term-scoped.
		return $query;
	}

	private static function build_tax_query( string $folder_param ) {
		if ( 'uncategorized' === $folder_param ) {
			return array(
				'taxonomy' => RayetunMediaNest_Taxonomy::TAXONOMY,
				'operator' => 'NOT EXISTS',
			);
		}

		$term_id = absint( $folder_param );
		if ( $term_id < 1 ) {
			return null;
		}

		$term = get_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return array(
			'taxonomy'         => RayetunMediaNest_Taxonomy::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => array( $term_id ),
			'operator'         => 'IN',
			'include_children' => false,
		);
	}
}
