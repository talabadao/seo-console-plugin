<?php
/**
 * Safe Mode: when on, SEO Console adds nothing to public pages (see docs/ARCHITECTURE.md §2, §4).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Turned on by the constant SEOC_SAFE_MODE (wp-config.php) or the general setting `safe_mode`.
 */
final class SafeMode {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether Safe Mode is on.
	 */
	public function is_on(): bool {
		return 'off' !== $this->source();
	}

	/**
	 * Why Safe Mode is on: 'constant', 'setting' or 'off'.
	 */
	public function source(): string {
		$source = 'off';
		if ( defined( 'SEOC_SAFE_MODE' ) && SEOC_SAFE_MODE ) {
			$source = 'constant';
		} elseif ( $this->settings->get( Settings::GENERAL, 'safe_mode' ) ) {
			$source = 'setting';
		}
		/**
		 * Filters the Safe Mode source ('constant', 'setting' or 'off').
		 *
		 * @param string $source Source.
		 */
		$source = (string) apply_filters( 'seoc_safe_mode', $source );
		return in_array( $source, array( 'constant', 'setting', 'off' ), true ) ? $source : 'setting';
	}

	/**
	 * Turns the setting on or off (the constant always wins).
	 *
	 * @param bool $on New state.
	 * @return true|\WP_Error
	 */
	public function set( bool $on ) {
		return $this->settings->set( Settings::GENERAL, 'safe_mode', $on );
	}

	/**
	 * Hooks the admin bar notice.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/**
	 * Adds a "Safe Mode" node to the admin bar for managers while Safe Mode is on.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		if ( ! $this->is_on() || ! Capabilities::can_manage() || ! is_object( $bar ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'seoc-safe-mode',
				'title' => esc_html__( 'SEO Console: Safe Mode', 'seo-console' ),
				'href'  => esc_url( admin_url( 'admin.php?page=seo-console-settings' ) ),
				'meta'  => array( 'title' => esc_attr__( 'SEO Console adds nothing to public pages while Safe Mode is on.', 'seo-console' ) ),
			)
		);
	}
}
