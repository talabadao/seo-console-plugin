<?php
/**
 * PageSpeed Insights v5 client.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the PageSpeed Insights API (performance category only) and maps every
 * failure to a typed WP_Error (`seoc_psi_*`) with a plain-English message.
 *
 * The API key is optional (PSI works without one, with a very low shared quota)
 * and is never included in errors or return values.
 */
class PsiClient {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	const STRATEGIES = array( 'mobile', 'desktop' );

	/**
	 * Google API key (may be empty).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Google API key (from core Secrets), or null/'' for keyless.
	 * @param int         $timeout Request timeout in seconds (PSI runs take 10–40 s).
	 */
	public function __construct( $api_key = null, int $timeout = 60 ) {
		$this->api_key = is_string( $api_key ) ? trim( $api_key ) : '';
		$this->timeout = max( 10, $timeout );
	}

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public function has_key(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Runs one PSI test and returns the decoded raw response.
	 *
	 * @param string $url      Absolute http(s) URL to test.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return array|WP_Error Raw PSI JSON as an array, or seoc_psi_* error.
	 */
	public function run( string $url, string $strategy = 'mobile' ) {
		$url = trim( $url );
		if ( ! self::is_testable_url( $url ) ) {
			return new WP_Error(
				'seoc_psi_invalid_url',
				__( 'This address cannot be tested. Use a full public address starting with http:// or https://.', 'seo-console' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $strategy, self::STRATEGIES, true ) ) {
			return new WP_Error(
				'seoc_psi_invalid_strategy',
				__( 'The test device must be "mobile" or "desktop".', 'seo-console' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			$this->request_url( $url, $strategy ),
			array(
				'timeout'    => $this->timeout,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'SEO-Console/' . ( defined( 'SEOC_VERSION' ) ? SEOC_VERSION : 'dev' ) . '; ' . home_url( '/' ),
			)
		);

		return self::handle_response( $response );
	}

	/**
	 * Builds the request URL. The key is only ever placed here.
	 *
	 * @param string $url      Page URL.
	 * @param string $strategy Strategy.
	 * @return string
	 */
	private function request_url( string $url, string $strategy ): string {
		$args = array(
			'url'      => rawurlencode( $url ),
			'strategy' => $strategy,
			'category' => 'performance',
			'locale'   => 'en',
		);
		if ( $this->has_key() ) {
			$args['key'] = rawurlencode( $this->api_key );
		}
		return add_query_arg( $args, self::ENDPOINT );
	}

	/**
	 * Keyless PageSpeed Insights URL for the browser runner (the API key is never sent to the browser).
	 * The browser calls this with the visitor's own quota; the server keeps the keyed path as a fallback.
	 *
	 * @param string $url      Page URL.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return string
	 */
	public static function browser_url( string $url, string $strategy ): string {
		return add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => in_array( $strategy, self::STRATEGIES, true ) ? $strategy : 'mobile',
				'category' => 'performance',
				'locale'   => 'en',
			),
			self::ENDPOINT
		);
	}

	/**
	 * Whether a URL is public enough for Google to fetch: http/https, a host with a dot, not localhost or a
	 * private/reserved IP. Used to refuse doomed tests up-front with a clear message.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_public_url( string $url ): bool {
		if ( ! self::is_testable_url( $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || false === strpos( $host, '.' ) || '.test' === substr( $host, -5 ) || '.local' === substr( $host, -6 ) || '.localhost' === substr( $host, -10 ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a URL is something PSI can fetch (absolute http/https with a host).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_testable_url( string $url ): bool {
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Maps an HTTP response (or transport error) to raw data or a typed error.
	 *
	 * @param array|WP_Error $response wp_remote_* result.
	 * @return array|WP_Error
	 */
	public static function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'seoc_psi_network',
				__( 'Could not reach Google PageSpeed Insights. Your hosting may block outgoing connections or the request timed out. Try again in a few minutes.', 'seo-console' ),
				array(
					'status' => 504,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			if ( ! is_array( $body ) || ! isset( $body['lighthouseResult'] ) || ! is_array( $body['lighthouseResult'] ) ) {
				return new WP_Error(
					'seoc_psi_bad_response',
					__( 'Google PageSpeed Insights returned an unexpected answer. Try again later.', 'seo-console' ),
					array( 'status' => 502 )
				);
			}
			if ( ! empty( $body['lighthouseResult']['runtimeError']['code'] ) && 'NO_ERROR' !== $body['lighthouseResult']['runtimeError']['code'] ) {
				return self::lighthouse_error(
					(string) $body['lighthouseResult']['runtimeError']['code'],
					isset( $body['lighthouseResult']['runtimeError']['message'] ) ? (string) $body['lighthouseResult']['runtimeError']['message'] : ''
				);
			}
			return $body;
		}

		$error   = ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) ? $body['error'] : array();
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';
		$status  = isset( $error['status'] ) ? (string) $error['status'] : '';
		$reasons = self::error_reasons( $error );

		if ( 429 === $code || 'RESOURCE_EXHAUSTED' === $status || in_array( 'rateLimitExceeded', $reasons, true ) || in_array( 'RATE_LIMIT_EXCEEDED', $reasons, true ) ) {
			$retry = wp_remote_retrieve_header( $response, 'retry-after' );
			return new WP_Error(
				'seoc_psi_quota',
				__( 'The Google PageSpeed Insights quota is used up for now. Tests will continue automatically later. Adding your own Google API key gives a much higher daily limit.', 'seo-console' ),
				array(
					'status'      => 429,
					'retry_after' => is_numeric( $retry ) ? (int) $retry : null,
				)
			);
		}

		if ( in_array( 'API_KEY_INVALID', $reasons, true ) || in_array( 'keyInvalid', $reasons, true ) || false !== stripos( $message, 'API key not valid' ) ) {
			return new WP_Error(
				'seoc_psi_auth',
				__( 'Google rejected the API key. Check the key in SEO Console settings.', 'seo-console' ),
				array( 'status' => 401 )
			);
		}

		if ( 403 === $code || 'PERMISSION_DENIED' === $status ) {
			return new WP_Error(
				'seoc_psi_auth',
				__( 'The Google API key is not allowed to use PageSpeed Insights. In Google Cloud Console, enable the "PageSpeed Insights API" for the key\'s project.', 'seo-console' ),
				array( 'status' => 403 )
			);
		}

		if ( 400 === $code && preg_match( "/\\burl\\b|Invalid value at 'url'|INVALID_URL/i", $message ) && false === stripos( $message, 'lighthouse' ) ) {
			return new WP_Error(
				'seoc_psi_invalid_url',
				__( 'Google could not test this address. Check that it is a public page that opens in a browser.', 'seo-console' ),
				array( 'status' => 400 )
			);
		}

		if ( false !== stripos( $message, 'lighthouse' ) || in_array( 'lighthouseUserFacingError', $reasons, true ) ) {
			$lh_code = preg_match( '/Lighthouse returned error:\s*([A-Z_]+)/', $message, $m ) ? $m[1] : 'LIGHTHOUSE_ERROR';
			return self::lighthouse_error( $lh_code, $message );
		}

		return new WP_Error(
			'seoc_psi_http',
			__( 'Google PageSpeed Insights returned an error. Try again later.', 'seo-console' ),
			array(
				'status'    => $code >= 400 ? $code : 502,
				'http_code' => $code,
				'detail'    => self::strip_key( $message ),
			)
		);
	}

