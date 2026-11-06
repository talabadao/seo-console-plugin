<?php
/**
 * Sitemap discovery, parsing, validation and cross-checks.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\HttpFetcher;
use SEOConsole\Modules\Audit\SitemapAnalyzer;
use SEOConsole\Modules\Audit\Url;
use WP_UnitTestCase;

require_once __DIR__ . '/MockHttp.php';

class SitemapAnalyzerTest extends WP_UnitTestCase {

	/** @var SitemapAnalyzer */
	private $sitemaps;

	public function set_up(): void {
		parent::set_up();
		$scope          = new Url( 'http://example.org/', true );
		$this->sitemaps = new SitemapAnalyzer( $scope, new HttpFetcher( $scope ) );
	}

	private function run_all( array $declared, array &$urls ): array {
		$state = $this->sitemaps->initial_state( $declared );
		$done  = $this->sitemaps->process(
			$state,
			static function ( array $batch ) use ( &$urls ) {
				$urls = array_merge( $urls, array_column( $batch, 'loc' ) );
			},
			microtime( true ) + 30
		);
		$this->assertTrue( $done );
		return $state;
	}

	private function check_rules( array $state ): array {
		return array_column( SitemapAnalyzer::checks( $state ), 'rule' );
	}

	public function test_parse_index(): void {
		$parsed = $this->sitemaps->parse( MockHttp::fixture( 'sitemaps/sitemap_index.xml' ) );
		$this->assertSame( 'index', $parsed['type'] );
		$this->assertSame( 3, $parsed['count'] );
		$this->assertSame( 'http://example.org/post-sitemap.xml', $parsed['sitemaps'][0] );
		$this->assertSame( array(), $parsed['problems'] );
	}

	public function test_index_with_children_and_gz(): void {
		$http = ( new MockHttp(
			array(
				'http://example.org/sitemap_index.xml' => MockHttp::xml( MockHttp::fixture( 'sitemaps/sitemap_index.xml' ) ),
				'http://example.org/post-sitemap.xml'  => MockHttp::xml( MockHttp::fixture( 'sitemaps/post-sitemap.xml' ) ),
				'http://example.org/page-sitemap.xml'  => MockHttp::xml( MockHttp::fixture( 'sitemaps/page-sitemap.xml' ) ),
			)
		) )->install();
		$urls  = array();
		$state = $this->run_all( array( 'http://example.org/sitemap_index.xml' ), $urls );

		$this->assertTrue( $state['found'] );
		$this->assertSame( 9, $state['url_count'] );
		$this->assertCount( 9, $urls );
		$this->assertContains( 'http://example.org/about/', $urls );
		$this->assertSame( array( 'index', 'urlset', 'urlset', 'gz' ), array_column( $state['files'], 'type' ) );
		$this->assertSame( 0, $http->count( 'http://example.org/archive-sitemap.xml.gz' ), 'Gzip sitemaps are not downloaded.' );
		$this->assertSame( array( 'sitemap_gz_skipped' ), $this->check_rules( $state ) );
	}

	public function test_invalid_xml_and_wrong_root(): void {
		( new MockHttp(
			array(
				'http://example.org/broken.xml' => MockHttp::xml( MockHttp::fixture( 'sitemaps/invalid.xml' ) ),
				'http://example.org/feed.xml'   => MockHttp::xml( MockHttp::fixture( 'sitemaps/wrong-root.xml' ) ),
			)
		) )->install();
		$urls   = array();
		$state  = $this->run_all( array( 'http://example.org/broken.xml', 'http://example.org/feed.xml', 'http://example.org/gone.xml' ), $urls );
		$checks = SitemapAnalyzer::checks( $state );

		$this->assertFalse( $state['found'] );
		$this->assertSame( array( 'sitemap_missing', 'sitemap_invalid' ), array_column( $checks, 'rule' ) );
		$problems = array_column( $checks[1]['data']['files'], 'problem' );
		$this->assertSame( array( 'malformed', 'wrong_root', 'unreachable' ), $problems );
		$this->assertNotEmpty( $checks[1]['data']['files'][0]['error'] );
		$this->assertSame( 404, $checks[1]['data']['files'][2]['status'] );
	}

	public function test_bad_entries(): void {
		$parsed = $this->sitemaps->parse( MockHttp::fixture( 'sitemaps/bad-entries.xml' ) );
		$this->assertSame( 'urlset', $parsed['type'] );
		$this->assertSame( 4, $parsed['count'] );
		$this->assertSame( array( 'http://example.org/good/', 'http://example.org/bad-date/' ), array_column( $parsed['urls'], 'loc' ) );
		$this->assertSame( 1, $parsed['problems']['loc_not_absolute']['count'] );
		$this->assertSame( array( 'https://other-domain.com/page/' ), $parsed['problems']['other_host']['examples'] );
		$this->assertSame( array( '05/01/2026' ), $parsed['problems']['bad_lastmod']['examples'] );

		$state = array(
			'found' => true,
			'files' => array(
				array(
					'url'      => 'http://example.org/s.xml',
					'problems' => $parsed['problems'],
				),
			),
		);
		$this->assertSame( array( 'sitemap_bad_loc', 'sitemap_bad_lastmod' ), $this->check_rules( $state ) );
	}

	public function test_too_many_urls(): void {
		$xml    = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . str_repeat( '<url><loc>http://example.org/p/</loc></url>', 50001 ) . '</urlset>';
		$parsed = $this->sitemaps->parse( $xml );
		$this->assertSame( 50001, $parsed['problems']['too_many_urls']['count'] );
		$this->assertCount( SitemapAnalyzer::MAX_URLS, $parsed['urls'] );
	}

	public function test_discovery_probes_default_locations(): void {
		$http  = ( new MockHttp( array( 'http://example.org/wp-sitemap.xml' => MockHttp::xml( MockHttp::fixture( 'sitemaps/page-sitemap.xml' ) ) ) ) )->install();
		$urls  = array();
		$state = $this->run_all( array(), $urls );
		$this->assertTrue( $state['found'] );
		$this->assertSame( 1, $http->count( 'http://example.org/sitemap_index.xml' ) );
		$this->assertSame( 0, $http->count( 'http://example.org/sitemap.xml' ), 'Probing stops at the first sitemap found.' );
		$this->assertSame( array(), $this->check_rules( $state ), 'Missing probe locations are not problems.' );
		$this->assertCount( 2, $urls );
	}

	public function test_non_http_declared_sitemaps_are_ignored(): void {
		$http  = ( new MockHttp() )->install();
		$state = $this->sitemaps->initial_state( array( 'javascript:alert(1)', '/relative.xml' ) );
		$this->assertSame( array( 'probe', 'probe', 'probe' ), array_column( $state['queue'], 'kind' ) );
		$this->assertSame( array( 'javascript:alert(1)', '/relative.xml' ), $state['invalid'] );
		$this->assertSame( array(), $http->requests );
	}

	public function test_nothing_found(): void {
		( new MockHttp() )->install();
		$urls  = array();
		$state = $this->run_all( array(), $urls );
		$this->assertSame( array( 'sitemap_missing' ), $this->check_rules( $state ) );
		$this->assertCount( 3, $state['tried'] );
	}

	public function test_process_stops_at_deadline_and_resumes(): void {
		( new MockHttp(
			array(
				'http://example.org/page-sitemap.xml' => MockHttp::xml( MockHttp::fixture( 'sitemaps/page-sitemap.xml' ) ),
				'http://example.org/post-sitemap.xml' => MockHttp::xml( MockHttp::fixture( 'sitemaps/post-sitemap.xml' ) ),
			)
		) )->install();
		$state = $this->sitemaps->initial_state( array( 'http://example.org/page-sitemap.xml', 'http://example.org/post-sitemap.xml' ) );
		$urls  = array();
		$noop  = static function ( array $batch ) use ( &$urls ) {
			$urls = array_merge( $urls, $batch );
		};
		// Past the deadline: still one file per call, so tiny budgets make progress.
		$this->assertFalse( $this->sitemaps->process( $state, $noop, microtime( true ) - 1 ) );
		$this->assertCount( 2, $urls );
		$state = json_decode( wp_json_encode( $state ), true ); // State survives a JSON round trip.
		$this->assertTrue( $this->sitemaps->process( $state, $noop, microtime( true ) - 1 ) );
		$this->assertCount( 9, $urls );
	}

	public function test_valid_lastmod(): void {
		foreach ( array( '2026', '2026-10', '2026-10-01', '2026-10-01T10:00Z', '2026-10-01T10:00:00+07:00', '2026-10-01T10:00:00.5Z' ) as $ok ) {
			$this->assertTrue( SitemapAnalyzer::valid_lastmod( $ok ), $ok );
		}
		foreach ( array( '01/10/2026', '2026-10-01 10:00:00', 'yesterday', '2026-10-01T10:00' ) as $bad ) {
			$this->assertFalse( SitemapAnalyzer::valid_lastmod( $bad ), $bad );
		}
	}

	public function test_cross_check(): void {
		$page  = array(
			'in_sitemap'     => 1,
			'crawl_state'    => 'crawled',
			'status_code'    => 200,
			'robots_blocked' => 0,
			'indexability'   => 'indexable',
			'is_html'        => 1,
			'indexable'      => 1,
		);
		$pages = array(
			array( 'url' => 'http://example.org/ok/' ) + $page,
			array(
				'url'         => 'http://example.org/404/',
				'status_code' => 404,
			) + $page,
			array(
				'url'         => 'http://example.org/301/',
				'status_code' => 301,
			) + $page,
			array(
				'url'          => 'http://example.org/noindex/',
				'indexability' => 'noindex',
				'indexable'    => 0,
			) + $page,
			array(
				'url'          => 'http://example.org/canon/',
				'indexability' => 'canonicalised',
				'indexable'    => 0,
			) + $page,
			array(
				'url'         => 'http://example.org/blocked/',
				'crawl_state' => 'blocked',
			) + $page,
			array(
				'url'        => 'http://example.org/not-listed/',
				'in_sitemap' => 0,
			) + $page,
			array(
				'url'         => 'http://example.org/queued/',
				'crawl_state' => 'not_crawled',
			) + $page,
		);
		$cross = SitemapAnalyzer::cross_check( $pages, true );
		$this->assertSame( array( 'http://example.org/404/' ), $cross['error'] );
		$this->assertSame( array( 'http://example.org/301/' ), $cross['redirect'] );
		$this->assertSame( array( 'http://example.org/noindex/' ), $cross['noindex'] );
		$this->assertSame( array( 'http://example.org/canon/' ), $cross['canonicalised'] );
		$this->assertSame( array( 'http://example.org/blocked/' ), $cross['blocked'] );
		$this->assertSame( array( 'http://example.org/not-listed/' ), $cross['missing'] );

		$this->assertSame( array(), SitemapAnalyzer::cross_check( $pages, false )['missing'] );
	}
}
