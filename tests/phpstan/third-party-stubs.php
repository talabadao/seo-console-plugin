<?php
/**
 * Signatures of the third-party plugin APIs SEO Console calls behind function_exists/class_exists guards.
 * Only read by PHPStan (scanFiles); never loaded at runtime.
 *
 * @package SEOConsole
 */

namespace {
	/** Rank Math main class. */
	class RankMath {}

	/** @return bool */
	function rocket_clean_domain( $lang = '' ) {}
	/** @param string[] $urls */
	function rocket_clean_files( $urls ) {}
	/** @return bool */
	function sg_cachepress_purge_cache( $url = null ) {}
	/** @return bool */
	function sg_cachepress_purge_everything() {}
	/** @return void */
	function w3tc_flush_all() {}
	/** @return void */
	function w3tc_flush_url( $url ) {}
	/** @return void */
	function wp_cache_clear_cache( $blog_id = 0 ) {}
	/** @return void */
	function wpsc_delete_url_cache( $url ) {}
	/** @return void */
	function wpfc_clear_all_cache( $minified = false ) {}
	/** @return string */
	function wpseo_replace_vars( $content, $args, $omit = array() ) {}
}

namespace RankMath {
	/** Rank Math helper. */
	class Helper {
		/** @return string */
		public static function replace_vars( $content, $post = array() ) {}
	}
}
