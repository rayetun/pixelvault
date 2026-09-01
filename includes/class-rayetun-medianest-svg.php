<?php
/**
 * SVG upload support with on-upload sanitization.
 *
 * Free feature: lets trusted users upload SVGs (which WordPress blocks by default),
 * sanitizing every file on upload to strip the XSS vectors raw SVG can carry
 * (scripts, event handlers, external/data/javascript refs, entities). Gated behind
 * a capability so only trusted roles can upload SVG at all.
 *
 * Sanitization is defense-in-depth: the primary engine is the vetted, bundled
 * enshrined/svg-sanitize library (`vendor/enshrined/svg-sanitize`, GPL-2.0, the same
 * library Safe SVG uses), hardened to strip remote references. A strict in-house
 * allowlist sanitizer is the fallback if that library ever fails to load. To update the
 * bundled library, replace the vendored `src/` from a tagged release of
 * darylldoyle/svg-sanitizer (currently 0.22.0).
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_SVG {

	public static function register() {
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_mime' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_filetype' ), 10, 5 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'sanitize_on_upload' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_preview_css' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'attachment_for_js' ), 10, 2 );
	}

	// ── Capability gate ──────────────────────────────────────────────────────

	/**
	 * Whether the current user may upload SVG files. Defaults to administrators;
	 * filterable so a site can widen (e.g. to editors) deliberately.
	 */
	public static function user_can_upload(): bool {
		/**
		 * Capability required to upload SVG files.
		 *
		 * @param string $capability Default 'manage_options' (administrators only).
		 */
		$cap = apply_filters( 'rayetun_medianest_svg_upload_capability', 'manage_options' );
		return current_user_can( $cap );
	}

	// ── Mime / filetype ──────────────────────────────────────────────────────

	public static function allow_mime( $mimes ) {
		if ( self::user_can_upload() ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * WordPress's real-filetype check can leave SVG ext/type empty (it is XML), which
	 * makes the upload fail with "file type not permitted". Restore them for allowed users.
	 *
	 * @param array       $data      ext/type/proper_filename.
	 * @param string      $file      Full path to the file.
	 * @param string      $filename  The name of the file.
	 * @param string[]    $mimes     Allowed mime map.
	 * @param string|bool $real_mime The actual mime type (WP 5.1+), unused.
	 */
	public static function fix_filetype( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		if ( ! self::user_can_upload() ) {
			return $data;
		}
		$check = wp_check_filetype( $filename, $mimes );
		if ( 'svg' === $check['ext'] || 'svgz' === $check['ext'] ) {
			$data['ext']  = $check['ext'];
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	// ── Sanitize on upload ───────────────────────────────────────────────────

	/**
	 * Sanitize an SVG the moment it is uploaded, before WordPress moves it into place.
	 * Rejects the upload if the file cannot be safely sanitized.
	 *
	 * @param array $file $_FILES-style entry (name, type, tmp_name, ...).
	 * @return array
	 */
	public static function sanitize_on_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return $file;
		}

		$is_svg = ( isset( $file['type'] ) && 'image/svg+xml' === $file['type'] )
			|| (bool) preg_match( '/\.svgz?$/i', $file['name'] );
		if ( ! $is_svg ) {
			return $file;
		}

		if ( ! self::user_can_upload() ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'pixelvault' );
			return $file;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem ) {
			$file['error'] = __( 'Could not read the SVG to sanitize it.', 'pixelvault' );
			return $file;
		}

		$raw = $wp_filesystem->get_contents( $file['tmp_name'] );
		if ( false === $raw || '' === $raw ) {
			$file['error'] = __( 'Could not read the SVG to sanitize it.', 'pixelvault' );
			return $file;
		}

		// Handle gzipped .svgz (gzip magic bytes 1f 8b).
		$gzipped = ( "\x1f\x8b" === substr( $raw, 0, 2 ) );
		if ( $gzipped ) {
			$raw = function_exists( 'gzdecode' ) ? gzdecode( $raw ) : false;
			if ( false === $raw ) {
				$file['error'] = __( 'This SVG (compressed) could not be read for sanitizing.', 'pixelvault' );
				return $file;
			}
		}

		$clean = self::sanitize( $raw );
		if ( false === $clean ) {
			$file['error'] = __( 'This SVG could not be sanitized safely and was rejected.', 'pixelvault' );
			return $file;
		}

		if ( $gzipped && function_exists( 'gzencode' ) ) {
			$clean = gzencode( $clean );
		}

		if ( ! $wp_filesystem->put_contents( $file['tmp_name'], $clean, FS_CHMOD_FILE ) ) {
			$file['error'] = __( 'Could not write the sanitized SVG.', 'pixelvault' );
		}
		return $file;
	}

	// ── The sanitizer ────────────────────────────────────────────────────────

	/**
	 * Sanitize raw SVG markup. Returns clean markup, or false if it cannot be made safe
	 * (fail-closed: an unparseable or hostile file is rejected).
	 *
	 * Defense in depth: the vetted, bundled enshrined/svg-sanitize library (the one Safe
	 * SVG uses) is the primary engine when available; the strict in-house allowlist
	 * sanitizer below is the fallback if the library cannot be loaded.
	 *
	 * @param string $svg Raw SVG XML.
	 * @return string|false
	 */
	public static function sanitize( string $svg ) {
		$svg = trim( $svg );
		if ( '' === $svg ) {
			return false;
		}

		// Cheap hard blocks up front, whichever engine runs: entity declarations
		// (XXE / billion-laughs) and embedded PHP.
		if ( preg_match( '/<!ENTITY/i', $svg ) || false !== stripos( $svg, '<?php' ) ) {
			return false;
		}

		$primary = self::run_library( $svg );

		if ( false === $primary ) {
			return false; // library rejected the file outright.
		}

		if ( is_string( $primary ) ) {
			// Second, stricter layer: run the library's output through our allowlist to
			// enforce a no-remote-references policy (the library, even with remote refs
			// disabled, can leave an external <image href> — a tracking/SSRF vector).
			$second = self::sanitize_allowlist( $primary );
			return is_string( $second ) ? $second : $primary; // keep library output if the 2nd pass can't parse it (still sanitized).
		}

		// $primary is null — library unavailable; fall back to the allowlist sanitizer alone.
		return self::sanitize_allowlist( $svg );
	}

	/**
	 * Run the bundled enshrined/svg-sanitize library, hardened to strip remote references.
	 *
	 * @param string $svg Raw SVG.
	 * @return string|false|null Clean markup, false if rejected, or null if the library is
	 *                           unavailable (caller should fall back).
	 */
	private static function run_library( string $svg ) {
		if ( ! self::load_library() ) {
			return null;
		}
		$sanitizer = new \enshrined\svgSanitize\Sanitizer();
		$sanitizer->removeRemoteReferences( true );
		$sanitizer->minify( false );
		$clean = $sanitizer->sanitize( $svg );
		return ( is_string( $clean ) && '' !== trim( $clean ) ) ? $clean : false;
	}

	/**
	 * Register a minimal PSR-4 loader for the bundled library, once. Respects any copy
	 * another plugin may already have loaded (guards against class redeclaration).
	 *
	 * @return bool Whether the Sanitizer class is available.
	 */
	private static function load_library(): bool {
		if ( class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
			return true;
		}
		static $registered = false;
		if ( ! $registered ) {
			$base = RAYETUN_MEDIANEST_DIR . 'vendor/enshrined/svg-sanitize/src/';
			if ( ! is_readable( $base . 'Sanitizer.php' ) ) {
				return false;
			}
			spl_autoload_register(
				static function ( $class ) use ( $base ) {
					$prefix = 'enshrined\\svgSanitize\\';
					if ( 0 !== strpos( $class, $prefix ) ) {
						return;
					}
					$file = $base . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_readable( $file ) ) {
						require_once $file;
					}
				}
			);
			$registered = true;
		}
		return class_exists( '\enshrined\svgSanitize\Sanitizer' );
	}

	/**
	 * In-house fallback sanitizer (strict element allowlist + attribute/value blocklists).
	 * Used only when the bundled library cannot be loaded. Returns clean markup or false.
	 *
	 * @param string $svg Raw SVG XML.
	 * @return string|false
	 */
	private static function sanitize_allowlist( string $svg ) {
		$svg = trim( $svg );
		if ( '' === $svg ) {
			return false;
		}
		if ( preg_match( '/<!ENTITY/i', $svg ) || false !== stripos( $svg, '<?php' ) ) {
			return false;
		}

		$prev_errors = libxml_use_internal_errors( true );
		// PHP < 8.0: block external entity loading (default-safe on 8.0+, function deprecated there).
		$prev_loader = null;
		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$prev_loader = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
		}

		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		// LIBXML_NONET blocks network access. Do NOT pass LIBXML_NOENT — that would
		// expand entities (the billion-laughs vector); we want references left inert.
		$loaded = $dom->loadXML( $svg, LIBXML_NONET );

		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) && null !== $prev_loader ) {
			libxml_disable_entity_loader( $prev_loader ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );

		if ( ! $loaded || ! $dom->documentElement ) {
			return false;
		}
		if ( 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
			return false;
		}

		// Strip any DOCTYPE node (defense in depth).
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				$dom->removeChild( $child );
			}
		}

		self::scrub_element( $dom->documentElement );

		$out = $dom->saveXML( $dom->documentElement, LIBXML_NOEMPTYTAG );
		if ( false === $out || '' === $out ) {
			return false;
		}
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
	}

	/**
	 * Curated allowlist of safe SVG element names (lowercased for comparison).
	 * Anything not listed — <script>, <foreignObject>, <handler>, etc. — is removed.
	 */
	private static function allowed_elements(): array {
		$allowed = array(
			'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'style', 'title', 'desc', 'metadata',
			'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
			'text', 'tspan', 'textpath', 'tref', 'a', 'view', 'image',
			'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'marker',
			'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite',
			'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap', 'feflood',
			'fegaussianblur', 'feimage', 'femerge', 'femergenode', 'femorphology', 'feoffset',
			'fespecularlighting', 'fetile', 'feturbulence', 'fedistantlight', 'fepointlight',
			'fespotlight', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr',
			'animate', 'animatemotion', 'animatetransform', 'set', 'mpath',
		);
		/**
		 * Filter the allowlist of SVG element names permitted after sanitization.
		 *
		 * @param string[] $allowed Lowercased element names.
		 */
		return (array) apply_filters( 'rayetun_medianest_svg_allowed_elements', $allowed );
	}

	private static function scrub_element( DOMElement $el ) {
		$tag = strtolower( $el->localName ? $el->localName : $el->nodeName );

		if ( ! in_array( $tag, self::allowed_elements(), true ) ) {
			if ( $el->parentNode ) {
				$el->parentNode->removeChild( $el );
			}
			return;
		}

		// A <style> block can smuggle @import / behavior / javascript: — blank it if so.
		if ( 'style' === $tag && preg_match( '/(javascript:|expression\s*\(|@import|behavior\s*:|<\/?\w)/i', $el->textContent ) ) {
			$el->textContent = '';
		}

		if ( $el->hasAttributes() ) {
			foreach ( iterator_to_array( $el->attributes ) as $attr ) {
				self::scrub_attribute( $el, $attr );
			}
		}

		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof DOMElement ) {
				self::scrub_element( $child );
			}
		}
	}

	private static function scrub_attribute( DOMElement $el, DOMAttr $attr ) {
		$local = strtolower( $attr->localName ? $attr->localName : $attr->nodeName );
		$value = (string) $attr->value;
		$low   = strtolower( $value );

		// Event handlers (onload, onclick, …).
		if ( 0 === strpos( $local, 'on' ) ) {
			$el->removeAttributeNode( $attr );
			return;
		}

		// href / xlink:href: allow only internal fragment refs (#id). Blocks external,
		// data: and javascript: targets, and <use>/<image> external references.
		if ( 'href' === $local ) {
			$v = ltrim( $value );
			if ( '' === $v || '#' !== $v[0] ) {
				$el->removeAttributeNode( $attr );
				return;
			}
		}

		// Any scripty value.
		if ( false !== strpos( $low, 'javascript:' )
			|| false !== strpos( $low, 'data:text/html' )
			|| preg_match( '/(?:^|[^a-z])expression\s*\(/i', $value ) ) {
			$el->removeAttributeNode( $attr );
			return;
		}

		// Inline style with dangerous tokens.
		if ( 'style' === $local
			&& preg_match( '/(javascript:|expression\s*\(|@import|behavior\s*:|url\s*\(\s*[\'"]?\s*(?:javascript|data):)/i', $value ) ) {
			$el->removeAttributeNode( $attr );
		}
	}

	// ── Admin preview ────────────────────────────────────────────────────────

	/**
	 * SVGs often have no intrinsic pixel size, so the media grid renders them tiny or
	 * blank. Give SVG thumbnails a sane box in admin.
	 */
	public static function admin_preview_css() {
		echo '<style>.attachment .thumbnail img[src$=".svg"],.media-icon img[src$=".svg"],.attachment-info .thumbnail img[src$=".svg"]{width:100%;height:auto;min-height:60px;object-fit:contain;}</style>';
	}

	/**
	 * Ensure the media modal treats SVGs as viewable images so a preview shows.
	 *
	 * @param array   $response   Attachment data prepared for JS.
	 * @param WP_Post $attachment The attachment post.
	 */
	public static function attachment_for_js( $response, $attachment ) {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment ) ) {
			return $response;
		}
		$url = wp_get_attachment_url( $attachment->ID );
		if ( $url ) {
			$response['image']  = array( 'src' => $url );
			$response['sizes']  = array(
				'full' => array( 'url' => $url, 'orientation' => 'landscape' ),
			);
			$response['icon']   = $url;
		}
		return $response;
	}
}
