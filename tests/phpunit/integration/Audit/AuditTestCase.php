<?php
/**
 * Base class: creates the audit tables (real tables, created before the per-test transaction) and helpers.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\AuditStore;
use SEOConsole\Modules\Audit\Crawler;
use SEOConsole\Modules\Audit\HttpFetcher;
use SEOConsole\Modules\Audit\Url;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/unit/Audit/MockHttp.php';

abstract class AuditTestCase extends WP_UnitTestCase {

	/** @var AuditStore */
	protected $store;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		( new AuditStore() )->install();
	}

	public function set_up() {
		parent::set_up();
		$this->store = new AuditStore();
	}

	/** Mini-site routes plus real posts for the post-backed URLs. */
	protected function mini_site( array $extra_routes = array() ): MockHttp {
		$this->set_permalink_structure( '/%postname%/' );
		$routes = require dirname( __DIR__, 2 ) . '/fixtures/audit/minisite.php';
		return ( new MockHttp( array_merge( $routes, $extra_routes ) ) )->install();
	}

	protected function crawler(): Crawler {
		$scope = new Url( 'http://example.org/' );
		return new Crawler( $this->store, $scope, new HttpFetcher( $scope ) );
	}

	/** Runs steps until the run is done. */
	protected function finish( Crawler $crawler, int $run_id, float $budget = 20.0, int $max_steps = 200 ): int {
		$steps    = 0;
		$progress = $crawler->progress( $run_id );
		while ( ! $progress['done'] && $steps < $max_steps ) {
			$progress = $crawler->step( $run_id, $budget );
			++$steps;
		}
		$this->assertTrue( $progress['done'], 'Run did not finish.' );
		return $steps;
	}

	/** rule_id => list of page URLs ('' for run-level). */
	protected function issues_by_rule( int $run_id ): array {
		$out = array();
		foreach ( $this->store->issues( $run_id )['items'] as $issue ) {
			$out[ $issue['rule_id'] ][] = $issue['url'];
		}
		return $out;
	}

	protected function page( int $run_id, string $path ): ?array {
		return $this->store->get_page_by_url( $run_id, 'http://example.org' . $path );
	}
}
