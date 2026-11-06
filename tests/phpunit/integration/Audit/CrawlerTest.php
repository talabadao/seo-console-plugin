<?php
/**
 * Crawler on a mocked mini-site.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\AuditStore;

require_once __DIR__ . '/AuditTestCase.php';

class CrawlerTest extends AuditTestCase {

	const FAST = array( 'delay_ms' => 0 );

	/** @var int[] */
	private $posts = array();

	public function set_up() {
		parent::set_up();
		$this->posts = array(
			'post' => self::factory()->post->create( array( 'post_name' => 'seoc-post' ) ),
			'thin' => self::factory()->post->create( array( 'post_name' => 'seoc-thin' ) ),
		);
	}

	public function test_full_crawl_of_mini_site(): void {
		$http      = $this->mini_site();
		$completed = array();
		add_action(
			'seoc_audit_completed',
			static function ( $run_id, $summary ) use ( &$completed ) {
				$completed = array( $run_id, $summary );
			},
			10,
			2
		);
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST );
		$this->finish( $crawler, $run_id );

		$run = $this->store->get_run( $run_id );
		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 'done', $run['phase'] );
		$this->assertNotNull( $run['finished_at'] );
		$this->assertSame( $run_id, $completed[0] );

		// Pages.
		$states = $this->store->count_pages_by_state( $run_id );
		$this->assertSame( 14, $states['crawled'] ); // home about contact blog noindex-page seoc-post seoc-thin old-page new-page missing loop-a orphan deep api-thing.
		$this->assertSame( 2, $states['blocked'] ); // /private/secret/ (link) + /private/listed/ (sitemap).
		$this->assertSame( 14, $run['pages_crawled'] );
		$this->assertSame( 16, $run['pages_found'] );

		$home = $this->page( $run_id, '/' );
		$this->assertSame( 0, $home['depth'] );
		$this->assertSame( 200, $home['status_code'] );
		$this->assertSame( 'Mini Site Home – Gardens, Coffee and Travel', $home['title'] );
		$this->assertSame( 'indexable', $home['indexability'] );
		$this->assertSame( 0, $home['in_sitemap'] );

		$old = $this->page( $run_id, '/old-page/' );
		$this->assertSame( 301, $old['status_code'] );
		$this->assertSame( 200, $old['final_status'] );
		$this->assertSame( 'http://example.org/new-page/', $old['final_url'] );
		$this->assertSame( array( 301, 302 ), array_column( $old['redirect_chain'], 'status' ) );
		$this->assertSame( 'redirect', $old['indexability'] );

		$new = $this->page( $run_id, '/new-page/' );
		$this->assertSame( 'crawled', $new['crawl_state'] );
		$this->assertSame( 'redirect', $new['found_via'] );
		$this->assertSame( 1, $http->count( 'http://example.org/new-page/' ), 'The redirect target response is reused, not fetched again.' );

		$loop = $this->page( $run_id, '/loop-a/' );
		$this->assertTrue( $loop['extra']['redirect_loop'] );

		$this->assertSame( 404, $this->page( $run_id, '/missing/' )['status_code'] );
		$this->assertSame( 'noindex', $this->page( $run_id, '/noindex-page/' )['indexability'] );
		$this->assertSame( 'not_html', $this->page( $run_id, '/api-thing/' )['indexability'] );
		$this->assertSame( 2, $this->page( $run_id, '/deep/' )['depth'] );
		$this->assertNull( $this->page( $run_id, '/orphan/' )['depth'] );
		$this->assertSame( 0, $this->page( $run_id, '/orphan/' )['inbound_internal'] );
		$this->assertSame( 9, $this->page( $run_id, '/about/' )['inbound_internal'] ); // Every crawled HTML page but itself and the 404/redirects.
		$this->assertSame( 1, $this->page( $run_id, '/seoc-post/' )['in_sitemap'] );
		$this->assertSame( $this->posts['post'], $this->page( $run_id, '/seoc-post/' )['post_id'] );
		$this->assertSame( array( 'http://example.org/wp-content/uploads/photo.jpg' ), $this->page( $run_id, '/seoc-post/' )['images_missing_alt'] );

		$blocked = $this->page( $run_id, '/private/secret/' );
		$this->assertSame( 'blocked', $blocked['crawl_state'] );
		$this->assertSame( 1, $blocked['robots_blocked'] );

		// Never requested: robots-blocked, admin, assets, external, replytocom.
		foreach ( array( 'http://example.org/private/secret/', 'http://example.org/wp-admin/', 'http://example.org/wp-content/uploads/a.jpg', 'https://external.example.com/', 'http://example.org/?replytocom=5', 'http://example.org/archive-sitemap.xml.gz' ) as $url ) {
			$this->assertSame( 0, $http->count( $url ), $url );
		}
		$this->assertNull( $this->page( $run_id, '/wp-admin/' ) );
		// Every URL fetched once.
		$this->assertSame( 1, max( array_count_values( $http->requests ) ) );
		// Request arguments.
		$this->assertSame( 0, $http->args[0]['redirection'] );
		$this->assertStringStartsWith( 'SEOConsole-Audit/1.0 (+http://example.org/', $http->args[0]['user-agent'] );

		// Links of the home page: deduplicated, external kept, skipped schemes ignored.
		$links = $this->store->internal_links_from( $run_id, array( $home['id'] ) )[ $home['id'] ];
		$to    = array_column( $links, 'to_url' );
		$this->assertContains( 'http://example.org/missing/', $to );
		$this->assertContains( 'http://example.org/about/', $to );
		$this->assertSame( 1, count( array_keys( $to, 'http://example.org/seoc-post/', true ) ) );
		$this->assertSame( 14, $home['internal_links_out'] ); // 5 nav + 9 in content (/about and /about/ are one).
		$this->assertSame( 1, $home['external_links_out'] );
		$linking = $this->store->linking_pages( $run_id, 'http://example.org/missing/' );
		$this->assertEqualsCanonicalizing( array( 'http://example.org/', 'http://example.org/about/' ), array_column( $linking, 'url' ) );

		// Issues.
		$issues = $this->issues_by_rule( $run_id );
		$expect = array(
			'http_4xx'              => array( '/missing/' ),
			'sitemap_error_page'    => array( '/missing/' ),
			'redirect_chain'        => array( '/old-page/' ),
			'sitemap_redirect'      => array( '/old-page/' ),
			'redirect_loop'         => array( '/loop-a/' ),
			'broken_internal_links' => array( '/', '/about/' ),
			'links_to_redirect'     => array( '/' ),
			'noindex_in_sitemap'    => array( '/noindex-page/' ),
			'noindex_in_nav'        => array( '/noindex-page/' ),
			'orphan_page'           => array( '/orphan/' ),
			'title_duplicate'       => array( '/about/', '/contact/' ),
			'meta_desc_missing'     => array( '/contact/' ),
			'thin_content'          => array( '/seoc-thin/' ),
			'img_missing_alt'       => array( '/seoc-post/' ),
			'sitemap_blocked'       => array( '/private/listed/' ),
			'blocked_by_robots'     => array( '/private/secret/' ),
			'missing_from_sitemap'  => array( '/', '/blog/', '/deep/', '/new-page/' ),
		);
		foreach ( $expect as $rule => $paths ) {
			$this->assertArrayHasKey( $rule, $issues, $rule );
			$this->assertEqualsCanonicalizing(
				array_map(
					static function ( $path ) {
						return 'http://example.org' . $path;
					},
					$paths
				),
				$issues[ $rule ],
				$rule
			);
		}
		$this->assertSame( array( '' ), $issues['sitemap_gz_skipped'] );
		foreach ( array( 'loopback_failed', 'robots_missing', 'robots_disallow_all', 'sitemap_missing', 'title_missing', 'canonical_missing', 'http_5xx', 'viewport_missing' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $issues, $absent );
		}
		// The broken-link issue on the home page lists the link.
		$broken = $this->store->issues(
			$run_id,
			array(
				'rule_id' => 'links_to_redirect',
			)
		)['items'][0]['data'];
		$this->assertEqualsCanonicalizing( array( 'http://example.org/old-page/', 'http://example.org/loop-a/' ), array_column( $broken['links'], 'url' ) );

		// Summary.
		$summary = $run['summary'];
		$this->assertSame( $run['score'], $summary['score'] );
		$this->assertGreaterThan( 0, $summary['score'] );
		$this->assertLessThan( 100, $summary['score'] );
		$this->assertGreaterThan( 0, $summary['by_severity']['error'] );
		$this->assertSame( 2, $summary['by_rule']['title_duplicate']['count'] );
		$this->assertSame( 'error', $summary['top_issues'][0]['severity'] );
		$this->assertSame( 'core', $summary['robots']['manager'] );
		$this->assertTrue( $summary['sitemap']['found'] );
		$this->assertSame( 9, $summary['sitemap']['urls'] );
		$this->assertSame( 4, $summary['cross_check']['missing'] );
		$this->assertSame( 1, $summary['cross_check']['noindex'] );
		$this->assertSame( $completed[1]['score'], $summary['score'] );
	}

	public function test_resume_across_many_small_steps(): void {
		$http    = $this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'max_per_step' => 2 ) );

		$first = $crawler->progress( $run_id );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 'crawl', $first['phase'] );
		$this->assertLessThanOrEqual( 3, $first['pages_crawled'], 'At most 2 requests (+1 reused redirect target) per step.' );

		$previous = $first['pages_crawled'];
		$steps    = 0;
		do {
			$progress = $crawler->step( $run_id, 20.0 );
			$this->assertGreaterThanOrEqual( $previous, $progress['pages_crawled'] );
			$this->assertLessThanOrEqual( $previous + 4, $progress['pages_crawled'] );
			$previous = $progress['pages_crawled'];
			++$steps;
		} while ( ! $progress['done'] && $steps < 50 );

		$this->assertTrue( $progress['done'] );
		$this->assertGreaterThanOrEqual( 6, $steps );
		$this->assertSame( 14, $progress['pages_crawled'] );
		$this->assertSame( 1, max( array_count_values( $http->requests ) ), 'No URL is fetched twice across steps.' );
		$this->assertArrayHasKey( 'orphan_page', $this->issues_by_rule( $run_id ) );

		// A finished run ignores further steps.
		$this->assertSame( 'done', $crawler->step( $run_id )['status'] );
	}

	public function test_max_pages_limit(): void {
		$this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'max_pages' => 4 ) );
		$this->finish( $crawler, $run_id );

		$run    = $this->store->get_run( $run_id );
		$states = $this->store->count_pages_by_state( $run_id );
		$this->assertSame( 'done', $run['status'] );
		$this->assertLessThanOrEqual( 5, $states['crawled'] );
		$this->assertGreaterThan( 0, $states['not_crawled'] );
		$this->assertArrayNotHasKey( 'queued', $states );
	}

	public function test_time_budget_stops_a_step(): void {
		$slow = static function () {
			usleep( 60000 );
			return MockHttp::html( '<html><head><title>Slow page title for budget test</title></head><body><a href="/s1/">1</a><a href="/s2/">2</a><a href="/s3/">3</a><a href="/s4/">4</a><a href="/s5/">5</a><a href="/s6/">6</a></body></html>' );
		};
		$this->set_permalink_structure( '/%postname%/' );
		( new MockHttp(
			array(
				'http://example.org/robots.txt'        => MockHttp::text( "User-agent: *\nDisallow:\n" ),
				'http://example.org/sitemap_index.xml' => MockHttp::xml( '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' ),
				'*'                                    => $slow,
			)
		) )->install();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST, 0.25 );
		$first   = $crawler->progress( $run_id );
		$this->assertFalse( $first['done'] );
		$this->assertLessThan( 7, $first['pages_crawled'] );

		$this->finish( $crawler, $run_id, 0.25 );
		$this->assertSame( 7, $this->store->get_run( $run_id )['pages_crawled'] );
	}

	public function test_max_depth(): void {
		$this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'max_depth' => 1 ) );
		$this->finish( $crawler, $run_id );
		$this->assertNull( $this->page( $run_id, '/deep/' ), 'Depth-2 links are not followed.' );
		$this->assertNotNull( $this->page( $run_id, '/about/' ) );
	}

	public function test_loopback_failure_is_reported(): void {
		( new MockHttp( array( '*' => MockHttp::error() ) ) )->install();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST );
		$run     = $this->store->get_run( $run_id );

		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'loopback_failed', $run['error'] );
		$this->assertTrue( $crawler->progress( $run_id )['done'] );
		$this->assertNull( $run['score'] );
		$this->assertTrue( $run['summary']['loopback_failed'] );
		$issues = $this->store->issues( $run_id )['items'];
		$this->assertSame( array( 'loopback_failed' ), array_column( $issues, 'rule_id' ) );
		$this->assertStringContainsString( 'cURL error 7', $issues[0]['data']['error'] );
	}

	public function test_ignoring_robots_txt(): void {
		$this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'respect_robots' => false ) );
		$this->finish( $crawler, $run_id );

		$secret = $this->page( $run_id, '/private/secret/' );
		$this->assertSame( 'crawled', $secret['crawl_state'] );
		$this->assertSame( 1, $secret['robots_blocked'], 'Still flagged as blocked for Googlebot.' );
		$this->assertSame( 'blocked', $secret['indexability'] );
		$this->assertSame( 0, $secret['indexable'] );
	}

	public function test_sitemaps_can_be_skipped(): void {
		$http    = $this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'include_sitemaps' => false ) );
		$this->finish( $crawler, $run_id );
		$this->assertSame( 0, $http->count( 'http://example.org/sitemap_index.xml' ) );
		$this->assertNull( $this->page( $run_id, '/orphan/' ) );
		$this->assertArrayNotHasKey( 'missing_from_sitemap', $this->issues_by_rule( $run_id ) );
	}

	public function test_cancel(): void {
		$this->mini_site();
		$crawler = $this->crawler();
		$run_id  = $crawler->start( self::FAST + array( 'max_per_step' => 1 ) );
		$crawler->cancel( $run_id );
		$this->assertSame( 'cancelled', $this->store->get_run( $run_id )['status'] );
		$this->assertArrayNotHasKey( 'queued', $this->store->count_pages_by_state( $run_id ) );
		$this->assertTrue( $crawler->step( $run_id )['done'] );
	}

	public function test_settings_are_clamped(): void {
		$settings = \SEOConsole\Modules\Audit\Crawler::sanitize_settings(
			array(
				'max_pages'      => 999999,
				'delay_ms'       => -5,
				'respect_robots' => 0,
				'unknown'        => 'x',
			)
		);
		$this->assertSame( 10000, $settings['max_pages'] );
		$this->assertSame( 0, $settings['delay_ms'] );
		$this->assertFalse( $settings['respect_robots'] );
		$this->assertArrayNotHasKey( 'unknown', $settings );
		$this->assertSame( 500, \SEOConsole\Modules\Audit\Crawler::default_settings()['max_pages'] );
	}

	public function test_store_hash_matches_url_lookup(): void {
		$this->assertSame( md5( 'http://example.org/' ), AuditStore::hash( 'http://example.org/' ) );
	}
}
