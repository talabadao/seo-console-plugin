<?php
/**
 * Polylang stand-in: languages come from $GLOBALS['seoc_test_pll_languages'][ $post_id ] (locale string).
 *
 * @package SEOConsole
 */

// phpcs:disable

if ( ! function_exists( 'pll_get_post_language' ) ) {
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		return isset( $GLOBALS['seoc_test_pll_languages'][ $post_id ] ) ? $GLOBALS['seoc_test_pll_languages'][ $post_id ] : false;
	}
}
