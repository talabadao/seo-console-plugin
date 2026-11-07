<?php
/**
 * Works out whether the SEO plugin adds the brand to titles, and how.
 *
 * Rank Math and Yoast build titles from a template ("%title% %sep% %sitename%"). A custom per-post SEO title
 * replaces that template completely: plain text is output as is, without the brand. So when the site's template
 * ends with the site name, a proposed title is stored with the same template suffix (for example
 * "Water filter guide %%sep%% %%sitename%%") and the AI is told not to write the brand itself.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use WP_Post;

defined( 'ABSPATH' ) || exit;

final class TitleTemplate {

	/** Default title templates when the SEO plugin has none stored. */
	const DEFAULTS = array(
		'yoast'    => '%%title%% %%page%% %%sep%% %%sitename%%',
		'rankmath' => '%title% %sep% %sitename%',
	);

	/** Suffix used when the setting forces "brand appended" but the template has none. */
	const DEFAULT_SUFFIX = array(
		'yoast'    => ' %%sep%% %%sitename%%',
		'rankmath' => ' %sep% %sitename%',
	);

	/**
	 * The brand suffix to append to proposed titles ('' = none).
	 *
	 * @param string  $plugin  Active SEO plugin: 'rankmath'|'yoast'|'none'.
	 * @param WP_Post $post    Post.
	 * @param string  $setting Module setting brand_appended: 'auto'|'yes'|'no'.
	 * @return string
	 */
	public static function suffix( string $plugin, WP_Post $post, string $setting = 'auto' ): string {
		if ( 'no' === $setting || ! isset( self::DEFAULTS[ $plugin ] ) ) {
			return '';
		}
		$found = self::template_suffix( $plugin, self::template( $plugin, $post->post_type ) );
		if ( '' === $found && 'yes' === $setting ) {
			$found = self::DEFAULT_SUFFIX[ $plugin ];
		}
		return $found;
	}

	/**
	 * The post-type title template of the SEO plugin.
	 *
	 * @param string $plugin    'rankmath'|'yoast'.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function template( string $plugin, string $post_type ): string {
		if ( 'yoast' === $plugin ) {
			$titles = get_option( 'wpseo_titles', array() );
			$key    = 'title-' . $post_type;
		} else {
			$titles = get_option( 'rank-math-options-titles', array() );
			$key    = 'pt_' . $post_type . '_title';
		}
		if ( is_array( $titles ) && isset( $titles[ $key ] ) && '' !== trim( (string) $titles[ $key ] ) ) {
			return (string) $titles[ $key ];
		}
		return isset( self::DEFAULTS[ $plugin ] ) ? self::DEFAULTS[ $plugin ] : '';
	}

	/**
	 * The trailing "separator + site name" part of a template, e.g. " %%sep%% %%sitename%%", or ''.
	 *
	 * @param string $plugin   'rankmath'|'yoast'.
	 * @param string $template Template.
	 * @return string
	 */
	public static function template_suffix( string $plugin, string $template ): string {
		$pattern = 'yoast' === $plugin
			? '/\s*(?:(?:%%sep%%|[-|–—·•:])\s*)?%%sitename%%\s*$/u'
			: '/\s*(?:(?:%sep%|[-|–—·•:])\s*)?%sitename%\s*$/u';
		if ( preg_match( $pattern, $template, $m ) ) {
			return ' ' . ltrim( $m[0] );
		}
		return '';
	}

	/** Yoast separator option values → characters. */
	const YOAST_SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * The suffix as visitors see it, e.g. " - Aqua Shop": template variables replaced through the SEO plugin when it
	 * offers a function for it, otherwise with its separator setting (default "-") and the site name.
	 *
	 * @param string  $plugin 'rankmath'|'yoast'|'none'.
	 * @param string  $suffix Suffix with template variables.
	 * @param WP_Post $post   Post.
	 * @return string
	 */
	public static function resolve_suffix( string $plugin, string $suffix, WP_Post $post ): string {
		if ( '' === trim( $suffix ) ) {
			return '';
		}
		$out = null;
		if ( 'rankmath' === $plugin && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
			$out = (string) \RankMath\Helper::replace_vars( $suffix, $post );
		} elseif ( 'yoast' === $plugin && function_exists( 'wpseo_replace_vars' ) ) {
			$out = (string) wpseo_replace_vars( $suffix, $post );
		}
		if ( null === $out ) {
			$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			$out  = str_replace(
				array( '%%sep%%', '%sep%', '%%sitename%%', '%sitename%' ),
				array( self::separator( $plugin ), self::separator( $plugin ), $site, $site ),
				$suffix
			);
		}
		// Anything the plugin could not replace is not shown.
		$out = (string) preg_replace( '/%%?[a-z_]+%%?/i', '', $out );
		$out = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $out, ENT_QUOTES, 'UTF-8' ) ) ) );
		return '' === $out ? '' : ' ' . $out;
	}

	/**
	 * Title separator configured in the SEO plugin.
	 *
	 * @param string $plugin 'rankmath'|'yoast'.
	 * @return string
	 */
	public static function separator( string $plugin ): string {
		if ( 'rankmath' === $plugin ) {
			$titles = get_option( 'rank-math-options-titles', array() );
			$sep    = is_array( $titles ) && isset( $titles['title_separator'] ) ? trim( html_entity_decode( (string) $titles['title_separator'], ENT_QUOTES, 'UTF-8' ) ) : '';
			return '' === $sep ? '-' : $sep;
		}
		if ( 'yoast' === $plugin ) {
			$titles = get_option( 'wpseo_titles', array() );
			$key    = is_array( $titles ) && isset( $titles['separator'] ) ? (string) $titles['separator'] : '';
			return isset( self::YOAST_SEPARATORS[ $key ] ) ? self::YOAST_SEPARATORS[ $key ] : '-';
		}
		return '-';
	}

	/**
	 * Removes the suffix from a stored title, giving the text a person (or the AI) sees as "the title".
	 *
	 * @param string $title  Stored title.
	 * @param string $suffix Suffix.
	 * @return string
	 */
	public static function strip( string $title, string $suffix ): string {
		if ( '' !== $suffix && strlen( $title ) >= strlen( $suffix ) && substr( $title, -strlen( $suffix ) ) === $suffix ) {
			return rtrim( substr( $title, 0, -strlen( $suffix ) ) );
		}
		return $title;
	}
}
