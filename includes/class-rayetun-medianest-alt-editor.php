<?php
/**
 * Bulk Alt Text Editor — REST endpoints for the inline alt-text editing UI.
 *
 * REST endpoints:
 *   GET  /rayetun-medianest/v1/alt-editor            — paginated list of images missing alt text
 *   POST /rayetun-medianest/v1/alt-editor/{id}       — save alt text for one attachment
 *
 * Both are consumed by the JS modal injected into the media-library sidebar
 * when the "Missing Alt Text" smart folder is the active filter.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Alt_Editor {

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public static function register_routes() {

		// GET /alt-editor — paginated images missing alt text.
		register_rest_route(
			'rayetun-medianest/v1',
			'/alt-editor',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_images' ),
				// This lists missing-alt images across the whole site, so it requires the
				// capability to edit content owned by others — not just upload_files.
				'permission_callback' => function () {
					return current_user_can( 'edit_others_posts' );
				},
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => (int) RayetunMediaNest_Settings::get( 'alt_per_page', 20 ),
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// POST /alt-editor/{id} — save alt text for one attachment.
		register_rest_route(
			'rayetun-medianest/v1',
			'/alt-editor/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_alt' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'args'                => array(
					'id'       => array( 'type' => 'integer', 'required' => true ),
					'alt_text' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of images whose _wp_attachment_image_alt is missing or empty.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_images( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		$query = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => $per_page, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'paged'                  => $page,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
			),
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$thumb    = wp_get_attachment_image_src( $post->ID, 'thumbnail' );
			$items[]  = array(
				'id'        => $post->ID,
				'filename'  => basename( (string) get_attached_file( $post->ID ) ),
				'title'     => $post->post_title,
				'thumbnail' => $thumb ? $thumb[0] : '',
				'alt'       => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			);
		}

		return rest_ensure_response( array(
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => $page,
			'per_page' => $per_page,
			'items'    => $items,
		) );
	}

	/**
	 * Save alt text for one attachment.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_alt( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$alt_text = sanitize_text_field( $request->get_param( 'alt_text' ) );

		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Attachment not found.', 'pixelvault' ),
				array( 'status' => 404 )
			);
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );

		// Flush the smart-folder count cache so the missing_alt badge updates.
		RayetunMediaNest_Smart_Folders::flush_counts();

		do_action( 'rayetun_medianest_alt_text_saved', $id, $alt_text );

		return rest_ensure_response( array(
			'success'  => true,
			'id'       => $id,
			'alt_text' => $alt_text,
		) );
	}
}
