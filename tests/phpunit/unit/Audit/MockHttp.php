<?php
/**
 * Fake HTTP layer for audit tests (pre_http_request). Unknown URLs return 404.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use WP_Error;

/**
 * Routes => responses.
 */
class MockHttp {

	/** @var array<string, array|WP_Error|callable> */
	public $routes = array();

	/** @var string[] Requested URLs in order. */
	public $requests = array();

	/** @var array[] Request args in order. */
	public $args = array();

	/**
	 * Constructor.
	 *
	 * @param array $routes URL => response.
	 */
	public function __construct( array $routes = array() ) {
		$this->routes = $routes;
	}

	/** Hooks into pre_http_request (removed by the test case's hook restore). */
	public function install(): self {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
		return $this;
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $pre  Preempt.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public function handle( $pre, $args, $url ) {
		$this->requests[] = $url;
		$this->args[]     = $args;
		if ( isset( $this->routes['*'] ) && ! isset( $this->routes[ $url ] ) ) {
			$route = $this->routes['*'];
		} else {
			$route = isset( $this->routes[ $url ] ) ? $this->routes[ $url ] : self::html( '<html><head><title>Not found</title></head><body>404</body></html>', 404 );
		}
		if ( is_callable( $route ) && ! is_array( $route ) ) {
			$route = call_user_func( $route, $url, $args );
		}
		return $route;
	}

	/**
	 * Requests to a URL.
	 *
	 * @param string $url URL.
	 */
	public function count( string $url ): int {
		return count( array_keys( $this->requests, $url, true ) );
	}

	/**
	 * Response array.
	 *
	 * @param string $body    Body.
	 * @param int    $status  Status.
	 * @param array  $headers Headers.
	 */
	public static function response( string $body, int $status = 200, array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * HTML response.
	 *
	 * @param string $body    HTML.
	 * @param int    $status  Status.
	 * @param array  $headers Extra headers.
	 */
	public static function html( string $body, int $status = 200, array $headers = array() ): array {
		return self::response( $body, $status, $headers + array( 'content-type' => 'text/html; charset=UTF-8' ) );
	}

	/**
	 * XML response.
	 *
	 * @param string $body XML.
	 */
	public static function xml( string $body ): array {
		return self::response( $body, 200, array( 'content-type' => 'application/xml; charset=UTF-8' ) );
	}

	/**
	 * Plain text response.
	 *
	 * @param string $body Text.
	 */
	public static function text( string $body ): array {
		return self::response( $body, 200, array( 'content-type' => 'text/plain; charset=UTF-8' ) );
	}

	/**
	 * Redirect.
	 *
	 * @param string $location Location.
	 * @param int    $status   Status.
	 */
	public static function redirect( string $location, int $status = 301 ): array {
		return self::response( '', $status, array( 'location' => $location ) );
	}

	/** Transport error. */
	public static function error(): WP_Error {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to example.org port 80: Connection refused' );
	}

	/**
	 * Fixture file content.
	 *
	 * @param string $name Path under tests/phpunit/fixtures/audit/.
	 */
	public static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/audit/' . $name );
	}
}
