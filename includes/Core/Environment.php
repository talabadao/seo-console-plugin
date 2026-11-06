<?php
/**
 * Environment facts and checks for GET /environment.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

use SEOConsole\Integrations\CachePlugins;
use SEOConsole\Integrations\SeoPlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal core checks; the Speed module adds more through the `seoc_environment_checks` filter.
 * Each check: { id, label, value, status: 'good'|'needs-work'|'poor'|'unknown', description }.
 */
final class Environment {

	/**
	 * SEO plugins.
	 *
	 * @var SeoPlugins
	 */
	private $seo;

	/**
	 * Cache plugins.
	 *
	 * @var CachePlugins
	 */
	private $cache;

	/**
	 * Safe Mode.
	 *
	 * @var SafeMode
	 */
	private $safe_mode;

	/**
	 * Constructor.
	 *
	 * @param SeoPlugins   $seo       SEO plugins.
	 * @param CachePlugins $cache     Cache plugins.
	 * @param SafeMode     $safe_mode Safe Mode.
	 */
	public function __construct( SeoPlugins $seo, CachePlugins $cache, SafeMode $safe_mode ) {
		$this->seo       = $seo;
		$this->cache     = $cache;
		$this->safe_mode = $safe_mode;
	}

	/**
	 * Raw facts.
	 *
	 * @return array<string, mixed>
	 */
	public function facts(): array {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return array(
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'plugin_version'  => defined( 'SEOC_VERSION' ) ? SEOC_VERSION : '',
			'server_software' => $server,
			'memory_limit'    => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'seo_plugin'      => $this->seo->active(),
			'seo_plugins'     => $this->seo->detected(),
			'cache_plugins'   => $this->cache->detect(),
			'safe_mode'       => $this->safe_mode->is_on(),
			'multisite'       => is_multisite(),
		);
	}

	/**
	 * Checks (core + filtered).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function checks(): array {
		$facts  = $this->facts();
		$checks = array();

		$php      = $facts['php_version'];
		$checks[] = array(
			'id'          => 'php_version',
			'label'       => __( 'PHP version', 'seo-console' ),
			'value'       => $php,
			'status'      => version_compare( $php, '8.1', '>=' ) ? 'good' : 'needs-work',
			'description' => version_compare( $php, '8.1', '>=' )
				? __( 'A current PHP version.', 'seo-console' )
				: __( 'PHP 8.1 or newer is noticeably faster. Ask your host to upgrade.', 'seo-console' ),
		);

		$wp       = $facts['wp_version'];
		$checks[] = array(
			'id'          => 'wp_version',
			'label'       => __( 'WordPress version', 'seo-console' ),
			'value'       => $wp,
			'status'      => version_compare( $wp, '6.5', '>=' ) ? 'good' : 'poor',
			'description' => __( 'SEO Console needs WordPress 6.5 or newer.', 'seo-console' ),
		);

		$checks[] = array(
			'id'          => 'server_software',
			'label'       => __( 'Web server', 'seo-console' ),
			'value'       => '' !== $facts['server_software'] ? $facts['server_software'] : __( 'Unknown', 'seo-console' ),
			'status'      => 'unknown',
			'description' => __( 'Apache, LiteSpeed and Nginx are all supported.', 'seo-console' ),
		);

		$memory   = wp_convert_hr_to_bytes( $facts['memory_limit'] );
		$checks[] = array(
			'id'          => 'memory_limit',
			'label'       => __( 'PHP memory limit', 'seo-console' ),
			'value'       => $facts['memory_limit'],
			'status'      => ( -1 === (int) $facts['memory_limit'] || $memory >= 256 * MB_IN_BYTES ) ? 'good' : ( $memory >= 128 * MB_IN_BYTES ? 'needs-work' : 'poor' ),
			'description' => __( '256 MB or more is comfortable; 128 MB is the minimum.', 'seo-console' ),
		);

		$checks[] = array(
			'id'          => 'seo_plugin',
			'label'       => __( 'SEO plugin', 'seo-console' ),
			'value'       => \SEOConsole\Integrations\SeoPlugins::name( $facts['seo_plugin'] ),
			'status'      => 'none' === $facts['seo_plugin'] ? 'needs-work' : 'good',
			'description' => 'none' === $facts['seo_plugin']
				? __( 'Install Rank Math or Yoast SEO: SEO Console works alongside them for titles and meta descriptions.', 'seo-console' )
				: __( 'SEO titles and descriptions are read and written through this plugin.', 'seo-console' ),
		);

		$names    = wp_list_pluck( $facts['cache_plugins'], 'name' );
		$checks[] = array(
			'id'          => 'cache_plugins',
			'label'       => __( 'Page cache plugin', 'seo-console' ),
			'value'       => empty( $names ) ? __( 'None', 'seo-console' ) : implode( ', ', $names ),
			'status'      => empty( $names ) ? 'needs-work' : ( count( $names ) > 1 ? 'needs-work' : 'good' ),
			'description' => empty( $names )
				? __( 'A page cache plugin makes pages load much faster.', 'seo-console' )
				: ( count( $names ) > 1 ? __( 'More than one cache plugin is active; keep only one.', 'seo-console' ) : __( 'Caches are purged automatically after SEO Console changes.', 'seo-console' ) ),
		);

		$checks[] = array(
			'id'          => 'safe_mode',
			'label'       => __( 'Safe Mode', 'seo-console' ),
			'value'       => $facts['safe_mode'] ? __( 'On', 'seo-console' ) : __( 'Off', 'seo-console' ),
			'status'      => $facts['safe_mode'] ? 'needs-work' : 'good',
			'description' => $facts['safe_mode']
				? __( 'SEO Console adds nothing to public pages while Safe Mode is on.', 'seo-console' )
				: __( 'Enabled modules may add output to public pages.', 'seo-console' ),
		);

		/**
		 * Filters the environment checks shown on the Environment screen.
		 *
		 * @param array $checks List of { id, label, value, status, description }.
		 * @param array $facts  Raw facts.
		 */
		$checks = (array) apply_filters( 'seoc_environment_checks', $checks, $facts );
		return array_values( array_filter( $checks, 'is_array' ) );
	}
}
