<?php
/**
 * EnvironmentProbe with faked facts and a mocked loopback.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\EnvironmentProbe;
use WP_Error;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/unit/Speed/SpeedTestTrait.php';

class EnvironmentProbeTest extends WP_UnitTestCase {

	use SpeedTestTrait;

	private static function by_id( array $run ): array {
		return array_column( $run['items'], null, 'id' );
	}

	private function good_facts(): array {
		return array(
			'php_version'        => '8.3.6',
			'memory_limit'       => '256M',
			'max_execution_time' => 60,
			'opcache'            => true,
			'ext_object_cache'   => true,
			'server_software'    => 'LiteSpeed',
			'server_protocol'    => 'HTTP/2.0',
			'https'              => true,
			'disable_wp_cron'    => true,
			'overdue_cron'       => 0,
			'wp_version'         => '6.9.11',
			'core_update'        => null,
			'active_plugins'     => 18,
		);
	}

	public function test_loopback_headers_compression_cache_and_median(): void {
		$this->mock_http(
			function () {
				static $n = 0;
				++$n;
				return self::response(
					200,
					'<html></html>',
					array(
						'server'            => 'LiteSpeed',
						'content-encoding'  => 'br',
						'alt-svc'           => 'h3=":443"; ma=2592000, h3-29=":443"',
						'x-litespeed-cache' => 1 === $n ? 'miss' : 'hit',
						'cache-control'     => 'public, max-age=604800',
					)
				);
			}
		);
		$probe = new EnvironmentProbe( $this->good_facts() );
		$run   = $probe->run();

		$this->assertCount( 3, $this->http_requests );
		foreach ( $this->http_requests as $request ) {
			$this->assertSame( home_url( '/' ), $request['url'], 'Loopback only hits the home URL.' );
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->assertSame( 'br, gzip, deflate', $request['args']['headers']['Accept-Encoding'] );
		}

		$lb = $run['loopback'];
		$this->assertTrue( $lb['ok'] );
		$this->assertCount( 3, $lb['ttfb_ms'] );
		$this->assertIsInt( $lb['ttfb_median'] );
		$this->assertSame( 'br', $lb['compression'] );
		$this->assertTrue( $lb['http3'] );
		$this->assertSame( 'hit', $lb['cache'] );
		$this->assertSame( 'x-litespeed-cache', $lb['cache_header'] );
		$this->assertSame( 'LiteSpeed', $lb['headers']['server'] );

		$items = self::by_id( $run );
		$this->assertSame(
			array( 'php_version', 'memory_limit', 'max_execution_time', 'opcache', 'object_cache', 'server_software', 'https', 'http_version', 'compression', 'page_cache', 'ttfb', 'wp_cron', 'wp_version', 'active_plugins' ),
			array_keys( $items )
		);
		foreach ( $items as $id => $item ) {
			$this->assertSame( 'good', $item['verdict'], $id );
			$this->assertNotEmpty( $item['advice'], $id );
			$this->assertNotEmpty( $item['label'], $id );
		}
		$this->assertSame( 'Brotli', $items['compression']['value'] );
		$this->assertSame( 'HTTP/3', $items['http_version']['value'] );
		$this->assertSame( 14, $run['summary']['good'] );
	}

	public function test_bad_environment(): void {
		$this->mock_http(
			function () {
				return self::response( 200, '<html></html>', array( 'server' => 'Apache' ) );
			}
		);
		$facts = array(
			'php_version'        => '7.4.33',
			'memory_limit'       => '64M',
			'max_execution_time' => 20,
			'opcache'            => false,
			'ext_object_cache'   => false,
			'redis_available'    => true,
			'server_software'    => 'Apache',
			'server_protocol'    => 'HTTP/1.1',
			'https'              => false,
			'disable_wp_cron'    => true,
			'overdue_cron'       => 7,
			'wp_version'         => '6.5.5',
			'core_update'        => '6.9.11',
			'active_plugins'     => 63,
		);
		$items = self::by_id( ( new EnvironmentProbe( $facts ) )->run() );

		$this->assertSame( 'needs-work', $items['php_version']['verdict'] );
		$this->assertStringContainsString( '8.1', $items['php_version']['advice'] );
		$this->assertSame( 'poor', $items['memory_limit']['verdict'] );
		$this->assertSame( 'poor', $items['max_execution_time']['verdict'] );
		$this->assertSame( 'poor', $items['opcache']['verdict'] );
		$this->assertSame( 'needs-work', $items['object_cache']['verdict'] );
		$this->assertStringContainsString( 'extension', $items['object_cache']['advice'] );
		$this->assertSame( 'poor', $items['https']['verdict'] );
		$this->assertSame( 'needs-work', $items['http_version']['verdict'] );
		$this->assertSame( 'poor', $items['compression']['verdict'] );
		$this->assertSame( 'needs-work', $items['page_cache']['verdict'] );
		$this->assertSame( 'poor', $items['wp_cron']['verdict'] );
		$this->assertSame( 'needs-work', $items['wp_version']['verdict'] );
		$this->assertSame( 'poor', $items['active_plugins']['verdict'] );
	}

	public function test_loopback_failure_is_unknown(): void {
		$this->mock_http(
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
		);
		$run = ( new EnvironmentProbe( $this->good_facts() ) )->run();
		$this->assertFalse( $run['loopback']['ok'] );
		$this->assertStringContainsString( 'Failed to connect', $run['loopback']['error'] );
		$this->assertCount( 1, $this->http_requests, 'Stops after the first failure.' );
		$items = self::by_id( $run );
		foreach ( array( 'compression', 'page_cache', 'ttfb' ) as $id ) {
			$this->assertSame( 'unknown', $items[ $id ]['verdict'] );
			$this->assertStringContainsString( 'loopback', $items[ $id ]['advice'] );
		}
		$this->assertSame( 'HTTP/2', $items['http_version']['value'] );
	}

	public function test_without_loopback_makes_no_requests(): void {
		$this->mock_http(
			function () {
				return self::response( 200, '' );
			}
		);
		$run = ( new EnvironmentProbe( $this->good_facts() ) )->run( false );
		$this->assertNull( $run['loopback'] );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_redirects_followed_only_on_same_host(): void {
		$this->mock_http(
			function ( $url ) {
				if ( home_url( '/' ) === $url ) {
					return self::response( 301, '', array( 'location' => 'https://evil.example.com/' ) );
				}
				return self::response( 200, 'other' );
			}
		);
		( new EnvironmentProbe( $this->good_facts() ) )->loopback( 1 );
		$this->assertSame( array( home_url( '/' ) ), wp_list_pluck( $this->http_requests, 'url' ) );

		$this->mock_http(
			function ( $url ) {
				if ( home_url( '/' ) === $url ) {
					return self::response( 301, '', array( 'location' => 'https://www.example.org/' ) );
				}
				return self::response( 200, 'ok', array( 'content-encoding' => 'gzip' ) );
			}
		);
		$lb = ( new EnvironmentProbe( $this->good_facts() ) )->loopback( 1 );
		$this->assertSame( array( home_url( '/' ), 'https://www.example.org/' ), wp_list_pluck( $this->http_requests, 'url' ) );
		$this->assertSame( 'gzip', $lb['compression'] );
	}

	public function test_cache_footprint_in_body_and_cron_lateness(): void {
		$this->mock_http(
			function () {
				return self::response( 200, '<html></html><!-- This website is like a Rocket, isn\'t it? -->' );
			}
		);
		$lb = ( new EnvironmentProbe() )->loopback( 1 );
		$this->assertSame( 'hit', $lb['cache'] );

		wp_schedule_single_event( time() - HOUR_IN_SECONDS, 'seoc_test_late_event' );
		$facts = ( new EnvironmentProbe() )->facts();
		$this->assertGreaterThanOrEqual( 1, $facts['overdue_cron'] );
		$this->assertSame( PHP_VERSION, $facts['php_version'] );
		$this->assertIsInt( $facts['active_plugins'] );
	}
}
