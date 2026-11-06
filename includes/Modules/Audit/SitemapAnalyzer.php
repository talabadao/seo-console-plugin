<?php
/**
 * XML sitemap discovery, parsing, validation and crawl cross-checks.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use DOMDocument;
use DOMElement;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties.

/**
 * Sitemap checks.
 */
class SitemapAnalyzer {

	/** URLs collected from all sitemaps at most. */
	const MAX_URLS = 20000;

	/** Sitemap files read at most. */
	const MAX_FILES = 60;

	/** Protocol limits per file. */
	const LIMIT_URLS  = 50000;
	const LIMIT_BYTES = 52428800;

	/** Where WordPress SEO plugins and core publish the sitemap. */
	const DEFAULT_PATHS = array( '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' );

	/** @var Url */
	private $scope;

	/** @var HttpFetcher */
	private $fetcher;

	/** @var array Parse result of the last read() (urls + child sitemaps). */
	private $last_read = array(
		'urls'     => array(),
		'sitemaps' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param Url|null         $scope   Site scope.
	 * @param HttpFetcher|null $fetcher HTTP client.
	 */
	public function __construct( ?Url $scope = null, ?HttpFetcher $fetcher = null ) {
		$this->scope   = $scope ? $scope : new Url();
		$this->fetcher = $fetcher ? $fetcher : new HttpFetcher( $this->scope );
	}

	/**
	 * Initial processing state: the robots.txt Sitemap: URLs, or the default locations probed in order.
	 *
	 * @param string[] $robots_sitemaps Sitemap: URLs from robots.txt.
	 */
	public function initial_state( array $robots_sitemaps ): array {
		$queue = array();
		foreach ( $robots_sitemaps as $url ) {
			$queue[] = array(
				'url'  => trim( (string) $url ),
				'kind' => 'declared',
			);
		}
		if ( ! $queue ) {
			foreach ( self::DEFAULT_PATHS as $path ) {
				$queue[] = array(
					'url'  => $this->scope->root_url( $path ),
					'kind' => 'probe',
				);
			}
		}
		return array(
			'queue'     => $queue,
			'files'     => array(),
			'seen'      => array(),
			'found'     => false,
			'declared'  => (bool) $robots_sitemaps,
			'url_count' => 0,
			'capped'    => false,
			'tried'     => array(),
		);
	}

	/**
	 * Processes queued sitemap files until the queue is empty or the deadline passes.
	 *
	 * @param array    $state    State from initial_state() (by reference, JSON-serialisable).
	 * @param callable $on_urls  Receives each file's list of [ 'loc', 'lastmod' ] (already capped).
	 * @param float    $deadline microtime( true ) value to stop at.
	 * @return bool True when finished.
	 */
	public function process( array &$state, callable $on_urls, float $deadline ): bool {
		$read = 0;
		while ( $state['queue'] ) {
			if ( $read > 0 && microtime( true ) >= $deadline ) {
				return false; // At least one file per call, so tiny budgets still progress.
			}
			$item = array_shift( $state['queue'] );
			if ( 'probe' === $item['kind'] && $state['found'] ) {
				continue;
			}
			if ( isset( $state['seen'][ $item['url'] ] ) || count( $state['files'] ) >= self::MAX_FILES ) {
				continue;
			}
			$state['seen'][ $item['url'] ] = true;
			$state['tried'][]              = $item['url'];
			++$read;

			$file         = $this->read( $item['url'] );
			$result       = $this->last_read;
			$file['kind'] = $item['kind'];

			if ( 'probe' === $item['kind'] && in_array( $file['type'], array( 'error', 'invalid' ), true ) ) {
				// A missing default location is not a problem in itself.
				continue;
			}
			if ( in_array( $file['type'], array( 'index', 'urlset' ), true ) ) {
				$state['found'] = true;
			}
			$state['files'][] = $file;

			if ( 'index' === $file['type'] ) {
				foreach ( $result['sitemaps'] as $child ) {
					$state['queue'][] = array(
						'url'  => $child,
						'kind' => 'child',
					);
				}
			} elseif ( 'urlset' === $file['type'] && $result['urls'] ) {
				$room = self::MAX_URLS - $state['url_count'];
				if ( $room <= 0 ) {
					$state['capped'] = true;
					continue;
				}
				$urls = array_slice( $result['urls'], 0, $room );
				if ( count( $result['urls'] ) > $room ) {
					$state['capped'] = true;
				}
				$state['url_count'] += count( $urls );
				call_user_func( $on_urls, $urls );
			}
		}
		return true;
	}

	/**
	 * Fetches and parses one sitemap file.
	 *
	 * @param string $url Sitemap URL.
	 * @return array File summary: url, status, type (index|urlset|invalid|error|gz), count, bytes, problems.
	 */
	public function read( string $url ): array {
		$this->last_read = array(
			'urls'     => array(),
			'sitemaps' => array(),
		);
		$file            = array(
			'url'      => $url,
			'status'   => 0,
			'type'     => 'error',
			'count'    => 0,
			'bytes'    => 0,
			'problems' => array(),
		);
		$path            = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '.gz' === strtolower( substr( $path, -3 ) ) ) {
			$file['type']           = 'gz';
			$file['problems']['gz'] = array( 'count' => 1 );
			return $file;
		}
		if ( ! $this->scope->is_internal( $url ) ) {
			$file['problems']['other_host'] = array(
				'count'    => 1,
				'examples' => array( $url ),
			);
			return $file;
		}
		$response       = $this->fetcher->get( $url );
		$file['status'] = (int) $response['final_status'];
		$file['bytes']  = (int) $response['bytes'];
		if ( '' !== $response['error'] || 200 !== $file['status'] ) {
			$file['problems']['unreachable'] = array(
				'count'  => 1,
				'status' => $file['status'],
				'error'  => AuditStore::truncate( (string) $response['error'], 191 ),
			);
			return $file;
		}
		$type = isset( $response['headers']['content-type'] ) ? strtolower( (string) $response['headers']['content-type'] ) : '';
		if ( false !== strpos( $type, 'gzip' ) ) {
			$file['type']           = 'gz';
			$file['problems']['gz'] = array( 'count' => 1 );
			return $file;
		}
		$truncated = strlen( (string) $response['body'] ) < $file['bytes'];
		$parsed    = $this->parse( (string) $response['body'] );
		if ( $truncated && 'invalid' === $parsed['type'] ) {
			$parsed['problems'] = array(
				'too_big_to_read' => array(
					'count' => 1,
					'bytes' => $file['bytes'],
				),
			);
		}
		if ( $file['bytes'] > self::LIMIT_BYTES ) {
			$parsed['problems']['too_large'] = array(
				'count' => 1,
				'bytes' => $file['bytes'],
			);
		}
		$this->last_read  = array(
			'urls'     => $parsed['urls'],
			'sitemaps' => $parsed['sitemaps'],
		);
		$file['type']     = $parsed['type'];
		$file['count']    = $parsed['count'];
		$file['problems'] = $parsed['problems'];
		return $file;
	}

