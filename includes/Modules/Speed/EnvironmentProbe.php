<?php
/**
 * Server / WordPress environment facts with plain-English verdicts.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Result of run():
 *
 *     [
 *       'checked_at' => 'Y-m-d H:i:s' (UTC),
 *       'items'      => [ [ 'id', 'label', 'value' => string, 'verdict' => good|needs-work|poor|unknown, 'advice' => string ] ],
 *       'summary'    => [ 'good' => n, 'needs-work' => n, 'poor' => n, 'unknown' => n ],
 *       'loopback'   => loopback() result|null,
 *     ]
 *
 * The only outbound requests are GETs of the site's own home URL.
 */
class EnvironmentProbe {

	/** Cache-status headers set by hosts, CDNs and cache plugins. */
	const CACHE_HEADERS = array(
		'x-litespeed-cache',
		'x-lsadc-cache',
		'cf-cache-status',
		'x-proxy-cache',
		'x-sg-cache',
		'x-cache',
		'x-cache-status',
		'x-nginx-cache',
		'x-fastcgi-cache',
		'x-srcache-fetch-status',
		'x-varnish-cache',
		'x-kinsta-cache',
		'x-wpe-cached',
		'x-ac',
	);

	/**
	 * Fact overrides (tests, or values gathered elsewhere).
	 *
	 * @var array
	 */
	private $overrides;

	/**
	 * Constructor.
	 *
	 * @param array $overrides Facts to use instead of detection (see facts()).
	 */
	public function __construct( array $overrides = array() ) {
		$this->overrides = $overrides;
	}

	/**
	 * Runs every check.
	 *
	 * @param bool $with_loopback Whether to request the home page (3 requests).
	 * @return array
	 */
	public function run( bool $with_loopback = true ): array {
		$facts    = $this->facts();
		$loopback = $with_loopback ? $this->loopback() : null;

		$items = array(
			$this->php_item( $facts ),
			$this->memory_item( $facts ),
			$this->time_item( $facts ),
			$this->opcache_item( $facts ),
			$this->object_cache_item( $facts ),
			$this->server_item( $facts ),
			$this->https_item( $facts ),
			$this->http_version_item( $facts, $loopback ),
			$this->compression_item( $loopback ),
			$this->page_cache_item( $loopback ),
			$this->ttfb_item( $loopback ),
			$this->cron_item( $facts ),
			$this->wp_version_item( $facts ),
			$this->plugins_item( $facts ),
		);

		$summary = array(
			'good'       => 0,
			'needs-work' => 0,
			'poor'       => 0,
			'unknown'    => 0,
		);
		foreach ( $items as $item ) {
			++$summary[ $item['verdict'] ];
		}
		return array(
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
			'items'      => $items,
			'summary'    => $summary,
			'loopback'   => $loopback,
		);
	}

	/**
	 * Raw facts (overridable).
	 *
	 * @return array
	 */
	public function facts(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$update  = get_site_transient( 'update_core' );
		$latest  = null;
		$offered = isset( $update->updates ) && is_array( $update->updates ) ? $update->updates : array();
		foreach ( $offered as $offer ) {
			if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
				$latest = (string) $offer->current;
				break;
			}
		}

