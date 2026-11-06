<?php
/**
 * Replaces the SEO Console web app's one-file helper `seo-console-meta.php` (see docs/ARCHITECTURE.md §9).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Integrations;

use SEOConsole\Core\Capabilities;
use SEOConsole\Plugin;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the six SEO meta keys in the core REST API and `GET seo-console/v1/status`; warns when the old helper
 * plugin is still active.
 */
final class HelperCompat {

	const OLD_HELPER = 'seo-console-meta.php';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the meta keys with show_in_rest for every public post type that supports the editor.
	 */
	public function register_meta(): void {
		foreach ( $this->post_types() as $post_type ) {
			foreach ( SeoPlugins::all_meta_keys() as $key ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => 'sanitize_text_field',
						'auth_callback'     => static function ( $allowed, $meta_key, $object_id ) {
							return current_user_can( 'edit_post', (int) $object_id );
						},
					)
				);
			}
		}
	}

	/**
	 * Public post types that support the editor.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( post_type_supports( $type, 'editor' ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	/**
	 * Registers GET /status.
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( Capabilities::class, 'can_read_status' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * GET /status response.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$features = array( 'seo_meta_rest', 'suggestions', 'change_log' );
		foreach ( $this->plugin->modules() as $id => $module ) {
			if ( $this->plugin->settings()->is_enabled( $id ) ) {
				$features[] = 'module:' . $id;
			}
		}
		/**
		 * Filters the features listed by GET /status.
		 *
		 * @param string[] $features Feature ids.
		 */
		$features = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'seoc_status_features', $features ) ) ) );
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'version'  => defined( 'SEOC_VERSION' ) ? SEOC_VERSION : '',
				'plugin'   => 'seo-console',
				'features' => $features,
			)
		);
	}

	/**
	 * Main file (relative to the plugins folder) of the old helper when it is active, else null.
	 */
	public function old_helper_file(): ?string {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $active as $file ) {
			if ( is_string( $file ) && self::OLD_HELPER === basename( $file ) && is_plugin_active( $file ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Nonce'd core URL that deactivates the old helper.
	 *
	 * @param string $file Plugin file.
	 */
	public static function deactivate_url( string $file ): string {
		return wp_nonce_url(
			admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) . '&plugin_status=all' ),
			'deactivate-plugin_' . $file
		);
	}
}
