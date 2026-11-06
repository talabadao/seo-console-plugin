<?php
/**
 * Chrome UX Report (CrUX) API client: current 28-day record and 6-month history.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CrUX record result:
 *
 *     [
 *       'scope'       => 'origin'|'url',
 *       'id'          => origin or URL asked for,
 *       'form_factor' => 'PHONE'|'DESKTOP'|'ALL',
 *       'has_data'    => bool,           // false = not enough real-user data (CrUX 404), not an error
 *       'period'      => [ 'first' => 'Y-m-d', 'last' => 'Y-m-d' ]|null,
 *       'metrics'     => [ lcp|inp|cls|fcp|ttfb => [ 'p75' => int|float, 'rating' => good|ni|poor, 'good' => 0–1, 'ni' => 0–1, 'poor' => 0–1 ] ],
 *       'assessment'  => 'pass'|'fail'|null, // Core Web Vitals
 *     ]
 *
 * CrUX history result:
 *
 *     [
 *       'scope', 'id', 'form_factor', 'has_data',
 *       'periods' => [ 'Y-m-d' (last day of each 28-day window), … ] oldest first,
 *       'metrics' => [ lcp|… => [ 'p75' => (int|float|null)[], 'good' => (float|null)[], 'ni' => [], 'poor' => [] ] ],
 *     ]
 */
class CruxClient {

	const ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:';

	const FORM_FACTORS = array( 'PHONE', 'DESKTOP', 'TABLET', 'ALL' );

	/** CrUX metric name → our key. */
	const METRICS = array(
		'largest_contentful_paint'        => 'lcp',
		'interaction_to_next_paint'       => 'inp',
		'cumulative_layout_shift'         => 'cls',
		'first_contentful_paint'          => 'fcp',
		'experimental_time_to_first_byte' => 'ttfb',
		'time_to_first_byte'              => 'ttfb',
	);

	/**
	 * Google API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Google API key (the CrUX API always needs one).
	 * @param int         $timeout Timeout in seconds.
	 */
	public function __construct( $api_key, int $timeout = 20 ) {
		$this->api_key = is_string( $api_key ) ? trim( $api_key ) : '';
		$this->timeout = max( 5, $timeout );
	}

