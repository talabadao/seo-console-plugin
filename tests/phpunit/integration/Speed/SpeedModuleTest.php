<?php
/**
 * Speed module: boot, runs, jobs, runtime data, re-test on change, integrations.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Modules\Speed\SpeedModule;
use SEOConsole\Modules\Speed\SpeedRunner;
use SEOConsole\Modules\Speed\SpeedStore;
use SEOConsole\Plugin;

require_once __DIR__ . '/SpeedTestCase.php';

class SpeedModuleTest extends SpeedTestCase {

	public function test_module_boots(): void {
		$plugin = Plugin::instance();
		$module = $this->module();
		$this->assertTrue( $plugin->module_enabled( 'speed' ) );
		$this->assertSame( 'speed', $module->id() );
		$schema = $plugin->settings()->schema( 'speed' );
		foreach ( array( 'strategies', 'url_mode', 'custom_urls', 'psi_schedule', 'crux_enabled', 'retention_days', 'tweak_lcp_boost', 'tweak_disable_emojis', 'revisions_to_keep', 'preconnect_origins' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema, $key );
		}
		$this->assertFalse( $plugin->settings()->get( 'speed', 'tweak_disable_emojis' ) );
		$this->assertSame( 'daily', $plugin->settings()->get( 'speed', 'psi_schedule' ) );

		global $wpdb;
		foreach ( SpeedStore::TABLES as $key ) {
			$this->assertSame( SpeedStore::table( $key ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', SpeedStore::table( $key ) ) ) );
		}
		$this->assertSame( array( 'seo-console-speed', 'seo-console-environment' ), wp_list_pluck( $module->admin_pages(), 'slug' ) );
		$this->assertTrue( Plugin::instance()->queue()->available() );
	}

	public function test_run_tests_each_url_and_strategy_and_stores_results(): void {
		$completed = array();
		add_action(
			'seoc_speed_run_completed',
			static function ( $run_id, $summary ) use ( &$completed ) {
				$completed[] = array( $run_id, $summary );
			},
			10,
			2
		);
		$run = $this->runner()->start_run( 'manual' );
		$this->assertSame( 'queued', $run['status'] );
		$this->assertSame( 2, $run['urls_total'] );

		$rows = $this->runner()->store()->results_for_run( $run['id'] );
		$this->assertSame( array( 'mobile', 'desktop' ), wp_list_pluck( $rows, 'strategy' ) );
		foreach ( $rows as $row ) {
			$this->assertTrue( as_has_scheduled_action( SpeedRunner::HOOK_TEST, array( $row['id'] ), 'seo-console' ) );
		}

		// A second manual run is refused while this one is open.
		$second = $this->runner()->start_run( 'manual' );
		$this->assertWPError( $second );
		$this->assertSame( 'seoc_speed_run_active', $second->get_error_code() );

		foreach ( $rows as $row ) {
			$this->runner()->test_url( $row['id'] );
		}
		$this->runner()->test_url( $rows[0]['id'] ); // Idempotent.

		$run = $this->runner()->store()->get_run( $run['id'] );
		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 2, $run['urls_done'] );
		$this->assertSame( 34, $run['summary']['home']['mobile'] );
		$this->assertSame( 91, $run['summary']['home']['desktop'] );
		$this->assertSame( 'fail', $run['summary']['field'] );
		$this->assertCount( 1, $completed );
		$this->assertSame( $run['id'], $completed[0][0] );

		$mobile = $this->runner()->store()->get_result( $rows[0]['id'] );
		$this->assertSame( 'done', $mobile['status'] );
		$this->assertSame( 6821, $mobile['lcp_ms'] );
		$this->assertSame( 4300, $mobile['field_lcp_ms'] );
		$this->assertSame( 'poor', $mobile['field_category'] );
		$this->assertSame( '(max-width: 768px) 100vw, 1600px', $mobile['result']['lcp_element']['page_attrs']['sizes'] );

		// Only PSI and the site itself were contacted, the API key never left in a non-Google request.
		foreach ( $this->http_requests as $request ) {
			$host = wp_parse_url( $request['url'], PHP_URL_HOST );
			$this->assertContains( $host, array( 'www.googleapis.com', 'example.org' ) );
		}

		// Runtime option for the tweaks.
		$runtime = SpeedRunner::runtime();
		$this->assertCount( 1, $runtime['lcp_images'] );
		$this->assertSame( home_url( '/' ), $runtime['lcp_images'][0]['page_url'] );
		$this->assertSame( 'https://example.org/wp-content/uploads/2026/05/hero.jpg', $runtime['lcp_images'][0]['image_url'] );
		$this->assertStringContainsString( '768w', $runtime['lcp_images'][0]['srcset'] );
		$this->assertContains( 'https://fonts.gstatic.com', $runtime['preconnect'] );
		$this->assertTrue( $this->module()->checklist( array() )['speedTested'] );
	}

	public function test_retry_then_fail_on_quota(): void {
		$this->routes['#pagespeedonline#'] = function () {
			return self::response( 429, $this->fixture_raw( 'psi-error-quota.json' ), array( 'retry-after' => '90' ) );
		};
		update_option( 'seoc_settings', array( 'modules' => array( 'speed' => array( 'strategies' => 'mobile' ) ) ) );
		$run = $this->runner()->start_run( 'manual' );
		$row = $this->runner()->store()->results_for_run( $run['id'] )[0];

		$this->runner()->test_url( $row['id'] );
		$row = $this->runner()->store()->get_result( $row['id'] );
		$this->assertSame( 'retry', $row['status'] );
		$this->assertSame( 'seoc_psi_quota', $row['error_code'] );
		$next = as_next_scheduled_action( SpeedRunner::HOOK_TEST, array( $row['id'] ), 'seo-console' );
		$this->assertGreaterThanOrEqual( time() + 80, $next );
		$this->assertSame( 'running', $this->runner()->store()->get_run( $run['id'] )['status'] );

		$this->runner()->test_url( $row['id'] );
		$this->assertSame( 'failed', $this->runner()->store()->get_result( $row['id'] )['status'] );
		$run = $this->runner()->store()->get_run( $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( array(), SpeedRunner::runtime()['lcp_images'] );
	}

	public function test_invalid_url_is_not_retried(): void {
		$this->routes['#pagespeedonline#'] = function () {
			return self::response( 400, $this->fixture_raw( 'psi-error-invalid-url.json' ) );
		};
		$run = $this->complete_run();
		$this->assertSame( 'failed', $run['status'] );
		foreach ( $this->runner()->store()->results_for_run( $run['id'] ) as $row ) {
			$this->assertSame( 1, $row['attempts'] );
			$this->assertSame( 'seoc_psi_invalid_url', $row['error_code'] );
		}
	}

	public function test_scheduled_run_respects_schedule(): void {
		Plugin::instance()->settings()->set( 'speed', 'psi_schedule', 'off' );
		$this->runner()->scheduled_run();
		$this->assertNull( $this->runner()->store()->active_run() );

		Plugin::instance()->settings()->set( 'speed', 'psi_schedule', 'weekly' );
		$this->runner()->scheduled_run();
		$run = $this->runner()->store()->active_run();
		$this->assertSame( 'schedule', $run['trigger_source'] );
		foreach ( $this->runner()->store()->results_for_run( $run['id'] ) as $row ) {
			$this->runner()->test_url( $row['id'] );
		}
		$this->runner()->scheduled_run(); // Weekly: last one is recent → skipped.
		$this->assertNull( $this->runner()->store()->active_run() );
	}

	public function test_ensure_jobs(): void {
		$this->module()->ensure_jobs();
		foreach ( array( SpeedRunner::HOOK_RUN, SpeedRunner::HOOK_CRUX, SpeedRunner::HOOK_HISTORY, SpeedRunner::HOOK_PRUNE ) as $hook ) {
			$this->assertTrue( as_has_scheduled_action( $hook, array(), 'seo-console' ), $hook );
		}
		Plugin::instance()->settings()->update(
			'speed',
			array(
				'psi_schedule' => 'off',
				'crux_enabled' => false,
			)
		);
		$this->module()->ensure_jobs();
		$this->assertFalse( as_has_scheduled_action( SpeedRunner::HOOK_RUN, array(), 'seo-console' ) );
		$this->assertFalse( as_has_scheduled_action( SpeedRunner::HOOK_CRUX, array(), 'seo-console' ) );

		$this->module()->on_disable();
		$this->assertFalse( as_has_scheduled_action( SpeedRunner::HOOK_PRUNE, array(), 'seo-console' ) );
	}

	public function test_frontend_change_queues_one_retest(): void {
		$changes = Plugin::instance()->changes();
		$first   = $changes->apply( new ChangeRequest( 'module_setting', 'site', null, 'speed.tweak_disable_emojis', true, array( 'module' => 'speed' ) ) );
		$second  = $changes->apply( new ChangeRequest( 'module_setting', 'site', null, 'speed.tweak_clean_head', true, array( 'module' => 'speed' ) ) );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );

		$pending = get_option( SpeedRunner::RETEST_OPTION );
		$this->assertSame( $second->id, $pending['change_id'] );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => SpeedRunner::HOOK_RETEST, 'status' => 'pending', 'group' => 'seo-console' ) ) );

		$this->runner()->run_retest();
		$run = $this->runner()->store()->active_run();
		$this->assertSame( 'change', $run['trigger_source'] );
		$this->assertSame( $second->id, $run['change_id'] );
		$this->assertFalse( get_option( SpeedRunner::RETEST_OPTION ) );

		// Reverting is a change too.
		$changes->revert( $first->id );
		$this->assertNotEmpty( get_option( SpeedRunner::RETEST_OPTION ) );
	}

	public function test_crux_jobs(): void {
		$this->assertWPError( $this->runner()->fetch_crux() );
		$this->assertSame( 'seoc_crux_no_key', $this->runner()->fetch_crux_history()->get_error_code() );

		Plugin::instance()->secrets()->set( 'google_api_key', 'AIzaTESTKEY' );
		$result = $this->runner()->fetch_crux();
		$this->assertSame( 4, $result['stored'] ); // Origin + home URL, phone + desktop.
		$record = $this->runner()->store()->latest_crux( 'record', 'http://example.org', 'PHONE' );
		$this->assertSame( 3120, $record['data']['metrics']['lcp']['p75'] );

		$this->assertSame( 2, $this->runner()->fetch_crux_history()['stored'] );
		$this->assertTrue( $this->runner()->fetch_crux_history()['skipped'] );
		$history = $this->runner()->store()->latest_crux( 'history', 'http://example.org', 'PHONE' );
		$this->assertCount( 4, $history['data']['periods'] );
		foreach ( $this->http_requests as $request ) {
			$this->assertSame( 'chromeuxreport.googleapis.com', wp_parse_url( $request['url'], PHP_URL_HOST ) );
		}
	}

	public function test_prune(): void {
		$run = $this->complete_run();
		global $wpdb;
		$wpdb->update( SpeedStore::table( 'runs' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ), array( 'id' => $run['id'] ) );
		$deleted = $this->runner()->prune();
		$this->assertSame( 1, $deleted['runs'] );
		$this->assertSame( 2, $deleted['results'] );
	}

	public function test_tweaks_from_settings_skip_cache_plugin_features(): void {
		$module = $this->module();
		Plugin::instance()->settings()->update(
			'speed',
			array(
				'tweak_disable_emojis'  => true,
				'tweak_limit_revisions' => true,
			)
		);
		$this->assertSame( array( 'disable_emojis', 'limit_revisions' ), $module->enabled_tweaks() );

		add_filter(
			'seoc_cache_plugins',
			static function () {
				return array(
					array(
						'id'         => 'wp-rocket',
						'name'       => 'WP Rocket',
						'version'    => '3.17',
						'page_cache' => true,
					),
				);
			}
		);
		update_option( 'wp_rocket_settings', array( 'emoji' => 1 ) );
		$module->reset_cache_features();
		$this->assertTrue( $module->cache_features()['disable_emojis'] );
		$this->assertSame( 'wp-rocket', $module->cache_audit()->plugin() );

		$this->go_to( home_url( '/' ) );
		$module->register_frontend( Plugin::instance() );
		do_action( 'wp' );
		$this->assertSame( 7, has_action( 'wp_head', 'print_emoji_detection_script' ), 'WP Rocket already removes emojis: tweak skipped.' );
	}

	public function test_integrations(): void {
		$cards = $this->module()->dashboard_cards( array() );
		$this->assertSame( array( 'speed-mobile-score', 'speed-cwv' ), wp_list_pluck( $cards, 'id' ) );
		$this->assertNull( $cards[0]['value'] );
		$this->assertSame( 'none', $cards[0]['dataKind'] );
		$this->assertSame( array(), $this->module()->ai_site_context( array() ) );
		$this->assertFalse( $this->module()->checklist( array() )['speedTested'] );

		$this->complete_run();
		$cards = $this->module()->dashboard_cards( array() );
		$this->assertSame( 34, $cards[0]['value'] );
		$this->assertSame( 'poor', $cards[0]['status'] );
		$this->assertSame( 'lab', $cards[0]['dataKind'] );
		$this->assertSame( 'Failed', $cards[1]['value'] );
		$this->assertSame( 'field', $cards[1]['dataKind'] );

		$context = $this->module()->ai_site_context( array( 'other' => 'x' ) );
		$this->assertSame( 'x', $context['other'] );
		$this->assertStringContainsString( 'score 34/100', $context['speed'] );
		$this->assertStringContainsString( 'Top speed to-dos', $context['speed'] );

		$config = apply_filters( 'seoc_checklist', array() );
		$this->assertTrue( $config['speedTested'] );
	}

	public function test_environment_checks_filter(): void {
		$checks = Plugin::instance()->environment()->checks();
		$by_id  = array_column( $checks, null, 'id' );
		foreach ( array( 'php_version', 'opcache', 'object_cache', 'compression', 'page_cache', 'ttfb', 'wp_cron', 'active_plugins', 'seo_plugin', 'safe_mode' ) as $id ) {
			$this->assertArrayHasKey( $id, $by_id, $id );
		}
		$this->assertSame( 'speed', $by_id['php_version']['source'] );
		$this->assertSame( 'Gzip', $by_id['compression']['value'] );
		$this->assertCount( count( $by_id ), $checks, 'No duplicate ids.' );
		foreach ( $checks as $check ) {
			$this->assertContains( $check['status'], array( 'good', 'needs-work', 'poor', 'unknown' ) );
		}
	}
}
