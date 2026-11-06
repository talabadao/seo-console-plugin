<?php
/**
 * Uninstall: removes SEO Console data unless "Keep data on uninstall" is on.
 *
 * @package SEOConsole
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';
\SEOConsole\Autoloader::register();

if ( class_exists( \SEOConsole\Core\Uninstaller::class ) ) {
	\SEOConsole\Core\Uninstaller::run();
}
