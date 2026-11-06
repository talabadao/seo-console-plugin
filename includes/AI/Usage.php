<?php
/**
 * AI token usage, estimated cost and the monthly budget (see docs/ARCHITECTURE.md §10).
 *
 * @package SEOConsole
 */

namespace SEOConsole\AI;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One row per Claude call in `seoc_ai_usage`. Months are calendar months in UTC.
 */
final class Usage {

	/**
	 * USD per 1M tokens: [ input, output ]. Cache reads cost 10% of input, cache writes 125% of input.
	 */
	const PRICES = array(
		'claude-opus-5-5'   => array( 4.0, 20.0 ),
		'claude-sonnet-5-5' => array( 2.0, 10.0 ),
		'claude-haiku-5-5'  => array( 0.10, 0.50 ),
	);

	const CACHE_READ_FACTOR = 0.1;

	const CACHE_WRITE_FACTOR = 1.25;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Estimated USD cost of one response's usage block.
	 *
	 * @param string $model Model id (unknown models are priced like Opus).
	 * @param array  $usage input_tokens, output_tokens, cache_creation_input_tokens, cache_read_input_tokens.
	 */
	public static function estimate_cost( string $model, array $usage ): float {
		$prices = self::PRICES['claude-opus-5-5'];
		foreach ( self::PRICES as $id => $candidate ) {
			if ( 0 === strpos( $model, $id ) ) {
				$prices = $candidate;
				break;
			}
		}
		$input  = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$output = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$write  = isset( $usage['cache_creation_input_tokens'] ) ? (int) $usage['cache_creation_input_tokens'] : 0;
		$read   = isset( $usage['cache_read_input_tokens'] ) ? (int) $usage['cache_read_input_tokens'] : 0;
		$cost   = ( $input * $prices[0] + $write * $prices[0] * self::CACHE_WRITE_FACTOR + $read * $prices[0] * self::CACHE_READ_FACTOR + $output * $prices[1] ) / 1000000;
		return round( $cost, 6 );
	}

	/**
	 * Records one call.
	 *
	 * @param string $model      Model that served the call.
	 * @param array  $usage      Usage block of the response.
	 * @param array  $meta       module, context, status ('ok'|'refusal'|'truncated'|'error'), request_id.
	 * @return float Estimated cost in USD.
	 */
	public function record( string $model, array $usage, array $meta = array() ): float {
		global $wpdb;
		$cost = self::estimate_cost( $model, $usage );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			Schema::table( 'ai_usage' ),
			array(
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
				'model'                 => substr( sanitize_text_field( $model ), 0, 80 ),
				'module'                => substr( sanitize_key( isset( $meta['module'] ) ? (string) $meta['module'] : '' ), 0, 40 ),
				'context'               => substr( sanitize_text_field( isset( $meta['context'] ) ? (string) $meta['context'] : '' ), 0, 80 ),
				'status'                => substr( sanitize_key( isset( $meta['status'] ) ? (string) $meta['status'] : 'ok' ), 0, 20 ),
				'input_tokens'          => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
				'output_tokens'         => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
				'cache_creation_tokens' => isset( $usage['cache_creation_input_tokens'] ) ? (int) $usage['cache_creation_input_tokens'] : 0,
				'cache_read_tokens'     => isset( $usage['cache_read_input_tokens'] ) ? (int) $usage['cache_read_input_tokens'] : 0,
				'cost_usd'              => $cost,
				'request_id'            => isset( $meta['request_id'] ) ? substr( sanitize_text_field( (string) $meta['request_id'] ), 0, 100 ) : null,
			)
		);
		return $cost;
	}

	/**
	 * Totals for a month.
	 *
	 * @param string|null $month 'YYYY-MM' (UTC), default current month.
	 * @return array{month: string, calls: int, input_tokens: int, output_tokens: int, cost_usd: float, budget_usd: int, remaining_usd: float}
	 */
	public function month_summary( ?string $month = null ): array {
		global $wpdb;
		$month = ( null !== $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ) ? $month : gmdate( 'Y-m' );
		$start = $month . '-01 00:00:00';
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 month' ) );
		$table = Schema::table( 'ai_usage' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens + cache_creation_tokens + cache_read_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens, COALESCE(SUM(cost_usd),0) AS cost FROM {$table} WHERE created_at >= %s AND created_at < %s", $start, $end ), ARRAY_A );
		$cost   = $row ? round( (float) $row['cost'], 4 ) : 0.0;
		$budget = $this->budget();
		return array(
			'month'         => $month,
			'calls'         => $row ? (int) $row['calls'] : 0,
			'input_tokens'  => $row ? (int) $row['input_tokens'] : 0,
			'output_tokens' => $row ? (int) $row['output_tokens'] : 0,
			'cost_usd'      => $cost,
			'budget_usd'    => $budget,
			'remaining_usd' => max( 0.0, round( $budget - $cost, 4 ) ),
		);
	}

	/**
	 * Estimated cost of the current month.
	 */
	public function month_cost(): float {
		$summary = $this->month_summary();
		return $summary['cost_usd'];
	}

	/**
	 * Monthly budget in USD (0 = AI off).
	 */
	public function budget(): int {
		return (int) $this->settings->get( Settings::GENERAL, 'ai_monthly_budget_usd' );
	}

	/**
	 * Whether the monthly budget allows another call.
	 */
	public function within_budget(): bool {
		$budget = $this->budget();
		return $budget > 0 && $this->month_cost() < $budget;
	}

	/**
	 * Deletes rows older than some days (default 400).
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function purge( int $days = 400 ): int {
		global $wpdb;
		$table  = Schema::table( 'ai_usage' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 31, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
