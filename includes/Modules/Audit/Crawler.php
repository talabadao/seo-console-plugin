<?php
/**
 * Resumable, batched crawler of the site itself.
 *
 * Lifecycle of a run: start() → step() … step() until done. Each step works within a time budget and stores all
 * progress in the audit tables, so a background job can call step() again in a later request.
 * Phases: seed (robots.txt + sitemaps) → crawl (frontier) → finalize (inbound links, rules, score) → done.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Site crawler.
 */
class Crawler {

	/** @var AuditStore */
	private $store;

	/** @var Url */
	private $scope;

	/** @var HttpFetcher */
	private $fetcher;

	/** @var HtmlAnalyzer */
	private $html;

	/** @var int Rows in the frontier (for the discovery cap) during a step. */
	private $found = 0;

	/**
	 * Constructor.
	 *
	 * @param AuditStore|null  $store   Store.
	 * @param Url|null         $scope   Site scope.
	 * @param HttpFetcher|null $fetcher HTTP client.
	 */
	public function __construct( ?AuditStore $store = null, ?Url $scope = null, ?HttpFetcher $fetcher = null ) {
		$this->store   = $store ? $store : new AuditStore();
		$this->scope   = $scope ? $scope : new Url();
		$this->fetcher = $fetcher ? $fetcher : new HttpFetcher( $this->scope );
		$this->html    = new HtmlAnalyzer();
	}

	/**
	 * Default run settings (Phase B maps these to the module settings schema).
	 */
	public static function default_settings(): array {
		return array(
			'max_pages'          => 500,
			'max_depth'          => 10,
			'respect_robots'     => true,
			'include_sitemaps'   => true,
			'delay_ms'           => 250,
			'max_per_step'       => 50,
			'timeout'            => 15,
			'thin_content_words' => 200,
			'slow_ms'            => 2000,
		);
	}

	/**
	 * Merges and clamps settings.
	 *
	 * @param array $settings Partial settings.
	 */
	public static function sanitize_settings( array $settings ): array {
		$defaults = self::default_settings();
		$out      = array();
		$ranges   = array(
			'max_pages'          => array( 1, 10000 ),
			'max_depth'          => array( 1, 50 ),
			'delay_ms'           => array( 0, 5000 ),
			'max_per_step'       => array( 1, 500 ),
			'timeout'            => array( 3, 30 ),
			'thin_content_words' => array( 0, 5000 ),
			'slow_ms'            => array( 200, 30000 ),
		);
		foreach ( $defaults as $key => $default ) {
			$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
			if ( is_bool( $default ) ) {
				$out[ $key ] = (bool) $value;
			} else {
				$out[ $key ] = max( $ranges[ $key ][0], min( $ranges[ $key ][1], (int) $value ) );
			}
		}
		return $out;
	}

	/**
	 * Creates a run, seeds the frontier with the home page and starts working within the budget.
	 *
	 * @param array $settings       Run settings (see default_settings()).
	 * @param float $seconds_budget Time budget for the first step.
	 * @return int Run id (0 on failure).
	 */
	public function start( array $settings = array(), float $seconds_budget = 20.0 ): int {
		$settings = self::sanitize_settings( $settings );
		$run_id   = $this->store->create_run( $settings );
		if ( ! $run_id ) {
			return 0;
		}
		$this->store->update_run(
			$run_id,
			array(
				'status'     => 'running',
				'started_at' => AuditStore::now(),
			)
		);
		$this->store->enqueue(
			$run_id,
			array(
				array(
					'url'       => $this->scope->home(),
					'depth'     => 0,
					'found_via' => 'home',
				),
			)
		);
		$this->step( $run_id, $seconds_budget );
		return $run_id;
	}

