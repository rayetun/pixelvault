<?php
/**
 * Blocks Manager — a "Blocks" settings tab that lists every PixelVault block (free and
 * Pro) and lets the site owner switch each one on or off.
 *
 * A single option, `rayetun_medianest_block_states` (block name => bool, missing = on),
 * gates block registration. The free blocks read it here; PixelVault Pro reads the very
 * same helper for its blocks, so one screen controls the whole family. Pro blocks that
 * aren't installed show as locked "Pro" cards (a gentle upsell), never a broken toggle.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Blocks_Manager {

	const OPTION    = 'rayetun_medianest_block_states';
	const NAMESPACE = 'rayetun-medianest/v1';

	public static function register() {
		add_action( 'rest_api_init',                            array( __CLASS__, 'register_routes' ) );
		add_filter( 'rayetun_medianest_settings_tabs',          array( __CLASS__, 'add_tab' ), 20, 2 );
		add_action( 'rayetun_medianest_render_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_enqueue_scripts',                    array( __CLASS__, 'enqueue' ) );
	}

	/** Whether a block is switched on (default: yes). Read by both free and Pro. */
	public static function is_enabled( string $block_name ): bool {
		$states = (array) get_option( self::OPTION, array() );
		return ! array_key_exists( $block_name, $states ) || (bool) $states[ $block_name ];
	}

	private static function pro_active(): bool {
		return RayetunMediaNest::pro_is_active();
	}

	private static function pro_url(): string {
		return class_exists( 'RayetunMediaNest_Pro_Upsell' )
			? RayetunMediaNest_Pro_Upsell::pro_url()
			: 'https://checkout.freemius.com/plugin/39343/plan/67509/';
	}

	private static function icon( string $paths ): string {
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
	}

	/** Every PixelVault block: [ name, title, desc, icon, pro ]. */
	private static function blocks(): array {
		return array(
			array(
				'name'  => 'rayetun-medianest/document-library',
				'title' => __( 'Document Library', 'pixelvault' ),
				'desc'  => __( 'Publish any folder as a browsable, downloadable file library with a folder tree, search and per-file downloads.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/><path d="M15 4v5h5"/><path d="M8 13h8M8 17h5"/>' ),
				'pro'   => false,
			),
			array(
				'name'  => 'rayetun-medianest/gallery',
				'title' => __( 'Gallery', 'pixelvault' ),
				'desc'  => __( 'Display any folder as a responsive image gallery on the front end.', 'pixelvault' ),
				'icon'  => self::icon( '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>' ),
				'pro'   => false,
			),
			array(
				'name'  => 'pixelvault-pro/download-cart',
				'title' => __( 'Multi-Select Download Cart', 'pixelvault' ),
				'desc'  => __( 'Browse a folder, tick the files you want, and download them together as a single ZIP.', 'pixelvault' ),
				'icon'  => self::icon( '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/metadata-search',
				'title' => __( 'Media Metadata Search', 'pixelvault' ),
				'desc'  => __( 'A front-end search box that finds files by title and by the custom metadata you store on them.', 'pixelvault' ),
				'icon'  => self::icon( '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/qr-share',
				'title' => __( 'QR Code Share', 'pixelvault' ),
				'desc'  => __( 'Show a scannable QR code for a share link or any URL, great for print, packaging and events.', 'pixelvault' ),
				'icon'  => self::icon( '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M20 14v.01M14 20v.01M20 20v.01M17 17v.01"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/credit-badge',
				'title' => __( 'Asset Credit & Rights Badge', 'pixelvault' ),
				'desc'  => __( 'Display an image\'s photographer, licence and usage terms anywhere you place the block.', 'pixelvault' ),
				'icon'  => self::icon( '<circle cx="12" cy="8" r="5"/><path d="M8.5 13L7 22l5-3 5 3-1.5-9"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/alt-editor',
				'title' => __( 'Frontend Alt Text Editor', 'pixelvault' ),
				'desc'  => __( 'Let trusted users fix image alt text right from the front end, no wp-admin needed.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/client-portal',
				'title' => __( 'Client Portal', 'pixelvault' ),
				'desc'  => __( 'Show each client only the folder their share link unlocks, wrapped in your own branding.', 'pixelvault' ),
				'icon'  => self::icon( '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>' ),
				'pro'   => true,
			),
			array(
				'name'  => 'pixelvault-pro/access-request',
				'title' => __( 'Access Request', 'pixelvault' ),
				'desc'  => __( 'Let visitors request access to a restricted folder. You approve, and they are emailed a secure link.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>' ),
				'pro'   => true,
			),
		);
	}

	// ── REST ─────────────────────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/blocks/state', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_set_state' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'block'   => array( 'type' => 'string', 'required' => true ),
					'enabled' => array( 'type' => 'boolean', 'required' => true ),
				),
			),
		) );
	}

	public static function rest_set_state( WP_REST_Request $request ) {
		$block = sanitize_text_field( (string) $request->get_param( 'block' ) );
		$known = wp_list_pluck( self::blocks(), 'name' );
		if ( ! in_array( $block, $known, true ) ) {
			return new WP_Error( 'unknown_block', __( 'Unknown block.', 'pixelvault' ), array( 'status' => 400 ) );
		}
		$states           = (array) get_option( self::OPTION, array() );
		$states[ $block ] = (bool) $request->get_param( 'enabled' );
		update_option( self::OPTION, $states, false );
		return rest_ensure_response( array( 'block' => $block, 'enabled' => $states[ $block ] ) );
	}

	// ── Settings tab ──────────────────────────────────────────────────────────────────

	public static function add_tab( $tabs, $settings ) {
		$tabs['blocks'] = array(
			'label'    => __( 'Blocks', 'pixelvault' ),
			'icon_svg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
		);
		return (array) $tabs;
	}

	public static function render_panel( $settings ) {
		$pro_active = self::pro_active();
		$pro_url    = esc_url( self::pro_url() );
		?>
		<div class="rayetun-mn-panel" data-panel="blocks" style="display:none">
			<div class="rayetun-mn-panel-header">
				<h2><?php esc_html_e( 'Blocks', 'pixelvault' ); ?></h2>
				<p><?php esc_html_e( 'Turn each PixelVault block on or off. Disabled blocks are hidden from the editor and load no assets.', 'pixelvault' ); ?></p>
			</div>
			<style>
				.mn-bm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin:16px 0}
				.mn-bm-card{position:relative;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
				.mn-bm-card.is-locked{background:linear-gradient(#fff,#fbfbff);border-color:#e0e7ff}
				.mn-bm-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
				.mn-bm-ico{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;background:#eef2ff;color:#4f46e5}
				.mn-bm-card h3{margin:0 0 6px;font-size:15px;font-weight:700;color:#1e293b}
				.mn-bm-card p{margin:0;font-size:13px;line-height:1.55;color:#64748b}
				.mn-bm-badge{font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:999px;background:#4f46e5;color:#fff;white-space:nowrap;text-decoration:none}
				.mn-bm-switch{position:relative;display:inline-block;width:40px;height:22px;flex:0 0 auto}
				.mn-bm-switch input{position:absolute;opacity:0;width:0;height:0}
				.mn-bm-slider{position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:background .18s ease}
				.mn-bm-slider::after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:transform .18s ease}
				.mn-bm-switch input:checked + .mn-bm-slider{background:#4f46e5}
				.mn-bm-switch input:checked + .mn-bm-slider::after{transform:translateX(18px)}
				.mn-bm-switch input:focus-visible + .mn-bm-slider{outline:2px solid #4f46e5;outline-offset:2px}
				.mn-bm-status{font-size:12px;color:#94a3b8;min-height:14px;margin-top:4px}
			</style>
			<div class="mn-bm-grid">
				<?php foreach ( self::blocks() as $b ) :
					$locked = $b['pro'] && ! $pro_active;
					$on     = self::is_enabled( $b['name'] );
					?>
					<div class="mn-bm-card <?php echo $locked ? 'is-locked' : ''; ?>">
						<div class="mn-bm-top">
							<span class="mn-bm-ico"><?php echo $b['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></span>
							<?php if ( $locked ) : ?>
								<a class="mn-bm-badge" href="<?php echo $pro_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Pro', 'pixelvault' ); ?></a>
							<?php else : ?>
								<label class="mn-bm-switch">
									<input type="checkbox" class="mn-bm-toggle" data-block="<?php echo esc_attr( $b['name'] ); ?>" <?php checked( $on ); ?> />
									<span class="mn-bm-slider"></span>
								</label>
							<?php endif; ?>
						</div>
						<h3><?php echo esc_html( $b['title'] ); ?></h3>
						<p><?php echo esc_html( $b['desc'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
			<p id="mn-bm-status" class="mn-bm-status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	public static function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'rayetun-medianest' !== $page ) {
			return;
		}
		$js = 'admin/js/rayetun-medianest-blocks-manager.js';
		wp_enqueue_script(
			'rayetun-medianest-blocks-manager',
			RAYETUN_MEDIANEST_URL . $js,
			array( 'wp-api-fetch', 'wp-i18n' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( RAYETUN_MEDIANEST_DIR . $js ) ? '.' . filemtime( RAYETUN_MEDIANEST_DIR . $js ) : '' ),
			true
		);
		wp_set_script_translations( 'rayetun-medianest-blocks-manager', 'pixelvault', RAYETUN_MEDIANEST_DIR . 'languages/' );
		wp_localize_script( 'rayetun-medianest-blocks-manager', 'mnBlocksManager', array(
			'restUrl' => esc_url_raw( rest_url( self::NAMESPACE . '/blocks/state' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				'saving' => __( 'Saving…', 'pixelvault' ),
				'saved'  => __( 'Saved.', 'pixelvault' ),
				'error'  => __( 'Could not save. Please try again.', 'pixelvault' ),
			),
		) );
	}
}
