<?php
/**
 * Test stub imitating WP Rocket (SEOC_TEST_STUB=wp-rocket). Purges are recorded in $GLOBALS['seoc_stub_purges'].
 *
 * @package SEOConsole
 */

if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
	define( 'WP_ROCKET_VERSION', '3.17-stub' );
}
$GLOBALS['seoc_stub_purges'] = array();

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	/** Purges the whole cache (stub). */
	function rocket_clean_domain() {
		$GLOBALS['seoc_stub_purges'][] = 'wp-rocket:all';
		return true;
	}
}
if ( ! function_exists( 'rocket_clean_files' ) ) {
	/**
	 * Purges URLs (stub).
	 *
	 * @param string[] $urls URLs.
	 */
	function rocket_clean_files( $urls ) {
		foreach ( (array) $urls as $url ) {
			$GLOBALS['seoc_stub_purges'][] = 'wp-rocket:' . $url;
		}
	}
}
