<?php
/**
 * Fetches pages of the site itself, following redirects manually so the chain can be recorded.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback HTTP client for the crawler.
 */
class HttpFetcher {

	const MAX_REDIRECTS = 5;

	/** Bodies above this size are truncated (large-HTML detection uses Content-Length when present). */
	const MAX_BYTES = 3145728;

	/** @var Url */
	private $scope;

	/** @var int */
	private $timeout;

	/** @var string */
	private $user_agent;

	/**
	 * Constructor.
	 *
	 * @param Url|null $scope   Site scope (only internal URLs are fetched).
	 * @param int      $timeout Seconds per request.
	 */
	public function __construct( ?Url $scope = null, int $timeout = 15 ) {
		$this->scope      = $scope ? $scope : new Url();
		$this->timeout    = max( 1, $timeout );
		$this->user_agent = 'SEOConsole-Audit/1.0 (+' . home_url( '/' ) . ')';
	}

	/** User agent sent with every request. */
	public function user_agent(): string {
		return $this->user_agent;
	}

	/**
	 * GETs an internal URL and follows up to 5 internal redirects.
	 *
	 * @param string $url     Absolute internal URL.
	 * @param int    $timeout Optional per-call timeout (seconds).
	 * @return array {
	 *     @type string $url         Requested URL.
	 *     @type int    $status      Status of the first response (0 on transport error).
	 *     @type int    $final_status Status of the last response.
	 *     @type string $final_url   URL of the last response (or the external/looping redirect target).
	 *     @type array  $chain       Redirect hops: [ [ 'url', 'status', 'location' ] ].
	 *     @type bool   $loop        Redirect loop detected.
	 *     @type bool   $too_many    More than 5 redirects.
	 *     @type array  $headers     Lower-case headers of the last response.
	 *     @type string $body        Body of the last response.
	 *     @type int    $ms          Duration of the first request in ms (≈ TTFB + download).
	 *     @type int    $total_ms    Duration of all requests.
	 *     @type int    $bytes       Size of the last body (Content-Length when larger than what was read).
	 *     @type string $error       Transport error message ('' when none).
	 *     @type string $error_code  WP_Error code.
	 * }
	 */
	public function get( string $url, int $timeout = 0 ): array {
		$result = array(
			'url'          => $url,
			'status'       => 0,
			'final_status' => 0,
			'final_url'    => $url,
			'chain'        => array(),
			'loop'         => false,
			'too_many'     => false,
			'headers'      => array(),
			'body'         => '',
			'ms'           => 0,
			'total_ms'     => 0,
			'bytes'        => 0,
			'error'        => '',
			'error_code'   => '',
		);

		$current = $url;
		$visited = array( $current => true );
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$response = $this->request( $current, $timeout > 0 ? $timeout : $this->timeout );
			if ( 0 === $hop ) {
				$result['ms'] = $response['ms'];
			}
			$result['total_ms'] += $response['ms'];

			if ( '' !== $response['error'] ) {
				$result['error']      = $response['error'];
				$result['error_code'] = $response['error_code'];
				if ( 0 === $hop ) {
					return $result;
				}
				$result['final_url'] = $current;
				return $result;
			}

			$status = $response['status'];
			if ( 0 === $hop ) {
				$result['status'] = $status;
			}
			$result['final_status'] = $status;
			$result['final_url']    = $current;
			$result['headers']      = $response['headers'];
			$result['body']         = $response['body'];
			$result['bytes']        = $response['bytes'];

			$location = isset( $response['headers']['location'] ) ? (string) $response['headers']['location'] : '';
			if ( $status < 300 || $status >= 400 || 304 === $status || '' === $location ) {
				return $result;
			}

			$target            = $this->scope->absolute( $location, $current );
			$result['chain'][] = array(
				'url'      => $current,
				'status'   => $status,
				'location' => $target,
			);
			$result['body']    = '';
			if ( '' === $target ) {
				return $result;
			}
			if ( isset( $visited[ $target ] ) ) {
				$result['loop']      = true;
				$result['final_url'] = $target;
				return $result;
			}
			if ( ! $this->scope->is_internal( $target ) ) {
				$result['final_url'] = $target;
				return $result;
			}
			if ( self::MAX_REDIRECTS === $hop ) {
				$result['too_many']  = true;
				$result['final_url'] = $target;
				return $result;
			}
			$visited[ $target ] = true;
			$current            = $target;
		}
		return $result;
	}

	/**
	 * One request without following redirects.
	 *
	 * @param string $url     URL.
	 * @param int    $timeout Seconds.
	 * @return array [ status, headers, body, bytes, ms, error, error_code ].
	 */
	public function request( string $url, int $timeout ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'user-agent'          => $this->user_agent,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'     => 0,
				'headers'    => array(),
				'body'       => '',
				'bytes'      => 0,
				'ms'         => $ms,
				'error'      => $response->get_error_message(),
				'error_code' => (string) $response->get_error_code(),
			);
		}

		$headers = array();
		$raw     = wp_remote_retrieve_headers( $response );
		if ( is_array( $raw ) || $raw instanceof \Traversable ) {
			foreach ( $raw as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}
		$body  = (string) wp_remote_retrieve_body( $response );
		$bytes = strlen( $body );
		if ( isset( $headers['content-length'] ) && (int) $headers['content-length'] > $bytes ) {
			$bytes = (int) $headers['content-length'];
		}

		return array(
			'status'     => (int) wp_remote_retrieve_response_code( $response ),
			'headers'    => $headers,
			'body'       => $body,
			'bytes'      => $bytes,
			'ms'         => $ms,
			'error'      => '',
			'error_code' => '',
		);
	}
}
