<?php
/**
 * Enqueues build/<entry>.js on SEO Console pages only (see docs/ARCHITECTURE.md §13).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Admin;

use SEOConsole\Core\Capabilities;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Script + `window.seocConfig` inline config.
 */
final class Assets {

	/**
	 * Plugin.
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
	 * Absolute path of a build file.
	 *
	 * @param string $file File relative to build/.
	 */
	public static function build_path( string $file ): string {
		return SEOC_DIR . 'build/' . $file;
	}

	/**
	 * Whether build/<entry>.js exists.
	 *
	 * @param string $entry Entry.
	 */
	public function is_built( string $entry ): bool {
		return file_exists( self::build_path( sanitize_key( $entry ) . '.js' ) );
	}

	/**
	 * Enqueues one page's script, styles and config.
	 *
	 * @param array $page  Page { slug, title, entry, position }.
	 * @param array $pages All pages (for navigation).
	 */
	public function enqueue( array $page, array $pages = array() ): void {
		$entry = sanitize_key( (string) $page['entry'] );
		wp_enqueue_style( 'wp-components' );
		if ( ! $this->is_built( $entry ) ) {
			return;
		}
		$asset_file = self::build_path( $entry . '.asset.php' );
		$asset      = file_exists( $asset_file ) ? include $asset_file : array();
		$deps       = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array( 'wp-element', 'wp-api-fetch', 'wp-components', 'wp-i18n' );
		$version    = isset( $asset['version'] ) ? (string) $asset['version'] : SEOC_VERSION;
		$handle     = 'seoc-' . $entry;

		wp_enqueue_script( $handle, SEOC_URL . 'build/' . $entry . '.js', $deps, $version, true );
		wp_set_script_translations( $handle, 'seo-console' );
		wp_add_inline_script( $handle, 'window.seocConfig = ' . wp_json_encode( $this->config( $page, $pages ) ) . ';', 'before' );

		foreach ( array(
			$entry . '.css'            => $handle,
			'style-' . $entry . '.css' => $handle . '-style',
		) as $css => $style_handle ) {
			if ( file_exists( self::build_path( $css ) ) ) {
				wp_enqueue_style( $style_handle, SEOC_URL . 'build/' . $css, array( 'wp-components' ), $version );
				if ( file_exists( self::build_path( str_replace( '.css', '-rtl.css', $css ) ) ) ) {
					wp_style_add_data( $style_handle, 'rtl', 'replace' );
				}
			}
		}
	}

	/**
	 * `window.seocConfig` (ARCHITECTURE §13).
	 *
	 * @param array $page  Current page.
	 * @param array $pages All pages.
	 * @return array<string, mixed>
	 */
	public function config( array $page, array $pages = array() ): array {
		$modules = array();
		$info    = array();
		foreach ( $this->plugin->modules() as $id => $module ) {
			$modules[ $id ] = $this->plugin->settings()->is_enabled( $id );
			$module_pages   = (array) $module->admin_pages();
			$info[ $id ]    = array(
				'title'       => $module->title(),
				'description' => $module->description(),
				'page'        => isset( $module_pages[0]['slug'] ) ? sanitize_key( (string) $module_pages[0]['slug'] ) : null,
			);
		}
		/**
		 * Filters the Dashboard cards contributed by modules.
		 *
		 * @param array $cards List of cards (shape agreed between modules and the Dashboard UI).
		 */
		$cards = array_values( (array) apply_filters( 'seoc_dashboard_cards', array() ) );
		/**
		 * Filters the Dashboard "getting started" checklist.
		 *
		 * @param array{speedTested: bool, auditRun: bool} $checklist Checklist flags.
		 */
		$checklist = array_merge(
			array(
				'speedTested' => false,
				'auditRun'    => false,
			),
			(array) apply_filters(
				'seoc_checklist',
				array(
					'speedTested' => false,
					'auditRun'    => false,
				)
			)
		);
		$nav       = array();
		foreach ( $pages as $item ) {
			$nav[] = array(
				'slug'  => $item['slug'],
				'title' => $item['title'],
				'entry' => $item['entry'],
				'url'   => admin_url( 'admin.php?page=' . $item['slug'] ),
			);
		}
		$claude = $this->plugin->claude();
		return array(
			'restNamespace'    => Plugin::REST_NAMESPACE,
			'adminUrl'         => admin_url(),
			'pluginUrl'        => SEOC_URL,
			'version'          => SEOC_VERSION,
			'page'             => (string) $page['entry'],
			'modules'          => (object) $modules,
			'moduleInfo'       => (object) $info,
			'dashboardCards'   => $cards,
			'checklist'        => $checklist,
			'googleConfigured' => $this->plugin->secrets()->has( 'google_api_key' ),
			'seoPlugin'        => $this->plugin->seo()->active(),
			'cachePlugins'     => $this->plugin->cache()->detect(),
			'safeMode'         => $this->plugin->safe_mode(),
			'aiConfigured'     => $claude->is_available(),
			'pages'            => $nav,
			'canManage'        => Capabilities::can_manage(),
		);
	}
}
