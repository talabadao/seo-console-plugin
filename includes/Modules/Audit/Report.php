<?php
/**
 * Health score, summaries, run-to-run diffs and the AI hand-off list.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use SEOConsole\Modules\Audit\Rules\Rule;
use SEOConsole\Modules\Audit\Rules\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Reporting on audit runs.
 */
class Report {

	/** Penalty of one page issue; a page's total penalty is capped at 1 (= the page counts as fully broken). */
	const PAGE_WEIGHTS = array(
		'error'   => 0.5,
		'warning' => 0.15,
		'notice'  => 0.03,
	);

	/** Points removed by one site-wide issue (robots.txt, sitemap, loopback). */
	const RUN_WEIGHTS = array(
		'error'   => 15,
		'warning' => 5,
		'notice'  => 1,
	);

	/** Maximum points site-wide issues can remove. */
	const RUN_PENALTY_CAP = 60;

	/** Rule → field the Meta module can suggest with AI. */
	const AI_FIELDS = array(
		'title_missing'       => 'title',
		'title_duplicate'     => 'title',
		'title_too_long'      => 'title',
		'title_too_short'     => 'title',
		'meta_desc_missing'   => 'description',
		'meta_desc_duplicate' => 'description',
		'meta_desc_too_long'  => 'description',
		'meta_desc_too_short' => 'description',
		'img_missing_alt'     => 'image_alt',
	);

	/** @var AuditStore */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param AuditStore|null $store Store.
	 */
	public function __construct( ?AuditStore $store = null ) {
		$this->store = $store ? $store : new AuditStore();
	}

