<?php
/**
 * GET /changes and POST /changes/{id}/revert.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Rest;

use SEOConsole\Changes\ChangeManager;
use SEOConsole\Plugin;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Change log routes.
 */
final class ChangesController extends Controller {

	/** {@inheritDoc} */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					array(
						'module'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'status'      => array(
							'type' => 'string',
							'enum' => ChangeManager::STATUSES,
						),
						'object_type' => array(
							'type' => 'string',
							'enum' => array( 'post', 'term', 'attachment', 'option', 'site' ),
						),
						'object_id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					$this->pagination_args()
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/changes/(?P<id>\d+)/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /changes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_items( WP_REST_Request $request ) {
		$args = array(
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
		);
		foreach ( array( 'module', 'status', 'object_type', 'object_id' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		$manager  = $this->plugin->changes();
		$result   = $manager->query( $args );
		$response = $this->respond(
			array(
				'items'    => array_map( array( $manager, 'to_rest' ), $result['items'] ),
				'total'    => $result['total'],
				'page'     => max( 1, $args['page'] ),
				'per_page' => max( 1, $args['per_page'] ),
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ) );
		return $response;
	}

	/**
	 * POST /changes/{id}/revert.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revert( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$manager = $this->plugin->changes();
		$result  = $manager->revert( $id, (bool) $request->get_param( 'force' ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond( array( 'change' => $manager->to_rest( $manager->get( $id ) ) ) );
	}
}