	/**
	 * Parses sitemap XML and validates entries.
	 *
	 * @param string $xml XML.
	 * @return array {
	 *     @type string  $type     index|urlset|invalid.
	 *     @type array[] $urls     [ 'loc' => normalised URL, 'lastmod' => string ] (urlset; valid same-host entries only).
	 *     @type string[] $sitemaps Child sitemap URLs (index).
	 *     @type int     $count    Entries in the file.
	 *     @type array   $problems code => [ 'count' => n, 'examples' => [...] ].
	 * }
	 */
	public function parse( string $xml ): array {
		$out = array(
			'type'     => 'invalid',
			'urls'     => array(),
			'sitemaps' => array(),
			'count'    => 0,
			'problems' => array(),
		);
		$xml = ltrim( $xml, "\xEF\xBB\xBF \t\r\n" );
		if ( '' === $xml ) {
			$out['problems']['empty'] = array( 'count' => 1 );
			return $out;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok || ! $doc->documentElement ) {
			$first                        = $errors ? $errors[0] : null;
			$out['problems']['malformed'] = array(
				'count' => 1,
				'error' => $first ? trim( $first->message ) . ' (line ' . (int) $first->line . ')' : '',
			);
			return $out;
		}

		$root = strtolower( (string) $doc->documentElement->localName );
		if ( 'sitemapindex' !== $root && 'urlset' !== $root ) {
			$out['problems']['wrong_root'] = array(
				'count' => 1,
				'root'  => $root,
			);
			return $out;
		}
		$out['type'] = 'sitemapindex' === $root ? 'index' : 'urlset';
		$entry_name  = 'index' === $out['type'] ? 'sitemap' : 'url';

		foreach ( $doc->documentElement->childNodes as $entry ) {
			if ( ! $entry instanceof DOMElement || strtolower( (string) $entry->localName ) !== $entry_name ) {
				continue;
			}
			++$out['count'];
			$loc     = '';
			$lastmod = '';
			foreach ( $entry->childNodes as $child ) {
				if ( ! $child instanceof DOMElement ) {
					continue;
				}
				$name = strtolower( (string) $child->localName );
				if ( 'loc' === $name ) {
					$loc = trim( $child->textContent );
				} elseif ( 'lastmod' === $name ) {
					$lastmod = trim( $child->textContent );
				}
			}
			if ( '' === $loc || ! preg_match( '#^https?://#i', $loc ) ) {
				self::problem( $out['problems'], 'loc_not_absolute', $loc );
				continue;
			}
			if ( '' !== $lastmod && ! self::valid_lastmod( $lastmod ) ) {
				self::problem( $out['problems'], 'bad_lastmod', $lastmod );
			}
			if ( ! $this->scope->is_internal( $loc ) ) {
				self::problem( $out['problems'], 'other_host', $loc );
				continue;
			}
			if ( 'index' === $out['type'] ) {
				$out['sitemaps'][] = $loc;
			} elseif ( count( $out['urls'] ) < self::MAX_URLS ) {
				$normalized = $this->scope->normalize( $loc );
				if ( '' !== $normalized ) {
					$out['urls'][] = array(
						'loc'     => $normalized,
						'lastmod' => $lastmod,
					);
				}
			}
		}
		if ( $out['count'] > self::LIMIT_URLS ) {
			$out['problems']['too_many_urls'] = array( 'count' => $out['count'] );
		}
		return $out;
	}

