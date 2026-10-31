<?php
/**
 * PixelVault Document Library — frontend document browser block.
 *
 * Block: rayetun-medianest/document-library (server-rendered, hydrated by the
 * WordPress Interactivity API on the frontend).
 *
 * PHASE 1 (this file): block registration + a full server-rendered browser
 * (sidebar folder tree + breadcrumb + file tiles with type icons, size and a
 * per-file download, plus a Download-All button). Navigation/search/layout are
 * wired to Interactivity directives here and driven by the view store in later
 * phases. See PIXELVAULT-DOCLIB-PLAN.md.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RayetunMediaNest_DocLib {

	const VERSION       = RAYETUN_MEDIANEST_VERSION;
	const STORE         = 'pixelvault/doclib';
	const PRESETS       = array( 'cards', 'compact', 'minimal' );
	const MAX_DL_FILES  = 500;
	const MAX_DL_BYTES  = 314572800; // 300 MB safety cap for "Download all".

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ) );
		// Shortcode parity with the block, so Classic Editor, page builders, widgets
		// and theme templates can place the Document Library (search + folder browsing).
		add_shortcode( 'pixelvault_document_library', array( __CLASS__, 'render_shortcode' ) );
	}

	// ── "Download all" (frontend-safe folder ZIP) ────────────────────────────────

	public static function maybe_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only download of already-public media; no state change.
		if ( empty( $_GET['pv_doclib_download'] ) ) { return; }
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP downloads are not supported on this server.', 'pixelvault' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only public download.
		$root   = isset( $_GET['root'] ) ? absint( wp_unslash( $_GET['root'] ) ) : 0;
		$folder = isset( $_GET['folder'] ) ? absint( wp_unslash( $_GET['folder'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Scope + visibility (guest-aware). The requested folder must live inside the
		// block's visible root subtree, otherwise fall back to the root.
		$tree = self::scoped_tree( $root );
		$node = $folder ? self::find_node( is_array( $tree ) ? $tree : array(), $folder ) : null;
		if ( ! $folder || ! $node ) {
			$folder = $root ? $root : ( isset( $tree[0] ) ? (int) $tree[0]->term_id : 0 );
		}
		if ( ! $folder ) {
			wp_die( esc_html__( 'Folder not found.', 'pixelvault' ), '', array( 'response' => 404 ) );
		}

		/**
		 * Gate "Download all" for a folder. Return false to block (add-ons can require
		 * login, an email, a password, etc.).
		 *
		 * @param bool $allowed Whether the download is allowed.
		 * @param int  $folder  Folder term ID.
		 * @param int  $root    The block's root folder scope.
		 */
		if ( ! apply_filters( 'rayetun_medianest_doclib_can_download', true, $folder, $root ) ) {
			wp_die( esc_html__( 'You do not have permission to download this folder.', 'pixelvault' ), '', array( 'response' => 403 ) );
		}

		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::MAX_DL_FILES, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => array( array(
				'taxonomy'         => RayetunMediaNest_Taxonomy::TAXONOMY,
				'terms'            => $folder,
				'include_children' => false,
			) ),
		) );
		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'This folder has no files to download.', 'pixelvault' ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged

		// wp_tempnam() lives in wp-admin/includes/file.php, which is NOT loaded on the
		// front end where this download handler runs (template_redirect). Load it first.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'pv-doclib.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Could not create the ZIP archive.', 'pixelvault' ) );
		}

		$bytes = 0;
		$added = 0;
		$used  = array();
		foreach ( $ids as $id ) {
			$path = get_attached_file( $id );
			if ( ! $path || ! file_exists( $path ) ) { continue; }
			$size = (int) filesize( $path );
			if ( $bytes + $size > self::MAX_DL_BYTES ) { continue; } // skip files that would blow the cap
			$base = basename( $path );
			$name = $base;
			$n    = 1;
			while ( isset( $used[ strtolower( $name ) ] ) ) {
				$name = pathinfo( $base, PATHINFO_FILENAME ) . '-' . ( ++$n ) . '.' . pathinfo( $base, PATHINFO_EXTENSION );
			}
			$used[ strtolower( $name ) ] = true;
			$zip->addFile( $path, $name );
			$bytes += $size;
			$added++;
		}
		$zip->close();

		if ( ! $added ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'No downloadable files in this folder.', 'pixelvault' ) );
		}

		$term        = get_term( $folder, RayetunMediaNest_Taxonomy::TAXONOMY );
		$folder_name = ( $term && ! is_wp_error( $term ) ) ? $term->name : 'folder';
		$filename    = sanitize_file_name( $folder_name . '.zip' );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming the archive to the browser; WP_Filesystem cannot stream to output.
		wp_delete_file( $tmp );
		exit;
	}

	// ── Public REST endpoint (frontend folder browsing) ──────────────────────────

	public static function register_rest() {
		register_rest_route( 'rayetun-medianest/v1', '/document-library', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_folder' ),
			// Public browsing. Folder visibility is still enforced server-side by
			// get_visible_folders() (guest-aware), so no private folder can leak.
			'permission_callback' => '__return_true',
			'args'                => array(
				'folder'   => array( 'default' => 0 ),
				'root'     => array( 'default' => 0 ),
				'search'   => array( 'default' => '' ),
				'page'     => array( 'default' => 1 ),
				'per_page' => array( 'default' => 20 ),
				'size'     => array( 'default' => 1 ),
				'dl'       => array( 'default' => 1 ),
				'counts'   => array( 'default' => 1 ),
			),
		) );
	}

	public static function rest_folder( WP_REST_Request $req ) {
		$root      = absint( $req->get_param( 'root' ) );
		$folder    = absint( $req->get_param( 'folder' ) );
		$search    = sanitize_text_field( (string) $req->get_param( 'search' ) );
		$per_page  = min( 100, max( 1, absint( $req->get_param( 'per_page' ) ) ?: 20 ) );
		$show_size = (bool) absint( $req->get_param( 'size' ) );
		$show_dl   = (bool) absint( $req->get_param( 'dl' ) );
		$counts    = (bool) absint( $req->get_param( 'counts' ) );

		// Root-scoped, visibility-filtered tree for the current viewer (guest-aware).
		$tree = self::scoped_tree( $root );
		// The requested folder must live inside that scoped, visible tree; otherwise
		// fall back to the root (prevents navigating outside the block's scope).
		$node = $folder ? self::find_node( is_array( $tree ) ? $tree : array(), $folder ) : null;
		if ( ! $folder || ! $node ) {
			$folder = $root ? $root : ( isset( $tree[0] ) ? (int) $tree[0]->term_id : 0 );
		}

		if ( ! $folder ) {
			return rest_ensure_response( array( 'current' => 0, 'files_html' => '', 'breadcrumb_html' => '', 'subfolders_html' => '', 'pagination_html' => '', 'page' => 1, 'pages' => 0 ) );
		}

		$page = max( 1, absint( $req->get_param( 'page' ) ) );
		$data = self::folder_files( $folder, $page, $per_page, $search );

		$flags = array( 'show_size' => $show_size, 'show_download' => $show_dl );

		ob_start(); self::render_files( $data['files'], $flags );        $files_html = ob_get_clean();
		ob_start(); self::render_breadcrumb( (int) $folder, $root );     $crumb_html = ob_get_clean();
		ob_start(); self::render_subfolders( (int) $folder, $root, $counts ); $subs_html = ob_get_clean();
		ob_start(); self::render_pagination( (int) $data['page'], (int) $data['pages'] ); $pag_html = ob_get_clean();

		return rest_ensure_response( array(
			'current'         => (int) $folder,
			'page'            => (int) $data['page'],
			'pages'           => (int) $data['pages'],
			'total'           => (int) $data['total'],
			'files_html'      => $files_html,
			'breadcrumb_html' => $crumb_html,
			'subfolders_html' => $subs_html,
			'pagination_html' => $pag_html,
		) );
	}

	// ── Block registration ───────────────────────────────────────────────────

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) { return; }
		if ( class_exists( 'RayetunMediaNest_Blocks_Manager' ) && ! RayetunMediaNest_Blocks_Manager::is_enabled( 'rayetun-medianest/document-library' ) ) {
			return;
		}

		$block_js  = 'admin/js/rayetun-medianest-doclib-block.js';
		$path      = RAYETUN_MEDIANEST_DIR . $block_js;
		$ver       = self::VERSION . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );

		wp_register_script(
			'rayetun-medianest-doclib-block',
			RAYETUN_MEDIANEST_URL . $block_js,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render' ),
			$ver,
			true
		);

		/* Schema-driven controls: the editor builds its InspectorControls from this
		   same schema, so the option list never drifts from the render/attributes. */
		wp_localize_script( 'rayetun-medianest-doclib-block', 'pvDoclibConfig', array(
			'schema'  => self::schema(),
			'restUrl' => esc_url_raw( rest_url( 'rayetun-medianest/v1/folders' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );

		$css      = 'public/css/rayetun-medianest-doclib.css';
		$css_path = RAYETUN_MEDIANEST_DIR . $css;
		$css_ver  = self::VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );

		wp_register_style( 'rayetun-medianest-doclib', RAYETUN_MEDIANEST_URL . $css, array(), $css_ver );

		$view      = 'public/js/rayetun-medianest-doclib-view.js';
		$view_path = RAYETUN_MEDIANEST_DIR . $view;
		$view_ver  = self::VERSION . ( file_exists( $view_path ) ? '.' . filemtime( $view_path ) : '' );
		wp_register_script( 'rayetun-medianest-doclib-view', RAYETUN_MEDIANEST_URL . $view, array(), $view_ver, true );
		wp_localize_script( 'rayetun-medianest-doclib-view', 'pvDoclibView', array(
			'restUrl'     => esc_url_raw( rest_url( 'rayetun-medianest/v1/document-library' ) ),
			'downloadUrl' => esc_url_raw( home_url( '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'i18n'        => array( 'error' => __( 'Could not load this folder. Please try again.', 'pixelvault' ) ),
		) );

		// Register from block.json metadata (title/description/icon shown on the
		// WordPress.org plugin page) while keeping the runtime script/style/attributes/
		// render here in PHP. The path points at the folder that contains block.json.
		register_block_type( RAYETUN_MEDIANEST_DIR . 'blocks/document-library', array(
			'editor_script'   => 'rayetun-medianest-doclib-block',
			'style'           => 'rayetun-medianest-doclib',      // frontend
			'editor_style'    => 'rayetun-medianest-doclib',      // same CSS in the editor so the preview matches the frontend
			'view_script'     => 'rayetun-medianest-doclib-view', // frontend interactivity (auto-enqueued when block present)
			'render_callback' => array( __CLASS__, 'render_block' ),
			'attributes'      => self::attributes(),
		) );
	}

	/**
	 * Block attributes are derived from the control SCHEMA so the editor UI and the
	 * render defaults never drift (schema-driven controls, see the editor script).
	 */
	public static function attributes(): array {
		$attrs = array();
		foreach ( self::schema() as $c ) {
			if ( 'toggle' === $c['type'] ) {
				$type = 'boolean';
			} elseif ( in_array( $c['type'], array( 'range', 'folder' ), true ) ) {
				$type = 'number';
			} elseif ( 'folders' === $c['type'] ) {
				$type = 'array';
			} else {
				$type = 'string';
			}
			$attrs[ $c['key'] ] = array( 'type' => $type, 'default' => $c['default'] );
			if ( 'array' === $type ) {
				// REST (and so ServerSideRender) rejects an array attribute without an
				// item schema, which shows up in the editor as "Error loading block".
				$attrs[ $c['key'] ]['items'] = array( 'type' => 'number' );
			}
		}
		return $attrs;
	}

	/**
	 * The single source of truth for the block's options. The editor renders these
	 * generically; the render reads them for defaults. Add an option = add a row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function schema(): array {
		$schema = array(
			array( 'key' => 'root_folder_id', 'type' => 'folder', 'default' => 0,        'panel' => 'source',  'label' => __( 'Root folder', 'pixelvault' ), 'help' => __( 'Show this folder and its subfolders. Leave empty for all top-level folders.', 'pixelvault' ) ),
			array( 'key' => 'preset',         'type' => 'preset', 'default' => 'cards',   'panel' => 'design',  'label' => __( 'Design preset', 'pixelvault' ), 'choices' => array( 'cards' => __( 'Cards', 'pixelvault' ), 'compact' => __( 'Compact', 'pixelvault' ), 'minimal' => __( 'Minimal', 'pixelvault' ) ) ),
			array( 'key' => 'layout',         'type' => 'select', 'default' => 'grid',    'panel' => 'design',  'label' => __( 'Default layout', 'pixelvault' ), 'choices' => array( 'grid' => __( 'Grid', 'pixelvault' ), 'list' => __( 'List', 'pixelvault' ) ) ),
			array( 'key' => 'columns',        'type' => 'range',  'default' => 3, 'min' => 1, 'max' => 6, 'panel' => 'design', 'label' => __( 'Grid columns', 'pixelvault' ) ),
			array( 'key' => 'accent',         'type' => 'color',  'default' => '#4f46e5', 'panel' => 'design',  'label' => __( 'Accent colour', 'pixelvault' ) ),
			array( 'key' => 'framed',         'type' => 'toggle', 'default' => true,      'panel' => 'design',  'label' => __( 'Framed (border, shadow, sidebar divider)', 'pixelvault' ) ),
			array( 'key' => 'show_sidebar',   'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show folder sidebar', 'pixelvault' ) ),
			array( 'key' => 'show_breadcrumb','type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show breadcrumb', 'pixelvault' ) ),
			array( 'key' => 'show_search',    'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show search', 'pixelvault' ) ),
			array( 'key' => 'show_counts',    'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show file counts', 'pixelvault' ) ),
			array( 'key' => 'show_size',      'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show file size', 'pixelvault' ) ),
			array( 'key' => 'show_download',  'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show per-file download', 'pixelvault' ) ),
			array( 'key' => 'show_downloadall','type'=> 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show "Download all"', 'pixelvault' ) ),
			array( 'key' => 'show_perpage',   'type' => 'toggle', 'default' => true,      'panel' => 'display', 'label' => __( 'Show items-per-page selector', 'pixelvault' ) ),
			array( 'key' => 'per_page',       'type' => 'range',  'default' => 20, 'min' => 6, 'max' => 100, 'panel' => 'display', 'label' => __( 'Default items per page', 'pixelvault' ) ),
		);

		/**
		 * Filter the Document Library block's option schema. Add-ons (e.g. PixelVault
		 * Pro) can append controls; each row is an associative array of
		 * { key, type, label, default, panel, ... }. The block attributes, editor
		 * controls and render defaults are all derived from this, so one entry is
		 * enough to add a fully wired option.
		 *
		 * @param array $schema Ordered list of control definitions.
		 */
		return apply_filters( 'rayetun_medianest_doclib_schema', $schema );
	}

	private static function defaults(): array {
		$d = array();
		foreach ( self::schema() as $c ) { $d[ $c['key'] ] = $c['default']; }
		return $d;
	}

	/** Options for the frontend items-per-page selector (includes the block default). */
	private static function perpage_choices( int $current ): array {
		$choices = array( 10, 20, 50, 100 );
		if ( ! in_array( $current, $choices, true ) ) {
			$choices[] = $current;
			sort( $choices );
		}
		return $choices;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Shortcode entry point: [pixelvault_document_library root="12" columns="4" ...].
	 * Normalizes string attributes to the block's typed options and renders the same
	 * output as the block. Accepts every schema key (dashes or underscores), plus the
	 * friendly aliases root / folder / id for the root folder.
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts     = is_array( $atts ) ? $atts : array();
		$defaults = self::defaults();
		$types    = array();
		foreach ( self::schema() as $c ) { $types[ $c['key'] ] = $c['type']; }

		$aliases    = array( 'root' => 'root_folder_id', 'folder' => 'root_folder_id', 'id' => 'root_folder_id' );
		$normalized = $defaults;

		foreach ( $atts as $key => $val ) {
			$key = str_replace( '-', '_', sanitize_key( (string) $key ) );
			if ( isset( $aliases[ $key ] ) ) { $key = $aliases[ $key ]; }
			if ( ! array_key_exists( $key, $defaults ) ) { continue; }
			$t = isset( $types[ $key ] ) ? $types[ $key ] : 'string';
			if ( 'toggle' === $t ) {
				$normalized[ $key ] = self::shortcode_truthy( $val );
			} elseif ( in_array( $t, array( 'range', 'folder' ), true ) ) {
				$normalized[ $key ] = (int) $val;
			} else {
				$normalized[ $key ] = sanitize_text_field( (string) $val );
			}
		}

		// Blocks auto-enqueue their view script/style; a shortcode must do it itself.
		if ( wp_style_is( 'rayetun-medianest-doclib', 'registered' ) ) {
			wp_enqueue_style( 'rayetun-medianest-doclib' );
		}
		if ( wp_script_is( 'rayetun-medianest-doclib-view', 'registered' ) ) {
			wp_enqueue_script( 'rayetun-medianest-doclib-view' );
		}

		return self::render_block( $normalized );
	}

	/** Interpret a shortcode attribute as a boolean (default true unless clearly falsey). */
	private static function shortcode_truthy( $v ): bool {
		if ( is_bool( $v ) ) { return $v; }
		$v = strtolower( trim( (string) $v ) );
		return ! in_array( $v, array( '', '0', 'false', 'no', 'off' ), true );
	}

	public static function render_block( $atts ) {
		$a = wp_parse_args( (array) $atts, self::defaults() );

		$root_id = absint( $a['root_folder_id'] );
		$preset  = in_array( $a['preset'], self::PRESETS, true ) ? $a['preset'] : 'cards';
		$tree    = self::scoped_tree( $root_id );

		if ( empty( $tree ) && ! $root_id ) {
			if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return '<p class="pv-doclib-empty">' . esc_html__( 'No folders yet. Create folders in the Media Library, then choose one here.', 'pixelvault' ) . '</p>';
			}
			return '';
		}

		// The folder whose files show first: the chosen root, or the first top-level folder.
		$current  = $root_id ? $root_id : ( isset( $tree[0] ) ? (int) $tree[0]->term_id : 0 );
		$per_page = max( 1, (int) $a['per_page'] );
		$data     = $current ? self::folder_files( $current, 1, $per_page ) : array( 'files' => array(), 'pages' => 0 );

		$cols  = max( 1, min( 6, (int) $a['columns'] ) );
		$uid   = 'pv-dl-' . wp_unique_id();
		$style = '--pv-cols:' . $cols . ';--pv-accent:' . sanitize_hex_color( $a['accent'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $uid ); ?>"
			class="pv-doclib pv-doclib--<?php echo esc_attr( $preset ); ?> pv-layout-<?php echo esc_attr( $a['layout'] ); ?><?php echo $a['framed'] ? ' pv-doclib--framed' : ''; ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-pv-root="<?php echo absint( $root_id ); ?>"
			data-pv-current="<?php echo absint( $current ); ?>"
			data-pv-page="1"
			data-pv-perpage="<?php echo absint( $per_page ); ?>"
			data-pv-size="<?php echo $a['show_size'] ? '1' : '0'; ?>"
			data-pv-dl="<?php echo $a['show_download'] ? '1' : '0'; ?>">

			<?php if ( $a['show_sidebar'] ) : ?>
				<aside class="pv-doclib__sidebar" role="tree" aria-label="<?php esc_attr_e( 'Folders', 'pixelvault' ); ?>">
					<?php self::render_tree( $tree, $current, (bool) $a['show_counts'], self::ancestor_set( $current, $root_id ) ); ?>
				</aside>
			<?php endif; ?>

			<div class="pv-doclib__main">
				<?php if ( $a['show_breadcrumb'] || $a['show_downloadall'] ) : ?>
					<div class="pv-doclib__header">
						<?php if ( $a['show_breadcrumb'] ) : ?>
							<nav class="pv-doclib__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'pixelvault' ); ?>">
								<?php self::render_breadcrumb( $current, $root_id ); ?>
							</nav>
						<?php endif; ?>
						<?php if ( $a['show_downloadall'] ) : ?>
							<button type="button" class="pv-doclib__download-all" data-pv-downloadall>
								<?php echo self::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
								<span><?php esc_html_e( 'Download all', 'pixelvault' ); ?></span>
							</button>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="pv-doclib__toolbar">
					<?php if ( $a['show_search'] ) : ?>
						<input type="search" class="pv-doclib__search" placeholder="<?php esc_attr_e( 'Search files…', 'pixelvault' ); ?>" aria-label="<?php esc_attr_e( 'Search files', 'pixelvault' ); ?>" />
					<?php endif; ?>
					<?php if ( $a['show_perpage'] ) : ?>
						<label class="pv-doclib__perpage">
							<span class="pv-doclib__perpage-label"><?php esc_html_e( 'Show', 'pixelvault' ); ?></span>
							<select class="pv-doclib__perpage-select" aria-label="<?php esc_attr_e( 'Items per page', 'pixelvault' ); ?>">
								<?php foreach ( self::perpage_choices( $per_page ) as $choice ) : ?>
									<option value="<?php echo absint( $choice ); ?>" <?php selected( $choice, $per_page ); ?>><?php echo absint( $choice ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<div class="pv-doclib__layout-toggle" role="group" aria-label="<?php esc_attr_e( 'Layout', 'pixelvault' ); ?>">
						<button type="button" class="pv-doclib__layout-btn<?php echo 'grid' === $a['layout'] ? ' is-active' : ''; ?>" data-layout="grid" aria-label="<?php esc_attr_e( 'Grid view', 'pixelvault' ); ?>"><?php echo self::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></button>
						<button type="button" class="pv-doclib__layout-btn<?php echo 'list' === $a['layout'] ? ' is-active' : ''; ?>" data-layout="list" aria-label="<?php esc_attr_e( 'List view', 'pixelvault' ); ?>"><?php echo self::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></button>
					</div>
				</div>

				<div class="pv-doclib__subfolders"><?php self::render_subfolders( $current, $root_id, (bool) $a['show_counts'] ); ?></div>

				<div class="pv-doclib__files-wrap" aria-live="polite">
					<?php self::render_files( $data['files'], $a ); ?>
				</div>
				<div class="pv-doclib__pagination-wrap"><?php self::render_pagination( 1, (int) $data['pages'] ); ?></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ── Render helpers ─────────────────────────────────────────────────────────

	private static function render_tree( array $nodes, int $current, bool $show_counts, array $open = array() ) {
		echo '<ul class="pv-doclib__tree" role="group">';
		foreach ( $nodes as $n ) {
			$tid      = (int) $n->term_id;
			$has_kids = ! empty( $n->children );
			$is_cur   = $tid === $current;
			$count    = $show_counts ? self::folder_count( $tid ) : 0;
			// Folders on the path to the current folder start expanded; the rest start
			// collapsed. A toggle lets visitors expand or collapse any branch.
			$expanded = $has_kids && isset( $open[ $tid ] );
			printf(
				'<li class="pv-doclib__tree-item" role="treeitem"%s%s>',
				$has_kids ? ' aria-expanded="' . ( $expanded ? 'true' : 'false' ) . '"' : '',
				$is_cur ? ' aria-current="true"' : ''
			);
			if ( $has_kids ) {
				printf(
					'<button type="button" class="pv-doclib__tree-toggle" data-pv-treetoggle aria-label="%s">%s</button>',
					esc_attr__( 'Expand or collapse folder', 'pixelvault' ),
					self::icon( 'chevron' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
				);
			}
			printf(
				'<button type="button" class="pv-doclib__tree-btn%s" data-folder="%d">%s<span class="pv-doclib__tree-name">%s</span>%s</button>',
				$is_cur ? ' is-active' : '',
				absint( $tid ),
				self::icon( 'folder' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
				esc_html( $n->name ),
				( $show_counts && $count ) ? '<span class="pv-doclib__tree-count">' . esc_html( (string) $count ) . '</span>' : ''
			);
			if ( $has_kids ) {
				self::render_tree( $n->children, $current, $show_counts, $open );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Term IDs on the path from the root to the current folder (inclusive). Used to
	 * expand just the branch that leads to the selected folder in the sidebar tree.
	 *
	 * @return array<int,bool> Keyed by term ID for O(1) lookup.
	 */
	private static function ancestor_set( int $current, int $root_id ): array {
		$set   = array();
		$tid   = $current;
		$guard = 0;
		while ( $tid && $guard++ < 50 ) {
			$set[ $tid ] = true;
			if ( $root_id && $tid === $root_id ) { break; }
			$f   = RayetunMediaNest_Folder_Service::get_one( $tid );
			$tid = ( $f && isset( $f->parent_id ) ) ? (int) $f->parent_id : 0;
		}
		return $set;
	}

	private static function render_breadcrumb( int $current, int $root_id ) {
		$crumbs = array();
		$tid    = $current;
		$guard  = 0;
		while ( $tid && $guard++ < 20 ) {
			$f = RayetunMediaNest_Folder_Service::get_one( $tid );
			if ( ! $f ) { break; }
			array_unshift( $crumbs, array( 'id' => (int) $f->term_id, 'name' => $f->name ) );
			if ( $root_id && (int) $f->term_id === $root_id ) { break; }
			$tid = isset( $f->parent_id ) ? (int) $f->parent_id : 0;
		}
		$last = count( $crumbs ) - 1;
		foreach ( $crumbs as $i => $c ) {
			if ( $i === $last ) {
				printf( '<span class="pv-doclib__crumb is-current" aria-current="page">%s</span>', esc_html( $c['name'] ) );
			} else {
				printf(
					'<button type="button" class="pv-doclib__crumb" data-folder="%d">%s</button><span class="pv-doclib__crumb-sep" aria-hidden="true">/</span>',
					absint( $c['id'] ),
					esc_html( $c['name'] )
				);
			}
		}
	}

	/** Quick-nav pills for the direct child folders of the current folder. */
	private static function render_subfolders( int $current, int $root_id, bool $show_counts ) {
		if ( ! $current ) { return; }
		$flat = RayetunMediaNest_Folder_Service::get_flat( 'attachment' );
		foreach ( (array) $flat as $f ) {
			if ( (int) $f->parent_id !== $current ) { continue; }
			$tid   = (int) $f->term_id;
			$count = $show_counts ? self::folder_count( $tid ) : 0;
			printf(
				'<button type="button" class="pv-doclib__subfolder" data-folder="%d">%s<span class="pv-doclib__subfolder-name">%s</span>%s</button>',
				absint( $tid ),
				self::icon( 'folder' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
				esc_html( $f->name ),
				( $show_counts && $count ) ? '<span class="pv-doclib__subfolder-count">' . esc_html( (string) $count ) . '</span>' : ''
			);
		}
	}

	private static function render_files( array $files, array $a ) {
		if ( empty( $files ) ) {
			echo '<p class="pv-doclib__empty">' . esc_html__( 'This folder is empty.', 'pixelvault' ) . '</p>';
			return;
		}
		echo '<ul class="pv-doclib__files" role="list">';
		foreach ( $files as $f ) {
			echo '<li class="pv-doclib__file" role="listitem">';
			printf( '<span class="pv-doclib__file-icon" aria-hidden="true">%s</span>', self::file_icon( $f['mime'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
			echo '<span class="pv-doclib__file-main">';
			printf( '<span class="pv-doclib__file-name" title="%s">%s</span>', esc_attr( $f['name'] ), esc_html( $f['name'] ) );
			/**
			 * Fires inside a file tile, right after the file name. Add-ons (PixelVault
			 * Pro) can echo extra per-file information here, such as the file's
			 * description, caption, date, dimensions or custom metadata. The add-on is
			 * responsible for escaping its own output. Enrich the file data via the
			 * rayetun_medianest_doclib_file filter, then read it from $f here.
			 *
			 * @param array $f File data (see the rayetun_medianest_doclib_file filter).
			 */
			do_action( 'rayetun_medianest_doclib_file_meta', $f );
			echo '</span>';
			if ( $a['show_size'] && $f['size'] ) {
				printf( '<span class="pv-doclib__file-size">%s</span>', esc_html( $f['size'] ) );
			}
			if ( $a['show_download'] ) {
				printf(
					'<a class="pv-doclib__file-dl" href="%s" download aria-label="%s">%s</a>',
					esc_url( $f['url'] ),
					/* translators: %s: file name */
					esc_attr( sprintf( __( 'Download %s', 'pixelvault' ), $f['name'] ) ),
					self::icon( 'download' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
				);
			}
			/**
			 * Fires inside a file tile, after the download link. Add-ons (PixelVault
			 * Pro) can echo extra actions here — preview, share, protected download,
			 * etc. The add-on is responsible for escaping its own output.
			 *
			 * @param array $f File data (see the rayetun_medianest_doclib_file filter).
			 */
			do_action( 'rayetun_medianest_doclib_file_actions', $f );
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function render_pagination( int $page, int $pages ) {
		if ( $pages <= 1 ) { return; }
		echo '<nav class="pv-doclib__pagination" aria-label="' . esc_attr__( 'Pagination', 'pixelvault' ) . '">';
		printf(
			'<button type="button" class="pv-doclib__page-btn pv-doclib__page-nav"%s data-pv-goto="%d" aria-label="%s">%s</button>',
			$page <= 1 ? ' disabled' : '',
			absint( $page - 1 ),
			esc_attr__( 'Previous page', 'pixelvault' ),
			'&lsaquo;'
		);
		foreach ( self::page_window( $page, $pages ) as $p ) {
			if ( 0 === $p ) { echo '<span class="pv-doclib__page-gap" aria-hidden="true">&hellip;</span>'; continue; }
			printf(
				'<button type="button" class="pv-doclib__page-btn%s" data-pv-goto="%d"%s>%d</button>',
				$p === $page ? ' is-current' : '',
				absint( $p ),
				$p === $page ? ' aria-current="page"' : '',
				absint( $p )
			);
		}
		printf(
			'<button type="button" class="pv-doclib__page-btn pv-doclib__page-nav"%s data-pv-goto="%d" aria-label="%s">%s</button>',
			$page >= $pages ? ' disabled' : '',
			absint( $page + 1 ),
			esc_attr__( 'Next page', 'pixelvault' ),
			'&rsaquo;'
		);
		echo '</nav>';
	}

	// ── Data ───────────────────────────────────────────────────────────────────

	/** Return the visible tree scoped to $root_id (its subtree) or all top-level. */
	public static function scoped_tree( int $root_id ): array {
		$tree = RayetunMediaNest_Folder_Service::get_tree( 'attachment' );
		if ( ! $root_id ) { return is_array( $tree ) ? $tree : array(); }
		$found = self::find_node( is_array( $tree ) ? $tree : array(), $root_id );
		return $found ? array( $found ) : array();
	}

	private static function find_node( array $nodes, int $id ) {
		foreach ( $nodes as $n ) {
			if ( (int) $n->term_id === $id ) { return $n; }
			if ( ! empty( $n->children ) ) {
				$hit = self::find_node( $n->children, $id );
				if ( $hit ) { return $hit; }
			}
		}
		return null;
	}

	private static function folder_count( int $term_id ): int {
		$term = get_term( $term_id, RayetunMediaNest_Taxonomy::TAXONOMY );
		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->count : 0;
	}

	/**
	 * Paginated files (attachments) directly in a folder, shaped for the tiles.
	 *
	 * @return array{files:array,total:int,pages:int,page:int}
	 */
	public static function folder_files( int $term_id, int $page = 1, int $per_page = 20, string $search = '' ): array {
		$per_page = min( 100, max( 1, $per_page ) );
		$page     = max( 1, $page );
		$args     = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'      => array( array(
				'taxonomy'         => RayetunMediaNest_Taxonomy::TAXONOMY,
				'terms'            => $term_id,
				'include_children' => false,
			) ),
		);
		if ( '' !== $search ) { $args['s'] = $search; }

		/**
		 * Filter the WP_Query args used to fetch a folder's files. Add-ons can change
		 * ordering, add meta queries, etc.
		 *
		 * @param array  $args    WP_Query args.
		 * @param int    $term_id Folder term ID.
		 * @param string $search  Current search string.
		 */
		$args = apply_filters( 'rayetun_medianest_doclib_file_query_args', $args, $term_id, $search );

		$q   = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $p ) {
			$path = get_attached_file( $p->ID );
			$file = array(
				'id'   => $p->ID,
				'name' => get_the_title( $p->ID ),
				'url'  => wp_get_attachment_url( $p->ID ),
				'mime' => get_post_mime_type( $p->ID ),
				'size' => ( $path && file_exists( $path ) ) ? size_format( filesize( $path ) ) : '',
			);
			/**
			 * Filter a single file's data before it is rendered as a tile. Add-ons can
			 * add keys (used by the file-actions hook) or adjust display values.
			 *
			 * @param array $file          File data.
			 * @param int   $attachment_id Attachment ID.
			 */
			$out[] = apply_filters( 'rayetun_medianest_doclib_file', $file, $p->ID );
		}
		return array(
			'files' => $out,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'page'  => $page,
		);
	}

	/** Windowed page list for pagination: first, last, current +/- 1, with 0 = gap. */
	private static function page_window( int $page, int $pages ): array {
		if ( $pages <= 7 ) { return range( 1, $pages ); }
		$w = array( 1 );
		if ( $page > 3 ) { $w[] = 0; }
		foreach ( array( $page - 1, $page, $page + 1 ) as $p ) {
			if ( $p > 1 && $p < $pages ) { $w[] = $p; }
		}
		if ( $page < $pages - 2 ) { $w[] = 0; }
		$w[] = $pages;
		return $w;
	}

	// ── Icons (inline SVG, escaped-exempt static markup) ─────────────────────────

	private static function file_icon( string $mime ): string {
		$m = strtolower( $mime );
		if ( 0 === strpos( $m, 'image/' ) ) { return self::icon( 'image' ); }
		if ( 0 === strpos( $m, 'video/' ) ) { return self::icon( 'video' ); }
		if ( 0 === strpos( $m, 'audio/' ) ) { return self::icon( 'audio' ); }
		if ( false !== strpos( $m, 'pdf' ) ) { return self::icon( 'pdf' ); }
		if ( false !== strpos( $m, 'word' ) || false !== strpos( $m, 'msword' ) || false !== strpos( $m, 'officedocument.wordprocessing' ) ) { return self::icon( 'doc' ); }
		if ( false !== strpos( $m, 'sheet' ) || false !== strpos( $m, 'excel' ) || false !== strpos( $m, 'csv' ) ) { return self::icon( 'sheet' ); }
		if ( false !== strpos( $m, 'zip' ) || false !== strpos( $m, 'compressed' ) ) { return self::icon( 'zip' ); }
		return self::icon( 'file' );
	}

	/**
	 * Static inline SVG set. All markup is hardcoded (no user data), so it is safe
	 * to echo directly; callers annotate the phpcs escape exemption.
	 */
	private static function icon( string $name ): string {
		$icons = array(
			'folder'   => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>',
			'download' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 14a1 1 0 011 1v1h12v-1a1 1 0 112 0v1a2 2 0 01-2 2H4a2 2 0 01-2-2v-1a1 1 0 011-1z"/><path d="M9 3a1 1 0 112 0v7.6l2.3-2.3a1 1 0 011.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 011.4-1.4L9 10.6V3z"/></svg>',
			'chevron'  => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 5l5 5-5 5"/></svg>',
			'grid'     => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 3h6v6H3V3zm8 0h6v6h-6V3zM3 11h6v6H3v-6zm8 0h6v6h-6v-6z"/></svg>',
			'list'     => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 4h12v3H4V4zm0 5h12v3H4V9zm0 5h12v3H4v-3z"/></svg>',
			'image'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 16l-5-5L5 20"/></svg>',
			'video'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3V9z" fill="currentColor"/></svg>',
			'audio'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/></svg>',
			'pdf'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><text x="8.5" y="17" font-size="6" fill="currentColor" stroke="none">PDF</text></svg>',
			'doc'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><path d="M9 12h6M9 15h6M9 9h3"/></svg>',
			'sheet'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><path d="M9 11h6v7H9zM9 14h6M12 11v7"/></svg>',
			'zip'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/><path d="M12 7v2M12 11v2M12 15v2"/></svg>',
			'file'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/></svg>',
		);
		return isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['file'];
	}
}
