<?php
/**
 * AuditModule: boot, settings, jobs, REST, cross-module hooks.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\AI\ClientInterface;
use SEOConsole\Modules\AbstractModule;
use SEOConsole\Modules\Audit\AuditModule;
use SEOConsole\Modules\Audit\Report;
use SEOConsole\Plugin;
use WP_REST_Request;

require_once __DIR__ . '/AuditTestCase.php';

/**
 * Claude stand-in.
 */
class AuditFakeClaude implements ClientInterface {
	/** @var bool */
	public $available = true;

	public function is_available(): bool {
		return $this->available;
	}

	public function structured( string $system, array $messages, array $json_schema, array $opts = array() ) {
		return array();
	}

	public function chat( string $system, array $messages, array $opts = array() ) {
		return array( 'text' => '' );
	}
}

/**
 * Meta module stand-in.
 */
class AuditFakeMeta extends AbstractModule {
	public function id(): string {
		return 'meta';
	}

	public function title(): string {
		return 'Meta';
	}

	public function description(): string {
		return 'Fake meta.';
	}
}

class ModuleTest extends AuditTestCase {

	/** @var AuditModule */
	private $module;

	/** @var bool */
	private $modules_changed = false;

	public function set_up() {
		parent::set_up();
		$this->module = Plugin::instance()->module( 'audit' );
		$settings     = Plugin::instance()->settings();
		$settings->set( 'audit', 'delay_ms', 0 );
		delete_option( AuditModule::LOCK_OPTION );
		$this->module->unschedule_steps();
		Plugin::instance()->queue()->unschedule( AuditModule::START_HOOK );
		self::factory()->post->create( array( 'post_name' => 'seoc-post' ) );
		self::factory()->post->create( array( 'post_name' => 'seoc-thin' ) );
	}

	public function tear_down() {
		Plugin::instance()->reset_service( 'claude' );
		if ( $this->modules_changed ) {
			remove_all_filters( 'seoc_modules' );
			Plugin::instance()->load_modules();
		}
		$this->module->unschedule_steps();
		parent::tear_down();
	}

