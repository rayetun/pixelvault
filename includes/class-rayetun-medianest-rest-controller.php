<?php
/**
 * REST API controller for MediaNest folders.
 *
 * @package RayetunMediaNest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RayetunMediaNest_REST_Controller extends WP_REST_Controller {

	protected $namespace = 'rayetun-medianest/v1';
	protected $rest_base = 'folders';

	public function register_routes() {

		register_rest_route( $this->namespace, '/' . $this->rest_base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'post_type' => array( 'default' => 'attachment', 'sanitize_callback' => 'sanitize_key' ),
					'format'    => array( 'default' => 'tree', 'enum' => array( 'tree', 'flat' ), 'sanitize_callback' => 'sanitize_key' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_create_args(),
			),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reorder', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reorder_items' ),
			'permission_callback' => array( $this, 'reorder_permissions_check' ),
			'args'                => array(
				'order' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/assign', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'assign_attachment' ),
			'permission_callback' => array( $this, 'update_item_permissions_check' ),
			'args'                => array(
				'attachment_id' => array( 'required' => true, 'type' => 'integer' ),
				'term_ids'      => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<term_id>[\d]+)/lock', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'lock_folder' ),
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'args'                => array(
				'term_id' => array( 'type' => 'integer', 'required' => true ),
				'locked'  => array( 'type' => 'boolean', 'required' => true ),
			),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<term_id>[\d]+)/remove', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'remove_attachments' ),
			'permission_callback' => array( $this, 'update_item_permissions_check' ),
			'args'                => array(
				'term_id'        => array( 'type' => 'integer', 'required' => true ),
				'attachment_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<term_id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array( 'term_id' => array( 'type' => 'integer' ) ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_update_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'args'                => array( 'term_id' => array( 'type' => 'integer' ) ),
			),
		) );
	}

	public function get_items( $request ) {
		$format    = $request->get_param( 'format' );
		$post_type = $request->get_param( 'post_type' );
		$data      = ( 'flat' === $format )
			? RayetunMediaNest_Folder_Service::get_flat( $post_type )
			: RayetunMediaNest_Folder_Service::get_tree( $post_type );
		return rest_ensure_response( $data );
	}

	public function get_item( $request ) {
		$term_id = (int) $request['term_id'];

		// Enforce folder visibility: a user may only read a folder that appears in
		// their own visible set (which already honours per-folder visibility and
		// ownership rules). Administrators always have access.
		if ( ! $this->user_can_view_folder( $term_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to view this folder.', 'pixelvault' ), array( 'status' => 403 ) );
		}

		$folder = RayetunMediaNest_Folder_Service::get_one( $term_id );
		if ( ! $folder ) {
			return new WP_Error( 'not_found', __( 'Folder not found.', 'pixelvault' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $folder );
	}

	/**
	 * Whether the current user is allowed to see a specific folder.
	 * Reuses the visibility-filtered folder list so private/role/owner rules apply.
	 *
	 * @param int $term_id Folder term ID.
	 * @return bool
	 */
	private function user_can_view_folder( int $term_id ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$visible = RayetunMediaNest_Folder_Service::get_flat( 'attachment' );
		foreach ( (array) $visible as $folder ) {
			$fid = is_object( $folder ) ? (int) ( $folder->term_id ?? 0 ) : (int) ( $folder['term_id'] ?? 0 );
			if ( $fid === $term_id ) {
				return true;
			}
		}
		return false;
	}

	public function create_item( $request ) {
		$result = RayetunMediaNest_Folder_Service::create(
			(string) $request->get_param( 'name' ),
			array(
				'parent_id'     => (int)    $request->get_param( 'parent_id' ),
				'color'         => (string) $request->get_param( 'color' ),
				'icon'          => (string) $request->get_param( 'icon' ),
				'visibility'    => (string) $request->get_param( 'visibility' ),
				'allowed_roles' => (array)  $request->get_param( 'allowed_roles' ),
				'write_roles'   => (array)  $request->get_param( 'write_roles' ),
				'post_type'     => (string) $request->get_param( 'post_type' ),
			)
		);
		if ( is_wp_error( $result ) ) { return $result; }

		/**
		 * Fires after a folder is created via REST, so add-ons can persist their own
		 * custom per-folder fields (registered via rayetun_medianest_folder_rest_args).
		 *
		 * @param int             $term_id Newly created folder term ID.
		 * @param WP_REST_Request $request The REST request (read custom params from it).
		 */
		do_action( 'rayetun_medianest_folder_meta_save', (int) $result['term_id'], $request );

		return new WP_REST_Response( $result, 201 );
	}

	public function update_item( $request ) {
		$term_id = (int) $request['term_id'];

		if ( null !== $request->get_param( 'name' ) ) {
			$r = RayetunMediaNest_Folder_Service::rename( $term_id, (string) $request->get_param( 'name' ) );
			if ( is_wp_error( $r ) ) { return $r; }
		}

		if ( null !== $request->get_param( 'parent_id' ) ) {
			$r = RayetunMediaNest_Folder_Service::move( $term_id, (int) $request->get_param( 'parent_id' ) );
			if ( is_wp_error( $r ) ) { return $r; }
		}

		$meta = array();
		foreach ( array( 'color', 'icon', 'visibility', 'allowed_roles', 'write_roles' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$meta[ $key ] = $request->get_param( $key );
			}
		}
		if ( ! empty( $meta ) ) {
			$r = RayetunMediaNest_Folder_Service::update_meta( $term_id, $meta );
			if ( is_wp_error( $r ) ) { return $r; }
		}

		/**
		 * Fires after a folder is updated via REST, so add-ons can persist their own
		 * custom per-folder fields (registered via rayetun_medianest_folder_rest_args).
		 * Fires even when only custom fields were sent (no core fields changed).
		 *
		 * @param int             $term_id Folder term ID.
		 * @param WP_REST_Request $request The REST request (read custom params from it).
		 */
		do_action( 'rayetun_medianest_folder_meta_save', $term_id, $request );

		return rest_ensure_response( RayetunMediaNest_Folder_Service::get_one( $term_id ) );
	}

	public function delete_item( $request ) {
		$result = RayetunMediaNest_Folder_Service::delete( (int) $request['term_id'] );
		if ( is_wp_error( $result ) ) { return $result; }
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function reorder_items( $request ) {
		$result = RayetunMediaNest_Folder_Service::reorder( (array) $request->get_param( 'order' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( array( 'reordered' => true ) );
	}

	public function assign_attachment( $request ) {
		$result = RayetunMediaNest_Folder_Service::assign_attachment(
			(int)   $request->get_param( 'attachment_id' ),
			(array) $request->get_param( 'term_ids' )
		);
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( array( 'assigned' => true ) );
	}

	public function get_items_permissions_check( $request )   { return current_user_can( 'upload_files' ); }
	public function create_item_permissions_check( $request ) { return current_user_can( 'upload_files' ); }
	public function update_item_permissions_check( $request ) { return current_user_can( 'upload_files' ); }
	public function delete_item_permissions_check( $request ) { return current_user_can( 'upload_files' ); }

	/**
	 * Reordering changes the global folder structure visible to all users,
	 * so it requires administrator-level capability.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the current user can manage options.
	 */
	public function reorder_permissions_check( $request ) {
		// Reorder changes the global folder order, so it needs a folder-edit capability
		// (not merely upload_files). Administrators and folder editors qualify.
		return current_user_can( 'manage_options' ) || current_user_can( 'medianest_edit_any_folder' );
	}

	private function get_create_args() {
		$args = array(
			'name'          => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'parent_id'     => array( 'default' => 0, 'type' => 'integer' ),
			'color'         => array( 'default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ),
			'icon'          => array( 'default' => '', 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'visibility'    => array( 'default' => 'all', 'type' => 'string', 'enum' => array( 'all', 'roles', 'owner' ) ),
			'allowed_roles' => array( 'default' => array(), 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'write_roles'   => array( 'default' => array(), 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'post_type'     => array( 'default' => 'attachment', 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		);

		/**
		 * Filter the REST args accepted when creating a folder. Add-ons register custom
		 * per-folder fields here (each with its own sanitize_callback). Persist the values
		 * in the `rayetun_medianest_folder_meta_save` action.
		 *
		 * @param array $args Argument definitions keyed by param name.
		 */
		return (array) apply_filters( 'rayetun_medianest_folder_rest_args', $args, 'create' );
	}

	private function get_update_args() {
		$args = array(
			'term_id'       => array( 'type' => 'integer' ),
			'name'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'parent_id'     => array( 'type' => 'integer' ),
			'color'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ),
			'icon'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'visibility'    => array( 'type' => 'string', 'enum' => array( 'all', 'roles', 'owner' ) ),
			'allowed_roles' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'write_roles'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);

		/** This filter documented in get_create_args(). @param array $args, string $op */
		return (array) apply_filters( 'rayetun_medianest_folder_rest_args', $args, 'update' );
	}

	public function lock_folder( $request ) {
		$result = RayetunMediaNest_Folder_Service::set_lock(
			(int)  $request['term_id'],
			(bool) $request->get_param( 'locked' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'locked' => (bool) $request->get_param( 'locked' ) ) );
	}

	public function remove_attachments( $request ) {
		$result = RayetunMediaNest_Folder_Service::remove_attachments(
			(int)   $request['term_id'],
			(array) $request->get_param( 'attachment_ids' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}


}
