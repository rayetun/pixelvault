<?php
/**
 * Content Folders — organise Posts and Pages into the same folder tree as your media.
 *
 * The Media Library already lives in virtual folders; this extends that tree to the
 * `edit.php` list screens for Posts and Pages, adding a folder sidebar, a Folders column
 * and a folder filter. The folder data layer, REST CRUD and service layer are all shared
 * with media folders, so a single "Campaign" folder can hold both its images and its pages.
 *
 * The set of content types that can be foldered is a filter,
 * `rayetun_medianest_content_folder_types` (default Posts + Pages). PixelVault Pro extends
 * it to custom post types and WooCommerce — the same sidebar then works for those too.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Content_Folders {

	const OPTION    = 'rayetun_medianest_content_folders';
	const NAMESPACE  = 'rayetun-medianest/v1';
	const QUERY_VAR = 'mn_folder';

	/** @var string[]|null Cached list of enabled post types this request. */
	private static $types = null;

	public static function register() {
		add_filter( 'rayetun_medianest_folder_object_types', array( __CLASS__, 'add_object_types' ) );

		add_action( 'rest_api_init',                            array( __CLASS__, 'register_routes' ) );
		add_filter( 'rayetun_medianest_settings_tabs',          array( __CLASS__, 'add_settings_tab' ), 10, 2 );
		add_action( 'rayetun_medianest_render_settings_panels', array( __CLASS__, 'render_settings_panel' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings' ) );
		add_action( 'pre_get_posts',          array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_menu',             array( __CLASS__, 'add_list_columns' ) );
	}

	// ── Which content types get folders ──────────────────────────────────────────────

	/**
	 * Content types that MAY be foldered. Posts and Pages by default; add-ons (PixelVault
	 * Pro) extend this to custom post types and WooCommerce via the filter.
	 *
	 * @return WP_Post_Type[] Keyed by name.
	 */
	public static function eligible_types(): array {
		/**
		 * Filter the content types eligible for folders (the Media Library is handled
		 * separately, so 'attachment' is never included here).
		 *
		 * @param string[] $types Post-type names. Default ['post','page'].
		 */
		$names = (array) apply_filters( 'rayetun_medianest_content_folder_types', array( 'post', 'page' ) );
		$out   = array();
		foreach ( $names as $name ) {
			$name = sanitize_key( $name );
			if ( '' === $name || 'attachment' === $name ) {
				continue;
			}
			$pt = get_post_type_object( $name );
			if ( $pt ) {
				$out[ $name ] = $pt;
			}
		}
		return $out;
	}

	/** Post types the owner has turned foldering on for, limited to those still eligible. */
	public static function enabled_types(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}
		$stored   = (array) get_option( self::OPTION, array() );
		$stored   = array_map( 'sanitize_key', $stored );
		$eligible = array_keys( self::eligible_types() );
		self::$types = array_values( array_intersect( $stored, $eligible ) );
		return self::$types;
	}

	public static function add_object_types( $types ) {
		return array_merge( (array) $types, self::enabled_types() );
	}

	private static function is_enabled( string $post_type ): bool {
		return in_array( $post_type, self::enabled_types(), true );
	}

	// ── List-query filter ─────────────────────────────────────────────────────────────

	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || ! self::is_enabled( (string) $screen->post_type ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, no state change.
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		$tax = RayetunMediaNest_Taxonomy::TAXONOMY;
		if ( 'unfiled' === $raw ) {
			$query->set( 'tax_query', array( array(
				'taxonomy' => $tax,
				'operator' => 'NOT EXISTS',
			) ) );
			return;
		}
		$term_id = absint( $raw );
		if ( $term_id > 0 ) {
			$query->set( 'tax_query', array( array(
				'taxonomy'         => $tax,
				'field'            => 'term_id',
				'terms'            => array( $term_id ),
				'include_children' => false,
			) ) );
		}
	}

	// ── Folders column ────────────────────────────────────────────────────────────────

	public static function add_list_columns() {
		foreach ( self::enabled_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns",       array( __CLASS__, 'column_header' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_body' ), 10, 2 );
		}
	}

	public static function column_header( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['mn_content_folders'] = __( 'Folders', 'pixelvault' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['mn_content_folders'] ) ) {
			$new['mn_content_folders'] = __( 'Folders', 'pixelvault' );
		}
		return $new;
	}

	public static function column_body( $column, $post_id ) {
		if ( 'mn_content_folders' !== $column ) {
			return;
		}
		$terms = get_the_terms( (int) $post_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			echo '<span class="mn-cf-col-empty">&mdash;</span>';
			return;
		}
		$names = array();
		foreach ( $terms as $t ) {
			$url     = add_query_arg( self::QUERY_VAR, (int) $t->term_id );
			$names[] = '<a class="mn-cf-col-chip" href="' . esc_url( $url ) . '">' . esc_html( $t->name ) . '</a>';
		}
		echo wp_kses_post( implode( ' ', $names ) );
	}

	// ── Per-folder counts for the sidebar (one grouped query) ───────────────────────────

	private static function folder_counts( string $post_type ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- admin sidebar counts, small result set.
			"SELECT tt.term_id AS term_id, COUNT(p.ID) AS c
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')
			 GROUP BY tt.term_id",
			RayetunMediaNest_Taxonomy::TAXONOMY,
			$post_type
		) );
		$counts = array();
		foreach ( (array) $rows as $r ) {
			$counts[ (int) $r->term_id ] = (int) $r->c;
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
			$post_type
		) );
		$filed = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			 WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')",
			RayetunMediaNest_Taxonomy::TAXONOMY,
			$post_type
		) );
		$counts['__all']     = $total;
		$counts['__unfiled'] = max( 0, $total - $filed );
		return $counts;
	}

	// ── List-screen assets ──────────────────────────────────────────────────────────

	public static function enqueue( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! self::is_enabled( (string) $screen->post_type ) ) {
			return;
		}
		$post_type = (string) $screen->post_type;

		$css = 'admin/css/rayetun-medianest-content-folders.css';
		wp_enqueue_style(
			'rayetun-medianest-content-folders',
			RAYETUN_MEDIANEST_URL . $css,
			array(),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( RAYETUN_MEDIANEST_DIR . $css ) ? '.' . filemtime( RAYETUN_MEDIANEST_DIR . $css ) : '' )
		);

		$js = 'admin/js/rayetun-medianest-content-folders.js';
		wp_enqueue_script(
			'rayetun-medianest-content-folders',
			RAYETUN_MEDIANEST_URL . $js,
			array( 'wp-api-fetch', 'wp-i18n' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( RAYETUN_MEDIANEST_DIR . $js ) ? '.' . filemtime( RAYETUN_MEDIANEST_DIR . $js ) : '' ),
			true
		);
		wp_set_script_translations( 'rayetun-medianest-content-folders', 'pixelvault', RAYETUN_MEDIANEST_DIR . 'languages/' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only active-folder marker.
		$active = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$pt_obj = get_post_type_object( $post_type );

		wp_localize_script( 'rayetun-medianest-content-folders', 'mnCF', array(
			'restUrl'    => esc_url_raw( rest_url( self::NAMESPACE . '/folders' ) ),
			'treeUrl'    => esc_url_raw( rest_url( self::NAMESPACE . '/content-folders/tree' ) ),
			'assignUrl'  => esc_url_raw( rest_url( self::NAMESPACE . '/content-folders/assign' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'postType'   => $post_type,
			'postTypeLabel' => $pt_obj ? $pt_obj->labels->name : $post_type,
			'queryVar'   => self::QUERY_VAR,
			'active'     => $active,
			'counts'     => self::folder_counts( $post_type ),
			'canCreate'  => current_user_can( 'medianest_create_folders' ),
			'canManage'  => current_user_can( 'medianest_edit_any_folder' ) || current_user_can( 'manage_options' ),
			'strings'    => array(
				'title'        => __( 'Folders', 'pixelvault' ),
				'collapse'     => __( 'Collapse folder sidebar', 'pixelvault' ),
				'expand'       => __( 'Show folders', 'pixelvault' ),
				'allItems'     => __( 'All', 'pixelvault' ),
				'unfiled'      => __( 'Unfiled', 'pixelvault' ),
				'newFolder'    => __( 'New folder', 'pixelvault' ),
				'newSubfolder' => __( 'New subfolder', 'pixelvault' ),
				'newFolderName'=> __( 'Folder name', 'pixelvault' ),
				'rename'       => __( 'Rename', 'pixelvault' ),
				'delete'       => __( 'Delete', 'pixelvault' ),
				'save'         => __( 'Save', 'pixelvault' ),
				'cancel'       => __( 'Cancel', 'pixelvault' ),
				'deleteQ'      => __( 'Delete folder?', 'pixelvault' ),
				'expandAll'    => __( 'Expand', 'pixelvault' ),
				'collapseAll'  => __( 'Collapse', 'pixelvault' ),
				'addTo'        => __( 'Add to folder', 'pixelvault' ),
				'removeFrom'   => __( 'Remove from this folder', 'pixelvault' ),
				'apply'        => __( 'Apply', 'pixelvault' ),
				'selectFirst'  => __( 'Select one or more items first.', 'pixelvault' ),
				'chooseFolder' => __( 'Choose a folder…', 'pixelvault' ),
				'dropHere'     => __( 'Drop to file here', 'pixelvault' ),
				/* translators: 1: folder name. */
				'created'      => __( 'Created “%s”.', 'pixelvault' ),
				/* translators: 1: folder name. */
				'renamedTo'    => __( 'Renamed to “%s”.', 'pixelvault' ),
				/* translators: 1: folder name. */
				'deletedName'  => __( 'Deleted “%s”.', 'pixelvault' ),
				/* translators: 1: number of items, 2: folder name. */
				'filedInto'    => __( 'Filed %1$d item(s) into “%2$s”.', 'pixelvault' ),
				/* translators: 1: number of items, 2: folder name. */
				'removedFrom'  => __( 'Removed %1$d item(s) from “%2$s”.', 'pixelvault' ),
				'error'        => __( 'Something went wrong. Please try again.', 'pixelvault' ),
			),
		) );
	}

	// ── REST ─────────────────────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/content-folders/tree', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_tree' ),
				'permission_callback' => static function ( WP_REST_Request $r ) {
					$obj = get_post_type_object( sanitize_key( (string) $r->get_param( 'post_type' ) ) );
					return $obj && current_user_can( $obj->cap->edit_posts );
				},
				'args'                => array(
					'post_type' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/content-folders/assign', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_assign' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'post_ids'  => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
					'folder_id' => array( 'type' => 'integer', 'required' => true ),
					'op'        => array( 'type' => 'string', 'default' => 'add' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/content-folders/types', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_save_types' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'types' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ),
				),
			),
		) );
	}

	public static function rest_tree( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( ! self::is_enabled( $post_type ) ) {
			return new WP_Error( 'mn_not_foldered', __( 'That content type does not use folders.', 'pixelvault' ), array( 'status' => 400 ) );
		}
		$folders = array();
		if ( class_exists( 'RayetunMediaNest_Folder_Service' ) ) {
			foreach ( (array) RayetunMediaNest_Folder_Service::get_flat( $post_type ) as $f ) {
				$folders[] = array(
					'term_id'   => (int) $f->term_id,
					'name'      => (string) $f->name,
					'parent_id' => (int) $f->parent_id,
				);
			}
		}
		return rest_ensure_response( array(
			'folders' => $folders,
			'counts'  => self::folder_counts( $post_type ),
		) );
	}

	public static function rest_assign( WP_REST_Request $request ) {
		$post_ids  = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) );
		$folder_id = absint( $request->get_param( 'folder_id' ) );
		$op        = sanitize_key( (string) $request->get_param( 'op' ) );
		$op        = in_array( $op, array( 'add', 'remove' ), true ) ? $op : 'add';

		if ( empty( $post_ids ) || ! $folder_id ) {
			return new WP_Error( 'invalid', __( 'Select at least one item and a folder.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$folder = RayetunMediaNest_DB::get_folder_by_term_id( $folder_id );
		if ( ! $folder ) {
			return new WP_Error( 'no_folder', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}
		if ( ! RayetunMediaNest_Folder_Service::current_user_can_manage_folder( $folder_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot manage that folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}
		if ( RayetunMediaNest_Folder_Service::is_folder_locked( $folder_id ) ) {
			return new WP_Error( 'locked', __( 'That folder is locked.', 'pixelvault' ), array( 'status' => 423 ) );
		}

		$tax     = RayetunMediaNest_Taxonomy::TAXONOMY;
		$changed = 0;
		foreach ( $post_ids as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}
			if ( 'remove' === $op ) {
				$r = wp_remove_object_terms( $pid, $folder_id, $tax );
			} else {
				$r = wp_set_object_terms( $pid, array( $folder_id ), $tax, true );
			}
			if ( ! is_wp_error( $r ) && false !== $r ) {
				clean_object_term_cache( array( $pid ), $tax );
				$changed++;
				/**
				 * Fires after a post/page/CPT item is filed into (or removed from) a folder.
				 *
				 * @param int    $pid       Post ID.
				 * @param int    $folder_id Folder term ID.
				 * @param string $op        'add' or 'remove'.
				 */
				do_action( 'rayetun_medianest_content_assigned', $pid, $folder_id, $op );
			}
		}

		return rest_ensure_response( array( 'changed' => $changed, 'op' => $op, 'folder_id' => $folder_id ) );
	}

	public static function rest_save_types( WP_REST_Request $request ) {
		$eligible = array_keys( self::eligible_types() );
		$types    = array_values( array_intersect(
			array_map( 'sanitize_key', (array) $request->get_param( 'types' ) ),
			$eligible
		) );
		update_option( self::OPTION, $types, false );
		self::$types = null;
		return rest_ensure_response( array( 'types' => $types ) );
	}

	// ── Settings tab ────────────────────────────────────────────────────────────────

	public static function add_settings_tab( $tabs, $settings ) {
		$tabs['content_folders'] = array(
			'label'    => __( 'Content Folders', 'pixelvault' ),
			'icon_svg' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
		);
		return (array) $tabs;
	}

	public static function render_settings_panel( $settings ) {
		$enabled    = self::enabled_types();
		$eligible   = self::eligible_types();
		$pro_active = defined( 'PIXELVAULT_PRO_VERSION' ) || class_exists( 'PixelVault_Pro' );
		$pro_url    = class_exists( 'RayetunMediaNest_Pro_Upsell' ) ? RayetunMediaNest_Pro_Upsell::pro_url() : 'https://checkout.freemius.com/plugin/39343/plan/67509/';
		?>
		<div class="rayetun-mn-panel" data-panel="content_folders" style="display:none">
			<div class="rayetun-mn-panel-header">
				<h2><?php esc_html_e( 'Content Folders', 'pixelvault' ); ?></h2>
				<p><?php esc_html_e( 'Organise Posts and Pages into the same folder tree you use for media.', 'pixelvault' ); ?></p>
			</div>

			<style>
				.mn-cf-checklist{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px 16px;margin:14px 0 0}
				.mn-cf-check{display:flex;align-items:center;gap:9px;border:1px solid #e2e8f0;border-radius:9px;padding:11px 13px;cursor:pointer;background:#fff;font-size:13px;color:#1e293b}
				.mn-cf-check:hover{border-color:#a5b4fc;background:#f5f6ff}
				.mn-cf-check input{margin:0}
				.mn-cf-check code{background:#eef2ff;border-radius:4px;padding:1px 5px;font-size:11px;color:#64748b}
				.mn-cf-status{font-size:13px;font-weight:500;margin-top:12px}
				.mn-cf-status.is-ok{color:#10b981}.mn-cf-status.is-err{color:#ef4444}
				.mn-cf-check.is-locked{cursor:not-allowed;opacity:.8;background:#fafafe;border-style:dashed}
				.mn-cf-check.is-locked:hover{border-color:#e2e8f0;background:#fafafe}
				.mn-cf-check.is-locked input{cursor:not-allowed}
				.mn-cf-pro-badge{background:#4f46e5;color:#fff;font-size:9px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:2px 7px;border-radius:999px;margin-left:auto}
				.mn-cf-pro-label{margin:22px 0 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8}
				.mn-cf-pro-cta{display:inline-block;margin-top:14px;font-size:13px;font-weight:600;color:#4f46e5;text-decoration:none}
				.mn-cf-pro-cta:hover{text-decoration:underline}
			</style>

			<div class="rayetun-mn-info-box" style="margin-bottom:18px">
				<div class="rayetun-mn-info-box-icon" aria-hidden="true">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
				</div>
				<div>
					<strong><?php esc_html_e( 'How Content Folders work', 'pixelvault' ); ?></strong>
					<p>
						<?php esc_html_e( 'Turn on Posts and/or Pages below. A Folders sidebar then appears on that content type’s list screen (e.g. Posts → All Posts) where you create folders, click one to filter the list, and file selected items with “Add to folder”. Folders are shared with your Media Library, so one folder can hold both images and pages.', 'pixelvault' ); ?>
					</p>
				</div>
			</div>

			<div id="mn-cf-type-list" class="mn-cf-checklist">
				<?php foreach ( $eligible as $name => $pt ) : ?>
					<label class="mn-cf-check">
						<input type="checkbox" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $enabled, true ) ); ?> />
						<span><?php echo esc_html( $pt->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p id="mn-cf-save-status" class="mn-cf-status" role="status" aria-live="polite"></p>

			<?php if ( ! $pro_active ) : ?>
				<p class="mn-cf-pro-label"><?php esc_html_e( 'Available in PixelVault Pro', 'pixelvault' ); ?></p>
				<div class="mn-cf-checklist">
					<label class="mn-cf-check is-locked">
						<input type="checkbox" disabled />
						<span><?php esc_html_e( 'Custom post types', 'pixelvault' ); ?></span>
						<span class="mn-cf-pro-badge"><?php esc_html_e( 'Pro', 'pixelvault' ); ?></span>
					</label>
					<label class="mn-cf-check is-locked">
						<input type="checkbox" disabled />
						<span><?php esc_html_e( 'WooCommerce products', 'pixelvault' ); ?></span>
						<span class="mn-cf-pro-badge"><?php esc_html_e( 'Pro', 'pixelvault' ); ?></span>
					</label>
				</div>
				<a class="mn-cf-pro-cta" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Fold every post type with PixelVault Pro', 'pixelvault' ); ?> &rarr;</a>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function enqueue_settings( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'rayetun-medianest' !== $page ) {
			return;
		}
		$js = 'admin/js/rayetun-medianest-content-folders-settings.js';
		wp_enqueue_script(
			'rayetun-medianest-content-folders-settings',
			RAYETUN_MEDIANEST_URL . $js,
			array( 'wp-api-fetch', 'wp-i18n' ),
			RAYETUN_MEDIANEST_VERSION . ( file_exists( RAYETUN_MEDIANEST_DIR . $js ) ? '.' . filemtime( RAYETUN_MEDIANEST_DIR . $js ) : '' ),
			true
		);
		wp_set_script_translations( 'rayetun-medianest-content-folders-settings', 'pixelvault', RAYETUN_MEDIANEST_DIR . 'languages/' );
		wp_localize_script( 'rayetun-medianest-content-folders-settings', 'mnCFSettings', array(
			'restUrl' => esc_url_raw( rest_url( self::NAMESPACE . '/content-folders/types' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				'saved' => __( 'Saved.', 'pixelvault' ),
				'error' => __( 'Could not save. Please try again.', 'pixelvault' ),
			),
		) );
	}
}