	/**
	 * Current 28-day record.
	 *
	 * @param string $target      Origin (https://example.com) or full page URL.
	 * @param string $scope       'origin' or 'url'.
	 * @param string $form_factor 'PHONE', 'DESKTOP', 'TABLET' or 'ALL' (all devices).
	 * @return array|WP_Error
	 */
	public function query( string $target, string $scope = 'origin', string $form_factor = 'PHONE' ) {
		$body = $this->request_body( $target, $scope, $form_factor );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$data = $this->post( 'queryRecord', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::parse_record( $data, $scope, $body[ $scope ], $form_factor );
	}

	/**
	 * History (weekly 28-day windows; 25 periods ≈ 6 months).
	 *
	 * @param string $target      Origin or URL.
	 * @param string $scope       'origin' or 'url'.
	 * @param string $form_factor Form factor.
	 * @param int    $periods     Number of collection periods (1–40).
	 * @return array|WP_Error
	 */
	public function history( string $target, string $scope = 'origin', string $form_factor = 'PHONE', int $periods = 25 ) {
		$body = $this->request_body( $target, $scope, $form_factor );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$body['collectionPeriodCount'] = max( 1, min( 40, $periods ) );
		$data                          = $this->post( 'queryHistoryRecord', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::parse_history( $data, $scope, $body[ $scope ], $form_factor );
	}

	/**
	 * Validates input and builds the JSON body.
	 *
	 * @param string $target      Origin or URL.
	 * @param string $scope       Scope.
	 * @param string $form_factor Form factor.
	 * @return array|WP_Error
	 */
	private function request_body( string $target, string $scope, string $form_factor ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'seoc_crux_no_key', __( 'Real-user data (CrUX) needs a Google API key. Add one in SEO Console settings.', 'seo-console' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $scope, array( 'origin', 'url' ), true ) || ! in_array( $form_factor, self::FORM_FACTORS, true ) ) {
			return new WP_Error( 'seoc_crux_invalid', __( 'Invalid real-user data request.', 'seo-console' ), array( 'status' => 400 ) );
		}
		if ( ! PsiClient::is_testable_url( $target ) ) {
			return new WP_Error( 'seoc_crux_invalid', __( 'Invalid address for real-user data.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$value = 'origin' === $scope ? PsiParser::origin( $target ) : $target;
		$body  = array( $scope => $value );
		if ( 'ALL' !== $form_factor ) {
			$body['formFactor'] = $form_factor;
		}
		return $body;
	}

	/**
	 * POSTs to the API. 404 → empty marker array, other failures → seoc_crux_* errors.
	 *
	 * @param string $method queryRecord|queryHistoryRecord.
	 * @param array  $body   Body.
	 * @return array|WP_Error Decoded body, or [ '__no_data' => true ].
	 */
	private function post( string $method, array $body ) {
		$response = wp_remote_post(
			add_query_arg( 'key', rawurlencode( $this->api_key ), self::ENDPOINT . $method ),
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		return self::handle_response( $response );
	}

	/**
	 * Maps an HTTP response to data or a typed error.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	public static function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seoc_crux_network', __( 'Could not reach the Chrome UX Report API. Try again later.', 'seo-console' ), array( 'status' => 504 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return array( '__no_data' => true );
		}
		if ( 200 === $code && is_array( $data ) && isset( $data['record'] ) ) {
			return $data;
		}
		$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
		if ( 429 === $code ) {
			return new WP_Error( 'seoc_crux_quota', __( 'The Chrome UX Report quota is used up for now. It will retry later.', 'seo-console' ), array( 'status' => 429 ) );
		}
		if ( 403 === $code || false !== stripos( $message, 'API key' ) ) {
			return new WP_Error( 'seoc_crux_auth', __( 'Google rejected the API key for the Chrome UX Report API. Make sure the "Chrome UX Report API" is enabled for the key\'s project.', 'seo-console' ), array( 'status' => 403 ) );
		}
		if ( 400 === $code ) {
			return new WP_Error( 'seoc_crux_invalid', __( 'The Chrome UX Report API did not accept this address.', 'seo-console' ), array( 'status' => 400 ) );
		}
		return new WP_Error(
			'seoc_crux_http',
			__( 'The Chrome UX Report API returned an error. Try again later.', 'seo-console' ),
			array(
				'status'    => $code >= 400 ? $code : 502,
				'http_code' => $code,
			)
		);
	}

	/**
	 * Parses a queryRecord response.
	 *
	 * @param array  $data        Decoded response (or no-data marker).
	 * @param string $scope       Scope.
	 * @param string $id          Target.
	 * @param string $form_factor Form factor.
	 * @return array
	 */
	public static function parse_record( array $data, string $scope, string $id, string $form_factor ): array {
		$result = array(
			'scope'       => $scope,
			'id'          => $id,
			'form_factor' => $form_factor,
			'has_data'    => false,
			'period'      => null,
			'metrics'     => array(),
			'assessment'  => null,
		);
		if ( ! empty( $data['__no_data'] ) || empty( $data['record']['metrics'] ) || ! is_array( $data['record']['metrics'] ) ) {
			return $result;
		}
		$ratings = array();
		foreach ( $data['record']['metrics'] as $name => $metric ) {
			if ( ! isset( self::METRICS[ $name ] ) || ! is_array( $metric ) || ! isset( $metric['percentiles']['p75'] ) ) {
				continue;
			}
			$key = self::METRICS[ $name ];
			$p75 = self::number( $metric['percentiles']['p75'], $key );
			if ( null === $p75 ) {
				continue;
			}
			$hist = array( null, null, null );
			if ( isset( $metric['histogram'] ) && is_array( $metric['histogram'] ) ) {
				foreach ( array_slice( array_values( $metric['histogram'] ), 0, 3 ) as $i => $bin ) {
					$hist[ $i ] = isset( $bin['density'] ) ? self::density( $bin['density'] ) : null;
				}
			}
			$ratings[ $key ]           = Thresholds::rate( $key, $p75 );
			$result['metrics'][ $key ] = array(
				'p75'    => $p75,
				'rating' => $ratings[ $key ],
				'good'   => $hist[0],
				'ni'     => $hist[1],
				'poor'   => $hist[2],
			);
		}
		if ( isset( $data['record']['collectionPeriod'] ) && is_array( $data['record']['collectionPeriod'] ) ) {
			$result['period'] = array(
				'first' => self::date( isset( $data['record']['collectionPeriod']['firstDate'] ) ? $data['record']['collectionPeriod']['firstDate'] : null ),
				'last'  => self::date( isset( $data['record']['collectionPeriod']['lastDate'] ) ? $data['record']['collectionPeriod']['lastDate'] : null ),
			);
		}
		$result['has_data']   = ! empty( $result['metrics'] );
		$result['assessment'] = Thresholds::assess( $ratings );
		return $result;
	}

	/**
	 * Parses a queryHistoryRecord response.
	 *
	 * @param array  $data        Decoded response (or no-data marker).
	 * @param string $scope       Scope.
	 * @param string $id          Target.
	 * @param string $form_factor Form factor.
	 * @return array
	 */
	public static function parse_history( array $data, string $scope, string $id, string $form_factor ): array {
		$result = array(
			'scope'       => $scope,
			'id'          => $id,
			'form_factor' => $form_factor,
			'has_data'    => false,
			'periods'     => array(),
			'metrics'     => array(),
		);
		if ( ! empty( $data['__no_data'] ) || empty( $data['record']['metrics'] ) || ! is_array( $data['record']['metrics'] ) ) {
			return $result;
		}
		if ( isset( $data['record']['collectionPeriods'] ) && is_array( $data['record']['collectionPeriods'] ) ) {
			foreach ( $data['record']['collectionPeriods'] as $period ) {
				$result['periods'][] = self::date( isset( $period['lastDate'] ) ? $period['lastDate'] : null );
			}
		}
		foreach ( $data['record']['metrics'] as $name => $metric ) {
			if ( ! isset( self::METRICS[ $name ] ) || ! is_array( $metric ) ) {
				continue;
			}
			$key    = self::METRICS[ $name ];
			$series = array(
				'p75'  => array(),
				'good' => array(),
				'ni'   => array(),
				'poor' => array(),
			);
			if ( isset( $metric['percentilesTimeseries']['p75s'] ) && is_array( $metric['percentilesTimeseries']['p75s'] ) ) {
				foreach ( $metric['percentilesTimeseries']['p75s'] as $value ) {
					$series['p75'][] = self::number( $value, $key );
				}
			}
			if ( isset( $metric['histogramTimeseries'] ) && is_array( $metric['histogramTimeseries'] ) ) {
				foreach ( array( 'good', 'ni', 'poor' ) as $i => $bucket ) {
					$bin = isset( $metric['histogramTimeseries'][ $i ]['densities'] ) && is_array( $metric['histogramTimeseries'][ $i ]['densities'] )
						? $metric['histogramTimeseries'][ $i ]['densities']
						: array();
					foreach ( $bin as $density ) {
						$series[ $bucket ][] = self::density( $density );
					}
				}
			}
			$result['metrics'][ $key ] = $series;
		}
		$result['has_data'] = ! empty( $result['metrics'] );
		return $result;
	}

	/**
	 * Numeric p75 (CLS comes as a string, missing periods as null/"NaN").
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Metric key.
	 * @return int|float|null
	 */
	private static function number( $value, string $key ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return 'cls' === $key ? round( (float) $value, 2 ) : (int) round( (float) $value );
	}

	/**
	 * Density 0–1 or null ("NaN" for missing periods).
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function density( $value ) {
		return is_numeric( $value ) ? round( (float) $value, 4 ) : null;
	}

	/**
	 * CrUX {year, month, day} → Y-m-d.
	 *
	 * @param mixed $date Date object.
	 * @return string|null
	 */
	private static function date( $date ) {
		if ( ! is_array( $date ) || empty( $date['year'] ) || empty( $date['month'] ) || empty( $date['day'] ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day'] );
	}
}