	/**
	 * W3C datetime (YYYY, YYYY-MM, YYYY-MM-DD, or with time and timezone).
	 *
	 * @param string $value Value.
	 */
	public static function valid_lastmod( string $value ): bool {
		return 1 === preg_match( '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:\d{2}))?)?)?$/', $value );
	}

	/**
	 * Adds a counted problem with up to 5 examples.
	 *
	 * @param array  $problems Problems (by reference).
	 * @param string $code     Code.
	 * @param string $example  Example value.
	 */
	private static function problem( array &$problems, string $code, string $example ): void {
		if ( ! isset( $problems[ $code ] ) ) {
			$problems[ $code ] = array(
				'count'    => 0,
				'examples' => array(),
			);
		}
		++$problems[ $code ]['count'];
		if ( count( $problems[ $code ]['examples'] ) < 5 ) {
			$problems[ $code ]['examples'][] = AuditStore::truncate( $example, 300 );
		}
	}

	/**
	 * Run-level checks from processed state.
	 *
	 * @param array $state Processing state.
	 * @return array[] [ [ 'rule' => id, 'data' => array ] ].
	 */
	public static function checks( array $state ): array {
		$checks = array();
		if ( empty( $state['found'] ) ) {
			$checks[] = array(
				'rule' => 'sitemap_missing',
				'data' => array( 'tried' => array_slice( (array) ( $state['tried'] ?? array() ), 0, 10 ) ),
			);
		}
		$map     = array(
			'malformed'        => 'sitemap_invalid',
			'wrong_root'       => 'sitemap_invalid',
			'empty'            => 'sitemap_invalid',
			'unreachable'      => 'sitemap_invalid',
			'too_big_to_read'  => 'sitemap_too_large',
			'too_large'        => 'sitemap_too_large',
			'too_many_urls'    => 'sitemap_too_large',
			'loc_not_absolute' => 'sitemap_bad_loc',
			'other_host'       => 'sitemap_bad_loc',
			'bad_lastmod'      => 'sitemap_bad_lastmod',
			'gz'               => 'sitemap_gz_skipped',
		);
		$grouped = array();
		foreach ( (array) ( $state['files'] ?? array() ) as $file ) {
			foreach ( (array) $file['problems'] as $code => $problem ) {
				if ( ! isset( $map[ $code ] ) ) {
					continue;
				}
				$grouped[ $map[ $code ] ][] = array_merge(
					array(
						'sitemap' => $file['url'],
						'problem' => $code,
					),
					(array) $problem
				);
			}
		}
		foreach ( $grouped as $rule => $files ) {
			$checks[] = array(
				'rule' => $rule,
				'data' => array( 'files' => array_slice( $files, 0, 20 ) ),
			);
		}
		return $checks;
	}

