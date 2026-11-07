<?php
/**
 * Chooses representative URLs to test and validates user-provided ones.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Picked URL: [ 'url', 'type' => home|post|page|category|product|custom, 'object_id' => int|null, 'label' => string ].
 */
class UrlPicker {

	const MAX_AUTO = 5;

	const MAX_USER = 10;

	/**
	 * Active SEO plugin: 'rankmath', 'yoast' or 'none'.
	 *
	 * @var string
	 */
	private $seo_plugin;

	/**
	 * Constructor.
	 *
	 * @param string $seo_plugin 'auto' (detect), 'rankmath', 'yoast' or 'none' — Phase B passes core SeoPlugins::active().
	 */
	public function __construct( string $seo_plugin = 'auto' ) {
		if ( 'auto' === $seo_plugin ) {
			if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
				$seo_plugin = 'rankmath';
			} elseif ( defined( 'WPSEO_VERSION' ) ) {
				$seo_plugin = 'yoast';
			} else {
				$seo_plugin = 'none';
			}
		}
		$this->seo_plugin = in_array( $seo_plugin, array( 'rankmath', 'yoast' ), true ) ? $seo_plugin : 'none';
	}

	/**
	 * Picks up to $max representative, indexable URLs: home, latest post, a page, a category, a product.
	 *
	 * @param int $max Max URLs (1–5).
	 * @return array[]
	 */
	public function pick( int $max = self::MAX_AUTO ): array {
		$max  = max( 1, min( self::MAX_AUTO, $max ) );
		$urls = array(
			array(
				'url'       => home_url( '/' ),
				'type'      => 'home',
				'object_id' => 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ? (int) get_option( 'page_on_front' ) : null,
				'label'     => __( 'Home page', 'seo-console' ),
			),
		);

		$post = $this->first_indexable_post( 'post' );
		if ( $post ) {
			$urls[] = $this->post_entry( $post, 'post' );
		}
		$page = $this->first_indexable_post( 'page', $this->special_page_ids() );
		if ( $page ) {
			$urls[] = $this->post_entry( $page, 'page' );
		}
		$term = $this->first_indexable_category();
		if ( $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$urls[] = array(
					'url'       => $link,
					'type'      => 'category',
					'object_id' => (int) $term->term_id,
					'label'     => $term->name,
				);
			}
		}
		if ( $this->woocommerce_active() && post_type_exists( 'product' ) ) {
			$product = $this->first_indexable_post( 'product' );
			if ( $product ) {
				$urls[] = $this->post_entry( $product, 'product' );
			}
		}

		$seen = array();
		$out  = array();
		foreach ( $urls as $entry ) {
			if ( isset( $seen[ $entry['url'] ] ) ) {
				continue;
			}
			$seen[ $entry['url'] ] = true;
			$out[]                 = $entry;
		}
		return array_slice( $out, 0, $max );
	}

	/**
	 * Validates a user-provided list: same host as the site (www and non-www both accepted), http(s),
	 * max 10, duplicates removed, fragments dropped. Paths like "/about/" are resolved against the home URL.
	 *
	 * @param array $urls Input.
	 * @return array [ 'valid' => string[], 'errors' => [ [ 'url' => string, 'message' => string ] ] ]
	 */
	public function validate( array $urls ): array {
		$home   = PsiParser::host( home_url( '/' ) );
		$valid  = array();
		$errors = array();
		foreach ( $urls as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$url = trim( wp_strip_all_tags( $raw ) );
			if ( '' === $url ) {
				continue;
			}
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$url = home_url( $url );
			}
			$url = (string) preg_replace( '/#.*$/', '', $url );
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $url || ! PsiClient::is_testable_url( $url ) ) {
				$errors[] = array(
					'url'     => substr( $raw, 0, 200 ),
					'message' => __( 'Not a valid address. Use a full address like https://example.com/page/ or a path like /page/.', 'seo-console' ),
				);
				continue;
			}
			if ( ! self::same_host( PsiParser::host( $url ), $home ) ) {
				$errors[] = array(
					'url'     => substr( $raw, 0, 200 ),
					'message' => __( 'Only pages of this website can be tested.', 'seo-console' ),
				);
				continue;
			}
			if ( in_array( $url, $valid, true ) ) {
				continue;
			}
			if ( count( $valid ) >= self::MAX_USER ) {
				$errors[] = array(
					'url'     => substr( $raw, 0, 200 ),
					/* translators: %d: maximum number of URLs */
					'message' => sprintf( __( 'At most %d addresses can be tested; this one was left out.', 'seo-console' ), self::MAX_USER ),
				);
				continue;
			}
			$valid[] = $url;
		}
		return array(
			'valid'  => $valid,
			'errors' => $errors,
		);
	}

	/**
	 * Whether the SEO plugin marks a post noindex (per-post setting, else the post type default).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function is_noindex_post( WP_Post $post ): bool {
		if ( 'yoast' === $this->seo_plugin ) {
			$flag = (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $flag ) {
				return true;
			}
			if ( '2' === $flag ) {
				return false;
			}
			$titles = get_option( 'wpseo_titles', array() );
			return is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post->post_type ] );
		}
		if ( 'rankmath' === $this->seo_plugin ) {
			$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
			if ( is_array( $robots ) && $robots ) {
				return in_array( 'noindex', $robots, true );
			}
			return $this->rankmath_default_noindex( 'pt_' . $post->post_type );
		}
		return false;
	}

	/**
	 * Whether the SEO plugin marks a term archive noindex.
	 *
	 * @param WP_Term $term Term.
	 * @return bool
	 */
	public function is_noindex_term( WP_Term $term ): bool {
		if ( 'yoast' === $this->seo_plugin ) {
			$meta = get_option( 'wpseo_taxonomy_meta', array() );
			$flag = isset( $meta[ $term->taxonomy ][ $term->term_id ]['wpseo_noindex'] ) ? (string) $meta[ $term->taxonomy ][ $term->term_id ]['wpseo_noindex'] : 'default';
			if ( 'noindex' === $flag ) {
				return true;
			}
			if ( 'index' === $flag ) {
				return false;
			}
			$titles = get_option( 'wpseo_titles', array() );
			return is_array( $titles ) && ! empty( $titles[ 'noindex-tax-' . $term->taxonomy ] );
		}
		if ( 'rankmath' === $this->seo_plugin ) {
			$robots = get_term_meta( $term->term_id, 'rank_math_robots', true );
			if ( is_array( $robots ) && $robots ) {
				return in_array( 'noindex', $robots, true );
			}
			return $this->rankmath_default_noindex( 'tax_' . $term->taxonomy );
		}
		return false;
	}

	/**
	 * Rank Math default robots for a post type (`pt_<type>`) or taxonomy (`tax_<tax>`), falling back to the global setting.
	 *
	 * @param string $prefix Settings prefix.
	 * @return bool
	 */
	private function rankmath_default_noindex( string $prefix ): bool {
		$titles = get_option( 'rank-math-options-titles', array() );
		if ( ! is_array( $titles ) ) {
			return false;
		}
		if ( isset( $titles[ $prefix . '_custom_robots' ] ) && 'on' === $titles[ $prefix . '_custom_robots' ] ) {
			return isset( $titles[ $prefix . '_robots' ] ) && is_array( $titles[ $prefix . '_robots' ] ) && in_array( 'noindex', $titles[ $prefix . '_robots' ], true );
		}
		return isset( $titles['robots_global'] ) && is_array( $titles['robots_global'] ) && in_array( 'noindex', $titles['robots_global'], true );
	}

	/**
	 * Most recent published, public, non-password, indexable post of a type.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $exclude   Ids to skip.
	 * @return WP_Post|null
	 */
	private function first_indexable_post( string $post_type, array $exclude = array() ) {
		// Excluded ids are skipped in PHP rather than in the query; get_posts() already suppresses filters by default.
		$exclude = array_flip( array_filter( array_map( 'intval', $exclude ) ) );
		$posts   = get_posts(
			array(
				'post_type'     => $post_type,
				'post_status'   => 'publish',
				'has_password'  => false,
				'numberposts'   => 20 + count( $exclude ),
				'orderby'       => 'date',
				'order'         => 'DESC',
				'no_found_rows' => true,
			)
		);
		$checked = 0;
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || isset( $exclude[ $post->ID ] ) ) {
				continue;
			}
			if ( ++$checked > 20 ) {
				break;
			}
			if ( ! $this->is_noindex_post( $post ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Category with the most posts that is indexable (the default category last).
	 *
	 * @return WP_Term|null
	 */
	private function first_indexable_category() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
			)
		);
		if ( ! is_array( $terms ) ) {
			return null;
		}
		$default = (int) get_option( 'default_category' );
		usort(
			$terms,
			static function ( $a, $b ) use ( $default ) {
				$da = (int) $a->term_id === $default ? 1 : 0;
				$db = (int) $b->term_id === $default ? 1 : 0;
				if ( $da !== $db ) {
					return $da <=> $db;
				}
				return (int) $b->count <=> (int) $a->count;
			}
		);
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && ! $this->is_noindex_term( $term ) ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Front page, posts page and WooCommerce utility pages (never representative).
	 *
	 * @return int[]
	 */
	private function special_page_ids(): array {
		$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );
		foreach ( array( 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
			$ids[] = (int) get_option( 'woocommerce_' . $page . '_page_id' );
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Entry for a post.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $type Type label.
	 * @return array
	 */
	private function post_entry( WP_Post $post, string $type ): array {
		return array(
			'url'       => (string) get_permalink( $post ),
			'type'      => $type,
			'object_id' => (int) $post->ID,
			'label'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
		);
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function woocommerce_active(): bool {
		/**
		 * Filters whether the URL picker treats WooCommerce as active.
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'seoc_speed_woocommerce_active', class_exists( 'WooCommerce' ) );
	}

	/**
	 * Host equality ignoring a leading "www.".
	 *
	 * @param string $a Host.
	 * @param string $b Host.
	 * @return bool
	 */
	private static function same_host( string $a, string $b ): bool {
		return '' !== $a && preg_replace( '/^www\./', '', $a ) === preg_replace( '/^www\./', '', $b );
	}
}
