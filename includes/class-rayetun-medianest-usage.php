<?php
/**
 * Media Usage Map — find every post or page where an attachment is used.
 *
 * REST endpoint: GET /rayetun-medianest/v1/attachment/{id}/usage
 *
 * Checks:
 *   1. Featured image (_thumbnail_id postmeta)
 *   2. Post content — Gutenberg wp:image block by attachment ID
 *   3. Post content — direct URL occurrence (covers Classic Editor img tags,
 *      wp:gallery blocks, page-builder shortcodes, etc.)
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Usage {

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'rayetun-medianest/v1',
			'/attachment/(?P<id>[\d]+)/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_usage' ),
				// Require edit access to the specific attachment, not just upload_files —
				// usage data reveals related post information.
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	// ── Handler ───────────────────────────────────────────────────────────────

	public static function get_usage( WP_REST_Request $request ) {
		$attachment_id = (int) $request['id'];

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		$usages = array_merge(
			self::find_featured_image_usage( $attachment_id ),
			self::find_content_usage( $attachment_id )
		);

		// Deduplicate — a post can appear once per context type.
		$seen   = array();
		$unique = array();
		foreach ( $usages as $u ) {
			$key = $u['post_id'] . ':' . $u['context'];
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$unique[]     = $u;
		}

		// Sort: featured image first, then content, then by post title.
		usort( $unique, static function ( $a, $b ) {
			$order = array( 'featured_image' => 0, 'content' => 1 );
			$oa    = $order[ $a['context'] ] ?? 9;
			$ob    = $order[ $b['context'] ] ?? 9;
			if ( $oa !== $ob ) { return $oa - $ob; }
			return strcmp( $a['title'], $b['title'] );
		} );

		return rest_ensure_response( array(
			'attachment_id' => $attachment_id,
			'total'         => count( $unique ),
			'usages'        => $unique,
		) );
	}

	// ── Finders ───────────────────────────────────────────────────────────────

	/**
	 * Find posts where this attachment is set as the featured image.
	 */
	private static function find_featured_image_usage( int $attachment_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT pm.post_id FROM %i AS pm WHERE pm.meta_key = %s AND pm.meta_value = %d',
			$wpdb->postmeta,
			'_thumbnail_id',
			$attachment_id
		) );

		if ( empty( $post_ids ) ) { return array(); }

		$result = array();
		foreach ( $post_ids as $pid ) {
			$p = get_post( (int) $pid );
			if ( ! $p || in_array( $p->post_status, array( 'inherit', 'trash', 'auto-draft' ), true ) ) { continue; }
			$result[] = self::format_usage( $p, 'featured_image' );
		}
		return $result;
	}

	/**
	 * Find posts whose content references this attachment by ID or URL.
	 *
	 * Handles:
	 *   - Gutenberg wp:image and wp:gallery blocks (ID in block attributes)
	 *   - Classic Editor <img> tags and direct URL references
	 */
	private static function find_content_usage( int $attachment_id ): array {
		global $wpdb;

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) { return array(); }

		// Strip protocol so http://… and https://… both match.
		$url_no_protocol = preg_replace( '#^https?://#', '', $url );

		// Search for Gutenberg block reference ("id":123) OR raw URL fragment.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_status, post_date
			 FROM %i
			 WHERE post_type NOT IN ('attachment','revision')
			   AND post_status NOT IN ('inherit','trash','auto-draft')
			   AND ( post_content LIKE %s
			      OR post_content LIKE %s )",
			$wpdb->posts,
			'%' . $wpdb->esc_like( '"id":' . $attachment_id ) . '%',
			'%' . $wpdb->esc_like( $url_no_protocol ) . '%'
		) );

		if ( empty( $posts ) ) { return array(); }

		$result = array();
		foreach ( $posts as $p ) {
			$result[] = self::format_usage( $p, 'content' );
		}
		return $result;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a standardised usage record from a post object.
	 *
	 * @param WP_Post|object $post
	 * @param string         $context  'featured_image' | 'content'
	 * @return array
	 */
	private static function format_usage( $post, string $context ): array {
		return array(
			'post_id'     => (int) $post->ID,
			'title'       => ( $post->post_title !== '' ) ? $post->post_title : __( '(no title)', 'pixelvault' ),
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'context'     => $context,
			'edit_url'    => (string) ( get_edit_post_link( $post->ID, 'raw' ) ?: '' ),
			'view_url'    => (string) ( get_permalink( $post->ID ) ?: '' ),
			'date'        => $post->post_date,
		);
	}
}
