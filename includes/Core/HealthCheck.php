<?php
/**
 * Loopback health check after frontend-affecting changes (see docs/ARCHITECTURE.md §7.4).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

defined( 'ABSPATH' ) || exit;

/**
 * GETs the home page plus up to 3 URLs on the site's own host. Fails on WP_Error, HTTP ≥ 500 or a body containing
 * WordPress's critical-error message. A timeout where loopback is known to be blocked (`seoc_loopback_ok` = 'no') is
 * reported as inconclusive instead of failure.
 */
final class HealthCheck {

	const LOOPBACK_OPTION = 'seoc_loopback_ok';

	const MAX_EXTRA_URLS = 3;

	const TIMEOUT = 15;

	/**
	 * Runs the check.
	 *
	 * @param string[] $urls Extra URLs on this site (other hosts are ignored).
	 * @return array{ok: bool, inconclusive: bool, checks: array<int, array<string, mixed>>}
	 */
	public function run( array $urls = array() ): array {
		$targets = $this->targets( $urls );

		/**
		 * Short-circuits the health check (tests, hosts with a custom check). Return an array like run()'s result.
		 *
		 * @param array|null $result  Null to run normally.
		 * @param string[]   $targets URLs that would be checked.
		 */
		$pre = apply_filters( 'seoc_health_check_pre', null, $targets );
		if ( is_array( $pre ) && isset( $pre['ok'] ) ) {
			return array(
				'ok'           => (bool) $pre['ok'],
				'inconclusive' => ! empty( $pre['inconclusive'] ),
				'checks'       => isset( $pre['checks'] ) && is_array( $pre['checks'] ) ? $pre['checks'] : array(),
			);
		}

		$checks       = array();
		$ok           = true;
		$inconclusive = false;
		foreach ( $targets as $url ) {
			$check    = $this->check_url( $url );
			$checks[] = $check;
			if ( 'fail' === $check['status'] ) {
				$ok = false;
			} elseif ( 'inconclusive' === $check['status'] ) {
				$inconclusive = true;
			}
		}
		return array(
			'ok'           => $ok,
			'inconclusive' => $ok && $inconclusive,
			'checks'       => $checks,
		);
	}

	/**
	 * Home URL plus up to 3 same-host URLs.
	 *
	 * @param string[] $urls Extra URLs.
	 * @return string[]
	 */
	public function targets( array $urls ): array {
		$home    = home_url( '/' );
		$host    = wp_parse_url( $home, PHP_URL_HOST );
		$targets = array( $home );
		foreach ( $urls as $url ) {
			if ( count( $targets ) > self::MAX_EXTRA_URLS ) {
				break;
			}
			if ( ! is_string( $url ) ) {
				continue;
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host || in_array( $url, $targets, true ) ) {
				continue;
			}
			$targets[] = $url;
		}
		return $targets;
	}

	/**
	 * Checks one URL.
	 *
	 * @param string $url URL on this site.
	 * @return array{url: string, status: string, code: int, error: string, time_ms: int}
	 */
	private function check_url( string $url ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			add_query_arg( 'seoc_hc', wp_generate_password( 10, false ), $url ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		$time  = (int) round( ( microtime( true ) - $start ) * 1000 );
		$check = array(
			'url'     => $url,
			'status'  => 'ok',
			'code'    => 0,
			'error'   => '',
			'time_ms' => $time,
		);

		if ( is_wp_error( $response ) ) {
			// A transport error (no HTTP response at all) is only evidence of a broken site when this site's loopback
			// has worked before and now times out: a fatal error still answers with HTTP 500, and refused connections,
			// DNS or TLS failures come from the host, not from a meta/setting change. Hosts that block loopback would
			// otherwise see every approved change undone.
			$check['error'] = $response->get_error_message();
			$is_timeout     = false !== stripos( $check['error'], 'timed out' ) || false !== stripos( $check['error'], 'timeout' );
			if ( $is_timeout && 'yes' === get_option( self::LOOPBACK_OPTION ) ) {
				$check['status'] = 'fail';
			} else {
				$check['status'] = 'inconclusive';
				if ( 'yes' !== get_option( self::LOOPBACK_OPTION ) ) {
					update_option( self::LOOPBACK_OPTION, 'no', false );
				}
			}
			return $check;
		}

		$check['code'] = (int) wp_remote_retrieve_response_code( $response );
		$body          = (string) wp_remote_retrieve_body( $response );
		if ( $check['code'] >= 500 ) {
			$check['status'] = 'fail';
			/* translators: %d: HTTP status code */
			$check['error'] = sprintf( __( 'The page returned HTTP %d.', 'seo-console' ), $check['code'] );
		} elseif ( $this->has_critical_error( $body ) ) {
			$check['status'] = 'fail';
			$check['error']  = __( 'The page shows the WordPress critical error message.', 'seo-console' );
		} elseif ( $check['code'] >= 200 && $check['code'] < 400 && 'yes' !== get_option( self::LOOPBACK_OPTION ) ) {
			update_option( self::LOOPBACK_OPTION, 'yes', false );
		}
		return $check;
	}

	/**
	 * Whether a body contains WordPress's critical-error message (English or the site language).
	 *
	 * @param string $body Response body.
	 */
	private function has_critical_error( string $body ): bool {
		if ( '' === $body ) {
			return false;
		}
		$needles = array(
			'There has been a critical error on this website',
			// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core's own string.
			__( 'There has been a critical error on this website.' ),
		);
		foreach ( $needles as $needle ) {
			$needle = rtrim( $needle, '.' );
			if ( '' !== $needle && false !== strpos( $body, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
