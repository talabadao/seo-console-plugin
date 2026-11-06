<?php
/**
 * Core Web Vitals thresholds (shared by parser, CrUX and recommendations).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Google's published "good" / "poor" boundaries (p75). Milliseconds, CLS unitless.
 */
final class Thresholds {

	const LIMITS = array(
		'lcp'  => array( 2500, 4000 ),
		'inp'  => array( 200, 500 ),
		'cls'  => array( 0.1, 0.25 ),
		'fcp'  => array( 1800, 3000 ),
		'ttfb' => array( 800, 1800 ),
		'tbt'  => array( 200, 600 ),
		'si'   => array( 3400, 5800 ),
	);

	/** Metrics that make up the Core Web Vitals assessment. */
	const CORE = array( 'lcp', 'inp', 'cls' );

	/**
	 * Rates a value: 'good', 'ni' (needs improvement), 'poor', or null when unknown.
	 *
	 * @param string    $metric Metric key.
	 * @param float|int $value  Value.
	 * @return string|null
	 */
	public static function rate( string $metric, $value ) {
		if ( ! isset( self::LIMITS[ $metric ] ) || ! is_numeric( $value ) ) {
			return null;
		}
		list( $good, $poor ) = self::LIMITS[ $metric ];
		$value               = (float) $value;
		if ( $value <= $good ) {
			return 'good';
		}
		return $value <= $poor ? 'ni' : 'poor';
	}

	/**
	 * Core Web Vitals assessment from per-metric ratings: 'pass', 'fail' or null (not enough data).
	 * Passes when LCP, INP and CLS are all good (INP may be missing on low-traffic sites).
	 *
	 * @param array $ratings metric => rating.
	 * @return string|null
	 */
	public static function assess( array $ratings ) {
		if ( empty( $ratings['lcp'] ) || empty( $ratings['cls'] ) ) {
			return null;
		}
		foreach ( self::CORE as $metric ) {
			if ( isset( $ratings[ $metric ] ) && 'good' !== $ratings[ $metric ] ) {
				return 'fail';
			}
		}
		return 'pass';
	}
}
