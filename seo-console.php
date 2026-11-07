<?php
/**
 * Plugin Name:       SEO Console
 * Plugin URI:        https://github.com/talabadao/seo-console-plugin
 * Description:       Technical SEO audits, page speed monitoring and AI suggestions you review and approve. Works alongside Rank Math or Yoast SEO.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Artemis Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-console
 *
 * @package SEOConsole
 */

defined( 'ABSPATH' ) || exit;

define( 'SEOC_VERSION', '0.1.0' );
define( 'SEOC_FILE', __FILE__ );
define( 'SEOC_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOC_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOC_MIN_PHP', '7.4' );
define( 'SEOC_MIN_WP', '6.5' );

if ( version_compare( PHP_VERSION, SEOC_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), SEOC_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: PHP version, 2: WordPress version */
					__( 'SEO Console needs PHP %1$s+ and WordPress %2$s+. It is not running.', 'seo-console' ),
					SEOC_MIN_PHP,
					SEOC_MIN_WP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once SEOC_DIR . 'includes/Autoloader.php';
\SEOConsole\Autoloader::register();

// Action Scheduler handles its own versioning when several plugins bundle it.
if ( file_exists( SEOC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once SEOC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( __FILE__, array( \SEOConsole\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SEOConsole\Plugin::class, 'deactivate' ) );

// Boot on init (priority 0): module settings and labels are translated, and WordPress 6.7+ requires translations to load at init or later.
add_action( 'init', array( \SEOConsole\Plugin::class, 'boot' ), 0 );
