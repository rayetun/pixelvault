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

	const DEFAULT_URL = 'https://checkout.freemius.com/plugin/39343/plan/67509/';

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

	private static function yes(): string {
		return '<svg class="mn-pro-yes" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="' . esc_attr__( 'Included', 'pixelvault' ) . '"><path d="M20 6 9 17l-5-5"/></svg>';
	}
	private static function no(): string {
		return '<span class="mn-pro-no" role="img" aria-label="' . esc_attr__( 'Not included', 'pixelvault' ) . '">&mdash;</span>';
	}

	/** Free-vs-Pro rows: [ feature label, included in free? ]. Pro includes everything. */
	private static function comparison(): array {
		return array(
			array( __( 'Media library folders', 'pixelvault' ), true ),
			array( __( 'Document Library block', 'pixelvault' ), true ),
			array( __( 'Content Folders — Posts & Pages', 'pixelvault' ), true ),
			array( __( 'Content Folders — custom post types & WooCommerce', 'pixelvault' ), false ),
			array( __( 'Asset metadata & rights tracking', 'pixelvault' ), false ),
			array( __( 'Folder access control (login / role / password)', 'pixelvault' ), false ),
			array( __( 'Email & password-gated downloads', 'pixelvault' ), false ),
			array( __( 'Expiring share links (with IP / domain limits)', 'pixelvault' ), false ),
			array( __( 'White-label client portal', 'pixelvault' ), false ),
			array( __( 'Activity log (audit trail)', 'pixelvault' ), false ),
			array( __( 'Premium front-end blocks', 'pixelvault' ), false ),
			array( __( 'Priority email support', 'pixelvault' ), false ),
		);
	}

	/** FAQ rows: [ question, answer ]. */
	private static function faqs(): array {
		return array(
			array(
				__( 'Do I need the free PixelVault plugin?', 'pixelvault' ),
				__( 'Yes. Pro is an add-on that extends PixelVault, so keep the free plugin installed and active alongside it.', 'pixelvault' ),
			),
			array(
				__( 'Is there a free trial?', 'pixelvault' ),
				__( 'Yes — a 7-day free trial, no credit card required.', 'pixelvault' ),
			),
			array(
				__( 'What is your refund policy?', 'pixelvault' ),
				__( 'A 7-day, no-questions-asked money-back guarantee.', 'pixelvault' ),
			),
			array(
				__( 'How many sites can I use it on?', 'pixelvault' ),
				__( 'Choose a Single-site, 5-site, or Unlimited licence — each available annually or as a one-time lifetime purchase.', 'pixelvault' ),
			),
			array(
				__( 'Do I get updates and support?', 'pixelvault' ),
				__( 'Yes — automatic updates and priority email support for as long as your licence is active.', 'pixelvault' ),
			),
			array(
				__( 'Does it work with WooCommerce and custom post types?', 'pixelvault' ),
				__( 'Yes. Pro extends Content Folders to WooCommerce products and any custom post type (the free plugin covers Posts and Pages).', 'pixelvault' ),
			),
			array(
				__( 'What happens to my data if my licence lapses?', 'pixelvault' ),
				__( 'Your folders, metadata and settings are always preserved — nothing is deleted.', 'pixelvault' ),
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
				.mn-pro-note{margin:22px 0 0;font-size:12px;color:rgba(255,255,255,.85)}
				.mn-pro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin:22px 0}
				.mn-pro-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
				.mn-pro-ico{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;background:#eef2ff;color:#4f46e5;margin-bottom:12px}
				.mn-pro-card h3{margin:0 0 6px;font-size:15px;font-weight:700;color:#1e293b}
				.mn-pro-card p{margin:0;font-size:13px;line-height:1.55;color:#64748b}
				.mn-pro-foot{text-align:center;padding:8px 0 4px}
				.mn-pro-foot .mn-pro-cta{background:#4f46e5;color:#fff;box-shadow:none}
				.mn-pro-foot .mn-pro-cta:hover{background:#4338ca;color:#fff}
				.mn-pro-h2{margin:32px 0 14px;font-size:18px;font-weight:800;color:#1e293b;text-align:center}
				.mn-pro-tablewrap{overflow-x:auto}
				.mn-pro-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
				.mn-pro-table th,.mn-pro-table td{padding:12px 16px;border-bottom:1px solid #eef2f7;font-size:14px}
				.mn-pro-table thead th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;text-align:left}
				.mn-pro-table th.mn-pro-col,.mn-pro-table td.mn-pro-col{text-align:center;width:96px}
				.mn-pro-table thead th.mn-pro-pro{color:#4f46e5}
				.mn-pro-table td.mn-pro-pro{background:#f7f7ff}
				.mn-pro-table tbody tr:last-child td{border-bottom:0}
				.mn-pro-table td:first-child{color:#1e293b;font-weight:500}
				.mn-pro-yes{color:#10b981;vertical-align:middle}
				.mn-pro-no{color:#cbd5e1;font-weight:700}
				.mn-pro-faq{max-width:840px;margin:0 auto}
				.mn-pro-faq details{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px}
				.mn-pro-faq summary{cursor:pointer;padding:14px 18px;font-weight:600;font-size:14px;color:#1e293b;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px}
				.mn-pro-faq summary::-webkit-details-marker{display:none}
				.mn-pro-faq summary::after{content:"+";color:#4f46e5;font-size:20px;line-height:1}
				.mn-pro-faq details[open] summary::after{content:"\2212"}
				.mn-pro-faq details[open] summary{border-bottom:1px solid #eef2f7}
				.mn-pro-faq-a{padding:12px 18px 16px;font-size:14px;line-height:1.6;color:#475569}
			</style>

			<div class="mn-pro-hero">
				<span class="mn-pro-kicker"><?php esc_html_e( 'PixelVault Pro', 'pixelvault' ); ?></span>
				<h2><?php esc_html_e( 'Do more with PixelVault Pro', 'pixelvault' ); ?></h2>
				<p><?php esc_html_e( 'Everything you love in PixelVault, plus asset metadata & rights, folder access control, gated downloads, share links, content folders for every post type, an activity log, and a family of premium front-end blocks.', 'pixelvault' ); ?></p>
				<a class="mn-pro-cta" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get PixelVault Pro', 'pixelvault' ); ?>
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</a>
				<p class="mn-pro-note"><?php esc_html_e( '7-day money-back guarantee · Single, 5-site and unlimited licences · 7-day free trial', 'pixelvault' ); ?></p>
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

			<h2 class="mn-pro-h2"><?php esc_html_e( 'PixelVault Free vs Pro', 'pixelvault' ); ?></h2>
			<div class="mn-pro-tablewrap">
				<table class="mn-pro-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Feature', 'pixelvault' ); ?></th>
							<th class="mn-pro-col"><?php esc_html_e( 'Free', 'pixelvault' ); ?></th>
							<th class="mn-pro-col mn-pro-pro"><?php esc_html_e( 'Pro', 'pixelvault' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( self::comparison() as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row[0] ); ?></td>
								<td class="mn-pro-col"><?php echo $row[1] ? self::yes() : self::no(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></td>
								<td class="mn-pro-col mn-pro-pro"><?php echo self::yes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<h2 class="mn-pro-h2"><?php esc_html_e( 'Frequently asked questions', 'pixelvault' ); ?></h2>
			<div class="mn-pro-faq">
				<?php foreach ( self::faqs() as $faq ) : ?>
					<details>
						<summary><?php echo esc_html( $faq[0] ); ?></summary>
						<div class="mn-pro-faq-a"><?php echo esc_html( $faq[1] ); ?></div>
					</details>
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
