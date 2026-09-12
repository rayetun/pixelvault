<?php
/**
 * "Upgrade to Pro" — a single, opt-out settings tab that advertises the paid PixelVault
 * Pro add-on and links to its pricing page.
 *
 * WordPress.org-friendly by design: it contains NO Pro feature code and NO Freemius code,
 * only feature names/descriptions and one outbound link. It lives entirely on the plugin's
 * own settings screen (no admin-wide nag notices), and it hides itself automatically when
 * PixelVault Pro is already installed and active.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Pro_Upsell {

	const DEFAULT_URL = 'https://rayetun.com/pixelvault-pro/';

	public static function register() {
		// Never upsell to someone who already runs Pro.
		if ( self::pro_active() ) {
			return;
		}
		/**
		 * Filter whether to show the "Upgrade to Pro" tab at all. Return false to remove it.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'rayetun_medianest_show_pro_upsell', true ) ) {
			return;
		}
		add_filter( 'rayetun_medianest_settings_tabs',          array( __CLASS__, 'add_tab' ), 99, 2 );
		add_action( 'rayetun_medianest_render_settings_panels', array( __CLASS__, 'render_panel' ) );
	}

	private static function pro_active(): bool {
		return defined( 'PIXELVAULT_PRO_VERSION' ) || class_exists( 'PixelVault_Pro' );
	}

	/** The pricing / checkout destination. Set this to your Freemius checkout link. */
	public static function pro_url(): string {
		/**
		 * Filter the "Get Pro" URL (your pricing page or Freemius checkout link).
		 *
		 * @param string $url
		 */
		$url = (string) apply_filters( 'rayetun_medianest_pro_url', self::DEFAULT_URL );
		return add_query_arg(
			array(
				'utm_source'   => 'pixelvault',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'upgrade_tab',
			),
			$url
		);
	}

	public static function add_tab( $tabs, $settings ) {
		$tabs['pro_upsell'] = array(
			'label'    => __( 'Upgrade to Pro', 'pixelvault' ),
			'icon_svg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.2 5.3 5.8.5-4.4 3.8 1.3 5.6L12 21l-4.9 3.8"/><path d="M12 3L9.8 8.3 4 8.8l4.4 3.8L7.1 18.2 12 15l4.9 3.2-1.3-5.6L20 8.8l-5.8-.5z"/></svg>',
		);
		return (array) $tabs;
	}

	private static function icon( string $paths ): string {
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
	}

	/** The Pro feature cards shown in the upgrade tab. */
	private static function features(): array {
		return array(
			array(
				'title' => __( 'Asset Metadata & Rights', 'pixelvault' ),
				'desc'  => __( 'Photographer, licence, source and usage terms on every file — plus expiry dates and CSV import/export.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z"/><path d="M15 4v5h5"/><path d="M8 13h8M8 17h5"/>' ),
			),
			array(
				'title' => __( 'Folder Access Control', 'pixelvault' ),
				'desc'  => __( 'Restrict any folder by login, role or password in your front-end Document Library.', 'pixelvault' ),
				'icon'  => self::icon( '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>' ),
			),
			array(
				'title' => __( 'Gated Downloads', 'pixelvault' ),
				'desc'  => __( 'Capture an email before download or require a password — with a leads list you can export.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M4 4h16v12H5.2L4 17.2z"/><path d="M8 9h8M8 12h5"/>' ),
			),
			array(
				'title' => __( 'Share Links & Client Portal', 'pixelvault' ),
				'desc'  => __( 'Expiring share links (with optional IP/domain limits) and a white-label branded client portal.', 'pixelvault' ),
				'icon'  => self::icon( '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>' ),
			),
			array(
				'title' => __( 'Content Folders for CPTs & WooCommerce', 'pixelvault' ),
				'desc'  => __( 'You already fold Posts and Pages — Pro extends the same sidebar to products and any custom post type.', 'pixelvault' ),
				'icon'  => self::icon( '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>' ),
			),
			array(
				'title' => __( 'Activity Log', 'pixelvault' ),
				'desc'  => __( 'A full audit trail of who did what to your library, exportable to CSV.', 'pixelvault' ),
				'icon'  => self::icon( '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>' ),
			),
			array(
				'title' => __( 'Premium Front-End Blocks', 'pixelvault' ),
				'desc'  => __( 'Download Cart, Metadata Search, QR Share, Credit & Rights Badge, Alt-Text Editor, Client Portal and Access Request.', 'pixelvault' ),
				'icon'  => self::icon( '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>' ),
			),
		);
	}

	public static function render_panel( $settings ) {
		$url = esc_url( self::pro_url() );
		?>
		<div class="rayetun-mn-panel" data-panel="pro_upsell" style="display:none">
			<style>
				.mn-pro-hero{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:14px;padding:34px 30px;color:#fff;text-align:center}
				.mn-pro-kicker{display:inline-block;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:4px 12px;margin-bottom:14px}
				.mn-pro-hero h2{margin:0 0 8px;font-size:26px;font-weight:800;color:#fff;letter-spacing:-.01em}
				.mn-pro-hero p{margin:0 auto 20px;max-width:640px;font-size:15px;line-height:1.6;color:rgba(255,255,255,.92)}
				.mn-pro-cta{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#4338ca;font-weight:700;font-size:15px;text-decoration:none;border-radius:10px;padding:12px 26px;box-shadow:0 6px 20px rgba(0,0,0,.18)}
				.mn-pro-cta:hover{background:#f5f3ff;color:#4338ca}
				.mn-pro-note{margin:14px 0 0;font-size:12px;color:rgba(255,255,255,.85)}
				.mn-pro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin:22px 0}
				.mn-pro-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
				.mn-pro-ico{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;background:#eef2ff;color:#4f46e5;margin-bottom:12px}
				.mn-pro-card h3{margin:0 0 6px;font-size:15px;font-weight:700;color:#1e293b}
				.mn-pro-card p{margin:0;font-size:13px;line-height:1.55;color:#64748b}
				.mn-pro-foot{text-align:center;padding:8px 0 4px}
				.mn-pro-foot .mn-pro-cta{background:#4f46e5;color:#fff;box-shadow:none}
				.mn-pro-foot .mn-pro-cta:hover{background:#4338ca;color:#fff}
			</style>

			<div class="mn-pro-hero">
				<span class="mn-pro-kicker"><?php esc_html_e( 'PixelVault Pro', 'pixelvault' ); ?></span>
				<h2><?php esc_html_e( 'Do more with PixelVault Pro', 'pixelvault' ); ?></h2>
				<p><?php esc_html_e( 'Everything you love in PixelVault, plus asset metadata & rights, folder access control, gated downloads, share links, content folders for every post type, an activity log, and a family of premium front-end blocks.', 'pixelvault' ); ?></p>
				<a class="mn-pro-cta" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get PixelVault Pro', 'pixelvault' ); ?>
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</a>
				<p class="mn-pro-note"><?php esc_html_e( '14-day money-back guarantee · Single, 5-site and unlimited licences · Free trial available', 'pixelvault' ); ?></p>
			</div>

			<div class="mn-pro-grid">
				<?php foreach ( self::features() as $f ) : ?>
					<div class="mn-pro-card">
						<span class="mn-pro-ico"><?php echo $f['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></span>
						<h3><?php echo esc_html( $f['title'] ); ?></h3>
						<p><?php echo esc_html( $f['desc'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mn-pro-foot">
				<a class="mn-pro-cta" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View pricing & plans', 'pixelvault' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
