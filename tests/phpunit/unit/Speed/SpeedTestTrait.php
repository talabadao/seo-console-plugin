<?php
/**
 * Shared helpers for Speed tests: fixtures and HTTP mocking.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

trait SpeedTestTrait {

	/**
	 * Requests captured by mock_http().
	 *
	 * @var array[]
	 */
	protected $http_requests = array();

	/**
	 * Raw fixture contents.
	 */
	protected function fixture_raw( string $name ): string {
		$file = dirname( __DIR__, 2 ) . '/fixtures/speed/' . $name;
		$this->assertFileExists( $file );
		return (string) file_get_contents( $file );
	}

	/**
	 * Decoded JSON fixture.
	 */
	protected function fixture( string $name ): array {
		return json_decode( $this->fixture_raw( $name ), true );
	}

	/**
	 * Fakes HTTP: the responder gets ( $url, $args ) and returns a wp_remote_* style array or WP_Error.
	 */
	protected function mock_http( callable $responder ): void {
		$this->http_requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responder ) {
				$this->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $responder( $url, $args );
			},
			10,
			3
		);
	}

	/**
	 * A wp_remote_* style response.
	 */
	protected static function response( int $code, string $body, array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
