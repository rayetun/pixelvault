<?php
/**
 * Storage Analytics — media library usage statistics.
 *
 * Provides:
 *   REST GET /rayetun-medianest/v1/analytics — full stats payload (object-cached 5 min)
 *   WordPress Dashboard widget               — compact at-a-glance overview
 *
 * Cache key: 'analytics_data' in 'rayetun_medianest' group.
 * Cache is flushed whenever any attachment is added, edited, or deleted.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_Analytics {

	const CACHE_GROUP = 'rayetun_medianest';
	const CACHE_KEY   = 'analytics_data';
	const CACHE_TTL   = 5 * MINUTE_IN_SECONDS;

	// ── Registration ──────────────────────────────────────────────────────────

	public static function register() {
		add_action( 'rest_api_init',         array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_dashboard_setup',    array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_widget_assets' ) );
		/* Flush analytics cache whenever attachments change */
		add_action( 'add_attachment',    array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edit_attachment',   array( __CLASS__, 'flush_cache' ) );
		/* Flush analytics cache when a folder is deleted so stale entries disappear immediately */
		add_action( 'rayetun_medianest_folder_deleted', array( __CLASS__, 'flush_cache' ) );
	}

	// ── REST ──────────────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route(
			'rayetun-medianest/v1',
			'/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_analytics' ),
				// Site-wide storage stats are management-level data — require manage_options.
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			)
		);
	}

	public static function rest_get_analytics(): WP_REST_Response {
		return rest_ensure_response( self::get_data() );
	}

	// ── WordPress Dashboard Widget ────────────────────────────────────────────

	public static function add_dashboard_widget() {
		if ( ! current_user_can( 'upload_files' ) ) { return; }
		wp_add_dashboard_widget(
			'rayetun_medianest_analytics',
			__( 'PixelVault — Storage Overview', 'pixelvault' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/** Enqueue the widget stylesheet — only on the WP dashboard page. */
	public static function enqueue_widget_assets( string $hook ) {
		if ( 'index.php' !== $hook ) { return; }
		if ( ! current_user_can( 'upload_files' ) ) { return; }
		wp_enqueue_style(
			'rayetun-medianest-dashboard',
			RAYETUN_MEDIANEST_URL . 'admin/css/rayetun-medianest-dashboard.css',
			array(),
			RAYETUN_MEDIANEST_VERSION
		);
	}

	public static function render_dashboard_widget() {
		try {
			$data = self::get_data();
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'Analytics data could not be loaded.', 'pixelvault' ) . '</p>';
			return;
		}

		$total_storage = self::format_bytes( $data['total_storage'] );
		$total_count   = number_format_i18n( $data['total_count'] );
		$avg_size      = $data['total_count'] > 0
			? self::format_bytes( (int) ( $data['total_storage'] / $data['total_count'] ) )
			: '—';
		$settings_url  = admin_url( 'upload.php?page=rayetun-medianest&tab=analytics' );
		?>
		<div class="rmn-dw">

			<div class="rmn-dw-stats">
				<div class="rmn-dw-stat">
					<span class="rmn-dw-val"><?php echo esc_html( $total_storage ); ?></span>
					<span class="rmn-dw-lbl"><?php esc_html_e( 'Storage', 'pixelvault' ); ?></span>
				</div>
				<div class="rmn-dw-stat">
					<span class="rmn-dw-val"><?php echo esc_html( $total_count ); ?></span>
					<span class="rmn-dw-lbl"><?php esc_html_e( 'Files', 'pixelvault' ); ?></span>
				</div>
				<div class="rmn-dw-stat">
					<span class="rmn-dw-val"><?php echo esc_html( $avg_size ); ?></span>
					<span class="rmn-dw-lbl"><?php esc_html_e( 'Avg. Size', 'pixelvault' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $data['by_type'] ) ) :
				$max_type = ! empty( $data['by_type'][0]['total_size'] ) ? (int) $data['by_type'][0]['total_size'] : 1;
				?>
				<div class="rmn-dw-types">
					<?php foreach ( array_slice( $data['by_type'], 0, 4 ) as $type ) :
						$pct = $max_type > 0 ? round( (int) $type['total_size'] / $max_type * 100 ) : 0;
						?>
						<div class="rmn-dw-type-row">
							<span class="rmn-dw-type-name"><?php echo esc_html( $type['label'] ); ?></span>
							<div class="rmn-dw-type-bar">
								<div class="rmn-dw-type-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
							</div>
							<span class="rmn-dw-type-size"><?php echo esc_html( self::format_bytes( (int) $type['total_size'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['top_files'] ) ) : ?>
				<div class="rmn-dw-top">
					<div class="rmn-dw-top-title"><?php esc_html_e( 'Largest Files', 'pixelvault' ); ?></div>
					<?php foreach ( array_slice( $data['top_files'], 0, 5 ) as $file ) :
						$name     = $file['title'] ? $file['title'] : basename( (string) $file['url'] );
						$edit_url = $file['edit_url'] ? $file['edit_url'] : '#';
						?>
						<div class="rmn-dw-top-row">
							<a href="<?php echo esc_url( $edit_url ); ?>" class="rmn-dw-top-name" target="_blank" rel="noopener">
								<?php echo esc_html( $name ); ?>
							</a>
							<span class="rmn-dw-top-size"><?php echo esc_html( self::format_bytes( (int) $file['file_size'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="rmn-dw-footer">
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'View Full Analytics →', 'pixelvault' ); ?></a>
			</div>

		</div>
		<?php
	}

	// ── Data aggregation ──────────────────────────────────────────────────────

	/**
	 * Return the full analytics payload, served from object cache when possible.
	 * Public so other parts of the plugin (e.g. Settings) can call it directly.
	 */
	public static function get_data(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached ) { return $cached; }

		$data = array(
			'total_storage' => self::query_total_storage(),
			'total_count'   => self::query_total_count(),
			'by_type'       => self::query_by_type(),
			'top_files'     => self::query_top_files( 10 ),
			'by_folder'     => self::query_by_folder(),
			'no_size_count' => self::query_no_size_count(),
		);

		wp_cache_set( self::CACHE_KEY, $data, self::CACHE_GROUP, self::CACHE_TTL );
		return $data;
	}

	/** Sum of all cached file sizes for attachment posts. */
	private static function query_total_storage(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- result cached by get_data().
		return (int) $wpdb->get_var(
			"SELECT SUM( CAST( pm.meta_value AS UNSIGNED ) )
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_rmn_filesize'
			   AND p.post_type   = 'attachment'
			   AND p.post_status = 'inherit'"
		);
	}

	/** Total number of attachments. */
	private static function query_total_count(): int {
		return (int) ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) ) )->found_posts;
	}

	/** Storage breakdown grouped by MIME-type category. */
	private static function query_by_type(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by get_data().
		$rows = $wpdb->get_results(
			"SELECT
			     CASE
			         WHEN p.post_mime_type LIKE 'image/%'      THEN 'Images'
			         WHEN p.post_mime_type LIKE 'video/%'      THEN 'Videos'
			         WHEN p.post_mime_type LIKE 'audio/%'      THEN 'Audio'
			         WHEN p.post_mime_type = 'application/pdf' THEN 'PDFs'
			         ELSE 'Other'
			     END                                                          AS type_group,
			     COUNT(*)                                                     AS file_count,
			     COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 )     AS total_size
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			        ON p.ID = pm.post_id AND pm.meta_key = '_rmn_filesize'
			 WHERE p.post_type   = 'attachment'
			   AND p.post_status = 'inherit'
			 GROUP BY type_group
			 ORDER BY total_size DESC",
			ARRAY_A
		);

		return array_map( static function ( $row ) {
			return array(
				'label'      => $row['type_group'],
				'count'      => (int) $row['file_count'],
				'total_size' => (int) $row['total_size'],
			);
		}, $rows ?: array() );
	}

	/** Top N largest attachments. */
	private static function query_top_files( int $limit = 10 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by get_data().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type,
				        CAST( pm.meta_value AS UNSIGNED ) AS file_size
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm
				   ON p.ID = pm.post_id AND pm.meta_key = '_rmn_filesize'
				 WHERE p.post_type   = 'attachment'
				   AND p.post_status = 'inherit'
				   AND CAST( pm.meta_value AS UNSIGNED ) > 0
				 ORDER BY CAST( pm.meta_value AS UNSIGNED ) DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( static function ( $row ) {
			$id = (int) $row['ID'];
			return array(
				'id'        => $id,
				'title'     => $row['post_title'],
				'mime_type' => $row['post_mime_type'],
				'url'       => (string) wp_get_attachment_url( $id ),
				'edit_url'  => (string) get_edit_post_link( $id ),
				'file_size' => (int) $row['file_size'],
			);
		}, $rows ?: array() );
	}

	/** Storage breakdown per MediaNest folder. */
	private static function query_by_folder(): array {
		global $wpdb;
		$taxonomy = RayetunMediaNest_Taxonomy::TAXONOMY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by get_data().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name,
				        COUNT( DISTINCT tr.object_id )                          AS file_count,
				        COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 ) AS total_size
				 FROM {$wpdb->terms} t
				 JOIN {$wpdb->term_taxonomy} tt
				   ON t.term_id = tt.term_id AND tt.taxonomy = %s
				 LEFT JOIN {$wpdb->term_relationships} tr
				   ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 LEFT JOIN {$wpdb->postmeta} pm
				   ON tr.object_id = pm.post_id AND pm.meta_key = '_rmn_filesize'
				 GROUP BY t.term_id, t.name
				 ORDER BY total_size DESC",
				$taxonomy
			),
			ARRAY_A
		);

		return array_map( static function ( $row ) {
			return array(
				'term_id'    => (int) $row['term_id'],
				'name'       => $row['name'],
				'count'      => (int) $row['file_count'],
				'total_size' => (int) $row['total_size'],
			);
		}, $rows ?: array() );
	}

	/** Number of attachments that have no _rmn_filesize entry yet. */
	private static function query_no_size_count(): int {
		return (int) ( new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array( 'key' => '_rmn_filesize', 'compare' => 'NOT EXISTS' ),
			),
		) ) )->found_posts;
	}

	// ── Cache flush ───────────────────────────────────────────────────────────

	public static function flush_cache() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	// ── Formatting helper ─────────────────────────────────────────────────────

	public static function format_bytes( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 0 ) . ' KB';
		}
		return $bytes . ' B';
	}
}
