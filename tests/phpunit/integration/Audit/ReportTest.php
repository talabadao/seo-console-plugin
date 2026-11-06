<?php
/**
 * Scoring, summary, diff and AI hand-off.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\AuditStore;
use SEOConsole\Modules\Audit\Report;
use SEOConsole\Modules\Audit\Rules\Rules;

require_once __DIR__ . '/AuditTestCase.php';

class ReportTest extends AuditTestCase {

	/**
	 * Creates a finished run with crawled pages and issues.
	 *
	 * @param array $pages  path => post_id.
	 * @param array $issues [ rule_id, path|'' ].
	 */
	private function make_run( array $pages, array $issues, string $status = 'done' ): int {
		$run_id = $this->store->create_run( array() );
		$items  = array();
		foreach ( array_keys( $pages ) as $path ) {
			$items[] = array(
				'url'   => 'http://example.org' . $path,
				'depth' => 1,
			);
		}
		$this->store->enqueue( $run_id, $items );
		$ids = array();
		foreach ( $pages as $path => $post_id ) {
			$page = $this->page( $run_id, $path );
			$this->store->update_page(
				$page['id'],
				array(
					'crawl_state' => 'crawled',
					'status_code' => 200,
					'post_id'     => $post_id,
				)
			);
			$ids[ $path ] = $page['id'];
		}
		$rows = array();
		foreach ( $issues as $issue ) {
			list( $rule_id, $path ) = $issue;
			$rule                   = Rules::get( $rule_id );
			$rows[]                 = array(
				'rule_id'  => $rule_id,
				'severity' => $rule->severity,
				'category' => $rule->category,
				'page_id'  => '' === $path ? null : $ids[ $path ],
				'url_hash' => '' === $path ? '' : AuditStore::hash( 'http://example.org' . $path ),
				'data'     => array(),
			);
		}
		$this->store->insert_issues( $run_id, $rows );
		$report  = new Report( $this->store );
		$summary = $report->build_summary( $run_id );
		$this->store->update_run(
			$run_id,
			array(
				'status'  => $status,
				'score'   => $summary['score'],
				'summary' => $summary,
			)
		);
		return $run_id;
	}

	public function test_score_formula(): void {
		$this->assertSame( 100, Report::score( array(), 10 ) );
		$this->assertSame( 100, Report::score( array(), 0 ) );
		$error   = array(
			'page_id'  => 1,
			'severity' => 'error',
		);
		$warning = array(
			'page_id'  => 2,
			'severity' => 'warning',
		);
		$notice  = array(
			'page_id'  => 3,
			'severity' => 'notice',
		);
		// One error on 1 of 10 pages: 100 × (1 − 0.5/10) = 95.
		$this->assertSame( 95, Report::score( array( $error ), 10 ) );
		// A page's penalty is capped at 1: five errors on one page of ten = 90.
		$this->assertSame( 90, Report::score( array_fill( 0, 5, $error ), 10 ) );
		// Warnings and notices weigh less.
		$this->assertSame( 99, Report::score( array( $warning ), 10 ) );
		$this->assertSame( 100, Report::score( array( $notice ), 10 ) );
		// Site-wide issues remove points directly (capped).
		$this->assertSame( 85, Report::score( array( array( 'page_id' => null, 'severity' => 'error' ) ), 10 ) );
		$this->assertSame( 40, Report::score( array_fill( 0, 10, array( 'page_id' => null, 'severity' => 'error' ) ), 10 ) );
		// Every page broken → 0, never negative.
		$this->assertSame( 0, Report::score( array_merge( array_fill( 0, 2, $error ), array( array( 'page_id' => null, 'severity' => 'error' ) ) ), 1 ) );
		// More pages dilute the same issue.
		$this->assertGreaterThan( Report::score( array( $error ), 10 ), Report::score( array( $error ), 100 ) );
	}

	public function test_summary_counts(): void {
		$run_id  = $this->make_run(
			array(
				'/a/' => 0,
				'/b/' => 0,
			),
			array(
				array( 'http_4xx', '/a/' ),
				array( 'title_missing', '/a/' ),
				array( 'meta_desc_missing', '/b/' ),
				array( 'robots_no_sitemap', '' ),
			)
		);
		$summary = ( new Report( $this->store ) )->summary( $run_id );

		$this->assertSame( 2, $summary['pages_crawled'] );
		$this->assertSame( 4, $summary['issues_total'] );
		$this->assertSame(
			array(
				'error'   => 2,
				'warning' => 1,
				'notice'  => 1,
			),
			$summary['by_severity']
		);
		$this->assertSame( 1, $summary['by_category']['content']['error'] );
		$this->assertSame( 2, $summary['by_category']['content']['total'] );
		$this->assertSame( 2, $summary['by_category']['indexability']['total'] );
		$this->assertSame( 0, $summary['by_category']['social']['total'] );
		$this->assertSame( 'ai', $summary['by_rule']['title_missing']['fixable_by'] );
		$this->assertSame( array( 'http_4xx', 'title_missing', 'meta_desc_missing', 'robots_no_sitemap' ), array_column( $summary['top_issues'], 'rule_id' ) );
		// 1 page fully broken (2 errors), 1 page 0.15: 100 × (1 − 1.15/2) − 1 = 41.5 → 42.
		$this->assertSame( 42, $summary['score'] );
	}

	public function test_diff_between_runs(): void {
		$old = $this->make_run(
			array(
				'/a/' => 0,
				'/b/' => 0,
			),
			array(
				array( 'title_missing', '/a/' ),
				array( 'http_4xx', '/b/' ),
				array( 'robots_missing', '' ),
			)
		);
		$new = $this->make_run(
			array(
				'/a/' => 0,
				'/b/' => 0,
				'/c/' => 0,
			),
			array(
				array( 'title_missing', '/a/' ),
				array( 'meta_desc_missing', '/c/' ),
				array( 'robots_missing', '' ),
			)
		);
		$this->make_run( array(), array( array( 'sitemap_missing', '' ) ), 'failed' ); // Failed runs are never the baseline.

		$diff = ( new Report( $this->store ) )->diff( $new );
		$this->assertSame( $old, $diff['previous_run_id'] );
		$this->assertSame( 1, $diff['new']['count'] );
		$this->assertSame( array( 'meta_desc_missing' => 1 ), $diff['new']['by_rule'] );
		$this->assertSame( 'http://example.org/c/', $diff['new']['items'][0]['url'] );
		$this->assertSame( 1, $diff['fixed']['count'] );
		$this->assertSame( 'http_4xx', $diff['fixed']['items'][0]['rule_id'] );
		$this->assertSame( 1, $diff['fixed']['by_severity']['error'] );
		$this->assertSame( 2, $diff['open']['count'] );
		$this->assertEqualsCanonicalizing( array( 'title_missing', 'robots_missing' ), array_keys( $diff['open']['by_rule'] ) );
		$this->assertIsInt( $diff['score_change'] );
		$this->assertGreaterThan( 0, $diff['score_change'] );

		// Explicit previous run, and a first run with nothing to compare.
		$this->assertSame( $diff, ( new Report( $this->store ) )->diff( $new, $old ) );
		$first = ( new Report( $this->store ) )->diff( $old );
		$this->assertSame( 0, $first['previous_run_id'] );
		$this->assertSame( 3, $first['new']['count'] );
		$this->assertNull( $first['score_change'] );
	}

	public function test_ai_items(): void {
		$run_id = $this->make_run(
			array(
				'/post-a/'   => 11,
				'/post-b/'   => 22,
				'/category/' => 0,
			),
			array(
				array( 'title_too_long', '/post-a/' ),
				array( 'meta_desc_missing', '/post-a/' ),
				array( 'meta_desc_duplicate', '/post-a/' ),
				array( 'img_missing_alt', '/post-b/' ),
				array( 'h1_missing', '/post-b/' ),          // Not an AI fix.
				array( 'title_missing', '/category/' ),     // No post id.
				array( 'robots_missing', '' ),
			)
		);
		$items = ( new Report( $this->store ) )->ai_items( $run_id );
		$this->assertSame(
			array(
				array(
					'object_type' => 'post',
					'object_id'   => 11,
					'fields'      => array( 'title', 'description' ),
				),
				array(
					'object_type' => 'post',
					'object_id'   => 22,
					'fields'      => array( 'image_alt' ),
				),
			),
			$items
		);
		$this->assertCount( 1, ( new Report( $this->store ) )->ai_items( $run_id, 1 ) );
	}
}
