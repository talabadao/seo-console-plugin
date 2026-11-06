<?php
/**
 * Test stub imitating Rank Math SEO (SEOC_TEST_STUB=rankmath).
 *
 * @package SEOConsole
 */

namespace {
	if ( ! defined( 'RANK_MATH_VERSION' ) ) {
		define( 'RANK_MATH_VERSION', '1.0.250-stub' );
	}
	if ( ! class_exists( 'RankMath' ) ) {
		/** Main Rank Math class (stub). */
		class RankMath {
		}
	}
}

namespace RankMath {
	if ( ! class_exists( '\RankMath\Helper' ) ) {
		/** Rank Math helper (stub): replaces a few template variables. */
		class Helper {
			/**
			 * @param string        $content Template.
			 * @param \WP_Post|null $post    Post.
			 */
			public static function replace_vars( $content, $post = null ) {
				$title = $post ? get_the_title( $post ) : '';
				return str_replace( array( '%title%', '%sitename%', '%sep%' ), array( $title, get_bloginfo( 'name' ), '-' ), $content );
			}
		}
	}
}
