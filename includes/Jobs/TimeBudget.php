<?php
/**
 * Time budget for one background batch (see docs/ARCHITECTURE.md §8).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Usage: $budget = new TimeBudget(); while ( $budget->has_time( 2 ) && $item = next() ) { ... } then re-enqueue.
 */
final class TimeBudget {

	const DEFAULT_SECONDS = 20;

	/**
	 * Start (microtime).
	 *
	 * @var float
	 */
	private $start;

	/**
	 * Budget in seconds.
	 *
	 * @var float
	 */
	private $seconds;

	/**
	 * Constructor. The budget never exceeds 60% of max_execution_time when that is set.
	 *
	 * @param float|null $seconds Budget (default 20 s, filter `seoc_time_budget`).
	 */
	public function __construct( ?float $seconds = null ) {
		$this->start = microtime( true );
		if ( null === $seconds ) {
			/**
			 * Filters the default time budget of a background batch, in seconds.
			 *
			 * @param float $seconds Seconds.
			 */
			$seconds = (float) apply_filters( 'seoc_time_budget', (float) self::DEFAULT_SECONDS );
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$seconds = min( $seconds, $max * 0.6 );
		}
		$this->seconds = max( 0.0, $seconds );
	}

	/**
	 * Seconds since start.
	 */
	public function elapsed(): float {
		return microtime( true ) - $this->start;
	}

	/**
	 * Seconds left (never negative).
	 */
	public function remaining(): float {
		return max( 0.0, $this->seconds - $this->elapsed() );
	}

	/**
	 * Whether the budget is used up.
	 */
	public function exceeded(): bool {
		return $this->remaining() <= 0.0;
	}

	/**
	 * Whether at least $needed seconds are left.
	 *
	 * @param float $needed Seconds the next step needs.
	 */
	public function has_time( float $needed = 1.0 ): bool {
		return $this->remaining() >= $needed;
	}

	/**
	 * Budget in seconds.
	 */
	public function seconds(): float {
		return $this->seconds;
	}
}
