<?php
/**
 * Admin menu and pages (see docs/ARCHITECTURE.md §13).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Admin;

use SEOConsole\Core\Capabilities;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level "SEO Console" menu with the core pages and every enabled module's admin_pages(), sorted by position.
 */
final class Menu {

	const PARENT = 'seo-console';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Assets.
	 *
	 * @var Assets
	 */
	private $assets;

	/**
	 * Pages keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $pages = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->assets = new Assets( $plugin );
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Core pages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function core_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console',
				'title'    => __( 'Dashboard', 'seo-console' ),
				'entry'    => 'dashboard',
				'position' => 0,
			),
			array(
				'slug'     => 'seo-console-suggestions',
				'title'    => __( 'Suggestions', 'seo-console' ),
				'entry'    => 'suggestions',
				'position' => 10,
			),
			array(
				'slug'     => 'seo-console-changes',
				'title'    => __( 'Change log', 'seo-console' ),
				'entry'    => 'changes',
				'position' => 80,
			),
			array(
				'slug'     => 'seo-console-settings',
				'title'    => __( 'Settings', 'seo-console' ),
				'entry'    => 'settings',
				'position' => 90,
			),
		);
	}

	/**
	 * Core + enabled module pages, sorted by position.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pages(): array {
		$pages = self::core_pages();
		foreach ( $this->plugin->modules() as $id => $module ) {
			if ( ! $this->plugin->settings()->is_enabled( $id ) ) {
				continue;
			}
			foreach ( (array) $module->admin_pages() as $page ) {
				if ( ! is_array( $page ) || empty( $page['slug'] ) || empty( $page['title'] ) ) {
					continue;
				}
				$slug    = sanitize_key( (string) $page['slug'] );
				$pages[] = array(
					'slug'     => $slug,
					'title'    => (string) $page['title'],
					'entry'    => sanitize_key( isset( $page['entry'] ) ? (string) $page['entry'] : $id ),
					'position' => isset( $page['position'] ) ? (int) $page['position'] : 50,
					'module'   => $id,
				);
			}
		}
		foreach ( $pages as $index => $page ) {
			$pages[ $index ]['_order'] = $index;
		}
		usort(
			$pages,
			static function ( $a, $b ) {
				return array( $a['position'], $a['_order'] ) <=> array( $b['position'], $b['_order'] );
			}
		);
		$unique = array();
		foreach ( $pages as $page ) {
			unset( $page['_order'] );
			if ( ! isset( $unique[ $page['slug'] ] ) ) {
				$unique[ $page['slug'] ] = $page;
			}
		}
		return array_values( $unique );
	}

	/**
	 * admin_menu callback.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'SEO Console', 'seo-console' ),
			__( 'SEO Console', 'seo-console' ),
			Capabilities::MANAGE,
			self::PARENT,
			array( $this, 'render' ),
			'dashicons-chart-area',
			81
		);
		foreach ( $this->pages() as $page ) {
			$menu_title = esc_html( $page['title'] );
			if ( 'seo-console-suggestions' === $page['slug'] ) {
				$pending = $this->pending_count();
				if ( $pending > 0 ) {
					$menu_title .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . esc_html( number_format_i18n( $pending ) ) . '</span></span>';
				}
			}
			$hook = add_submenu_page(
				self::PARENT,
				$page['title'] . ' ‹ ' . __( 'SEO Console', 'seo-console' ),
				$menu_title,
				Capabilities::MANAGE,
				$page['slug'],
				array( $this, 'render' )
			);
			if ( $hook ) {
				$this->pages[ $hook ] = $page;
			}
		}
	}

	/**
	 * Renders the React root of the current page.
	 */
	public function render(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'seo-console' ) );
		}
		$page  = $this->current_page();
		$entry = $page ? $page['entry'] : 'dashboard';
		echo '<div class="wrap">';
		if ( ! $this->assets->is_built( $entry ) ) {
			echo '<h1>' . esc_html__( 'SEO Console', 'seo-console' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: file name */
					__( 'The admin screen file %s is missing. If you installed SEO Console from source, run "npm run build", or install the release zip.', 'seo-console' ),
					'build/' . $entry . '.js'
				)
			);
			echo '</p></div>';
		}
		// The React Page component adds the .seoc-app wrapper and the wp-header-end marker. The fallback
		// text shows if the script never runs (not enqueued, blocked, or a conflict stopped admin JS), so
		// the screen is never silently blank.
		echo '<div id="seoc-root" data-page="' . esc_attr( $entry ) . '">';
		echo '<p class="seoc-booting" style="padding:12px;color:#646970;">';
		echo esc_html__( 'Loading SEO Console…', 'seo-console' );
		echo '<noscript>' . esc_html__( ' This page needs JavaScript.', 'seo-console' ) . '</noscript>';
		echo '</p></div>';
		echo '</div>';
	}

	/**
	 * admin_enqueue_scripts callback: only on our pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! isset( $this->pages[ $hook_suffix ] ) ) {
			return;
		}
		$this->assets->enqueue( $this->pages[ $hook_suffix ], $this->pages() );
	}

	/**
	 * Page of the current request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_page(): ?array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $this->pages[ $screen->id ] ) ) {
			return $this->pages[ $screen->id ];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		foreach ( $this->pages as $page ) {
			if ( $page['slug'] === $slug ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Number of pending suggestions (menu bubble).
	 */
	private function pending_count(): int {
		$counts = $this->plugin->suggestions()->store()->counts( array() );
		return isset( $counts['pending'] ) ? (int) $counts['pending'] : 0;
	}
}
