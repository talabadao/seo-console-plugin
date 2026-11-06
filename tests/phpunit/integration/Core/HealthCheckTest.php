<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Core\HealthCheck;
use WP_Error;

require_once __DIR__ . '/CoreTestCase.php';

class HealthCheckTest extends CoreTestCase {

	public function test_ok_and_loopback_marked(): void {
		$result = ( new HealthCheck() )->run( array( home_url( '/sample-page/' ) ) );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $result['checks'] );
		$this->assertSame( 'yes', get_option( HealthCheck::LOOPBACK_OPTION ) );
		$first = $this->http_requests[0];
		$this->assertStringContainsString( 'seoc_hc=', $first['url'] );
		$this->assertSame( 'no-cache', $first['args']['headers']['Cache-Control'] );
		$this->assertSame( 15, $first['args']['timeout'] );
	}

	public function test_only_same_host_and_max_three_extra_urls(): void {
		$targets = ( new HealthCheck() )->targets(
			array( 'https://evil.example.com/', home_url( '/a/' ), home_url( '/b/' ), home_url( '/c/' ), home_url( '/d/' ), 42 )
		);
		$this->assertSame( array( home_url( '/' ), home_url( '/a/' ), home_url( '/b/' ), home_url( '/c/' ) ), $targets );
	}

	public function test_server_error_fails(): void {
		$this->break_loopback();
		$result = ( new HealthCheck() )->run();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 500, $result['checks'][0]['code'] );
	}

	public function test_critical_error_body_fails(): void {
		$this->http_handler = static function () {
			return CoreTestCase::response( 200, '<p>There has been a critical error on this website.</p>' );
		};
		$this->assertFalse( ( new HealthCheck() )->run()['ok'] );
	}

	public function test_network_error_fails_unless_loopback_known_blocked(): void {
		$this->http_handler = static function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
		};
		$this->assertFalse( ( new HealthCheck() )->run()['ok'] );

		update_option( HealthCheck::LOOPBACK_OPTION, 'no' );
		$result = ( new HealthCheck() )->run();
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['inconclusive'] );
		$this->assertSame( 'inconclusive', $result['checks'][0]['status'] );
	}

	public function test_pre_filter_short_circuits(): void {
		$cb = static function () {
			return array( 'ok' => false );
		};
		add_filter( 'seoc_health_check_pre', $cb );
		$result = ( new HealthCheck() )->run();
		remove_filter( 'seoc_health_check_pre', $cb );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $this->http_requests );
	}
}
