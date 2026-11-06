<?php
/**
 * GET /environment.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Rest;

use SEOConsole\Plugin;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Environment facts + checks (extended by modules through `seoc_environment_checks`).
 */
final class EnvironmentController extends Controller {

	/** {@inheritDoc} */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/environment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_environment' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * GET /environment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_environment( WP_REST_Request $request ) {
		unset( $request );
		$environment = $this->plugin->environment();
		return $this->respond(
			array(
				'facts'        => $environment->facts(),
				'checks'       => $environment->checks(),
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}
}
