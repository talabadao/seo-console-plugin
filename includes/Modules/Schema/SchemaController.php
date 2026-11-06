<?php
/**
 * REST routes of the Schema module under seo-console/v1/schema/… (manage_options).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Plugin;
use SEOConsole\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SchemaController extends Controller {

	/** @var SchemaService */
	private $service;

	/**
	 * @param Plugin        $plugin  Plugin.
	 * @param SchemaService $service Service.
	 */
	public function __construct( Plugin $plugin, SchemaService $service ) {
		parent::__construct( $plugin );
		$this->service = $service;
	}

	/** {@inheritDoc} */
	public function register_routes(): void {
		$ns         = Plugin::REST_NAMESPACE;
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			$ns,
			'/schema/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					$this->pagination_args(),
					array(
						'post_type' => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array_merge( array( '' ), SchemaService::post_types() ),
						),
						'status'    => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'has_ours', 'none', 'needs_input' ),
						),
						'search'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/schema/posts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			$ns,
			'/schema/posts/(?P<id>\d+)/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			$ns,
			'/schema/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => $permission,
				'args'                => array(
					'post_ids' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'use_ai'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/schema/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'nodes'   => array(
						'type'     => array( 'array', 'object' ),
						'required' => true,
					),
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);

		$site_args = array();
		foreach ( SettingsFields::business_keys() as $key ) {
			$site_args[ $key ] = array(
				'type'        => 'string',
				'description' => __( 'Unsaved value to preview (lists: one item per line).', 'seo-console' ),
			);
		}
		register_rest_route(
			$ns,
			'/schema/site',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'site' ),
				'permission_callback' => $permission,
				'args'                => $site_args,
			)
		);

		register_rest_route(
			$ns,
			'/schema/types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'types' ),
				'permission_callback' => $permission,
			)
		);
	}

	/** @return array<string, mixed> */
	private function id_arg(): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
	}

	/**
	 * GET /schema/posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_posts( WP_REST_Request $request ) {
		$args   = array(
			'post_type' => (string) $request['post_type'],
			'status'    => (string) $request['status'],
			'search'    => (string) $request['search'],
			'page'      => (int) $request['page'],
			'per_page'  => (int) $request['per_page'],
		);
		$result = $this->service->query_posts( $args );
		$items  = array();
		foreach ( $result['items'] as $post ) {
			$items[] = $this->service->row( $post );
		}
		$response = new WP_REST_Response(
			array(
				'items'      => $items,
				'total'      => $result['total'],
				'page'       => $args['page'],
				'per_page'   => $args['per_page'],
				'seo_plugin' => $this->service->seo_plugin(),
				'ai'         => $this->service->ai_available(),
				'batch'      => $this->service->batch_status(),
				'post_types' => $this->post_type_options(),
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ) );
		return $response;
	}

	/**
	 * GET /schema/posts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post( WP_REST_Request $request ) {
		$post = $this->find_post( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		return $this->respond( $this->service->detail( $post ) );
	}

	/**
	 * POST /schema/posts/{id}/remove: removes our schema through ChangeManager (undoable).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$post = $this->find_post( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		$change = $this->service->remove( $post->ID );
		if ( is_wp_error( $change ) ) {
			return $this->respond( $change );
		}
		return $this->respond( array( 'change' => $this->plugin->changes()->to_rest( $change ) ) );
	}

	/**
	 * POST /schema/suggest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $request['post_ids'] ) ) );
		return $this->respond( $this->service->suggest_many( $ids, (bool) $request['use_ai'] ) );
	}

	/**
	 * POST /schema/validate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate( WP_REST_Request $request ) {
		$nodes = $request['nodes'];
		if ( ! is_array( $nodes ) ) {
			return $this->respond( new WP_Error( 'seoc_invalid_schema', __( 'Send the structured data as JSON.', 'seo-console' ), array( 'status' => 400 ) ) );
		}
		$nodes   = Validator::is_list( $nodes ) ? $nodes : array( $nodes );
		$context = array();
		$post_id = (int) $request['post_id'];
		if ( $post_id > 0 ) {
			$post = $this->find_post( $post_id );
			if ( is_wp_error( $post ) ) {
				return $this->respond( $post );
			}
			$context['content'] = (string) $post->post_content;
		}
		return $this->respond( ( new Validator() )->validate_graph( $nodes, $context ) );
	}

	/**
	 * GET /schema/site: the business node from saved settings, or from unsaved values passed as
	 * query args (live preview while the form is edited; nothing is saved).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function site( WP_REST_Request $request ) {
		$overrides    = array();
		$field_errors = array();
		foreach ( SettingsFields::business_keys() as $key ) {
			if ( null === $request->get_param( $key ) ) {
				continue;
			}
			$clean = $this->plugin->settings()->validate( 'schema', $key, (string) $request->get_param( $key ) );
			if ( is_wp_error( $clean ) ) {
				$field_errors[ $key ] = $clean->get_error_message();
				continue;
			}
			$overrides[ $key ] = $clean;
		}
		$site                 = $this->service->site( array() === $overrides ? null : $overrides );
		$site['field_errors'] = (object) $field_errors;
		return $this->respond( $site );
	}

	/**
	 * GET /schema/types: the honest rich-result notes for the "About rich results" tab.
	 *
	 * @return WP_REST_Response
	 */
	public function types() {
		$items = array();
		foreach ( Types::all() as $type => $def ) {
			$subtype = null !== $def['parent'] && 'LocalBusiness' !== $type;
			if ( ! $def['top_level'] || $subtype ) {
				continue;
			}
			$items[] = array(
				'type'        => $type,
				'label'       => $def['label'],
				'rich_result' => $def['rich'],
				'note'        => $def['note'],
				'required'    => $def['required'],
				'recommended' => $def['recommended'],
				'ai'          => in_array( $type, Types::ai_types(), true ),
			);
		}
		return $this->respond( array( 'items' => $items ) );
	}

	/**
	 * A post of a public post type, or a 404 error.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|WP_Error
	 */
	private function find_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, SchemaService::post_types(), true ) ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * @return array[] [ { value, label } ]
	 */
	private function post_type_options(): array {
		$out = array();
		foreach ( SchemaService::post_types() as $name ) {
			$object = get_post_type_object( $name );
			$out[]  = array(
				'value' => $name,
				'label' => $object ? (string) $object->labels->name : $name,
			);
		}
		return $out;
	}
}