		$facts = array(
			'php_version'        => PHP_VERSION,
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'opcache'            => $this->detect_opcache(),
			'ext_object_cache'   => (bool) wp_using_ext_object_cache(),
			'redis_available'    => class_exists( 'Redis' ) || class_exists( 'Memcached' ) || class_exists( 'Memcache' ),
			'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'server_protocol'    => isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '',
			'https'              => 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ),
			'disable_wp_cron'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'overdue_cron'       => $this->overdue_cron_events(),
			'wp_version'         => (string) get_bloginfo( 'version' ),
			'core_update'        => $latest,
			'active_plugins'     => count( array_unique( $active ) ),
		);
		return array_merge( $facts, array_intersect_key( $this->overrides, $facts ) );
	}

	/**
	 * Requests the home page $requests times (sequentially) and reports headers and timings.
	 *
	 * @param int $requests Number of requests (1–5).
	 * @return array [ 'ok', 'url', 'error', 'status', 'ttfb_ms' => int[], 'ttfb_median' => int|null, 'measure' => 'ttfb'|'total',
	 *                 'headers' => [ name => value ], 'compression' => br|gzip|deflate|zstd|''|null, 'http3' => bool, 'cache' => hit|miss|none|null, 'cache_header' => string ]
	 */
	public function loopback( int $requests = 3 ): array {
		$url    = home_url( '/' );
		$result = array(
			'ok'           => false,
			'url'          => $url,
			'error'        => '',
			'status'       => 0,
			'ttfb_ms'      => array(),
			'ttfb_median'  => null,
			'measure'      => 'total',
			'headers'      => array(),
			'compression'  => null,
			'http3'        => false,
			'cache'        => null,
			'cache_header' => '',
		);

		$ttfb     = null;
		$listener = static function ( $headers = null, $info = null ) use ( &$ttfb ) {
			unset( $headers );
			if ( is_array( $info ) && isset( $info['starttransfer_time'] ) && $info['starttransfer_time'] > 0 ) {
				$ttfb = (float) $info['starttransfer_time'] * 1000;
			}
		};
		add_action( 'requests-curl.after_request', $listener, 10, 2 );

		$hits     = 0;
		$seen     = false;
		$response = null;
		for ( $i = 0, $n = max( 1, min( 5, $requests ) ); $i < $n; $i++ ) {
			$ttfb     = null;
			$start    = microtime( true );
			$response = $this->request( $url );
			$elapsed  = ( microtime( true ) - $start ) * 1000;
			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				break;
			}
			if ( null !== $ttfb ) {
				$result['measure'] = 'ttfb';
			}
			$result['ttfb_ms'][] = (int) round( null !== $ttfb ? $ttfb : $elapsed );
			$state               = self::cache_state( $response );
			if ( null !== $state['header'] ) {
				$seen                   = true;
				$result['cache_header'] = $state['header'];
				$hits                  += 'hit' === $state['state'] ? 1 : 0;
			}
		}
		remove_action( 'requests-curl.after_request', $listener, 10 );

		if ( empty( $result['ttfb_ms'] ) || is_wp_error( $response ) ) {
			$result['ttfb_ms'] = array();
			return $result;
		}

		$result['ok']     = true;
		$result['status'] = (int) wp_remote_retrieve_response_code( $response );
		$sorted           = $result['ttfb_ms'];
		sort( $sorted );
		$result['ttfb_median'] = $sorted[ (int) floor( count( $sorted ) / 2 ) ];

		foreach ( array_merge( array( 'server', 'content-encoding', 'alt-svc', 'cache-control', 'age', 'vary', 'x-powered-by' ), self::CACHE_HEADERS ) as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			if ( '' !== (string) $value ) {
				$result['headers'][ $name ] = substr( sanitize_text_field( (string) $value ), 0, 200 );
			}
		}
		$encoding              = strtolower( isset( $result['headers']['content-encoding'] ) ? $result['headers']['content-encoding'] : '' );
		$result['compression'] = preg_match( '/\b(br|zstd|gzip|deflate)\b/', $encoding, $m ) ? $m[1] : '';
		$result['http3']       = isset( $result['headers']['alt-svc'] ) && (bool) preg_match( '/\bh3\b|h3-\d+/', $result['headers']['alt-svc'] );
		if ( $seen ) {
			$result['cache'] = $hits > 0 ? 'hit' : 'miss';
		} else {
			$result['cache'] = self::body_says_cached( $response ) ? 'hit' : 'none';
		}
		return $result;
	}

	/**
	 * One loopback GET to the site's own home page. Redirects are followed only within the same host.
	 *
	 * @param string $url Home URL.
	 * @return array|\WP_Error
	 */
	private function request( string $url ) {
		$args     = array(
			'timeout'     => 10,
			'redirection' => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			'decompress'  => false,
			'headers'     => array(
				'Accept'          => 'text/html',
				'Accept-Encoding' => 'br, gzip, deflate',
			),
			'user-agent'  => 'Mozilla/5.0 (compatible; SEO-Console-Probe/' . ( defined( 'SEOC_VERSION' ) ? SEOC_VERSION : 'dev' ) . ')',
		);
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code >= 300 && $code < 400 && '' !== $location ) {
			$target = PsiParser::host( $location );
			if ( '' !== $target && PsiParser::same_site( $target, PsiParser::host( $url ) ) && 0 === stripos( $location, 'http' ) ) {
				$response = wp_remote_get( esc_url_raw( $location ), $args );
			}
		}
		return $response;
	}

	/**
	 * Cache state from response headers.
	 *
	 * @param array $response Response.
	 * @return array [ 'state' => hit|miss|null, 'header' => string|null ]
	 */
	private static function cache_state( $response ): array {
		foreach ( self::CACHE_HEADERS as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$value = strtolower( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$hit = (bool) preg_match( '/\bhit\b|^cached$|^yes$|^true$|^1$/', $value );
			return array(
				'state'  => $hit ? 'hit' : 'miss',
				'header' => $name,
			);
		}
		$age = wp_remote_retrieve_header( $response, 'age' );
		if ( is_numeric( $age ) && (int) $age > 0 ) {
			return array(
				'state'  => 'hit',
				'header' => 'age',
			);
		}
		return array(
			'state'  => null,
			'header' => null,
		);
	}

	/**
	 * Whether an (uncompressed) body carries a cache plugin's footprint comment.
	 *
	 * @param array $response Response.
	 * @return bool
	 */
	private static function body_says_cached( $response ): bool {
		$body     = (string) wp_remote_retrieve_body( $response );
		$encoding = strtolower( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) );
		if ( in_array( $encoding, array( 'gzip', 'deflate' ), true ) && class_exists( 'WP_Http_Encoding' ) ) {
			$decoded = \WP_Http_Encoding::decompress( $body );
			$body    = is_string( $decoded ) ? $decoded : '';
		} elseif ( '' !== $encoding ) {
			return false;
		}
		return (bool) preg_match( '/This website is like a Rocket|Cached page generated by WP-Super-Cache|Page cached by LiteSpeed|Performance optimized by W3 Total Cache|Cache served by|WP Fastest Cache file was created/i', substr( $body, -3000 ) . substr( $body, 0, 3000 ) );
	}

	/**
	 * OPcache state: true/false, or null when it cannot be read.
	 *
	 * @return bool|null
	 */
	private function detect_opcache() {
		if ( function_exists( 'opcache_get_status' ) && ! in_array( 'opcache_get_status', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
			$status = opcache_get_status( false );
			if ( is_array( $status ) ) {
				return ! empty( $status['opcache_enabled'] );
			}
		}
		$enabled = ini_get( 'opcache.enable' );
		if ( false === $enabled ) {
			return extension_loaded( 'Zend OPcache' ) ? null : false;
		}
		return (bool) $enabled;
	}

	/**
	 * Cron events more than 15 minutes late.
	 *
	 * @return int
	 */
	private function overdue_cron_events(): int {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$late  = 0;
		$limit = time() - 15 * MINUTE_IN_SECONDS;
		foreach ( (array) $crons as $timestamp => $hooks ) {
			if ( (int) $timestamp < $limit ) {
				foreach ( (array) $hooks as $events ) {
					$late += count( (array) $events );
				}
			}
		}
		return $late;
	}

	/**
	 * Item builder.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $value   Display value.
	 * @param string $verdict Verdict.
	 * @param string $advice  Advice.
	 * @return array
	 */
	private static function item( string $id, string $label, string $value, string $verdict, string $advice ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'value'   => $value,
			'verdict' => $verdict,
			'advice'  => $advice,
		);
	}

	/**
	 * PHP version.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function php_item( array $f ): array {
		$v = (string) $f['php_version'];
		if ( version_compare( $v, '8.1', '>=' ) ) {
			return self::item( 'php_version', __( 'PHP version', 'seo-console' ), $v, 'good', __( 'A modern PHP version. Keep it updated when your host offers a newer one.', 'seo-console' ) );
		}
		if ( version_compare( $v, '7.4', '>=' ) ) {
			return self::item( 'php_version', __( 'PHP version', 'seo-console' ), $v, 'needs-work', __( 'This PHP version no longer gets security updates and is slower. Ask your host (or use the hosting control panel) to switch to PHP 8.1 or newer; it alone often makes the site noticeably faster. Test the site afterwards.', 'seo-console' ) );
		}
		return self::item( 'php_version', __( 'PHP version', 'seo-console' ), $v, 'poor', __( 'This PHP version is very old and unsafe. Upgrade to PHP 8.1 or newer as soon as possible.', 'seo-console' ) );
	}

	/**
	 * Memory limit.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function memory_item( array $f ): array {
		$raw   = trim( (string) $f['memory_limit'] );
		$bytes = '-1' === $raw ? -1 : wp_convert_hr_to_bytes( $raw );
		$label = __( 'PHP memory limit', 'seo-console' );
		if ( -1 === $bytes || $bytes >= 256 * MB_IN_BYTES ) {
			return self::item( 'memory_limit', $label, '-1' === $raw ? __( 'unlimited', 'seo-console' ) : $raw, 'good', __( 'Enough memory for WordPress, page builders and WooCommerce.', 'seo-console' ) );
		}
		if ( $bytes >= 128 * MB_IN_BYTES ) {
			return self::item( 'memory_limit', $label, $raw, 'needs-work', __( 'Enough for a simple site. Sites with page builders or WooCommerce work better with 256M; ask your host to raise memory_limit.', 'seo-console' ) );
		}
		return self::item( 'memory_limit', $label, $raw, 'poor', __( 'Too little memory: pages may fail with "memory exhausted" errors. Ask your host to raise memory_limit to at least 256M.', 'seo-console' ) );
	}

	/**
	 * Max execution time.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function time_item( array $f ): array {
		$t     = (int) $f['max_execution_time'];
		$label = __( 'Max execution time', 'seo-console' );
		if ( 0 === $t || $t >= 60 ) {
			return self::item( 'max_execution_time', $label, 0 === $t ? __( 'no limit', 'seo-console' ) : $t . ' s', 'good', __( 'Long tasks (imports, backups, speed tests) have enough time.', 'seo-console' ) );
		}
		if ( $t >= 30 ) {
			return self::item( 'max_execution_time', $label, $t . ' s', 'needs-work', __( 'Fine for normal pages. SEO Console runs heavy work in small background batches, but imports and backups may need 60 s or more.', 'seo-console' ) );
		}
		return self::item( 'max_execution_time', $label, $t . ' s', 'poor', __( 'Very short. Background tasks and updates may be cut off. Ask your host to raise max_execution_time to at least 60.', 'seo-console' ) );
	}

	/**
	 * OPcache.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function opcache_item( array $f ): array {
		$label = __( 'OPcache', 'seo-console' );
		if ( null === $f['opcache'] ) {
			return self::item( 'opcache', $label, __( 'unknown', 'seo-console' ), 'unknown', __( 'The host does not allow reading the OPcache status. Ask them whether OPcache is enabled.', 'seo-console' ) );
		}
		return $f['opcache']
			? self::item( 'opcache', $label, __( 'enabled', 'seo-console' ), 'good', __( 'PHP code is kept compiled in memory, which makes every request faster.', 'seo-console' ) )
			: self::item( 'opcache', $label, __( 'disabled', 'seo-console' ), 'poor', __( 'PHP recompiles WordPress on every request. Ask your host to enable OPcache: it is free and makes uncached pages much faster.', 'seo-console' ) );
	}

	/**
	 * Persistent object cache.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function object_cache_item( array $f ): array {
		$label = __( 'Persistent object cache', 'seo-console' );
		if ( $f['ext_object_cache'] ) {
			return self::item( 'object_cache', $label, __( 'active', 'seo-console' ), 'good', __( 'Database results are cached in memory (Redis/Memcached).', 'seo-console' ) );
		}
		$advice = $f['redis_available']
			? __( 'Not active, but the server has a Redis/Memcached PHP extension. Turn on the object cache in your cache plugin (or install the host\'s recommended object-cache plugin) to speed up uncached pages, the admin and WooCommerce.', 'seo-console' )
			: __( 'Not active. If your host offers Redis or Memcached, turning it on speeds up uncached pages, the admin and WooCommerce. Not critical for small sites with page caching.', 'seo-console' );
		return self::item( 'object_cache', $label, __( 'not active', 'seo-console' ), 'needs-work', $advice );
	}

	/**
	 * Server software.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function server_item( array $f ): array {
		$software = (string) $f['server_software'];
		if ( '' === $software ) {
			return self::item( 'server_software', __( 'Web server', 'seo-console' ), __( 'unknown', 'seo-console' ), 'unknown', __( 'The server software could not be detected from this request.', 'seo-console' ) );
		}
		$advice = false !== stripos( $software, 'litespeed' )
			? __( 'LiteSpeed server: the free LiteSpeed Cache plugin gives the best page caching here.', 'seo-console' )
			: __( 'All common web servers work well with WordPress when page caching is on.', 'seo-console' );
		return self::item( 'server_software', __( 'Web server', 'seo-console' ), substr( $software, 0, 100 ), 'good', $advice );
	}

	/**
	 * HTTPS.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function https_item( array $f ): array {
		return $f['https']
			? self::item( 'https', __( 'HTTPS', 'seo-console' ), __( 'yes', 'seo-console' ), 'good', __( 'The site uses HTTPS, which Google expects and which enables HTTP/2 and HTTP/3.', 'seo-console' ) )
			: self::item( 'https', __( 'HTTPS', 'seo-console' ), __( 'no', 'seo-console' ), 'poor', __( 'The site address does not use HTTPS. Browsers mark it "Not secure" and HTTP/2/3 are unavailable. Install a free SSL certificate (most hosts offer Let\'s Encrypt) and change the site address to https://.', 'seo-console' ) );
	}

	/**
	 * HTTP version.
	 *
	 * @param array      $f        Facts.
	 * @param array|null $loopback Loopback.
	 * @return array
	 */
	private function http_version_item( array $f, $loopback ): array {
		$label    = __( 'HTTP version', 'seo-console' );
		$protocol = strtoupper( (string) $f['server_protocol'] );
		$http3    = is_array( $loopback ) && ! empty( $loopback['http3'] );
		if ( $http3 ) {
			return self::item( 'http_version', $label, 'HTTP/3', 'good', __( 'The server offers HTTP/3, the fastest protocol for mobile visitors.', 'seo-console' ) );
		}
		if ( preg_match( '#HTTP/(2|3)#', $protocol, $m ) ) {
			return self::item( 'http_version', $label, 'HTTP/' . $m[1], 'good', __( 'The server uses HTTP/2 or newer, so files load in parallel.', 'seo-console' ) );
		}
		if ( 'HTTP/1.1' === $protocol || 'HTTP/1.0' === $protocol ) {
			return self::item( 'http_version', $label, $protocol, 'needs-work', __( 'This request used HTTP/1.1. If a CDN or proxy sits in front of the site this may be fine; otherwise ask your host to enable HTTP/2 (and HTTP/3).', 'seo-console' ) );
		}
		return self::item( 'http_version', $label, __( 'unknown', 'seo-console' ), 'unknown', __( 'Open this page in a browser to detect the HTTP version.', 'seo-console' ) );
	}

	/**
	 * Compression.
	 *
	 * @param array|null $loopback Loopback.
	 * @return array
	 */
	private function compression_item( $loopback ): array {
		$label = __( 'Text compression', 'seo-console' );
		if ( ! is_array( $loopback ) || ! $loopback['ok'] ) {
			return self::item( 'compression', $label, __( 'unknown', 'seo-console' ), 'unknown', self::loopback_advice() );
		}
		switch ( $loopback['compression'] ) {
			case 'br':
			case 'zstd':
				return self::item( 'compression', $label, 'br' === $loopback['compression'] ? 'Brotli' : 'Zstandard', 'good', __( 'Pages are compressed with a modern algorithm.', 'seo-console' ) );
			case 'gzip':
			case 'deflate':
				return self::item( 'compression', $label, 'Gzip', 'good', __( 'Pages are compressed. Brotli would be about 15% smaller; ask your host whether it is available.', 'seo-console' ) );
			default:
				return self::item( 'compression', $label, __( 'none', 'seo-console' ), 'poor', __( 'Pages are sent uncompressed, often 3–5 times bigger than needed. Ask your host to enable Gzip or Brotli, or turn on compression in your cache plugin.', 'seo-console' ) );
		}
	}

	/**
	 * Page cache seen in headers.
	 *
	 * @param array|null $loopback Loopback.
	 * @return array
	 */
	private function page_cache_item( $loopback ): array {
		$label = __( 'Page cache (server response)', 'seo-console' );
		if ( ! is_array( $loopback ) || ! $loopback['ok'] ) {
			return self::item( 'page_cache', $label, __( 'unknown', 'seo-console' ), 'unknown', self::loopback_advice() );
		}
		$header = '' !== $loopback['cache_header'] ? $loopback['cache_header'] : __( 'page footprint', 'seo-console' );
		switch ( $loopback['cache'] ) {
			case 'hit':
				/* translators: %s: header name */
				return self::item( 'page_cache', $label, sprintf( __( 'HIT (%s)', 'seo-console' ), $header ), 'good', __( 'The home page is served from cache.', 'seo-console' ) );
			case 'miss':
				/* translators: %s: header name */
				return self::item( 'page_cache', $label, sprintf( __( 'MISS (%s)', 'seo-console' ), $header ), 'needs-work', __( 'A cache is present but the home page was not served from it in 3 requests. Check that the cache is enabled for the home page and not bypassed (cookies, query strings, logged-in rules).', 'seo-console' ) );
			default:
				return self::item( 'page_cache', $label, __( 'not detected', 'seo-console' ), 'needs-work', __( 'No page-cache signal was found in the response. If you use a cache plugin, make sure page caching is on; otherwise install one (LiteSpeed Cache on LiteSpeed servers, or the plugin your host recommends).', 'seo-console' ) );
		}
	}

	/**
	 * Server response time.
	 *
	 * @param array|null $loopback Loopback.
	 * @return array
	 */
	private function ttfb_item( $loopback ): array {
		$label = __( 'Server response time (TTFB)', 'seo-console' );
		if ( ! is_array( $loopback ) || ! $loopback['ok'] || null === $loopback['ttfb_median'] ) {
			return self::item( 'ttfb', $label, __( 'unknown', 'seo-console' ), 'unknown', self::loopback_advice() );
		}
		$ms = (int) $loopback['ttfb_median'];
		/* translators: %d: milliseconds */
		$value = sprintf( __( '%d ms (median of 3)', 'seo-console' ), $ms );
		if ( $ms <= 400 ) {
			return self::item( 'ttfb', $label, $value, 'good', __( 'The server answers quickly.', 'seo-console' ) );
		}
		if ( $ms <= 1000 ) {
			return self::item( 'ttfb', $label, $value, 'needs-work', __( 'The server is a bit slow to answer, even measured from the server itself. Make sure page caching works, use PHP 8.1+, and check autoloaded options in the Database section.', 'seo-console' ) );
		}
		return self::item( 'ttfb', $label, $value, 'poor', __( 'The server is slow to answer, even measured from the server itself. Check page caching first; if cached pages are still slow, the hosting plan is likely overloaded or too small.', 'seo-console' ) );
	}

	/**
	 * WP-Cron.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function cron_item( array $f ): array {
		$label   = __( 'Scheduled tasks (WP-Cron)', 'seo-console' );
		$overdue = (int) $f['overdue_cron'];
		if ( $f['disable_wp_cron'] ) {
			if ( 0 === $overdue ) {
				return self::item( 'wp_cron', $label, __( 'server cron', 'seo-console' ), 'good', __( 'WP-Cron is triggered by a real server cron job, which is the most reliable setup.', 'seo-console' ) );
			}
			/* translators: %d: number of late events */
			return self::item( 'wp_cron', $label, sprintf( __( 'disabled, %d tasks late', 'seo-console' ), $overdue ), 'poor', __( 'DISABLE_WP_CRON is set but no server cron job seems to run it, so scheduled tasks (backups, emails, SEO Console tests) are not running. Ask your host to add a cron job that calls wp-cron.php every 5 minutes.', 'seo-console' ) );
		}
		if ( $overdue > 0 ) {
			/* translators: %d: number of late events */
			return self::item( 'wp_cron', $label, sprintf( __( 'visitor-triggered, %d tasks late', 'seo-console' ), $overdue ), 'needs-work', __( 'Scheduled tasks run late. WP-Cron only runs when someone visits, and cached pages may not trigger it. Ask your host for a real cron job every 5 minutes and set DISABLE_WP_CRON in wp-config.php.', 'seo-console' ) );
		}
		return self::item( 'wp_cron', $label, __( 'visitor-triggered', 'seo-console' ), 'good', __( 'Scheduled tasks are running on time. For busy or fully cached sites, a real server cron job is even more reliable.', 'seo-console' ) );
	}

	/**
	 * WordPress version.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function wp_version_item( array $f ): array {
		$label = __( 'WordPress version', 'seo-console' );
		if ( ! empty( $f['core_update'] ) ) {
			/* translators: %s: version */
			return self::item( 'wp_version', $label, (string) $f['wp_version'], 'needs-work', sprintf( __( 'WordPress %s is available. Updates include performance and security fixes; back up and update.', 'seo-console' ), $f['core_update'] ) );
		}
		return self::item( 'wp_version', $label, (string) $f['wp_version'], 'good', __( 'WordPress is up to date.', 'seo-console' ) );
	}

	/**
	 * Active plugins.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private function plugins_item( array $f ): array {
		$n     = (int) $f['active_plugins'];
		$label = __( 'Active plugins', 'seo-console' );
		if ( $n <= 30 ) {
			return self::item( 'active_plugins', $label, (string) $n, 'good', __( 'A reasonable number of plugins. What they do matters more than how many there are.', 'seo-console' ) );
		}
		if ( $n <= 50 ) {
			return self::item( 'active_plugins', $label, (string) $n, 'needs-work', __( 'Quite a few plugins. Deactivate and delete the ones you no longer use; each one can add code to every page.', 'seo-console' ) );
		}
		return self::item( 'active_plugins', $label, (string) $n, 'poor', __( 'Many active plugins. Review them: remove unused ones and replace overlapping ones (for example two cache or two SEO plugins).', 'seo-console' ) );
	}

	/**
	 * Advice when the loopback request failed.
	 *
	 * @return string
	 */
	private static function loopback_advice(): string {
		return __( 'The site could not request its own home page (a "loopback" request). Some hosts or security plugins block this; it also affects WordPress Site Health and scheduled tasks. Ask your host to allow the server to reach its own domain.', 'seo-console' );
	}
}
