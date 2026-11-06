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
	 * Replaces the issues of a run.
	 *
	 * @param int    $run_id       Run id.
	 * @param array  $settings     Run settings (thin_content_words, slow_ms).
	 * @param array  $run_checks   Run-level check results [ [ 'rule', 'data' ] ].
	 * @param bool   $have_sitemap A sitemap was found.
	 * @param string $home         Normalised home URL.
	 * @return int Issues stored.
	 */
	public function build( int $run_id, array $settings, array $run_checks, bool $have_sitemap, string $home ): int {
		$this->store->delete_issues( $run_id );
		$ctx = new RuleContext(
			array_values( $this->store->page_index( $run_id ) ),
			array(
				'nav'          => array_keys( $this->store->nav_linked_hashes( $run_id ) ),
				'settings'     => $settings,
				'run_checks'   => $run_checks,
				'have_sitemap' => $have_sitemap,
				'home'         => $home,
			)
		);

		$total  = 0;
		$issues = self::evaluate_run( $ctx );
		$this->store->insert_issues( $run_id, $issues );
		$total += count( $issues );

		$after = 0;
		do {
			$pages = $this->store->pages( $run_id, $after, self::CHUNK, array( 'crawled', 'blocked', 'error' ) );
			if ( ! $pages ) {
				break;
			}
			$more = count( $pages ) === self::CHUNK;
			$ctx->clear_links();
			$ctx->set_links( $this->store->internal_links_from( $run_id, array_column( $pages, 'id' ) ) );
			$issues = array();
			foreach ( $pages as $page ) {
				$issues = array_merge( $issues, self::evaluate_page( $page, $ctx ) );
				$after  = (int) $page['id'];
			}
			$this->store->insert_issues( $run_id, $issues );
			$total += count( $issues );
		} while ( $more );

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