	/**
	 * Builds a seoc_psi_lighthouse error with advice for the known codes.
	 *
	 * @param string $lh_code Lighthouse error code (FAILED_DOCUMENT_REQUEST, NO_FCP, …).
	 * @param string $message Raw message.
	 * @return WP_Error
	 */
	private static function lighthouse_error( string $lh_code, string $message ): WP_Error {
		switch ( $lh_code ) {
			case 'NO_FCP':
				$text = __( 'Google loaded the page but nothing appeared on screen. The page may be blank for bots, blocked by a security plugin or firewall, or need a login.', 'seo-console' );
				break;
			case 'ERRORED_DOCUMENT_REQUEST':
			case 'FAILED_DOCUMENT_REQUEST':
			case 'DNS_FAILURE':
				$text = __( 'Google could not load the page. Check that it opens for logged-out visitors and that the server or firewall does not block Google.', 'seo-console' );
				break;
			case 'PROTOCOL_TIMEOUT':
			case 'PAGE_HUNG':
			case 'NO_NAVSTART':
				$text = __( 'The page took too long to respond during the test. Try again; if it keeps happening, the server may be overloaded.', 'seo-console' );
				break;
			default:
				$text = __( 'Google\'s Lighthouse test failed for this page. Try again in a few minutes.', 'seo-console' );
		}
		return new WP_Error(
			'seoc_psi_lighthouse',
			$text,
			array(
				'status'          => 502,
				'lighthouse_code' => $lh_code,
				'detail'          => self::strip_key( $message ),
			)
		);
	}

	/**
	 * Collects `reason` values from a Google API error body.
	 *
	 * @param array $error Error object.
	 * @return string[]
	 */
	private static function error_reasons( array $error ): array {
		$reasons = array();
		foreach ( array( 'errors', 'details' ) as $list ) {
			if ( empty( $error[ $list ] ) || ! is_array( $error[ $list ] ) ) {
				continue;
			}
			foreach ( $error[ $list ] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['reason'] ) && is_string( $entry['reason'] ) ) {
					$reasons[] = $entry['reason'];
				}
			}
		}
		return $reasons;
	}

	/**
	 * Removes anything that looks like an API key from a message.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_key( string $text ): string {
		$text = preg_replace( '/([?&]key=)[^&\s"]+/', '$1***', $text );
		$text = preg_replace( '/AIza[0-9A-Za-z_\-]{20,}/', '***', (string) $text );
		return substr( (string) $text, 0, 500 );
	}
}
