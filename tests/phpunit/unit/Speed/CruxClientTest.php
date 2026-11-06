<?php
/**
 * CruxClient: record + history parsing, 404 = no data, error mapping.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\CruxClient;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/SpeedTestTrait.php';

class CruxClientTest extends WP_UnitTestCase {

	use SpeedTestTrait;

	public function test_record_success(): void {
		$this->mock_http(
			function () {
				return self::response( 200, $this->fixture_raw( 'crux-record-origin-phone.json' ) );
			}
		);
		$r = ( new CruxClient( 'AIzaKEY' ) )->query( 'https://example.org/some/page/', 'origin', 'PHONE' );

		$this->assertTrue( $r['has_data'] );
		$this->assertSame( 'origin', $r['scope'] );
		$this->assertSame( 'https://example.org', $r['id'] );
		$this->assertSame( 'PHONE', $r['form_factor'] );
		$this->assertSame(
			array(
				'first' => '2026-10-04',
				'last'  => '2026-10-31',
			),
			$r['period']
		);
		$this->assertSame( 3120, $r['metrics']['lcp']['p75'] );
		$this->assertSame( 'ni', $r['metrics']['lcp']['rating'] );
		$this->assertEquals( 0.6588, $r['metrics']['lcp']['good'] );
		$this->assertEquals( 0.1363, $r['metrics']['lcp']['poor'] );
		$this->assertSame( 186, $r['metrics']['inp']['p75'] );
		$this->assertSame( 'good', $r['metrics']['inp']['rating'] );
		$this->assertEquals( 0.05, $r['metrics']['cls']['p75'] );
		$this->assertSame( 'good', $r['metrics']['cls']['rating'] );
		$this->assertSame( 2087, $r['metrics']['fcp']['p75'] );
		$this->assertSame( 1204, $r['metrics']['ttfb']['p75'] );
		$this->assertSame( 'ni', $r['metrics']['ttfb']['rating'] );
		$this->assertSame( 'fail', $r['assessment'] );
		$this->assertArrayNotHasKey( 'navigation_types', $r['metrics'] );

		// Request: POST JSON with key in query string, origin normalised.
		$req  = $this->http_requests[0];
		$body = json_decode( $req['args']['body'], true );
		$this->assertSame( 'POST', $req['args']['method'] );
		$this->assertStringStartsWith( CruxClient::ENDPOINT . 'queryRecord?key=AIzaKEY', $req['url'] );
		$this->assertSame(
			array(
				'origin'     => 'https://example.org',
				'formFactor' => 'PHONE',
			),
			$body
		);
	}

	public function test_url_scope_and_all_devices(): void {
		$this->mock_http(
			function () {
				return self::response( 200, $this->fixture_raw( 'crux-record-origin-phone.json' ) );
			}
		);
		( new CruxClient( 'k' ) )->query( 'https://example.org/page/', 'url', 'ALL' );
		$this->assertSame( array( 'url' => 'https://example.org/page/' ), json_decode( $this->http_requests[0]['args']['body'], true ) );
	}

	public function test_404_is_empty_result_not_error(): void {
		$this->mock_http(
			function () {
				return self::response( 404, $this->fixture_raw( 'crux-404.json' ) );
			}
		);
		$client = new CruxClient( 'k' );
		$r      = $client->query( 'https://example.org/', 'url', 'DESKTOP' );
		$this->assertIsArray( $r );
		$this->assertFalse( $r['has_data'] );
		$this->assertSame( array(), $r['metrics'] );
		$this->assertNull( $r['assessment'] );

		$h = $client->history( 'https://example.org/' );
		$this->assertIsArray( $h );
		$this->assertFalse( $h['has_data'] );
	}

	public function test_history_success(): void {
		$this->mock_http(
			function () {
				return self::response( 200, $this->fixture_raw( 'crux-history-origin-phone.json' ) );
			}
		);
		$h = ( new CruxClient( 'k' ) )->history( 'https://example.org', 'origin', 'PHONE', 4 );

		$this->assertStringContainsString( 'queryHistoryRecord', $this->http_requests[0]['url'] );
		$this->assertSame( 4, json_decode( $this->http_requests[0]['args']['body'], true )['collectionPeriodCount'] );
		$this->assertTrue( $h['has_data'] );
		$this->assertSame( array( '2026-10-10', '2026-10-17', '2026-10-24', '2026-10-31' ), $h['periods'] );
		$this->assertSame( array( 3480, 3310, null, 3120 ), $h['metrics']['lcp']['p75'] );
		$this->assertSame( array( 0.07, 0.06, null, 0.05 ), $h['metrics']['cls']['p75'] );
		$this->assertSame( array( 0.75, 0.77, null, 0.79 ), $h['metrics']['inp']['good'] );
		$this->assertSame( array( 0.07, 0.07, null, 0.06 ), $h['metrics']['inp']['poor'] );
	}

	public function test_errors(): void {
		$this->assertSame( 'seoc_crux_no_key', ( new CruxClient( '' ) )->query( 'https://example.org' )->get_error_code() );
		$this->assertSame( 'seoc_crux_invalid', ( new CruxClient( 'k' ) )->query( 'nope' )->get_error_code() );
		$this->assertSame( 'seoc_crux_invalid', ( new CruxClient( 'k' ) )->query( 'https://example.org', 'site' )->get_error_code() );

		$codes = array( 429, 403, 400, 500 );
		$this->mock_http(
			function () use ( &$codes ) {
				$code = array_shift( $codes );
				return self::response( $code, '{"error":{"code":' . $code . ',"message":"x"}}' );
			}
		);
		$client = new CruxClient( 'k' );
		$this->assertSame( 'seoc_crux_quota', $client->query( 'https://example.org' )->get_error_code() );
		$this->assertSame( 'seoc_crux_auth', $client->query( 'https://example.org' )->get_error_code() );
		$this->assertSame( 'seoc_crux_invalid', $client->query( 'https://example.org' )->get_error_code() );
		$this->assertSame( 'seoc_crux_http', $client->query( 'https://example.org' )->get_error_code() );
	}

	public function test_network_error(): void {
		$this->mock_http(
			function () {
				return new WP_Error( 'http_request_failed', 'timeout' );
			}
		);
		$this->assertSame( 'seoc_crux_network', ( new CruxClient( 'k' ) )->history( 'https://example.org' )->get_error_code() );
	}
}
