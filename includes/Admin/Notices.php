<?php
/**
 * Admin notices: old helper plugin active, secrets that cannot be decrypted, Safe Mode on.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Admin;

use SEOConsole\Core\Capabilities;
use SEOConsole\Integrations\HelperCompat;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Notices are shown to managers only.
 */
final class Notices {

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
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Notices for the current screen as [ type, html ] pairs.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function collect(): array {
		if ( ! Capabilities::can_manage() ) {
			return array();
		}
		$notices = array();
		$ours    = $this->is_our_screen();

		$old = $this->plugin->helper_compat()->old_helper_file();
		if ( null !== $old ) {
			$notices[] = array(
				'warning',
				esc_html__( 'SEO Console now includes everything the "SEO Console Meta" helper plugin did. You can deactivate the helper.', 'seo-console' )
				. ' <a href="' . esc_url( HelperCompat::deactivate_url( $old ) ) . '">' . esc_html__( 'Deactivate the helper', 'seo-console' ) . '</a>',
			);
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $ours || ( $screen && in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) ) {
			$broken = $this->plugin->secrets()->undecryptable();
			if ( ! empty( $broken ) ) {
				$notices[] = array(
					'error',
					esc_html__( 'SEO Console cannot read a saved API key (the site\'s security keys probably changed). Please enter the key again.', 'seo-console' )
					. ' <a href="' . esc_url( admin_url( 'admin.php?page=seo-console-settings' ) ) . '">' . esc_html__( 'Open settings', 'seo-console' ) . '</a>',
				);
			}
		}

		if ( $ours && $this->plugin->safe_mode() ) {
			$notices[] = array(
				'info',
				esc_html__( 'Safe Mode is on: SEO Console adds nothing to public pages.', 'seo-console' ),
			);
		}
		return $notices;
	}

	/**
	 * admin_notices callback.
	 */
	public function render(): void {
		foreach ( $this->collect() as $notice ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $notice[0] ),
				wp_kses(
					$notice[1],
					array(
						'a' => array( 'href' => array() ),
					)
				)
			);
		}
	}

	/**
	 * Whether the current screen is an SEO Console page.
	 */
	private function is_our_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 0 === strpos( $page, 'seo-console' );
	}
}