	/**
	 * Why a crawled sitemap URL should not be in the sitemap.
	 *
	 * @param array $page Page row (crawl_state, status_code, robots_blocked, indexability, in_sitemap).
	 * @return string '' | 'blocked' | 'error' | 'redirect' | 'noindex' | 'canonicalised'.
	 */
	public static function page_problem( array $page ): string {
		if ( empty( $page['in_sitemap'] ) ) {
			return '';
		}
		$state = (string) ( $page['crawl_state'] ?? '' );
		if ( 'blocked' === $state || ! empty( $page['robots_blocked'] ) ) {
			return 'blocked';
		}
		if ( 'crawled' !== $state && 'error' !== $state ) {
			return '';
		}
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( 'error' === $state || 0 === $status || $status >= 400 ) {
			return 'error';
		}
		if ( $status >= 300 ) {
			return 'redirect';
		}
		$indexability = (string) ( $page['indexability'] ?? '' );
		if ( 'noindex' === $indexability ) {
			return 'noindex';
		}
		if ( 'canonicalised' === $indexability ) {
			return 'canonicalised';
		}
		return '';
	}

	/**
	 * Crawl ↔ sitemap cross-check.
	 *
	 * @param array[] $pages        Page rows.
	 * @param bool    $have_sitemap A sitemap was found.
	 * @return array<string, string[]> problem => URLs; plus 'missing' (indexable pages not in the sitemap).
	 */
	public static function cross_check( array $pages, bool $have_sitemap ): array {
		$out = array(
			'error'         => array(),
			'redirect'      => array(),
			'noindex'       => array(),
			'canonicalised' => array(),
			'blocked'       => array(),
			'missing'       => array(),
		);
		foreach ( $pages as $page ) {
			$problem = self::page_problem( $page );
			if ( '' !== $problem ) {
				$out[ $problem ][] = (string) $page['url'];
			} elseif ( $have_sitemap && self::missing_from_sitemap( $page ) ) {
				$out['missing'][] = (string) $page['url'];
			}
		}
		return $out;
	}

	/**
	 * Indexable 200 HTML page found by the crawl but not listed in the sitemap.
	 *
	 * @param array $page Page row.
	 */
	public static function missing_from_sitemap( array $page ): bool {
		return empty( $page['in_sitemap'] )
			&& 'crawled' === ( $page['crawl_state'] ?? '' )
			&& 200 === (int) ( $page['status_code'] ?? 0 )
			&& ! empty( $page['is_html'] )
			&& ! empty( $page['indexable'] );
	}
}
