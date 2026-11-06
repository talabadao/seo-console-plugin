<?php
/**
 * GET/POST /settings and POST /settings/test-ai.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Rest;

use SEOConsole\Core\Secrets;
use SEOConsole\Core\Settings;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets are only ever returned as { has, masked, source }.
 */
final class SettingsController extends Controller {

	/** {@inheritDoc} */
	public function register_routes(): void {
		register_rest_route(
			\SEOConsole\Plugin::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'general' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'modules' => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
						'secrets' => array(
							'type'                 => 'object',
							'properties'           => array(
								'claude_api_key' => array( 'type' => 'string' ),
								'google_api_key' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			\SEOConsole\Plugin::REST_NAMESPACE,
			'/settings/test-ai',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_ai' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'model'   => array(
						'type' => 'string',
						'enum' => Settings::general_schema()['ai_model']['enum'],
					),
					'api_key' => array(
						'description' => __( 'Unsaved key to test; used for this request only and never stored.', 'seo-console' ),
						'type'        => 'string',
						'maxLength'   => 512,
					),
				),
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_settings( WP_REST_Request $request ) {
		unset( $request );
		return $this->respond( $this->payload() );
	}

	/**
	 * POST /settings: validates everything first, then saves (all-or-nothing for settings).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_settings( WP_REST_Request $request ) {
		$settings = $this->plugin->settings();
		$general  = $request->get_param( 'general' );
		$modules  = $request->get_param( 'modules' );
		$secrets  = $request->get_param( 'secrets' );
		$errors   = array();
		$plan     = array();

		if ( is_array( $general ) ) {
			foreach ( $general as $key => $value ) {
				$clean = $settings->validate( Settings::GENERAL, (string) $key, $value );
				if ( is_wp_error( $clean ) ) {
					$errors[ 'general.' . $key ] = $clean->get_error_message();
				} else {
					$plan[ Settings::GENERAL ][ $key ] = $clean;
				}
			}
		}
		if ( is_array( $modules ) ) {
			foreach ( $modules as $module => $values ) {
				$module = (string) $module;
				if ( ! $settings->has_module( $module ) ) {
					$errors[ $module ] = __( 'Unknown module.', 'seo-console' );
					continue;
				}
				foreach ( (array) $values as $key => $value ) {
					$clean = $settings->validate( $module, (string) $key, $value );
					if ( is_wp_error( $clean ) ) {
						$errors[ $module . '.' . $key ] = $clean->get_error_message();
					} else {
						$plan[ $module ][ $key ] = $clean;
					}
				}
			}
		}
		if ( is_array( $secrets ) ) {
			foreach ( $secrets as $name => $value ) {
				if ( ! in_array( $name, Secrets::KNOWN, true ) ) {
					$errors[ 'secrets.' . $name ] = __( 'Unknown key.', 'seo-console' );
				} elseif ( 'constant' === $this->plugin->secrets()->source( $name ) && '' !== trim( (string) $value ) ) {
					$errors[ 'secrets.' . $name ] = __( 'This key is set in wp-config.php and cannot be changed here.', 'seo-console' );
				}
			}
		}
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'seoc_invalid_settings',
				__( 'Some settings are invalid. Nothing was saved.', 'seo-console' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$changed = array();
		foreach ( $plan as $section => $values ) {
			$result = $settings->update( $section, $values );
			if ( is_wp_error( $result ) ) {
				return $this->respond( $result );
			}
			foreach ( array_keys( $values ) as $key ) {
				$changed[] = $section . '.' . $key;
			}
		}
		if ( is_array( $secrets ) ) {
			foreach ( $secrets as $name => $value ) {
				$result = $this->plugin->secrets()->set( $name, (string) $value );
				if ( is_wp_error( $result ) ) {
					return $this->respond( $result );
				}
				$changed[] = 'secrets.' . $name; // Name only, never the value.
			}
		}
		if ( ! empty( $changed ) ) {
			$this->plugin->logger()->info( 'Settings updated.', array( 'keys' => $changed ) );
		}
		return $this->respond( $this->payload() );
	}

	/**
	 * POST /settings/test-ai.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test_ai( WP_REST_Request $request ) {
		$client = $this->plugin->claude();
		if ( ! method_exists( $client, 'test_connection' ) ) {
			return new WP_Error( 'seoc_ai_request', __( 'The AI client cannot be tested.', 'seo-console' ), array( 'status' => 500 ) );
		}
		$model   = $request->get_param( 'model' );
		$api_key = $request->get_param( 'api_key' );
		return $this->respond( $client->test_connection( is_string( $model ) ? $model : null, is_string( $api_key ) ? $api_key : null ) );
	}

	/**
	 * Settings payload (shape documented in ARCHITECTURE "REST response shapes").
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$settings = $this->plugin->settings();
		$modules  = array();
		$info     = array();
		$schema   = array(
			'general' => Settings::general_schema(),
			'modules' => array(),
		);
		foreach ( $this->plugin->modules() as $id => $module ) {
			$modules[ $id ]           = $settings->all( $id );
			$info[ $id ]              = array(
				'title'       => $module->title(),
				'description' => $module->description(),
			);
			$schema['modules'][ $id ] = array(
				'title'           => $module->title(),
				'description'     => $module->description(),
				'default_enabled' => $module->default_enabled(),
				'settings'        => $settings->schema( $id ),
			);
		}
		$secrets = array();
		foreach ( Secrets::KNOWN as $name ) {
			$secrets[ $name ] = $this->plugin->secrets()->describe( $name );
		}
		$safe                    = $this->plugin->safe_mode_service();
		$schema['modules']       = (object) $schema['modules'];
		$usage                   = $this->plugin->usage()->month_summary();
		$usage['month_cost_usd'] = $usage['cost_usd'];
		return array(
			'general'     => $settings->all( Settings::GENERAL ),
			'modules'     => (object) $modules,
			'module_info' => (object) $info,
			'schema'      => $schema,
			'secrets'     => $secrets,
			'safe_mode'   => array(
				'on'     => $safe->is_on(),
				'source' => $safe->source(),
			),
			'ai_usage'    => $usage,
		);
	}
}
