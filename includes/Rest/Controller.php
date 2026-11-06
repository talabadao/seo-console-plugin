<?php
/**
 * Base class for core REST controllers (see docs/ARCHITECTURE.md §12).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Rest;

use SEOConsole\Core\Capabilities;
use SEOConsole\Plugin;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Routes live under `seo-console/v1`; permission `manage_options` unless a route says otherwise.
 */
abstract class Controller {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback: manage_options.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return Capabilities::can_manage();
	}

	/**
	 * Wraps data in a response, or passes a WP_Error through (with an HTTP status).
	 *
	 * @param mixed $data   Data or WP_Error.
	 * @param int   $status Success status.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( $data, int $status = 200 ) {
		if ( is_wp_error( $data ) ) {
			$error_data = $data->get_error_data();
			if ( ! is_array( $error_data ) || ! isset( $error_data['status'] ) ) {
				$data->add_data( array_merge( is_array( $error_data ) ? $error_data : array(), array( 'status' => 400 ) ) );
			}
			return $data;
		}
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Common pagination args.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
		);
	}

	/**
	 * Compact error for list results.
	 *
	 * @param WP_Error $error Error.
	 * @return array{code: string, message: string}
	 */
	protected static function error_array( WP_Error $error ): array {
		return array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}
}
