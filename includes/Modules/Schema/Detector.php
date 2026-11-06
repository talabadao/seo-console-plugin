<?php
/**
 * Finds the schema a post already has, so SEO Console never proposes a duplicate type:
 *  (a) what the SEO plugin outputs (Rank Math: rank_math_schema_* meta, its default rich snippet,
 *      FAQ/HowTo blocks, site-wide pieces; Yoast: its default graph pieces and blocks),
 *  (b) JSON-LD blocks and microdata in the post content (and optional rendered HTML),
 *  (c) SEO Console's own approved `_seoc_schema`.
 *
 * Each finding: [ 'type' => 'Article', 'source' => self::SOURCE_*, 'detail' => string ].
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Detector {

	const SOURCE_RANKMATH    = 'rankmath';
	const SOURCE_YOAST       = 'yoast';
	const SOURCE_WOOCOMMERCE = 'woocommerce';
	const SOURCE_JSONLD      = 'content_jsonld';
	const SOURCE_MICRODATA   = 'content_microdata';
	const SOURCE_HTML        = 'page_html';
	const SOURCE_SEOC        = 'seoc';

	/** @var string 'rankmath'|'yoast'|'none' */
	private $seo_plugin;

	/**
	 * @param string $seo_plugin Active SEO plugin ('rankmath'|'yoast'|'none'); core's SeoPlugins::active() in Phase B.
	 */
	public function __construct( string $seo_plugin = 'none' ) {
		$this->seo_plugin = in_array( $seo_plugin, array( 'rankmath', 'yoast' ), true ) ? $seo_plugin : 'none';
	}

	/** The SEO plugin this detector assumes. */
	public function seo_plugin(): string {
		return $this->seo_plugin;
	}

	/**
	 * Fallback detection when core's SeoPlugins is not available.
	 *
	 * @return string 'rankmath'|'yoast'|'none'
	 */
	public static function active_seo_plugin(): string {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		return 'none';
	}

	/**
	 * Everything detected for a post.
	 *
	 * @param int    $post_id       Post id.
	 * @param string $rendered_html Optional full page HTML (e.g. from a loopback fetch) to scan as well.
	 * @return array[]
	 */
	public function detect( int $post_id, string $rendered_html = '' ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$found = array();
		if ( 'rankmath' === $this->seo_plugin ) {
			$found = array_merge( $found, $this->from_rank_math( $post ) );
		} elseif ( 'yoast' === $this->seo_plugin ) {
			$found = array_merge( $found, $this->from_yoast( $post ) );
		}
		$found = array_merge( $found, $this->from_woocommerce( $post ) );

		foreach ( Html::json_ld_blocks( (string) $post->post_content ) as $block ) {
			$found = array_merge( $found, self::from_jsonld( $block, self::SOURCE_JSONLD ) );
		}
		foreach ( Html::microdata_types( (string) $post->post_content ) as $type ) {
			$found[] = self::finding( $type, self::SOURCE_MICRODATA, __( 'Microdata in the post content', 'seo-console' ) );
		}
		if ( '' !== $rendered_html ) {
			foreach ( Html::json_ld_blocks( $rendered_html ) as $block ) {
				$found = array_merge( $found, self::from_jsonld( $block, self::SOURCE_HTML ) );
			}
			foreach ( Html::microdata_types( $rendered_html ) as $type ) {
				$found[] = self::finding( $type, self::SOURCE_HTML, __( 'Microdata in the page HTML', 'seo-console' ) );
			}
		}
		foreach ( Store::types( Store::get( $post_id ) ) as $type ) {
			$found[] = self::finding( $type, self::SOURCE_SEOC, __( 'Approved in SEO Console', 'seo-console' ) );
		}

		$found = self::unique( $found );

		/**
		 * Filters schema detected for a post (add types output by a theme or another plugin).
		 *
		 * @param array[] $found   Findings: type, source, detail.
		 * @param int     $post_id Post id.
		 */
		return (array) apply_filters( 'seoc_schema_detected', $found, $post_id );
	}

	/**
	 * Whether a type (or one of its de-duplication group) is already present.
	 *
	 * @param array[] $found       Findings from detect().
	 * @param string  $type        Type.
	 * @param bool    $include_own Count SEO Console's own approved schema too.
	 */
	public static function has( array $found, string $type, bool $include_own = false ): bool {
		return null !== self::find( $found, $type, $include_own );
	}

	/**
	 * The first finding of the same group as $type, or null.
	 *
	 * @param array[] $found       Findings from detect().
	 * @param string  $type        Type.
	 * @param bool    $include_own Count SEO Console's own approved schema too.
	 * @return array|null
	 */
	public static function find( array $found, string $type, bool $include_own = false ): ?array {
		$group = Types::group( $type );
		foreach ( $found as $finding ) {
			if ( ! $include_own && self::SOURCE_SEOC === $finding['source'] ) {
				continue;
			}
			if ( Types::group( $finding['type'] ) === $group ) {
				return $finding;
			}
		}
		return null;
	}

	/**
	 * Human name of a source ("Rank Math").
	 *
	 * @param string $source Source.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			self::SOURCE_RANKMATH    => __( 'Rank Math', 'seo-console' ),
			self::SOURCE_YOAST       => __( 'Yoast SEO', 'seo-console' ),
			self::SOURCE_WOOCOMMERCE => __( 'WooCommerce', 'seo-console' ),
			self::SOURCE_JSONLD      => __( 'JSON-LD in the post content', 'seo-console' ),
			self::SOURCE_MICRODATA   => __( 'microdata in the post content', 'seo-console' ),
			self::SOURCE_HTML        => __( 'the page HTML (theme or another plugin)', 'seo-console' ),
			self::SOURCE_SEOC        => __( 'SEO Console', 'seo-console' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Types found in decoded JSON-LD (top level, @graph, and typed values one level down such as
	 * Product inside a WebPage's mainEntity).
	 *
	 * @param mixed  $data   Decoded JSON-LD.
	 * @param string $source Source id.
	 * @param int    $depth  Recursion depth.
	 * @return array[]
	 */
	public static function from_jsonld( $data, string $source, int $depth = 0 ): array {
		$found = array();
		if ( ! is_array( $data ) || $depth > 3 ) {
			return $found;
		}
		if ( Validator::is_list( $data ) ) {
			foreach ( $data as $item ) {
				$found = array_merge( $found, self::from_jsonld( $item, $source, $depth ) );
			}
			return $found;
		}
		if ( isset( $data['@graph'] ) ) {
			$found = array_merge( $found, self::from_jsonld( $data['@graph'], $source, $depth ) );
		}
		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$found[] = self::finding( $type, $source, __( 'JSON-LD block', 'seo-console' ) );
				}
			}
		}
		if ( 0 === $depth ) {
			foreach ( array( 'mainEntity', 'mainEntityOfPage', 'breadcrumb', 'video', 'subjectOf' ) as $prop ) {
				if ( isset( $data[ $prop ] ) && is_array( $data[ $prop ] ) ) {
					$found = array_merge( $found, self::from_jsonld( $data[ $prop ], $source, $depth + 1 ) );
				}
			}
		}
		return $found;
	}

	/**
	 * Rank Math: per-post schema meta, default rich snippet, blocks and site-wide pieces.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	private function from_rank_math( \WP_Post $post ): array {
		$found  = array();
		$titles = get_option( 'rank-math-options-titles', array() );
		$titles = is_array( $titles ) ? $titles : array();

		$has_meta_schema = false;
		foreach ( array_keys( (array) get_post_meta( $post->ID ) ) as $key ) {
			if ( 0 !== strpos( (string) $key, 'rank_math_schema_' ) ) {
				continue;
			}
			$has_meta_schema = true;
			$value           = get_post_meta( $post->ID, $key, true );
			$types           = is_array( $value ) && isset( $value['@type'] ) ? (array) $value['@type'] : array( substr( (string) $key, strlen( 'rank_math_schema_' ) ) );
			foreach ( $types as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					/* translators: %s: post meta key. */
					$found[] = self::finding( $type, self::SOURCE_RANKMATH, sprintf( __( 'Rank Math schema (%s)', 'seo-console' ), $key ) );
				}
			}
		}

		if ( ! $has_meta_schema ) {
			$legacy  = (string) get_post_meta( $post->ID, 'rank_math_rich_snippet', true );
			$key     = 'pt_' . $post->post_type . '_default_rich_snippet';
			$snippet = '' !== $legacy ? $legacy : ( isset( $titles[ $key ] ) ? (string) $titles[ $key ] : self::rank_math_default_snippet( $post->post_type ) );
			$type    = self::rank_math_snippet_type( $snippet, $post->post_type, $titles );
			if ( null !== $type ) {
				$found[] = self::finding( $type, self::SOURCE_RANKMATH, __( 'Rank Math default schema for this post type', 'seo-console' ) );
			}
		}

		if ( has_block( 'rank-math/faq-block', $post ) ) {
			$found[] = self::finding( 'FAQPage', self::SOURCE_RANKMATH, __( 'Rank Math FAQ block', 'seo-console' ) );
		}
		if ( has_block( 'rank-math/howto-block', $post ) ) {
			$found[] = self::finding( 'HowTo', self::SOURCE_RANKMATH, __( 'Rank Math HowTo block', 'seo-console' ) );
		}

		$site    = __( 'Rank Math site-wide schema', 'seo-console' );
		$found[] = self::finding( 'WebSite', self::SOURCE_RANKMATH, $site );
		$found[] = self::finding( 'WebPage', self::SOURCE_RANKMATH, $site );
		$kg      = isset( $titles['knowledgegraph_type'] ) ? (string) $titles['knowledgegraph_type'] : 'company';
		$found[] = self::finding( 'person' === $kg ? 'Person' : 'Organization', self::SOURCE_RANKMATH, $site );
		$modules = get_option( 'rank_math_modules', array() );
		if ( 'person' !== $kg && is_array( $modules ) && in_array( 'local-seo', $modules, true ) && ! empty( $titles['local_business_type'] ) ) {
			$found[] = self::finding( (string) $titles['local_business_type'], self::SOURCE_RANKMATH, __( 'Rank Math Local SEO', 'seo-console' ) );
		}
		$general = get_option( 'rank-math-options-general', array() );
		if ( is_array( $general ) && isset( $general['breadcrumbs'] ) && 'on' === $general['breadcrumbs'] ) {
			$found[] = self::finding( 'BreadcrumbList', self::SOURCE_RANKMATH, __( 'Rank Math breadcrumbs', 'seo-console' ) );
		}
		return $found;
	}

	/**
	 * Rank Math's default rich snippet when its option was never saved.
	 *
	 * @param string $post_type Post type.
	 */
	private static function rank_math_default_snippet( string $post_type ): string {
		if ( 'product' === $post_type || 'download' === $post_type ) {
			return 'product';
		}
		return in_array( $post_type, array( 'post', 'page' ), true ) ? 'article' : 'off';
	}

	/**
	 * Maps a Rank Math rich snippet slug to a schema type.
	 *
	 * @param string $snippet   Slug ('article', 'product', 'off', ...).
	 * @param string $post_type Post type.
	 * @param array  $titles    rank-math-options-titles.
	 * @return string|null
	 */
	private static function rank_math_snippet_type( string $snippet, string $post_type, array $titles ): ?string {
		if ( 'article' === $snippet ) {
			$key = 'pt_' . $post_type . '_default_article_type';
			if ( isset( $titles[ $key ] ) && '' !== $titles[ $key ] ) {
				return (string) $titles[ $key ];
			}
			return 'post' === $post_type ? 'BlogPosting' : 'Article';
		}
		$map = array(
			'book'       => 'Book',
			'course'     => 'Course',
			'event'      => 'Event',
			'jobposting' => 'JobPosting',
			'music'      => 'MusicGroup',
			'product'    => 'Product',
			'recipe'     => 'Recipe',
			'restaurant' => 'Restaurant',
			'video'      => 'VideoObject',
			'person'     => 'Person',
			'service'    => 'Service',
			'software'   => 'SoftwareApplication',
			'local'      => 'LocalBusiness',
		);
		return isset( $map[ $snippet ] ) ? $map[ $snippet ] : null;
	}

	/**
	 * Yoast: default graph pieces (WebPage, Article, BreadcrumbList, WebSite, Organization/Person) and blocks.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	private function from_yoast( \WP_Post $post ): array {
		$found  = array();
		$titles = get_option( 'wpseo_titles', array() );
		$titles = is_array( $titles ) ? $titles : array();
		$graph  = __( 'Yoast SEO schema graph', 'seo-console' );

		$page_type = (string) get_post_meta( $post->ID, '_yoast_wpseo_schema_page_type', true );
		if ( '' === $page_type ) {
			$key       = 'schema-page-type-' . $post->post_type;
			$page_type = isset( $titles[ $key ] ) && '' !== $titles[ $key ] ? (string) $titles[ $key ] : 'WebPage';
		}
		$found[] = self::finding( $page_type, self::SOURCE_YOAST, $graph );

		$article = (string) get_post_meta( $post->ID, '_yoast_wpseo_schema_article_type', true );
		if ( '' === $article ) {
			$key     = 'schema-article-type-' . $post->post_type;
			$article = isset( $titles[ $key ] ) && '' !== $titles[ $key ] ? (string) $titles[ $key ] : ( 'post' === $post->post_type ? 'Article' : 'None' );
		}
		if ( 'None' !== $article ) {
			$found[] = self::finding( $article, self::SOURCE_YOAST, $graph );
		}

		$found[] = self::finding( 'BreadcrumbList', self::SOURCE_YOAST, $graph );
		$found[] = self::finding( 'WebSite', self::SOURCE_YOAST, $graph );
		$who     = isset( $titles['company_or_person'] ) ? (string) $titles['company_or_person'] : 'company';
		$found[] = self::finding( 'person' === $who ? 'Person' : 'Organization', self::SOURCE_YOAST, $graph );
		if ( defined( 'WPSEO_LOCAL_VERSION' ) ) {
			$found[] = self::finding( 'LocalBusiness', self::SOURCE_YOAST, __( 'Yoast Local SEO', 'seo-console' ) );
		}
		if ( 'product' === $post->post_type && defined( 'WPSEO_WOO_VERSION' ) ) {
			$found[] = self::finding( 'Product', self::SOURCE_YOAST, __( 'Yoast WooCommerce SEO', 'seo-console' ) );
		}

		if ( has_block( 'yoast/faq-block', $post ) ) {
			$found[] = self::finding( 'FAQPage', self::SOURCE_YOAST, __( 'Yoast FAQ block', 'seo-console' ) );
		}
		if ( has_block( 'yoast/how-to-block', $post ) ) {
			$found[] = self::finding( 'HowTo', self::SOURCE_YOAST, __( 'Yoast How-to block', 'seo-console' ) );
		}
		return $found;
	}

	/**
	 * WooCommerce prints its own Product JSON-LD on product pages (Rank Math replaces it with its own).
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	private function from_woocommerce( \WP_Post $post ): array {
		if ( 'product' !== $post->post_type || ! class_exists( '\WooCommerce' ) || 'rankmath' === $this->seo_plugin ) {
			return array();
		}
		return array( self::finding( 'Product', self::SOURCE_WOOCOMMERCE, __( 'WooCommerce product structured data', 'seo-console' ) ) );
	}

	/**
	 * @param string $type   Type.
	 * @param string $source Source.
	 * @param string $detail Detail.
	 * @return array
	 */
	private static function finding( string $type, string $source, string $detail ): array {
		return array(
			'type'   => $type,
			'source' => $source,
			'detail' => $detail,
		);
	}

	/**
	 * Removes repeated type+source pairs (keeps the first detail).
	 *
	 * @param array[] $found Findings.
	 * @return array[]
	 */
	private static function unique( array $found ): array {
		$seen = array();
		$out  = array();
		foreach ( $found as $finding ) {
			$key = $finding['type'] . '|' . $finding['source'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $finding;
		}
		return $out;
	}
}
