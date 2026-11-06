<?php
/**
 * PsiClient: request building and error mapping.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\PsiClient;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/SpeedTestTrait.php';

class PsiClientTest extends WP_UnitTestCase {

	use SpeedTestTrait;

	public function test_success_returns_raw_and_builds_request(): void {
		$this->mock_http(
			function () {
				return self::response( 200, $this->fixture_raw( 'psi-mobile-image-lcp.json' ) );
			}
		);
		$client = new PsiClient( 'AIzaTESTKEY', 45 );
		$raw    = $client->run( 'https://example.org/?a=1&b=2', 'mobile' );

		$this->assertIsArray( $raw );
		$this->assertArrayHasKey( 'lighthouseResult', $raw );
		$this->assertCount( 1, $this->http_requests );

		$request = $this->http_requests[0];
		$this->assertStringStartsWith( PsiClient::ENDPOINT . '?', $request['url'] );
		parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'https://example.org/?a=1&b=2', $query['url'] );
		$this->assertSame( 'mobile', $query['strategy'] );
		$this->assertSame( 'performance', $query['category'] );
		$this->assertSame( 'AIzaTESTKEY', $query['key'] );
		$this->assertSame( 45, $request['args']['timeout'] );
	}

	public function test_no_key_is_omitted(): void {
		$this->mock_http(
			function () {
				return self::response( 200, $this->fixture_raw( 'psi-desktop-text-lcp.json' ) );
			}
		);
		$client = new PsiClient( '' );
		$this->assertFalse( $client->has_key() );
		$client->run( 'https://example.org/about/', 'desktop' );
		$this->assertStringNotContainsString( 'key=', $this->http_requests[0]['url'] );
		$this->assertStringContainsString( 'strategy=desktop', $this->http_requests[0]['url'] );
	}

	public function test_invalid_input_never_calls_api(): void {
		$this->mock_http(
			function () {
				return self::response( 200, '{}' );
			}
		);
		$client = new PsiClient();
		$this->assertWPError( $client->run( 'notaurl' ) );
		$this->assertSame( 'seoc_psi_invalid_url', $client->run( 'ftp://example.org/' )->get_error_code() );
		$this->assertSame( 'seoc_psi_invalid_strategy', $client->run( 'https://example.org/', 'tablet' )->get_error_code() );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @dataProvider error_cases
	 */
	public function test_error_mapping( int $code, string $fixture, string $expected ): void {
		$this->mock_http(
			function () use ( $code, $fixture ) {
				return self::response( $code, $this->fixture_raw( $fixture ), array( 'retry-after' => '30' ) );
			}
		);
		$result = ( new PsiClient( 'AIzaSECRETSECRETSECRETSECRET' ) )->run( 'https://example.org/' );
		$this->assertWPError( $result );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $result->get_error_data() ) . $result->get_error_message() );
	}

	public function error_cases(): array {
		return array(
			'quota'       => array( 429, 'psi-error-quota.json', 'seoc_psi_quota' ),
			'invalid url' => array( 400, 'psi-error-invalid-url.json', 'seoc_psi_invalid_url' ),
			'lighthouse'  => array( 500, 'psi-error-lighthouse.json', 'seoc_psi_lighthouse' ),
			'bad key'     => array( 400, 'psi-error-key.json', 'seoc_psi_auth' ),
		);
	}

	public function test_quota_error_carries_retry_after(): void {
		$this->mock_http(
			function () {
				return self::response( 429, $this->fixture_raw( 'psi-error-quota.json' ), array( 'retry-after' => '30' ) );
			}
		);
		$error = ( new PsiClient() )->run( 'https://example.org/' );
		$data  = $error->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 30, $data['retry_after'] );
	}

	public function test_lighthouse_code_is_extracted(): void {
		$this->mock_http(
			function () {
				return self::response( 500, $this->fixture_raw( 'psi-error-lighthouse.json' ) );
			}
		);
		$error = ( new PsiClient() )->run( 'https://example.org/' );
		$this->assertSame( 'FAILED_DOCUMENT_REQUEST', $error->get_error_data()['lighthouse_code'] );
	}

	public function test_runtime_error_in_200_response(): void {
		$body = wp_json_encode(
			array(
				'lighthouseResult' => array(
					'runtimeError' => array(
						'code'    => 'NO_FCP',
						'message' => 'The page did not paint any content.',
					),
				),
			)
		);
		$this->mock_http(
			function () use ( $body ) {
				return self::response( 200, $body );
			}
		);
		$error = ( new PsiClient() )->run( 'https://example.org/' );
		$this->assertSame( 'seoc_psi_lighthouse', $error->get_error_code() );
		$this->assertSame( 'NO_FCP', $error->get_error_data()['lighthouse_code'] );
	}

	public function test_network_error(): void {
		$this->mock_http(
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
		);
		$this->assertSame( 'seoc_psi_network', ( new PsiClient() )->run( 'https://example.org/' )->get_error_code() );
	}

	public function test_unexpected_body_and_other_http_errors(): void {
		$this->mock_http(
			function ( $url ) {
				return false !== strpos( $url, 'bad-json' ) ? self::response( 200, '<html>oops</html>' ) : self::response( 503, '{"error":{"code":503,"message":"Backend Error"}}' );
			}
		);
		$client = new PsiClient();
		$this->assertSame( 'seoc_psi_bad_response', $client->run( 'https://example.org/bad-json' )->get_error_code() );
		$this->assertSame( 'seoc_psi_http', $client->run( 'https://example.org/' )->get_error_code() );
	}

	public function test_permission_denied_maps_to_auth(): void {
		$this->mock_http(
			function () {
				return self::response( 403, '{"error":{"code":403,"message":"PageSpeed Insights API has not been used in project 1 before or it is disabled.","status":"PERMISSION_DENIED"}}' );
			}
		);
		$this->assertSame( 'seoc_psi_auth', ( new PsiClient( 'k' ) )->run( 'https://example.org/' )->get_error_code() );
	}
}