	/**
	 * Does as much work as fits in the budget.
	 *
	 * @param int   $run_id         Run id.
	 * @param float $seconds_budget Seconds (default 20).
	 * @return array{run_id: int, status: string, phase: string, pages_found: int, pages_crawled: int, done: bool}
	 */
	public function step( int $run_id, float $seconds_budget = 20.0 ): array {
		$deadline = microtime( true ) + max( 0.1, $seconds_budget );
		$run      = $this->store->get_run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return $this->progress( $run_id );
		}
		if ( 'queued' === $run['status'] ) {
			$this->store->update_run(
				$run_id,
				array(
					'status'     => 'running',
					'started_at' => AuditStore::now(),
				)
			);
		}
		$settings = self::sanitize_settings( (array) $run['settings'] );
		$state    = (array) $run['state'];

		if ( 'seed' === $run['phase'] ) {
			$phase = $this->seed( $run_id, $settings, $state, $deadline );
			$this->store->update_run(
				$run_id,
				array(
					'state' => $state,
					'phase' => $phase,
				)
			);
			if ( 'failed' === $phase ) {
				$this->fail_loopback( $run_id, $settings, $state );
				return $this->progress( $run_id );
			}
			$run['phase'] = $phase;
		}

		if ( 'crawl' === $run['phase'] && microtime( true ) < $deadline ) {
			$run['phase'] = $this->crawl( $run_id, $settings, $state, $deadline );
			$this->store->update_run( $run_id, array( 'phase' => $run['phase'] ) );
		}

