<?php
/**
 * Test stub imitating Yoast SEO (SEOC_TEST_STUB=yoast).
 *
 * @package SEOConsole
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '25.0-stub' );
}

if ( ! function_exists( 'wpseo_replace_vars' ) ) {
	/**
	 * Replaces a few Yoast template variables (stub).
	 *
	 * @param string       $content Template.
	 * @param WP_Post|array $args   Post.
	 */
	function wpseo_replace_vars( $content, $args ) {
		$title = $args instanceof WP_Post ? get_the_title( $args ) : '';
		return str_replace( array( '%%title%%', '%%sitename%%', '%%sep%%' ), array( $title, get_bloginfo( 'name' ), '-' ), $content );
	}
}