	/**
	 * Health score 0–100.
	 *
	 * Score = 100 × (1 − Σ min(1, page penalty) / pages crawled) − site-wide penalty (capped), clamped to 0–100.
	 * Page penalty = 0.5 per error + 0.15 per warning + 0.03 per notice. Site-wide issue: −15 / −5 / −1.
	 *
	 * @param array[] $issues        Issues: [ 'page_id' => int|null, 'severity' => string ].
	 * @param int     $pages_crawled Pages crawled.
	 */
	public static function score( array $issues, int $pages_crawled ): int {
		$per_page = array();
		$run      = 0.0;
		foreach ( $issues as $issue ) {
			$severity = (string) ( $issue['severity'] ?? 'notice' );
			if ( empty( $issue['page_id'] ) ) {
				$run += isset( self::RUN_WEIGHTS[ $severity ] ) ? self::RUN_WEIGHTS[ $severity ] : 0;
				continue;
			}
			$page_id              = (int) $issue['page_id'];
			$per_page[ $page_id ] = ( isset( $per_page[ $page_id ] ) ? $per_page[ $page_id ] : 0.0 ) + ( isset( self::PAGE_WEIGHTS[ $severity ] ) ? self::PAGE_WEIGHTS[ $severity ] : 0 );
		}
		$page_penalty = 0.0;
		foreach ( $per_page as $penalty ) {
			$page_penalty += min( 1.0, $penalty );
		}
		$pages = max( $pages_crawled, count( $per_page ), 1 );
		$score = 100 * ( 1 - $page_penalty / $pages ) - min( self::RUN_PENALTY_CAP, $run );
		return (int) max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Computes score + summary for a run from its stored issues.
	 *
	 * @param int   $run_id Run id.
	 * @param array $extra  Extra summary keys (robots, sitemap, cross_check…).
	 * @return array Summary (includes 'score').
	 */
	public function build_summary( int $run_id, array $extra = array() ): array {
		$run    = $this->store->get_run( $run_id );
		$keys   = $this->store->issue_keys( $run_id );
		$states = $this->store->count_pages_by_state( $run_id );
		$rules  = Rules::all();

		$crawled = (int) ( $states['crawled'] ?? 0 ) + (int) ( $states['error'] ?? 0 );
		$summary = array(
			'score'         => self::score( array_values( $keys ), $crawled ),
			'pages_found'   => array_sum( $states ),
			'pages_crawled' => $crawled,
			'pages'         => $states,
			'issues_total'  => count( $keys ),
			'by_severity'   => array_fill_keys( Rule::SEVERITIES, 0 ),
			'by_category'   => array(),
			'by_rule'       => array(),
			'top_issues'    => array(),
		);
		foreach ( Rule::CATEGORIES as $category ) {
			$summary['by_category'][ $category ] = array_fill_keys( Rule::SEVERITIES, 0 ) + array( 'total' => 0 );
		}
		foreach ( $this->store->issue_counts( $run_id ) as $row ) {
			$severity = $row['severity'];
			$category = $row['category'];
			if ( isset( $summary['by_severity'][ $severity ] ) ) {
				$summary['by_severity'][ $severity ] += $row['n'];
			}
			if ( isset( $summary['by_category'][ $category ][ $severity ] ) ) {
				$summary['by_category'][ $category ][ $severity ] += $row['n'];
				$summary['by_category'][ $category ]['total']     += $row['n'];
			}
			$summary['by_rule'][ $row['rule_id'] ] = array(
				'count'      => $row['n'],
				'severity'   => $severity,
				'category'   => $category,
				'title'      => isset( $rules[ $row['rule_id'] ] ) ? $rules[ $row['rule_id'] ]->title : $row['rule_id'],
				'fixable_by' => isset( $rules[ $row['rule_id'] ] ) ? $rules[ $row['rule_id'] ]->fixable_by : null,
			);
		}
		$summary['top_issues'] = self::top_issues( $summary['by_rule'] );
		if ( $run && is_array( $run['summary'] ) ) {
			$summary += array_diff_key( $run['summary'], $summary );
		}
		return array_merge( $summary, $extra );
	}

	/**
	 * Rules ordered by severity then count (top 10).
	 *
	 * @param array $by_rule rule_id => [ count, severity, category, title ].
	 * @return array[]
	 */
	public static function top_issues( array $by_rule, int $limit = 10 ): array {
		$rank = array_flip( Rule::SEVERITIES );
		$list = array();
		foreach ( $by_rule as $rule_id => $row ) {
			$list[] = array( 'rule_id' => $rule_id ) + $row;
		}
		usort(
			$list,
			static function ( $a, $b ) use ( $rank ) {
				return array( $rank[ $a['severity'] ] ?? 9, -$a['count'], $a['rule_id'] ) <=> array( $rank[ $b['severity'] ] ?? 9, -$b['count'], $b['rule_id'] );
			}
		);
		return array_slice( $list, 0, $limit );
	}

	/**
	 * Stored summary of a run.
	 *
	 * @param int $run_id Run id.
	 */
	public function summary( int $run_id ): array {
		$run = $this->store->get_run( $run_id );
		return $run && is_array( $run['summary'] ) ? $run['summary'] : array();
	}

	/**
	 * New / fixed / still-open issues between two runs (an issue = rule + URL).
	 *
	 * @param int $run_id          Current run.
	 * @param int $previous_run_id Earlier run (0 = the previous finished run).
	 * @param int $list_limit      Max items per list.
	 * @return array{previous_run_id: int, new: array, fixed: array, open: array, score_change: int|null}
	 */
	public function diff( int $run_id, int $previous_run_id = 0, int $list_limit = 200 ): array {
		if ( $previous_run_id <= 0 ) {
			$previous_run_id = $this->store->previous_done_run_id( $run_id );
		}
		$current  = $this->store->issue_keys( $run_id );
		$previous = $previous_run_id > 0 ? $this->store->issue_keys( $previous_run_id ) : array();

		$new   = array_diff_key( $current, $previous );
		$fixed = array_diff_key( $previous, $current );
		$open  = array_intersect_key( $current, $previous );

		$run_a = $this->store->get_run( $run_id );
		$run_b = $previous_run_id > 0 ? $this->store->get_run( $previous_run_id ) : null;

		return array(
			'previous_run_id' => $previous_run_id,
			'new'             => self::diff_part( $new, $list_limit ),
			'fixed'           => self::diff_part( $fixed, $list_limit ),
			'open'            => self::diff_part( $open, $list_limit ),
			'score_change'    => $run_a && $run_b && null !== $run_a['score'] && null !== $run_b['score'] ? $run_a['score'] - $run_b['score'] : null,
		);
	}

	/**
	 * Counts + capped list for a diff bucket.
	 *
	 * @param array $items Issue keys.
	 * @param int   $limit Max items.
	 */
	private static function diff_part( array $items, int $limit ): array {
		$by_severity = array_fill_keys( Rule::SEVERITIES, 0 );
		$by_rule     = array();
		foreach ( $items as $item ) {
			if ( isset( $by_severity[ $item['severity'] ] ) ) {
				++$by_severity[ $item['severity'] ];
			}
			$by_rule[ $item['rule_id'] ] = ( $by_rule[ $item['rule_id'] ] ?? 0 ) + 1;
		}
		return array(
			'count'       => count( $items ),
			'by_severity' => $by_severity,
			'by_rule'     => $by_rule,
			'items'       => array_slice(
				array_map(
					static function ( $item ) {
						return array(
							'rule_id'  => $item['rule_id'],
							'severity' => $item['severity'],
							'category' => $item['category'],
							'url'      => $item['url'],
						);
					},
					array_values( $items )
				),
				0,
				$limit
			),
		);
	}

	/**
	 * Issues the Meta module can fix with AI, grouped per post (only pages that map to a post id).
	 * Payload for the `seoc_request_ai_suggestions` action (ARCHITECTURE §11).
	 *
	 * @param int $run_id Run id.
	 * @param int $limit  Max posts.
	 * @return array[] [ [ 'object_type' => 'post', 'object_id' => int, 'fields' => string[] ] ].
	 */
	public function ai_items( int $run_id, int $limit = 500 ): array {
		$rules  = Rules::all();
		$items  = array();
		$page   = 1;
		$result = array();
		do {
			$result = $this->store->issues(
				$run_id,
				array(
					'page'     => $page,
					'per_page' => 1000,
				)
			);
			foreach ( $result['items'] as $issue ) {
				$rule = isset( $rules[ $issue['rule_id'] ] ) ? $rules[ $issue['rule_id'] ] : null;
				if ( ! $rule || 'ai' !== $rule->fixable_by || ! isset( self::AI_FIELDS[ $issue['rule_id'] ] ) || $issue['post_id'] <= 0 ) {
					continue;
				}
				$post_id = (int) $issue['post_id'];
				if ( ! isset( $items[ $post_id ] ) ) {
					if ( count( $items ) >= $limit ) {
						continue;
					}
					$items[ $post_id ] = array(
						'object_type' => 'post',
						'object_id'   => $post_id,
						'fields'      => array(),
					);
				}
				$field = self::AI_FIELDS[ $issue['rule_id'] ];
				if ( ! in_array( $field, $items[ $post_id ]['fields'], true ) ) {
					$items[ $post_id ]['fields'][] = $field;
				}
			}
			++$page;
			$more = count( $result['items'] ) === 1000;
		} while ( $more );
		return array_values( $items );
	}
}
