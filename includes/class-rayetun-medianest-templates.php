<?php
/**
 * Folder Templates — save the current folder structure as a reusable preset
 * and apply it (recreate the structure) on demand.
 *
 * Templates are stored in a single option as an array of:
 *   [ 'id' => string, 'name' => string, 'structure' => [ { ref, parent_ref, name, color, icon } ] ]
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_Templates {

	const OPTION_KEY = 'rayetun_medianest_folder_templates';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** Only administrators manage structural templates. */
	public static function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		$ns = 'rayetun-medianest/v1';

		register_rest_route( $ns, '/templates', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_templates' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_template' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'name' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $ns, '/templates/(?P<id>[a-z0-9\-]+)/apply', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'apply_template' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
			'args'                => array(
				'id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( $ns, '/templates/(?P<id>[a-z0-9\-]+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_template' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
			'args'                => array(
				'id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	// ── Storage ────────────────────────────────────────────────────────────────

	private static function all(): array {
		$templates = get_option( self::OPTION_KEY, array() );
		return is_array( $templates ) ? $templates : array();
	}

	// ── Endpoints ──────────────────────────────────────────────────────────────

	public static function list_templates() {
		$out = array();
		foreach ( self::all() as $tpl ) {
			$out[] = array(
				'id'     => $tpl['id'],
				'name'   => $tpl['name'],
				'count'  => isset( $tpl['structure'] ) ? count( (array) $tpl['structure'] ) : 0,
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Snapshot the current folder structure and store it under a name.
	 */
	public static function save_template( WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Template name is required.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$folders = RayetunMediaNest_DB::get_visible_folders( get_current_user_id(), 'attachment' );

		$structure = array();
		foreach ( (array) $folders as $f ) {
			$structure[] = array(
				'ref'        => (int) $f->term_id,
				'parent_ref' => (int) $f->parent_id,
				'name'       => (string) $f->name,
				'color'      => (string) $f->color,
				'icon'       => (string) $f->icon,
			);
		}

		if ( empty( $structure ) ) {
			return new WP_Error( 'no_folders', __( 'There are no folders to save as a template.', 'pixelvault' ), array( 'status' => 400 ) );
		}

		$templates   = self::all();
		$templates[] = array(
			'id'        => 'tpl-' . substr( md5( $name . microtime() ), 0, 10 ),
			'name'      => $name,
			'structure' => $structure,
		);
		update_option( self::OPTION_KEY, $templates );

		return rest_ensure_response( array( 'saved' => true, 'count' => count( $structure ) ) );
	}

	/**
	 * Recreate the folder structure stored in a template.
	 */
	public static function apply_template( WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request->get_param( 'id' ) );
		$tpl = null;
		foreach ( self::all() as $t ) {
			if ( $t['id'] === $id ) {
				$tpl = $t;
				break;
			}
		}
		if ( ! $tpl || empty( $tpl['structure'] ) ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}

		// Iterative passes so a parent is created before its children.
		$pending = $tpl['structure'];
		$map     = array( 0 => 0 );
		$created = 0;
		$skipped = 0;
		$guard   = 0;
		$max     = ( count( $pending ) * 2 ) + 2;

		while ( ! empty( $pending ) && $guard < $max ) {
			$guard++;
			$still = array();
			foreach ( $pending as $row ) {
				$ref        = isset( $row['ref'] ) ? (int) $row['ref'] : 0;
				$parent_ref = isset( $row['parent_ref'] ) ? (int) $row['parent_ref'] : 0;
				$name       = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

				if ( '' === $name ) {
					$skipped++;
					continue;
				}
				if ( ! array_key_exists( $parent_ref, $map ) ) {
					$still[] = $row;
					continue;
				}

				$result = RayetunMediaNest_Folder_Service::create(
					$name,
					array(
						'parent_id' => (int) $map[ $parent_ref ],
						'color'     => isset( $row['color'] ) ? sanitize_hex_color( $row['color'] ) : '',
						'icon'      => isset( $row['icon'] ) ? sanitize_key( $row['icon'] ) : '',
						'post_type' => 'attachment',
					)
				);

				if ( is_wp_error( $result ) ) {
					$skipped++;
					$map[ $ref ] = (int) $map[ $parent_ref ];
					continue;
				}
				$map[ $ref ] = (int) $result['term_id'];
				$created++;
			}
			if ( count( $still ) === count( $pending ) ) {
				break;
			}
			$pending = $still;
		}

		return rest_ensure_response(
			array(
				'created' => $created,
				'skipped' => $skipped,
				/* translators: 1: number created, 2: number skipped */
				'message' => sprintf( __( '%1$d folders created, %2$d skipped.', 'pixelvault' ), $created, $skipped ),
			)
		);
	}

	public static function delete_template( WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request->get_param( 'id' ) );
		$new = array_values( array_filter( self::all(), static function ( $t ) use ( $id ) {
			return $t['id'] !== $id;
		} ) );
		update_option( self::OPTION_KEY, $new );
		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
