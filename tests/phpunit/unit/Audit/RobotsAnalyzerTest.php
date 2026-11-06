<?php
/**
 * robots.txt checks.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\HttpFetcher;
use SEOConsole\Modules\Audit\RobotsAnalyzer;
use SEOConsole\Modules\Audit\Url;
use WP_UnitTestCase;

require_once __DIR__ . '/MockHttp.php';

class RobotsAnalyzerTest extends WP_UnitTestCase {

	const ASSETS = array(
		'theme_css' => 'http://example.org/wp-content/themes/t/style.css',
		'core_js'   => 'http://example.org/wp-includes/js/jquery/jquery.min.js',
		'uploads'   => 'http://example.org/wp-content/uploads/2024/01/image.jpg',
	);

	private function analyzer(): RobotsAnalyzer {
		$scope = new Url( 'http://example.org/', true );
		return new RobotsAnalyzer( $scope, new HttpFetcher( $scope ) );
	}

	private function analyze( string $content, int $status = 200, array $important = array() ): array {
		$fetched = array(
			'url'     => 'http://example.org/robots.txt',
			'source'  => 'http',
			'status'  => $status,
			'content' => $content,
			'error'   => '',
		);
		$important = $important ? $important : array( 'http://example.org/', 'http://example.org/hello/' );
		return $this->analyzer()->analyze( $fetched, $important, self::ASSETS );
	}

	private function rules( array $result ): array {
		return array_column( $result['checks'], 'rule' );
	}

	public function test_healthy_file_has_no_checks(): void {
		$result = $this->analyze( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: http://example.org/sitemap_index.xml\n" );
		$this->assertSame( array(), $result['checks'] );
		$this->assertTrue( $result['exists'] );
		$this->assertSame( array( 'http://example.org/sitemap_index.xml' ), $result['sitemaps'] );
		$this->assertSame( 'core', $result['manager'] );
	}

	public function test_missing_file(): void {
		$result = $this->analyze( '', 404 );
		$this->assertSame( array( 'robots_missing' ), $this->rules( $result ) );
		$this->assertFalse( $result['exists'] );
		$this->assertNotEmpty( $result['checks'][0]['data']['advice'] );
	}

	public function test_server_error(): void {
		$this->assertSame( array( 'robots_unreachable' ), $this->rules( $this->analyze( '', 503 ) ) );
	}

	public function test_disallow_all_for_googlebot(): void {
		$result = $this->analyze( "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow:\nSitemap: http://example.org/s.xml\n" );
		$this->assertSame( array( 'robots_disallow_all' ), $this->rules( $result ) );
		$this->assertSame( array( 'Googlebot' ), $result['checks'][0]['data']['user_agents'] );
	}

	public function test_blocks_assets_and_important_urls(): void {
		$result = $this->analyze( "User-agent: *\nDisallow: /wp-content/\nDisallow: /hello/\nSitemap: http://example.org/s.xml\n" );
		$this->assertSame( array( 'robots_blocks_important', 'robots_blocks_assets' ), $this->rules( $result ) );
		$this->assertSame( 'http://example.org/hello/', $result['checks'][0]['data']['urls'][0]['url'] );
		$this->assertSame( array( 'theme_css', 'uploads' ), array_column( $result['checks'][1]['data']['assets'], 'type' ) );
	}

	public function test_no_sitemap_and_syntax(): void {
		$result = $this->analyze( "Disallow: /x/\nUser-agent: *\nDisallow: /tmp/\nNoindex: /old/\n" );
		$this->assertSame( array( 'robots_no_sitemap', 'robots_syntax' ), $this->rules( $result ) );
		$this->assertSame( 2, $result['checks'][1]['data']['count'] );
	}

	public function test_fetch_over_http(): void {
		( new MockHttp( array( 'http://example.org/robots.txt' => MockHttp::text( "User-agent: *\nDisallow: /a/\n" ) ) ) )->install();
		$fetched = $this->analyzer()->fetch();
		$this->assertSame( 'http', $fetched['source'] );
		$this->assertSame( 200, $fetched['status'] );
		$this->assertStringContainsString( 'Disallow: /a/', $fetched['content'] );
	}

	public function test_fetch_falls_back_to_virtual_robots_when_loopback_fails(): void {
		( new MockHttp( array( '*' => MockHttp::error() ) ) )->install();
		update_option( 'blog_public', '1' );
		$fetched = $this->analyzer()->fetch();
		$this->assertSame( 'virtual', $fetched['source'] );
		$this->assertStringContainsString( 'Disallow: /wp-admin/', $fetched['content'] );
		$this->assertNotSame( '', $fetched['error'] );

		update_option( 'blog_public', '0' );
		$result = $this->analyzer()->analyze( $this->analyzer()->fetch(), array( 'http://example.org/' ), self::ASSETS );
		$this->assertSame( 'robots_disallow_all', $result['checks'][0]['rule'] );
		$this->assertTrue( $result['checks'][0]['data']['discourage_flag'] );
	}

	public function test_virtual_content_runs_robots_txt_filter(): void {
		add_filter(
			'robots_txt',
			static function ( $output ) {
				return $output . "Sitemap: http://example.org/from-filter.xml\n";
			}
		);
		$this->assertStringContainsString( 'from-filter.xml', RobotsAnalyzer::virtual_content() );
	}

	public function test_manager_advice(): void {
		$this->assertSame( 'core', RobotsAnalyzer::manager() );
		foreach ( array( 'file', 'rankmath', 'yoast', 'core' ) as $manager ) {
			$this->assertNotEmpty( RobotsAnalyzer::advice( $manager ) );
		}
		$this->assertStringContainsString( 'Rank Math', RobotsAnalyzer::advice( 'rankmath' ) );
	}

	public function test_important_urls_include_home_and_recent_posts(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$post_id = self::factory()->post->create( array( 'post_name' => 'recent-one' ) );
		$urls    = ( new RobotsAnalyzer( new Url( home_url( '/' ), true ) ) )->important_urls();
		$this->assertContains( home_url( '/' ), $urls );
		$this->assertContains( get_permalink( $post_id ), $urls );
	}
}
