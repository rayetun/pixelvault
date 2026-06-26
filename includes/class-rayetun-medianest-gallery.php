<?php
/**
 * PixelVault Gallery — Shortcode + Gutenberg Block
 *
 * Shortcode: [pixelvault_gallery folder="name" columns="3" lightbox="yes"]
 * Block:     rayetun-medianest/gallery (server-side rendered)
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Gallery {

	const VERSION = RAYETUN_MEDIANEST_VERSION;

	public static function register() {
		add_shortcode( 'pixelvault_gallery', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init',             array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
	}

	// ── Public entry points ──────────────────────────────────────

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( self::default_atts(), $atts, 'pixelvault_gallery' );
		self::enqueue_frontend();
		return self::build_html( $atts );
	}

	public static function render_block( $atts ) {
		self::enqueue_frontend();
		return self::build_html( array_merge( self::default_atts(), (array) $atts ) );
	}

	// ── Block registration ───────────────────────────────────────

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) { return; }

		$block_js   = 'admin/js/rayetun-medianest-gallery-block.js';
		$block_path = RAYETUN_MEDIANEST_DIR . $block_js;
		// Append filemtime() so the editor always loads the current file, even when the
		// plugin version constant hasn't changed (prevents a stale cached block title).
		$block_ver  = self::VERSION . ( file_exists( $block_path ) ? '.' . filemtime( $block_path ) : '' );

		wp_register_script(
			'rayetun-medianest-gallery-block',
			RAYETUN_MEDIANEST_URL . $block_js,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components',
			       'wp-i18n', 'wp-api-fetch', 'wp-server-side-render' ),
			$block_ver,
			true
		);

		register_block_type( 'rayetun-medianest/gallery', array(
			'editor_script'   => 'rayetun-medianest-gallery-block',
			'render_callback' => array( __CLASS__, 'render_block' ),
			'attributes'      => array(
				'folder_id'    => array( 'type' => 'number',  'default' => 0 ),
				'columns'      => array( 'type' => 'number',  'default' => 3 ),
				'size'         => array( 'type' => 'string',  'default' => 'medium' ),
				'aspect_ratio' => array( 'type' => 'string',  'default' => 'auto' ),
				'gap'          => array( 'type' => 'number',  'default' => 12 ),
				'lightbox'     => array( 'type' => 'boolean', 'default' => true ),
				'captions'     => array( 'type' => 'boolean', 'default' => false ),
				'orderby'      => array( 'type' => 'string',  'default' => 'date' ),
				'order'        => array( 'type' => 'string',  'default' => 'DESC' ),
				'limit'        => array( 'type' => 'number',  'default' => 50 ),
			),
		) );
	}

	// ── Frontend asset enqueueing ────────────────────────────────

	public static function enqueue_frontend() {
		wp_enqueue_style(
			'rayetun-medianest-gallery',
			RAYETUN_MEDIANEST_URL . 'public/css/rayetun-medianest-gallery.css',
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'rayetun-medianest-gallery',
			RAYETUN_MEDIANEST_URL . 'public/js/rayetun-medianest-gallery.js',
			array(),
			self::VERSION,
			true
		);
	}

	// ── Core rendering ───────────────────────────────────────────

	private static function build_html( array $a ): string {
		$images = self::get_images( $a );
		if ( empty( $images ) ) {
			if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				$name = $a['folder_id'] ? '#' . absint( $a['folder_id'] ) : esc_html( $a['folder'] );
				/* translators: %s: folder name or ID */
				$empty_msg = sprintf( esc_html__( 'No images found in folder: %s', 'pixelvault' ), $name );
				return '<p class="rmn-gallery-empty">' . $empty_msg . '</p>';
			}
			return '';
		}

		$cols   = max( 1, min( 6, (int) $a['columns'] ) );
		$gap    = max( 0, (int) $a['gap'] );
		$ratio  = in_array( $a['aspect_ratio'], array( 'square','landscape','portrait' ), true )
		          ? 'is-' . $a['aspect_ratio'] : '';
		$lb     = filter_var( $a['lightbox'], FILTER_VALIDATE_BOOLEAN );
		$caps   = filter_var( $a['captions'], FILTER_VALIDATE_BOOLEAN );
		$uid    = 'rmn-g-' . wp_unique_id();

		$style = '--rmn-cols:' . $cols . ';--rmn-gap:' . $gap . 'px';
		$html  = '<div id="' . esc_attr( $uid ) . '" class="rmn-gallery ' .
		         esc_attr( $ratio ) . '" style="' . esc_attr( $style ) . '">';

		foreach ( $images as $id ) {
			$img_html = wp_get_attachment_image( $id, $a['size'], false, array(
				'class'   => 'rmn-gallery-img',
				'loading' => 'lazy',
			) );
			if ( ! $img_html ) { continue; }

			$caption  = $caps ? wp_get_attachment_caption( $id ) : '';
			$full_url = wp_get_attachment_url( $id );

			$html .= '<figure class="rmn-gallery-item">';
			if ( $lb && $full_url ) {
				$lb_cap = esc_attr( $caption ?: get_the_title( $id ) );
				$html .= '<a class="rmn-lightbox-trigger" href="' . esc_url( $full_url ) . '"'
				       . ' data-gallery="' . esc_attr( $uid ) . '"'
				       . ' data-caption="' . $lb_cap . '"'
				       . ' aria-label="' . esc_attr( __( 'Open image', 'pixelvault' ) ) . '">';
			}
			$html .= $img_html;
			if ( $lb && $full_url ) { $html .= '</a>'; }
			if ( $caps && $caption ) {
				$html .= '<figcaption class="rmn-gallery-caption">'
				       . esc_html( $caption ) . '</figcaption>';
			}
			$html .= '</figure>';
		}

		$html .= '</div>';
		return $html;
	}

	// ── Data fetching ────────────────────────────────────────────

	private static function get_images( array $a ): array {
		/* Resolve folder term */
		$term = null;
		if ( ! empty( $a['folder_id'] ) ) {
			$term = get_term( (int) $a['folder_id'], RayetunMediaNest_Taxonomy::TAXONOMY );
		} elseif ( ! empty( $a['folder'] ) ) {
			$term = get_term_by( 'name', sanitize_text_field( $a['folder'] ),
			        RayetunMediaNest_Taxonomy::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				$term = get_term_by( 'slug', sanitize_title( $a['folder'] ),
				        RayetunMediaNest_Taxonomy::TAXONOMY );
			}
		}
		if ( ! $term || is_wp_error( $term ) ) { return array(); }

		$allowed_orderby = array( 'date', 'title', 'rand', 'menu_order', 'ID' );
		$orderby = in_array( $a['orderby'], $allowed_orderby, true ) ? $a['orderby'] : 'date';
		$order   = strtoupper( $a['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$query = new WP_Query( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => min( 200, max( 1, (int) $a['limit'] ) ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'orderby'          => $orderby,
			'order'            => $order,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'        => array( array(
				'taxonomy' => RayetunMediaNest_Taxonomy::TAXONOMY,
				'terms'    => $term->term_id,
			) ),
		) );

		return $query->have_posts() ? wp_list_pluck( $query->posts, 'ID' ) : array();
	}

	private static function default_atts(): array {
		return array(
			'folder'       => '',
			'folder_id'    => 0,
			'columns'      => 3,
			'size'         => 'medium',
			'aspect_ratio' => 'auto',
			'gap'          => 12,
			'lightbox'     => true,
			'captions'     => false,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'limit'        => 50,
		);
	}
}
