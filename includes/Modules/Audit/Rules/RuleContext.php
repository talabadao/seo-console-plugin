<?php
/**
 * Cross-page data rules need: other pages, links, navigation, duplicates, run-level checks, settings.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit\Rules;

use SEOConsole\Modules\Audit\AuditStore;

defined( 'ABSPATH' ) || exit;

/**
 * Rule evaluation context (built from the store, or from arrays in tests).
 */
final class RuleContext {

	/** @var array<string, array> Visited pages keyed by url_hash. */
	private $index;

	/** @var array<int, array[]> Internal outgoing links keyed by from_page_id. */
	private $links = array();

	/** @var array<string, true> url_hash => linked from navigation. */
	private $nav;

	/** @var array */
	private $settings;

	/** @var array[] Run-level checks: [ [ 'rule' => id, 'data' => array ] ]. */
	private $run_checks;

	/** @var bool */
	private $have_sitemap;

	/** @var array|null field => [ value => urls ]. */
	private $duplicates;

	/** @var string Home URL (normalised). */
	private $home;

	/** @var array<string, array> url_hash => [ 'count' => linking pages, 'pages' => example URLs ]. */
	private $slash_mismatch;

	/**
	 * Constructor.
	 *
	 * @param array[] $pages Visited pages (any keys; indexed by url_hash computed from url when missing).
	 * @param array   $args  links (page_id => links), nav (url_hash list or map), settings, run_checks, have_sitemap, home,
	 *                       slash_mismatch (url_hash => [ count, pages ]).
	 */
	public function __construct( array $pages = array(), array $args = array() ) {
		$this->index = array();
		foreach ( $pages as $page ) {
			$hash                 = isset( $page['url_hash'] ) ? (string) $page['url_hash'] : AuditStore::hash( (string) $page['url'] );
			$page['url_hash']     = $hash;
			$this->index[ $hash ] = $page;
		}
		if ( isset( $args['links'] ) ) {
			$this->set_links( (array) $args['links'] );
		}
		$nav = isset( $args['nav'] ) ? (array) $args['nav'] : array();
		if ( $nav && array_keys( $nav ) === range( 0, count( $nav ) - 1 ) ) {
			$nav = array_fill_keys( $nav, true );
		}
		$this->nav            = $nav;
		$this->settings       = isset( $args['settings'] ) ? (array) $args['settings'] : array();
		$this->run_checks     = isset( $args['run_checks'] ) ? (array) $args['run_checks'] : array();
		$this->have_sitemap   = ! empty( $args['have_sitemap'] );
		$this->home           = isset( $args['home'] ) ? (string) $args['home'] : home_url( '/' );
		$this->slash_mismatch = isset( $args['slash_mismatch'] ) ? (array) $args['slash_mismatch'] : array();
	}

	/**
	 * Pages linking to a URL with the wrong trailing slash.
	 *
	 * @param string $url_hash Target URL hash.
	 * @return array|null [ 'count' => int, 'pages' => string[] ].
	 */
	public function slash_mismatch( string $url_hash ): ?array {
		return isset( $this->slash_mismatch[ $url_hash ] ) ? (array) $this->slash_mismatch[ $url_hash ] : null;
	}

	/**
	 * Sets/merges internal links (page_id => list of [ to_url, to_url_hash?, anchor, nofollow, in_nav ]).
	 *
	 * @param array<int, array[]> $links Links.
	 */
	public function set_links( array $links ): void {
		foreach ( $links as $page_id => $list ) {
			$this->links[ (int) $page_id ] = array_map(
				static function ( $link ) {
					if ( empty( $link['to_url_hash'] ) ) {
						$link['to_url_hash'] = AuditStore::hash( (string) $link['to_url'] );
					}
					return $link;
				},
				(array) $list
			);
		}
	}

	/** Releases links of already-evaluated pages. */
	public function clear_links(): void {
		$this->links = array();
	}

	/**
	 * Setting value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function setting( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}

	/** Normalised home URL. */
	public function home(): string {
		return $this->home;
	}

	/** A sitemap was found. */
	public function have_sitemap(): bool {
		return $this->have_sitemap;
	}

	/**
	 * Visited page by URL.
	 *
	 * @param string $url Normalised URL.
	 */
	public function page( string $url ): ?array {
		return $this->page_by_hash( AuditStore::hash( $url ) );
	}

	/**
	 * Visited page by URL hash.
	 *
	 * @param string $hash URL hash.
	 */
	public function page_by_hash( string $hash ): ?array {
		return isset( $this->index[ $hash ] ) ? $this->index[ $hash ] : null;
	}

	/**
	 * Internal outgoing links of a page.
	 *
	 * @param int $page_id Page id.
	 * @return array[]
	 */
	public function links_from( int $page_id ): array {
		return isset( $this->links[ $page_id ] ) ? $this->links[ $page_id ] : array();
	}

	/**
	 * Linked from navigation?
	 *
	 * @param string $url_hash URL hash.
	 */
	public function in_nav( string $url_hash ): bool {
		return isset( $this->nav[ $url_hash ] );
	}

	/**
	 * Run-level check results for a rule.
	 *
	 * @param string $rule_id Rule id.
	 * @return array[] Data list.
	 */
	public function run_checks( string $rule_id ): array {
		$out = array();
		foreach ( $this->run_checks as $check ) {
			if ( isset( $check['rule'] ) && $rule_id === $check['rule'] ) {
				$out[] = isset( $check['data'] ) ? (array) $check['data'] : array();
			}
		}
		return $out;
	}

	/**
	 * Other indexable pages with the same title/description (case and whitespace insensitive).
	 *
	 * @param string $field   title|meta_description.
	 * @param string $value   Value of the page.
	 * @param string $own_url URL of the page (excluded).
	 * @return string[] URLs.
	 */
	public function duplicates( string $field, string $value, string $own_url ): array {
		if ( null === $this->duplicates ) {
			$this->duplicates = array(
				'title'            => array(),
				'meta_description' => array(),
			);
			foreach ( $this->index as $page ) {
				if ( empty( $page['indexable'] ) ) {
					continue;
				}
				foreach ( array_keys( $this->duplicates ) as $key ) {
					$normalized = self::normalize_text( (string) ( $page[ $key ] ?? '' ) );
					if ( '' !== $normalized ) {
						$this->duplicates[ $key ][ $normalized ][] = (string) $page['url'];
					}
				}
			}
		}
		$normalized = self::normalize_text( $value );
		if ( '' === $normalized || ! isset( $this->duplicates[ $field ][ $normalized ] ) ) {
			return array();
		}
		return array_values( array_diff( $this->duplicates[ $field ][ $normalized ], array( $own_url ) ) );
	}

	/**
	 * Lower-case, whitespace-collapsed text.
	 *
	 * @param string $text Text.
	 */
	public static function normalize_text( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		$text = null === $text ? '' : $text;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