		if ( 'finalize' === $run['phase'] && microtime( true ) < $deadline ) {
			$this->finalize( $run_id, $settings, $state );
		}
		return $this->progress( $run_id );
	}

	/**
	 * Stops a run.
	 *
	 * @param int $run_id Run id.
	 */
	public function cancel( int $run_id ): void {
		$run = $this->store->get_run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		$this->store->close_frontier( $run_id );
		$this->store->update_run(
			$run_id,
			array(
				'status'      => 'cancelled',
				'phase'       => 'done',
				'finished_at' => AuditStore::now(),
			)
		);
	}

	/**
	 * Progress snapshot.
	 *
	 * @param int $run_id Run id.
	 */
	public function progress( int $run_id ): array {
		$run    = $this->store->get_run( $run_id );
		$status = $run ? (string) $run['status'] : 'missing';
		return array(
			'run_id'        => $run_id,
			'status'        => $status,
			'phase'         => $run ? (string) $run['phase'] : '',
			'pages_found'   => $run ? (int) $run['pages_found'] : 0,
			'pages_crawled' => $run ? (int) $run['pages_crawled'] : 0,
			'done'          => ! in_array( $status, array( 'queued', 'running' ), true ),
		);
	}

	/* ----------------------------------------------------------------- Seed */

	/**
	 * robots.txt + sitemap discovery. Returns the next phase: seed|crawl|failed.
	 *
	 * @param int   $run_id   Run id.
	 * @param array $settings Settings.
	 * @param array $state    State (by reference).
	 * @param float $deadline Deadline.
	 */
	private function seed( int $run_id, array $settings, array &$state, float $deadline ): string {
		if ( ! isset( $state['robots'] ) ) {
			$robots_analyzer = new RobotsAnalyzer( $this->scope, $this->fetcher );
			$fetched         = $robots_analyzer->fetch();
			if ( 'http' !== $fetched['source'] ) {
				// robots.txt could not be requested at all: confirm with the home page.
				$home = $this->fetcher->get( $this->scope->home(), 10 );
				if ( '' !== $home['error'] ) {
					$state['loopback'] = array(
						'ok'    => false,
						'error' => AuditStore::truncate( (string) $home['error'], 191 ),
						'code'  => (string) $home['error_code'],
					);
					return 'failed';
				}
			}
			$state['loopback'] = array( 'ok' => true );
			$analysis          = $robots_analyzer->analyze( $fetched );
			$state['checks']   = $analysis['checks'];
			unset( $analysis['checks'] );
			$state['robots']  = $analysis;
			$state['sitemap'] = $settings['include_sitemaps'] ? ( new SitemapAnalyzer( $this->scope, $this->fetcher ) )->initial_state( $analysis['sitemaps'] ) : null;
		}

		if ( is_array( $state['sitemap'] ?? null ) ) {
			$sitemaps = new SitemapAnalyzer( $this->scope, $this->fetcher );
			$scope    = $this->scope;
			$store    = $this->store;
			$finished = $sitemaps->process(
				$state['sitemap'],
				static function ( array $urls ) use ( $run_id, $scope, $store ) {
					$items = array();
					foreach ( $urls as $entry ) {
						if ( '' === $scope->skip_reason( $entry['loc'] ) ) {
							$items[] = array(
								'url'        => $entry['loc'],
								'depth'      => null,
								'found_via'  => 'sitemap',
								'in_sitemap' => true,
							);
						}
					}
					$store->enqueue( $run_id, $items );
				},
				$deadline
			);
			if ( ! $finished ) {
				return 'seed';
			}
			$state['checks']          = array_merge( (array) $state['checks'], SitemapAnalyzer::checks( $state['sitemap'] ) );
			$state['sitemap']['seen'] = array();
		}
		return 'crawl';
	}

	/**
	 * Ends a run whose loopback requests failed.
	 *
	 * @param int   $run_id   Run id.
	 * @param array $settings Settings.
	 * @param array $state    State.
	 */
	private function fail_loopback( int $run_id, array $settings, array $state ): void {
		$state['checks'] = array(
			array(
				'rule' => 'loopback_failed',
				'data' => array(
					'error' => $state['loopback']['error'] ?? '',
					'code'  => $state['loopback']['code'] ?? '',
					'url'   => $this->scope->home(),
				),
			),
		);
		$this->store->close_frontier( $run_id );
		( new IssueBuilder( $this->store ) )->build( $run_id, $settings, $state['checks'], false, $this->scope->home() );
		$summary          = ( new Report( $this->store ) )->build_summary( $run_id, array( 'loopback_failed' => true ) );
		$summary['score'] = null;
		$this->store->update_run(
			$run_id,
			array(
				'status'      => 'failed',
				'phase'       => 'done',
				'error'       => 'loopback_failed',
				'finished_at' => AuditStore::now(),
				'summary'     => $summary,
				'state'       => $state,
			)
		);
	}

	/* ---------------------------------------------------------------- Crawl */

	/**
	 * Crawls queued URLs. Returns the next phase: crawl|finalize.
	 *
	 * @param int   $run_id   Run id.
	 * @param array $settings Settings.
	 * @param array $state    State.
	 * @param float $deadline Deadline.
	 */
	private function crawl( int $run_id, array $settings, array $state, float $deadline ): string {
		$robots  = ! empty( $state['robots']['exists'] ) ? RobotsTxt::parse( (string) $state['robots']['content'] ) : null;
		$delay   = (int) $settings['delay_ms'];
		$ua      = $this->fetcher->user_agent();
		$run     = $this->store->get_run( $run_id );
		$crawled = (int) $run['pages_crawled'];
		if ( $robots && $settings['respect_robots'] ) {
			$crawl_delay = $robots->crawl_delay( $ua );
			if ( null !== $crawl_delay ) {
				$delay = max( $delay, (int) min( 2000, $crawl_delay * 1000 ) );
			}
		}
		$this->found = array_sum( $this->store->count_pages_by_state( $run_id ) );

		$requests = 0;
		$avg      = 0.2;
		$phase    = 'crawl';
		while ( true ) {
			if ( $crawled >= $settings['max_pages'] ) {
				$phase = 'finalize';
				break;
			}
			$batch = $this->store->next_queued( $run_id, 1 ); // One at a time: newly found links can be shallower.
			if ( ! $batch ) {
				$phase = 'finalize';
				break;
			}
			foreach ( $batch as $row ) {
				$remaining   = $deadline - microtime( true );
				$out_of_time = $requests > 0 && $remaining < min( 2.0, max( 0.05, $avg * 1.5 ) ); // At least one page per step.
				if ( $requests >= $settings['max_per_step'] || $out_of_time || $crawled >= $settings['max_pages'] ) {
					break 2;
				}
				if ( $requests > 0 && $delay > 0 ) {
					usleep( $delay * 1000 );
				}
				$timeout = (int) max( 2, min( $settings['timeout'], floor( $remaining ) ) );
				$started = microtime( true );
				$count   = $this->process( $run_id, $row, $robots, $settings, $timeout );
				if ( $count > 0 ) {
					++$requests;
					$avg = 0.7 * $avg + 0.3 * ( microtime( true ) - $started );
				}
				$crawled += $count;
			}
		}

		$this->store->update_run(
			$run_id,
			array(
				'pages_crawled' => $crawled,
				'pages_found'   => array_sum( $this->store->count_pages_by_state( $run_id ) ),
			)
		);
		return $phase;
	}

	/**
	 * Crawls one queued URL.
	 *
	 * @param int            $run_id   Run id.
	 * @param array          $row      Queued page row.
	 * @param RobotsTxt|null $robots   Parsed robots.txt.
	 * @param array          $settings Settings.
	 * @param int            $timeout  Request timeout.
	 * @return int Pages crawled (0 when skipped without a request).
	 */
	private function process( int $run_id, array $row, ?RobotsTxt $robots, array $settings, int $timeout ): int {
		$page_id = (int) $row['id'];
		$url     = (string) $row['url'];
		$depth   = null === $row['depth'] ? null : (int) $row['depth'];

		if ( $robots && $settings['respect_robots'] && ! $robots->is_allowed( $url, $this->fetcher->user_agent() ) ) {
			$this->store->update_page(
				$page_id,
				array(
					'crawl_state'    => 'blocked',
					'robots_blocked' => $robots->is_allowed( $url, 'Googlebot' ) ? 0 : 1,
					'indexable'      => 0,
					'indexability'   => 'blocked',
					'extra'          => array( 'robots_rule' => $robots->matching_rule( $url, $this->fetcher->user_agent() ) ),
					'crawled_at'     => AuditStore::now(),
				)
			);
			return 0;
		}

		$response = $this->fetcher->get( $url, $timeout );
		if ( '' !== $response['error'] ) {
			$this->store->update_page(
				$page_id,
				array(
					'crawl_state'  => 'error',
					'status_code'  => 0,
					'error'        => AuditStore::truncate( (string) $response['error'], 191 ),
					'response_ms'  => $response['ms'],
					'indexable'    => 0,
					'indexability' => 'error',
					'crawled_at'   => AuditStore::now(),
				)
			);
			return 1;
		}

		if ( ! $response['chain'] ) {
			$this->record_response( $run_id, $page_id, $url, $depth, $response, $robots, $settings );
			return 1;
		}

		// Redirect: record the hop list on the requested URL…
		$loop     = (bool) $response['loop'];
		$too_many = (bool) $response['too_many'];
		$final    = (string) $response['final_url'];
		$this->store->update_page(
			$page_id,
			array(
				'crawl_state'    => 'crawled',
				'status_code'    => $response['status'],
				'final_status'   => $response['final_status'],
				'redirect_chain' => $response['chain'],
				'final_url'      => $final,
				'response_ms'    => $response['ms'],
				'indexable'      => 0,
				'indexability'   => 'redirect',
				'robots_blocked' => $robots && ! $robots->is_allowed( $url, 'Googlebot' ) ? 1 : 0,
				'extra'          => array(
					'redirect_loop'      => $loop,
					'too_many_redirects' => $too_many,
					'host_variant'       => ! $this->scope->is_canonical_host( $url ),
				),
				'crawled_at'     => AuditStore::now(),
			)
		);
		if ( $loop || $too_many || ! $this->scope->is_internal( $final ) || '' !== $this->scope->skip_reason( $final ) ) {
			return 1;
		}
		// …and the final response on the target URL, unless it was crawled already (saves a request).
		$target_id = $this->store->ensure_page( $run_id, $final, null === $depth ? -1 : $depth, 'redirect' );
		$target    = $target_id ? $this->store->get_page( $target_id ) : null;
		if ( ! $target || 'queued' !== $target['crawl_state'] ) {
			return 1;
		}
		$final_response           = $response;
		$final_response['status'] = $response['final_status'];
		$final_response['chain']  = array();
		$final_response['ms']     = (int) max( 0, $response['total_ms'] - $response['ms'] );
		$this->record_response( $run_id, $target_id, $final, $target['depth'], $final_response, $robots, $settings );
		return 2;
	}

	/**
	 * Stores a non-redirect response: status, HTML facts, links; queues new internal URLs.
	 *
	 * @param int            $run_id   Run id.
	 * @param int            $page_id  Page id.
	 * @param string         $url      Page URL.
	 * @param int|null       $depth    Click depth.
	 * @param array          $response Fetcher result.
	 * @param RobotsTxt|null $robots   Parsed robots.txt.
	 * @param array          $settings Settings.
	 */
	private function record_response( int $run_id, int $page_id, string $url, ?int $depth, array $response, ?RobotsTxt $robots, array $settings ): void {
		$status       = (int) $response['status'];
		$headers      = (array) $response['headers'];
		$content_type = isset( $headers['content-type'] ) ? strtolower( (string) $headers['content-type'] ) : '';
		$is_html      = false !== strpos( $content_type, 'text/html' ) || false !== strpos( $content_type, 'application/xhtml' );
		$host_variant = ! $this->scope->is_canonical_host( $url );
		$g_blocked    = $robots && ! $robots->is_allowed( $url, 'Googlebot' );

		$data = array(
			'crawl_state'    => 'crawled',
			'status_code'    => $status,
			'final_status'   => $status,
			'content_type'   => AuditStore::truncate( $content_type, 100 ),
			'is_html'        => $is_html ? 1 : 0,
			'response_ms'    => (int) $response['ms'],
			'bytes'          => (int) $response['bytes'],
			'robots_blocked' => $g_blocked ? 1 : 0,
			'x_robots'       => AuditStore::truncate( (string) ( $headers['x-robots-tag'] ?? '' ), 191 ),
			'indexable'      => 0,
			'crawled_at'     => AuditStore::now(),
			'extra'          => array( 'host_variant' => $host_variant ),
		);
		if ( $g_blocked ) {
			$data['extra']['robots_rule'] = $robots->matching_rule( $url, 'Googlebot' );
		}

		if ( $status >= 500 ) {
			$data['indexability'] = 'server_error';
		} elseif ( $status >= 400 ) {
			$data['indexability'] = 'client_error';
		} elseif ( 200 !== $status ) {
			$data['indexability'] = 'not_ok';
		} elseif ( ! $is_html ) {
			$data['indexability'] = 'not_html';
		}

		if ( 200 === $status && $is_html ) {
			$facts     = $this->html->analyze( (string) $response['body'], $url, $headers );
			$canonical = '' === $facts['canonical'] ? '' : $this->scope->normalize( $facts['canonical'] );
			$links     = $this->collect_links( $facts['links'] );

			if ( $facts['noindex'] ) {
				$indexability = 'noindex';
			} elseif ( $g_blocked ) {
				$indexability = 'blocked';
			} elseif ( $host_variant ) {
				$indexability = 'host_variant';
			} elseif ( '' !== $canonical && $canonical !== $url ) {
				$indexability = 'canonicalised';
			} else {
				$indexability = 'indexable';
			}
			$internal = count(
				array_filter(
					$links,
					static function ( $link ) {
						return $link['internal'];
					}
				)
			);
			$hreflang = array();
			foreach ( $facts['hreflang'] as $alt ) {
				$hreflang[] = array(
					'lang' => AuditStore::truncate( $alt['lang'], 40 ),
					'url'  => '' === $alt['url'] ? '' : $this->scope->normalize( $alt['url'] ),
				);
			}

			$data          = array_merge(
				$data,
				array(
					'indexable'          => 'indexable' === $indexability ? 1 : 0,
					'indexability'       => $indexability,
					'title'              => $facts['title'],
					'meta_description'   => $facts['meta_description'],
					'canonical'          => $canonical,
					'robots_meta'        => AuditStore::truncate( $facts['robots_meta'], 191 ),
					'h1_count'           => $facts['h1_count'],
					'h1'                 => $facts['h1'],
					'headings'           => $facts['headings'],
					'word_count'         => $facts['word_count'],
					'images_total'       => $facts['images_total'],
					'images_missing_alt' => $facts['images_missing_alt'],
					'internal_links_out' => $internal,
					'external_links_out' => count( $links ) - $internal,
					'hreflang'           => $hreflang,
					'og'                 => $facts['og'],
					'schema_types'       => $facts['schema_types'],
					'post_id'            => $host_variant ? 0 : (int) url_to_postid( $url ),
				)
			);
			$data['extra'] = array_merge(
				$data['extra'],
				array(
					'viewport'             => $facts['viewport'],
					'lang'                 => $facts['lang'],
					'charset'              => $facts['charset'],
					'mixed_content'        => $facts['mixed_content'],
					'images_missing_count' => $facts['images_missing_count'],
					'images_empty_alt'     => $facts['images_empty_alt'],
					'images_no_dimensions' => $facts['images_no_dimensions'],
					'images_no_dims_count' => $facts['images_no_dims_count'],
					'images_lazy'          => $facts['images_lazy'],
					'schema_errors'        => $facts['schema_errors'],
					'title_count'          => $facts['title_count'],
					'description_count'    => $facts['description_count'],
					'canonical_count'      => $facts['canonical_count'],
					'canonical_relative'   => $facts['canonical_relative'],
					'canonical_raw'        => AuditStore::truncate( $facts['canonical_raw'], 500 ),
					'noindex'              => $facts['noindex'],
					'nofollow'             => $facts['nofollow'],
				)
			);

			$this->store->replace_links( $run_id, $page_id, $links );
			if ( ! $host_variant && ! $facts['nofollow'] ) {
				$this->queue_discovered( $run_id, $depth, $links, $canonical, $hreflang, $url, $settings );
			}
		}

		$this->store->update_page( $page_id, $data );
	}

	/**
	 * Normalises and de-duplicates the links of a page.
	 *
	 * @param array[] $raw Links from HtmlAnalyzer.
	 * @return array[] [ url, anchor, nofollow, internal, in_nav ].
	 */
	private function collect_links( array $raw ): array {
		$links = array();
		foreach ( $raw as $link ) {
			$url = $this->scope->normalize( (string) $link['url'] );
			if ( '' === $url ) {
				continue;
			}
			if ( isset( $links[ $url ] ) ) {
				$links[ $url ]['in_nav'] = $links[ $url ]['in_nav'] || $link['in_nav'];
				continue;
			}
			$links[ $url ] = array(
				'url'      => $url,
				'anchor'   => (string) $link['anchor'],
				'nofollow' => (bool) $link['nofollow'],
				'internal' => $this->scope->is_internal( $url ),
				'in_nav'   => (bool) $link['in_nav'],
			);
			if ( count( $links ) >= 1000 ) {
				break;
			}
		}
		return array_values( $links );
	}

	/**
	 * Queues internal links (depth + 1), the canonical and hreflang alternates.
	 *
	 * @param int      $run_id    Run id.
	 * @param int|null $depth     Depth of the page.
	 * @param array[]  $links     Links.
	 * @param string   $canonical Canonical URL.
	 * @param array[]  $hreflang  Alternates.
	 * @param string   $url       Page URL.
	 * @param array    $settings  Settings.
	 */
	private function queue_discovered( int $run_id, ?int $depth, array $links, string $canonical, array $hreflang, string $url, array $settings ): void {
		$items      = array();
		$next_depth = null === $depth ? null : $depth + 1;
		if ( null === $next_depth || $next_depth <= $settings['max_depth'] ) {
			foreach ( $links as $link ) {
				if ( $link['internal'] && '' === $this->scope->skip_reason( $link['url'] ) ) {
					$items[] = array(
						'url'       => $link['url'],
						'depth'     => $next_depth,
						'found_via' => 'link',
					);
				}
			}
		}
		$others = array( $canonical => 'canonical' );
		foreach ( $hreflang as $alt ) {
			$others[ $alt['url'] ] = 'hreflang';
		}
		foreach ( $others as $other => $via ) {
			if ( '' !== $other && $other !== $url && $this->scope->is_internal( $other ) && '' === $this->scope->skip_reason( $other ) ) {
				$items[] = array(
					'url'       => (string) $other,
					'depth'     => null,
					'found_via' => $via,
				);
			}
		}
		$cap          = (int) $settings['max_pages'] * 5 + SitemapAnalyzer::MAX_URLS;
		$this->found += $this->store->enqueue( $run_id, $items, max( 1, $cap - $this->found ) );
	}

	/* ------------------------------------------------------------- Finalize */

	/**
	 * Inbound links, rules, score, summary; marks the run done and fires seoc_audit_completed.
	 *
	 * @param int   $run_id   Run id.
	 * @param array $settings Settings.
	 * @param array $state    State.
	 */
	private function finalize( int $run_id, array $settings, array $state ): void {
		$this->store->close_frontier( $run_id );
		$this->store->compute_inbound( $run_id );
		$have_sitemap = ! empty( $state['sitemap']['found'] );
		( new IssueBuilder( $this->store ) )->build( $run_id, $settings, (array) ( $state['checks'] ?? array() ), $have_sitemap, $this->scope->home() );

		$pages = array();
		$after = 0;
		do {
			$chunk = $this->store->pages( $run_id, $after, 500, array( 'crawled', 'blocked', 'error' ) );
			$more  = 500 === count( $chunk );
			foreach ( $chunk as $page ) {
				$pages[] = array_intersect_key( $page, array_flip( array( 'url', 'in_sitemap', 'crawl_state', 'status_code', 'robots_blocked', 'indexability', 'is_html', 'indexable' ) ) );
				$after   = (int) $page['id'];
			}
		} while ( $more );
		$cross = SitemapAnalyzer::cross_check( $pages, $have_sitemap );

		$robots  = (array) ( $state['robots'] ?? array() );
		$sitemap = (array) ( $state['sitemap'] ?? array() );
		$summary = ( new Report( $this->store ) )->build_summary(
			$run_id,
			array(
				'robots'      => array(
					'manager'  => $robots['manager'] ?? '',
					'source'   => $robots['source'] ?? '',
					'status'   => $robots['status'] ?? 0,
					'exists'   => ! empty( $robots['exists'] ),
					'sitemaps' => $robots['sitemaps'] ?? array(),
				),
				'sitemap'     => array(
					'found'  => $have_sitemap,
					'urls'   => (int) ( $sitemap['url_count'] ?? 0 ),
					'capped' => ! empty( $sitemap['capped'] ),
					'files'  => array_map(
						static function ( $file ) {
							return array_intersect_key( (array) $file, array_flip( array( 'url', 'type', 'status', 'count' ) ) );
						},
						array_slice( (array) ( $sitemap['files'] ?? array() ), 0, 60 )
					),
				),
				'cross_check' => array_map( 'count', $cross ),
				'settings'    => $settings,
			)
		);

		$this->store->update_run(
			$run_id,
			array(
				'status'        => 'done',
				'phase'         => 'done',
				'finished_at'   => AuditStore::now(),
				'score'         => $summary['score'],
				'summary'       => $summary,
				'pages_found'   => $summary['pages_found'],
				'pages_crawled' => $summary['pages_crawled'],
			)
		);

		/**
		 * Fires when an audit run has finished (ARCHITECTURE §11).
		 *
		 * @param int   $run_id  Run id.
		 * @param array $summary Summary (score, counts per severity/category, top issues).
		 */
		do_action( 'seoc_audit_completed', $run_id, $summary );
	}
}
