<?php
/**
 * GET /suggestions, POST /suggestions/{id}/approve|reject, POST /suggestions/bulk.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Rest;

use SEOConsole\Changes\Change;
use SEOConsole\Plugin;
use SEOConsole\Suggestions\SuggestionStore;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Approval queue routes.
 */
final class SuggestionsController extends Controller {

	/** {@inheritDoc} */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					array(
						'status'      => array(
							'type'    => 'string',
							'enum'    => array_merge( array( 'all' ), SuggestionStore::STATUSES ),
							'default' => 'pending',
						),
						'module'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'type'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
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
			'/suggestions/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'value' => array(
						'description' => __( 'Edited value to apply instead of the proposed one.', 'seo-console' ),
						'type'        => array( 'string', 'number', 'boolean', 'object', 'array', 'null' ),
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/suggestions/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/suggestions/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'approve', 'reject' ),
						'required' => true,
					),
					'ids'    => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems' => 1,
						'maxItems' => 100,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /suggestions.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_items( WP_REST_Request $request ) {
		$args = array(
			'status'   => (string) $request->get_param( 'status' ),
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
		);
		foreach ( array( 'module', 'type', 'object_type', 'object_id' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		$service  = $this->plugin->suggestions();
		$result   = $service->query( $args );
		$counts   = $service->store()->counts( $args );
		$data     = array(
			'items'    => $result['items'],
			'total'    => $result['total'],
			'page'     => max( 1, $args['page'] ),
			'per_page' => max( 1, $args['per_page'] ),
			'counts'   => $counts,
		);
		$response = $this->respond( $data );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ) );
		return $response;
	}

	/**
	 * POST /suggestions/{id}/approve.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function approve( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$edited = is_array( $params ) && array_key_exists( 'value', $params ) ? $params['value'] : null;
		$result = $this->plugin->suggestions()->approve( $id, $edited, (bool) $request->get_param( 'force' ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond(
			array(
				'suggestion' => $this->plugin->suggestions()->get( $id ),
				'change'     => $this->plugin->changes()->to_rest( $result ),
			)
		);
	}

	/**
	 * POST /suggestions/{id}/reject.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reject( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = $this->plugin->suggestions()->reject( $id );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond( array( 'suggestion' => $this->plugin->suggestions()->get( $id ) ) );
	}

	/**
	 * POST /suggestions/bulk.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ) {
		$results = $this->plugin->suggestions()->bulk( (string) $request->get_param( 'action' ), (array) $request->get_param( 'ids' ) );
		$out     = array();
		foreach ( $results as $id => $result ) {
			$item = array(
				'id' => (int) $id,
				'ok' => ! is_wp_error( $result ),
			);
			if ( $result instanceof WP_Error ) {
				$item['code']    = (string) $result->get_error_code();
				$item['message'] = $result->get_error_message();
			} elseif ( $result instanceof Change ) {
				$item['change_id'] = $result->id;
			}
			$out[] = $item;
		}
		return $this->respond( array( 'results' => $out ) );
	}
}
