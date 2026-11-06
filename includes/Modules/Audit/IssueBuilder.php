<?php
/**
 * Evaluates every rule over a finished crawl and stores the issues.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use SEOConsole\Modules\Audit\Rules\Rule;
use SEOConsole\Modules\Audit\Rules\RuleContext;
use SEOConsole\Modules\Audit\Rules\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Rule runner.
 */
class IssueBuilder {

	/** Pages evaluated per chunk (bounds memory). */
	const CHUNK = 100;

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
	 * Evaluation context for a run (visited pages, navigation links, trailing-slash links, run-level checks).
	 *
	 * @param int    $run_id       Run id.
	 * @param array  $settings     Run settings (thin_content_words, slow_ms).
	 * @param array  $run_checks   Run-level check results [ [ 'rule', 'data' ] ].
	 * @param bool   $have_sitemap A sitemap was found.
	 * @param string $home         Normalised home URL.
	 */
	public function context( int $run_id, array $settings, array $run_checks, bool $have_sitemap, string $home ): RuleContext {
		return new RuleContext(
			array_values( $this->store->page_index( $run_id ) ),
			array(
				'nav'            => array_keys( $this->store->nav_linked_hashes( $run_id ) ),
				'slash_mismatch' => $this->store->slash_mismatch_targets( $run_id ),
				'settings'       => $settings,
				'run_checks'     => $run_checks,
				'have_sitemap'   => $have_sitemap,
				'home'           => $home,
			)
		);
	}

	/**
	 * Starts (re-)evaluation: deletes the run's issues and stores the run-level ones.
	 *
	 * @param int         $run_id Run id.
	 * @param RuleContext $ctx    Context.
	 * @return int Issues stored.
	 */
	public function begin( int $run_id, RuleContext $ctx ): int {
		$this->store->delete_issues( $run_id );
		$issues = self::evaluate_run( $ctx );
		$this->store->insert_issues( $run_id, $issues );
		return count( $issues );
	}

	/**
	 * Evaluates the next chunk of visited pages.
	 *
	 * @param int         $run_id Run id.
	 * @param RuleContext $ctx    Context.
	 * @param int         $after  Last evaluated page id (0 to start).
	 * @param int         $size   Pages per chunk.
	 * @return array{after: int, pages: int, issues: int, done: bool}
	 */
	public function chunk( int $run_id, RuleContext $ctx, int $after, int $size = self::CHUNK ): array {
		$size  = max( 1, $size );
		$pages = $this->store->pages( $run_id, $after, $size, array( 'crawled', 'blocked', 'error' ) );
		$ctx->clear_links();
		$ctx->set_links( $this->store->internal_links_from( $run_id, array_column( $pages, 'id' ) ) );
		$issues = array();
		foreach ( $pages as $page ) {
			$issues = array_merge( $issues, self::evaluate_page( $page, $ctx ) );
			$after  = (int) $page['id'];
		}
		$this->store->insert_issues( $run_id, $issues );
		return array(
			'after'  => $after,
			'pages'  => count( $pages ),
			'issues' => count( $issues ),
			'done'   => count( $pages ) < $size,
		);
	}

	/**
	 * Replaces the issues of a run in one go (small runs, tests). Crawler::step() uses begin() + chunk().
	 *
	 * @param int    $run_id       Run id.
	 * @param array  $settings     Run settings.
	 * @param array  $run_checks   Run-level check results.
	 * @param bool   $have_sitemap A sitemap was found.
	 * @param string $home         Normalised home URL.
	 * @return int Issues stored.
	 */
	public function build( int $run_id, array $settings, array $run_checks, bool $have_sitemap, string $home ): int {
		$ctx   = $this->context( $run_id, $settings, $run_checks, $have_sitemap, $home );
		$total = $this->begin( $run_id, $ctx );
		$after = 0;
		do {
			$result = $this->chunk( $run_id, $ctx, $after );
			$after  = $result['after'];
			$total += $result['issues'];
		} while ( ! $result['done'] );
		return $total;
	}

	/**
	 * Run-level issues.
	 *
	 * @param RuleContext $ctx Context.
	 * @return array[] Issue rows.
	 */
	public static function evaluate_run( RuleContext $ctx ): array {
		$issues = array();
		foreach ( Rules::by_scope( 'run' ) as $rule ) {
			foreach ( $rule->check_run( $ctx ) as $data ) {
				$issues[] = self::issue( $rule, null, '', $data );
			}
		}
		return $issues;
	}

	/**
	 * Issues of one page.
	 *
	 * @param array       $page Decoded page row.
	 * @param RuleContext $ctx  Context.
	 * @return array[] Issue rows.
	 */
	public static function evaluate_page( array $page, RuleContext $ctx ): array {
		if ( empty( $page['url_hash'] ) ) {
			$page['url_hash'] = AuditStore::hash( (string) $page['url'] );
		}
		$issues = array();
		foreach ( Rules::by_scope( 'page' ) as $rule ) {
			$data = $rule->check_page( $page, $ctx );
			if ( null !== $data ) {
				$issues[] = self::issue( $rule, isset( $page['id'] ) ? (int) $page['id'] : null, (string) $page['url_hash'], $data );
			}
		}
		return $issues;
	}

	/**
	 * Issue row.
	 *
	 * @param Rule     $rule     Rule.
	 * @param int|null $page_id  Page id.
	 * @param string   $url_hash URL hash.
	 * @param array    $data     Data.
	 */
	private static function issue( Rule $rule, ?int $page_id, string $url_hash, array $data ): array {
		return array(
			'rule_id'  => $rule->id,
			'severity' => $rule->severity,
			'category' => $rule->category,
			'page_id'  => $page_id,
			'url_hash' => $url_hash,
			'data'     => $data,
		);
	}
}