	private function call( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/seo-console/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( $params ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Runs background steps like Action Scheduler would until the run is done. */
	private function run_jobs( int $run_id ): int {
		$seq = 1;
		while ( $seq < 200 ) {
			do_action( AuditModule::STEP_HOOK, $run_id, $seq );
			if ( $this->module->crawler()->progress( $run_id )['done'] ) {
				return $seq;
			}
			++$seq;
		}
		$this->fail( 'Run did not finish through the job hook.' );
		return $seq;
	}

	private function enable_fake_meta(): void {
		add_filter(
			'seoc_modules',
			static function ( $modules ) {
				$modules['meta'] = new AuditFakeMeta();
				return $modules;
			}
		);
		$this->modules_changed = true;
		Plugin::instance()->load_modules();
		Plugin::instance()->settings()->set_enabled( 'meta', true );
	}

	/** Routes with sample params. */
	private function routes(): array {
		return array(
			array( 'GET', '/audit/runs' ),
			array( 'POST', '/audit/runs' ),
			array( 'GET', '/audit/runs/1' ),
			array( 'POST', '/audit/runs/1/cancel' ),
			array( 'POST', '/audit/runs/1/advance' ),
			array( 'GET', '/audit/runs/1/issues' ),
			array( 'GET', '/audit/runs/1/pages' ),
			array( 'GET', '/audit/pages/1' ),
			array( 'GET', '/audit/robots' ),
			array( 'GET', '/audit/robots/test', array( 'url' => '/' ) ),
			array( 'GET', '/audit/sitemaps' ),
			array( 'POST', '/audit/ai-fixes' ),
			array( 'GET', '/audit/rules' ),
		);
	}

	public function test_module_boots_with_settings_tables_and_page(): void {
		$this->assertInstanceOf( AuditModule::class, $this->module );
		$this->assertTrue( Plugin::instance()->module_enabled( 'audit' ) );
		$this->assertSame( 'weekly', Plugin::instance()->settings()->get( 'audit', 'schedule' ) );
		$this->assertSame( 500, Plugin::instance()->settings()->get( 'audit', 'max_pages' ) );
		$this->assertSame( 5, Plugin::instance()->settings()->get( 'audit', 'keep_runs' ) );
		$this->assertFalse( Plugin::instance()->settings()->get( 'audit', 'auto_ai_suggestions' ) );
		$this->assertSame( 0, $this->module->crawler_settings()['delay_ms'] );
		$this->assertCount( 4, $this->module->tables() );
		$this->assertSame( 'seo-console-audit', $this->module->admin_pages()[0]['slug'] );
		$this->assertSame( 25, $this->module->admin_pages()[0]['position'] );
		$this->assertContains( $this->module->tables()[0], Plugin::instance()->module_tables() );

		$routes = rest_get_server()->get_routes( 'seo-console/v1' );
		foreach ( array( '/seo-console/v1/audit/runs', '/seo-console/v1/audit/runs/(?P<id>\d+)', '/seo-console/v1/audit/robots/test', '/seo-console/v1/audit/ai-fixes' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $routes[ $route ] as $endpoint ) {
				$this->assertIsCallable( $endpoint['permission_callback'] );
			}
		}
	}

	public function test_rest_denies_subscribers_and_visitors(): void {
		$http = $this->mini_site();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 403, $this->call( $route[0], $route[1], $route[2] ?? array() )->get_status(), $route[0] . ' ' . $route[1] );
		}
		wp_set_current_user( 0 );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 401, $this->call( $route[0], $route[1], $route[2] ?? array() )->get_status(), $route[0] . ' ' . $route[1] );
		}
		$this->assertSame( array(), $http->requests );
		$this->assertSame( 0, $this->store->active_run_id() );
	}

	public function test_start_steps_through_jobs_until_done(): void {
		$this->mini_site();
		$this->admin();
		Plugin::instance()->settings()->set( 'audit', 'max_per_step', 2 );
		$completed = 0;
		add_action(
			'seoc_audit_completed',
			static function () use ( &$completed ) {
				++$completed;
			}
		);

		$response = $this->call( 'POST', '/audit/runs' );
		$this->assertSame( 201, $response->get_status() );
		$data   = $response->get_data();
		$run_id = $data['run']['id'];
		$this->assertSame( 'running', $data['run']['status'] );
		$this->assertFalse( $data['progress']['done'] );
		$this->assertGreaterThan( 0, $data['progress']['pages_crawled'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['run']['created_at'] );
		$this->assertTrue( Plugin::instance()->queue()->is_scheduled( AuditModule::STEP_HOOK, array( $run_id, 1 ) ) );

		// A second start is refused while the first runs.
		$second = $this->call( 'POST', '/audit/runs' );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'seoc_audit_running', $second->get_data()['code'] );
		$this->assertSame( $run_id, $second->get_data()['data']['run_id'] );

		$this->assertGreaterThan( 3, $this->run_jobs( $run_id ) );
		$this->assertSame( 1, $completed );

		// Run detail with summary; no diff for the first run.
		$detail = $this->call( 'GET', "/audit/runs/$run_id" )->get_data();
		$this->assertSame( 'done', $detail['run']['status'] );
		$this->assertTrue( $detail['progress']['done'] );
		$this->assertSame( $detail['progress']['to_check'], $detail['progress']['checked'] );
		$this->assertIsInt( $detail['run']['score'] );
		$this->assertSame( $detail['run']['score'], $detail['summary']['score'] );
		$this->assertNull( $detail['diff'] );
		$this->assertGreaterThan( 0, $detail['run']['issues']['error'] );

		// History.
		$list = $this->call( 'GET', '/audit/runs' );
		$this->assertSame( $run_id, $list->get_data()['items'][0]['id'] );
		$this->assertNull( $list->get_data()['active_run_id'] );
		$this->assertSame( '1', $list->get_headers()['X-WP-Total'] );

		// Grouped issues and a rule's issue list.
		$groups = $this->call( 'GET', "/audit/runs/$run_id/issues", array( 'group' => true ) )->get_data();
		$ids    = array_column( $groups['groups'], 'rule_id' );
		$this->assertContains( 'orphan_page', $ids );
		$this->assertSame( 'error', $groups['groups'][0]['severity'] );
		$this->assertNotEmpty( $groups['groups'][0]['how_to_fix'] );
		$errors_only = $this->call( 'GET', "/audit/runs/$run_id/issues", array( 'group' => true, 'severity' => 'error' ) )->get_data();
		$this->assertSame( array( 'error' ), array_values( array_unique( array_column( $errors_only['groups'], 'severity' ) ) ) );
		$thin = $this->call( 'GET', "/audit/runs/$run_id/issues", array( 'rule_id' => 'thin_content' ) )->get_data();
		$this->assertSame( 1, $thin['total'] );
		$this->assertSame( 'http://example.org/seoc-thin/', $thin['items'][0]['url'] );
		$this->assertNotEmpty( $thin['items'][0]['edit_link'] );
		$this->assertSame( 42, $thin['items'][0]['data']->words ); // 40 + the H1.

		// Pages: filters and sorting.
		$pages = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'per_page' => 100 ) )->get_data();
		$this->assertSame( 16, $pages['total'] );
		$redirects = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'status' => '3xx' ) )->get_data();
		$this->assertEqualsCanonicalizing( array( 'http://example.org/old-page/', 'http://example.org/loop-a/' ), array_column( $redirects['items'], 'url' ) );
		$noindex = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'indexability' => 'noindex' ) )->get_data();
		$this->assertSame( array( 'http://example.org/noindex-page/' ), array_column( $noindex['items'], 'url' ) );
		$search = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'search' => 'orphan' ) )->get_data();
		$this->assertSame( 1, $search['total'] );
		$clean = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'has_issue' => false, 'per_page' => 100 ) )->get_data();
		$this->assertSame( 0, array_sum( array_column( $clean['items'], 'issues' ) ) );
		$sorted = $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'orderby' => 'issues', 'order' => 'desc' ) )->get_data();
		$this->assertGreaterThanOrEqual( $sorted['items'][1]['issues'], $sorted['items'][0]['issues'] );
		$this->assertSame( 400, $this->call( 'GET', "/audit/runs/$run_id/pages", array( 'status' => 'abc' ) )->get_status() );

		// Page detail.
		$about  = $this->store->get_page_by_url( $run_id, 'http://example.org/about/' );
		$detail = $this->call( 'GET', '/audit/pages/' . $about['id'] )->get_data();
		$this->assertSame( 'About Us | Mini Site Company', $detail['page']['title'] );
		$this->assertContains( 'title_duplicate', array_column( $detail['issues'], 'rule_id' ) );
		$this->assertContains( 'http://example.org/', array_column( $detail['inbound'], 'url' ) );
		$missing = array_values( array_filter( $detail['outbound'], static function ( $l ) { return 'http://example.org/missing/' === $l['url']; } ) );
		$this->assertSame( 404, $missing[0]['status_code'] );
		$this->assertSame( 404, $this->call( 'GET', '/audit/pages/999999' )->get_status() );

		// Robots + tester + sitemaps from the run.
		$robots = $this->call( 'GET', '/audit/robots' )->get_data();
		$this->assertSame( $run_id, $robots['run_id'] );
		$this->assertFalse( $robots['live'] );
		$this->assertSame( 'core', $robots['robots']['manager'] );
		$this->assertStringContainsString( 'Disallow: /private/', $robots['robots']['content'] );
		$this->assertNotEmpty( $robots['robots']['advice'] );
		$this->assertSame( array(), $robots['checks'] );
		$test = $this->call( 'GET', '/audit/robots/test', array( 'url' => '/private/x/' ) )->get_data();
		$this->assertFalse( $test['allowed'] );
		$this->assertSame( '/private/', $test['rule']['pattern'] );
		$this->assertTrue( $this->call( 'GET', '/audit/robots/test', array( 'url' => 'http://example.org/about/' ) )->get_data()['allowed'] );
		$this->assertSame( 400, $this->call( 'GET', '/audit/robots/test', array( 'url' => 'https://other.com/' ) )->get_status() );

		$sitemaps = $this->call( 'GET', '/audit/sitemaps' )->get_data();
		$this->assertTrue( $sitemaps['sitemap']['found'] );
		$this->assertSame( 9, $sitemaps['sitemap']['url_count'] );
		$this->assertCount( 4, $sitemaps['sitemap']['files'] );
		$this->assertSame( array( 'sitemap_gz_skipped' ), array_column( $sitemaps['checks'], 'rule_id' ) );
		$this->assertSame( array( 'http://example.org/missing/' ), $sitemaps['cross_check']['error']['urls'] );
		$this->assertSame( 4, $sitemaps['cross_check']['missing']['count'] );

		$rules = $this->call( 'GET', '/audit/rules' )->get_data();
		$this->assertArrayHasKey( 'link_slash_mismatch', (array) $rules['rules'] );

		// Cross-module hooks.
		$cards = apply_filters( 'seoc_dashboard_cards', array() );
		$card  = array_values( array_filter( $cards, static function ( $c ) { return 'audit' === $c['id']; } ) )[0];
		$this->assertSame( $detail['page']['id'] ? $this->store->get_run( $run_id )['score'] : null, $card['value'] );
		$this->assertGreaterThan( 0, $card['errors'] );
		$this->assertTrue( apply_filters( 'seoc_checklist', array( 'auditRun' => false ) )['auditRun'] );
		$context = apply_filters( 'seoc_ai_site_context', array() );
		$this->assertStringContainsString( 'health score', $context['audit'] );
		$this->assertStringContainsString( 'Top issues', $context['audit'] );
	}

	public function test_advance_steps_inline_and_respects_lock(): void {
		$this->mini_site();
		$this->admin();
		Plugin::instance()->settings()->set( 'audit', 'max_per_step', 1 );
		$run_id  = $this->call( 'POST', '/audit/runs' )->get_data()['run']['id'];
		$crawled = $this->store->get_run( $run_id )['pages_crawled'];

		$data = $this->call( 'POST', "/audit/runs/$run_id/advance" )->get_data();
		$this->assertTrue( $data['advanced'] );
		$this->assertGreaterThan( $crawled, $data['progress']['pages_crawled'] );

		add_option( AuditModule::LOCK_OPTION, time() + 60, '', false );
		$locked = $this->call( 'POST', "/audit/runs/$run_id/advance" )->get_data();
		$this->assertFalse( $locked['advanced'] );
		$this->assertSame( $data['progress']['pages_crawled'], $locked['progress']['pages_crawled'] );

		// A locked background step is retried later instead of running twice.
		do_action( AuditModule::STEP_HOOK, $run_id, 5 );
		$this->assertTrue( Plugin::instance()->queue()->is_scheduled( AuditModule::STEP_HOOK, array( $run_id, 6 ) ) );
		$this->assertSame( $data['progress']['pages_crawled'], $this->store->get_run( $run_id )['pages_crawled'] );

		// An expired lock is taken over.
		update_option( AuditModule::LOCK_OPTION, time() - 5 );
		$this->assertTrue( $this->call( 'POST', "/audit/runs/$run_id/advance" )->get_data()['advanced'] );
	}

	public function test_cancel(): void {
		$this->mini_site();
		$this->admin();
		Plugin::instance()->settings()->set( 'audit', 'max_per_step', 1 );
		$run_id = $this->call( 'POST', '/audit/runs' )->get_data()['run']['id'];
		$this->assertTrue( Plugin::instance()->queue()->is_scheduled( AuditModule::STEP_HOOK ) );

		$response = $this->call( 'POST', "/audit/runs/$run_id/cancel" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()['run']['status'] );
		$this->assertFalse( Plugin::instance()->queue()->is_scheduled( AuditModule::STEP_HOOK ) );
		$this->assertSame( 409, $this->call( 'POST', "/audit/runs/$run_id/cancel" )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/audit/runs/999999/cancel' )->get_status() );

		// A new run can start after cancelling.
		$this->assertSame( 201, $this->call( 'POST', '/audit/runs' )->get_status() );
	}

	public function test_ai_fixes(): void {
		$this->mini_site();
		$this->admin();

		// No finished run / meta off / no AI. The real Meta module is enabled by default on a full build.
		Plugin::instance()->settings()->set_enabled( 'meta', false );
		$this->assertSame( 'seoc_meta_disabled', $this->call( 'POST', '/audit/ai-fixes' )->get_data()['code'] );
		$this->enable_fake_meta();
		$claude            = new AuditFakeClaude();
		$claude->available = false;
		Plugin::instance()->replace_service( 'claude', $claude );
		$response = $this->call( 'POST', '/audit/ai-fixes' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'seoc_ai_not_configured', $response->get_data()['code'] );
		$claude->available = true;
		$this->assertSame( 404, $this->call( 'POST', '/audit/ai-fixes' )->get_status() );

		$run_id = $this->call( 'POST', '/audit/runs' )->get_data()['run']['id'];
		$this->run_jobs( $run_id );

		$fired = array();
		add_action(
			'seoc_request_ai_suggestions',
			static function ( $items, $origin ) use ( &$fired ) {
				$fired[] = array( $items, $origin );
			},
			10,
			2
		);
		$response = $this->call( 'POST', '/audit/ai-fixes', array( 'run_id' => $run_id ) );
		$this->assertSame( 200, $response->get_status() );
		$data     = $response->get_data();
		$expected = ( new Report( $this->store ) )->ai_items( $run_id );
		$this->assertNotEmpty( $expected );
		$this->assertSame( count( $expected ), $data['queued'] );
		$this->assertSame( array( array( $expected, 'audit' ) ), $fired );
		$this->assertSame( 1, $data['fields']['image_alt'] );
		$this->assertStringContainsString( 'module=meta', $data['suggestions_url'] );
		$post = get_page_by_path( 'seoc-post', OBJECT, 'post' );
		$this->assertContains(
			array(
				'object_type' => 'post',
				'object_id'   => $post->ID,
				'fields'      => array( 'image_alt' ),
			),
			$expected
		);

		// Only one rule.
		$fired = array();
		$data  = $this->call( 'POST', '/audit/ai-fixes', array( 'rule_ids' => array( 'img_missing_alt' ) ) )->get_data();
		$this->assertSame( 1, $data['queued'] );
		$this->assertSame( array( 'image_alt' ), $fired[0][0][0]['fields'] );
		$this->assertSame( 400, $this->call( 'POST', '/audit/ai-fixes', array( 'rule_ids' => array( 'http_4xx' ) ) )->get_status() );
	}

	public function test_auto_ai_suggestions_and_pruning_after_done(): void {
		$this->mini_site();
		$this->enable_fake_meta();
		Plugin::instance()->replace_service( 'claude', new AuditFakeClaude() );
		Plugin::instance()->settings()->set( 'audit', 'auto_ai_suggestions', true );
		Plugin::instance()->settings()->set( 'audit', 'keep_runs', 1 );
		$old = $this->store->create_run( array() );
		$this->store->update_run( $old, array( 'status' => 'done' ) );
		$fired = 0;
		add_action(
			'seoc_request_ai_suggestions',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$progress = $this->module->start_run( 20.0 );
		$this->assertTrue( $progress['done'] );
		$this->assertSame( 1, $fired );
		$this->assertNull( $this->store->get_run( $old ), 'Older runs are pruned.' );
	}

	public function test_finalize_is_batched_across_steps(): void {
		$this->mini_site();
		add_filter(
			'seoc_audit_finalize_chunk',
			static function () {
				return 3;
			}
		);
		$crawler  = $this->crawler();
		$run_id   = $crawler->start( array( 'delay_ms' => 0 ), 0.0 );
		$seen     = array();
		$steps    = 0;
		$progress = $crawler->progress( $run_id );
		while ( ! $progress['done'] && $steps < 200 ) {
			$progress = $crawler->step( $run_id, 0.0 ); // Budget 0: one unit of work per step.
			if ( 'finalize' === $progress['phase'] ) {
				$seen[] = $progress['checked'];
			}

			++$steps;
		}
		$this->assertTrue( $progress['done'] );
		$this->assertSame( array( 0, 3, 6, 9, 12, 15, 16 ), $seen, 'Rules were evaluated 3 pages per step (0 = crawl just ended).' );
		$this->assertSame( $seen, array_values( array_unique( $seen ) ) );
		
		$this->assertSame( 16, $progress['to_check'] );
		$this->assertSame( 16, $progress['checked'] );
		$issues = $this->issues_by_rule( $run_id );
		$this->assertSame( array( 'http://example.org/orphan/' ), $issues['orphan_page'] );
		$this->assertCount( 2, $issues['title_duplicate'] );
		$this->assertSame( 1, count( $issues['sitemap_gz_skipped'] ), 'Run-level issues are stored once.' );
	}

	public function test_jobs_scheduling(): void {
		$queue = Plugin::instance()->queue();
		$this->module->ensure_jobs();
		$this->assertTrue( $queue->is_scheduled( AuditModule::START_HOOK ) );

		Plugin::instance()->settings()->set( 'audit', 'schedule', 'off' );
		$this->module->ensure_jobs();
		$this->assertFalse( $queue->is_scheduled( AuditModule::START_HOOK ) );

		// Weekly: skipped when the last run is recent.
		$this->mini_site();
		Plugin::instance()->settings()->set( 'audit', 'schedule', 'weekly' );
		$recent = $this->store->create_run( array() );
		$this->store->update_run( $recent, array( 'status' => 'done' ) );
		do_action( AuditModule::START_HOOK );
		$this->assertSame( $recent, $this->store->list_runs( 1 )[0]['id'] );

		Plugin::instance()->settings()->set( 'audit', 'schedule', 'daily' );
		do_action( AuditModule::START_HOOK );
		$this->assertNotSame( $recent, $this->store->list_runs( 1 )[0]['id'] );
	}

	public function test_stale_run_is_closed_and_disable_cleans_up(): void {
		global $wpdb;
		$stale = $this->store->create_run( array() );
		$this->store->update_run( $stale, array( 'status' => 'running' ) );
		$wpdb->update( $this->store->table( 'runs' ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ), array( 'id' => $stale ) );
		$this->module->close_stale_run();
		$run = $this->store->get_run( $stale );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'stalled', $run['error'] );

		$active = $this->store->create_run( array() );
		$this->store->update_run( $active, array( 'status' => 'running' ) );
		$this->module->schedule_step( $active, 1 );
		$this->module->ensure_jobs();
		$this->module->on_disable();
		$this->assertSame( 'cancelled', $this->store->get_run( $active )['status'] );
		$this->assertFalse( Plugin::instance()->queue()->is_scheduled( AuditModule::STEP_HOOK ) );
		$this->assertFalse( Plugin::instance()->queue()->is_scheduled( AuditModule::START_HOOK ) );
	}

	public function test_dashboard_card_without_runs(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->store->table( 'runs' ) ); // phpcs:ignore
		$cards = apply_filters( 'seoc_dashboard_cards', array() );
		$card  = array_values( array_filter( $cards, static function ( $c ) { return 'audit' === $c['id']; } ) )[0];
		$this->assertNull( $card['value'] );
		$this->assertFalse( apply_filters( 'seoc_checklist', array() )['auditRun'] );
		$this->assertArrayNotHasKey( 'audit', apply_filters( 'seoc_ai_site_context', array() ) );
	}
}
