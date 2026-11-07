<?php
/**
 * Speed REST routes: permissions, shapes, tweak toggle → change log → revert, confirmations, validation.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\SpeedRunner;
use SEOConsole\Plugin;

require_once __DIR__ . '/SpeedTestCase.php';

class SpeedRestTest extends SpeedTestCase {

	public function routes(): array {
		return array(
			array( 'GET', '/speed/runs' ),
			array( 'POST', '/speed/runs' ),
			array( 'GET', '/speed/runs/1' ),
			array( 'GET', '/speed/overview' ),
			array( 'GET', '/speed/recommendations' ),
			array( 'GET', '/speed/tweaks' ),
			array( 'POST', '/speed/tweaks/disable_emojis' ),
			array( 'GET', '/speed/cache' ),
			array( 'GET', '/speed/maintenance' ),
			array( 'POST', '/speed/maintenance/expired_transients' ),
			array( 'GET', '/speed/urls' ),
			array( 'POST', '/speed/urls' ),
			array( 'GET', '/speed/crux' ),
			array( 'POST', '/speed/crux' ),
		);
	}

	public function test_permissions(): void {
		$body = array(
			'enabled' => true,
			'confirm' => true,
		);
		$this->login( 'subscriber' );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 403, $this->call( $route[0], $route[1], 'POST' === $route[0] ? $body : array() )->get_status(), implode( ' ', $route ) );
		}
		wp_set_current_user( 0 );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 401, $this->call( $route[0], $route[1], 'POST' === $route[0] ? $body : array() )->get_status(), implode( ' ', $route ) );
		}
		$this->assertSame( array(), $this->http_requests );
		$this->assertFalse( Plugin::instance()->settings()->get( 'speed', 'tweak_disable_emojis' ) );

		$routes = rest_get_server()->get_routes( 'seo-console/v1' );
		foreach ( $routes as $path => $endpoints ) {
			if ( 0 !== strpos( $path, '/seo-console/v1/speed' ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$this->assertIsCallable( $endpoint['permission_callback'] );
			}
		}
	}

	public function test_manual_run_progress_and_overview(): void {
		$this->login( 'administrator' );
		$empty = $this->call( 'GET', '/speed/overview' )->get_data();
		$this->assertFalse( $empty['has_key'] );
		$this->assertNull( $empty['last_run'] );
		$this->assertNull( $empty['pages'][0]['mobile'] );

		$response = $this->call( 'POST', '/speed/runs' );
		$this->assertSame( 201, $response->get_status() );
		$run = $response->get_data()['run'];
		$this->assertSame( 'manual', $run['trigger_source'] );
		$this->assertSame( 409, $this->call( 'POST', '/speed/runs' )->get_status() );

		$progress = $this->call( 'GET', '/speed/runs/' . $run['id'] )->get_data();
		$this->assertSame( array( 'pending', 'pending' ), wp_list_pluck( $progress['results'], 'status' ) );

		foreach ( $progress['results'] as $row ) {
			$this->runner()->test_url( $row['id'] );
		}
		$done = $this->call( 'GET', '/speed/runs/' . $run['id'] )->get_data();
		$this->assertSame( 'done', $done['run']['status'] );
		$this->assertSame( 34, $done['results'][0]['score'] );
		$this->assertSame( 'image', $done['results'][0]['lcp_element']['type'] );
		$this->assertArrayNotHasKey( 'result', $done['results'][0] );
		$this->assertSame( 404, $this->call( 'GET', '/speed/runs/999999' )->get_status() );

		$list = $this->call( 'GET', '/speed/runs' )->get_data();
		$this->assertSame( 1, $list['total'] );

		$overview = $this->call( 'GET', '/speed/overview' )->get_data();
		$this->assertSame( $run['id'], $overview['last_run']['id'] );
		$this->assertNull( $overview['active_run'] );
		$this->assertSame( 34, $overview['pages'][0]['mobile']['score'] );
		$this->assertSame( 91, $overview['pages'][0]['desktop']['score'] );
		$this->assertSame( 'ni', $overview['field']['psi_origin']['overall'] );
		$this->assertNull( $overview['field']['phone'] );
		$this->assertCount( 1, $overview['trend']['mobile'] );
		$this->assertSame( 34, $overview['trend']['mobile'][0]['score'] );
		$this->assertSame( array( 2500, 4000 ), $overview['thresholds']['lcp'] );
	}

	public function test_recommendations_merged_and_grouped(): void {
		if ( array() !== Plugin::instance()->cache()->detect() ) {
			$this->markTestSkipped( 'Describes a site without a cache plugin (SEOC_TEST_STUB run).' );
		}
		$this->login( 'administrator' );
		$empty = $this->call( 'GET', '/speed/recommendations' )->get_data();
		$this->assertSame( array(), $empty['items'] );
		$this->assertFalse( $empty['has_results'] );

		$this->complete_run();
		$data  = $this->call( 'GET', '/speed/recommendations' )->get_data();
		$by_id = array_column( $data['items'], null, 'id' );
		$this->assertTrue( $data['has_results'] );
		$this->assertSame( 'high', $data['items'][0]['impact'] );
		$this->assertSame( 'seo-console', $by_id['prioritize-lcp-image']['group'] );
		$this->assertSame( 'lcp_boost', $by_id['prioritize-lcp-image']['fixer']['tweak'] );
		// Render-blocking failed on mobile and desktop: one merged item with both pages.
		$this->assertSame( array( 'mobile', 'desktop' ), wp_list_pluck( $by_id['render-blocking-resources']['urls'], 'strategy' ) );
		$this->assertSame( 'developer', $by_id['render-blocking-resources']['group'] );
		$this->assertGreaterThan( 0, $data['counts']['developer'] );
		$this->assertNull( $data['cache_plugin'] );
	}

	public function test_tweak_toggle_is_logged_health_checked_and_undoable(): void {
		$this->login( 'administrator' );
		$state = $this->call( 'GET', '/speed/tweaks' )->get_data();
		$this->assertCount( 9, $state['tweaks'] );
		$by_id = array_column( $state['tweaks'], null, 'id' );
		$this->assertFalse( $by_id['disable_emojis']['enabled'] );
		$this->assertSame( 'missing_config', $by_id['lcp_boost']['skipped_reason'] );
		$this->assertNull( $by_id['disable_emojis']['last_change'] );

		$response = $this->call( 'POST', '/speed/tweaks/disable_emojis', array( 'enabled' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['tweak']['enabled'] );
		$this->assertTrue( $data['tweak']['active'] );
		$this->assertSame( 'module_setting', $data['change']['type'] );
		$this->assertSame( 'speed.tweak_disable_emojis', $data['change']['field'] );
		$this->assertSame( 'speed', $data['change']['module'] );
		$this->assertTrue( $data['change']['after_value'] );
		$this->assertSame( $data['change']['id'], $data['tweak']['last_change']['id'] );
		$this->assertTrue( Plugin::instance()->settings()->get( 'speed', 'tweak_disable_emojis' ) );

		// Health check ran against the home page, and a re-test is queued.
		$this->assertNotEmpty( preg_grep( '#seoc_hc=#', wp_list_pluck( $this->http_requests, 'url' ) ) );
		$this->assertSame( $data['change']['id'], get_option( SpeedRunner::RETEST_OPTION )['change_id'] );

		// Same value again: nothing new is logged.
		$again = $this->call( 'POST', '/speed/tweaks/disable_emojis', array( 'enabled' => true ) )->get_data();
		$this->assertNull( $again['change'] );

		// Undo through the change log.
		$revert = $this->call( 'POST', '/changes/' . $data['change']['id'] . '/revert' );
		$this->assertSame( 200, $revert->get_status() );
		$this->assertFalse( Plugin::instance()->settings()->get( 'speed', 'tweak_disable_emojis' ) );

		$this->assertSame( 400, $this->call( 'POST', '/speed/tweaks/not_a_tweak', array( 'enabled' => true ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/speed/tweaks/clean_head', array() )->get_status() );
	}

	public function test_tweak_rolled_back_when_health_check_fails(): void {
		$this->login( 'administrator' );
		$this->routes['#^http://example\.org/.*seoc_hc=#'] = self::response( 500, 'Internal Server Error' );
		$response = $this->call( 'POST', '/speed/tweaks/clean_head', array( 'enabled' => true ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'seoc_health_failed', $response->get_data()['code'] );
		$this->assertFalse( Plugin::instance()->settings()->get( 'speed', 'tweak_clean_head' ) );
	}

	public function test_cache_route(): void {
		if ( array() !== Plugin::instance()->cache()->detect() ) {
			$this->markTestSkipped( 'Describes a site without a cache plugin (SEOC_TEST_STUB run).' );
		}
		$this->login( 'administrator' );
		$data = $this->call( 'GET', '/speed/cache' )->get_data();
		$this->assertSame( array(), $data['detected'] );
		$this->assertNull( $data['audited'] );
		$this->assertNotEmpty( $data['note'] );

		add_filter(
			'seoc_cache_plugins',
			static function () {
				return array(
					array(
						'id'         => 'litespeed',
						'name'       => 'LiteSpeed Cache',
						'version'    => '7.0',
						'page_cache' => true,
					),
				);
			}
		);
		update_option( 'litespeed.conf.cache', 1 );
		update_option( 'litespeed.conf.optm-css_min', 0 );
		$data = $this->call( 'GET', '/speed/cache' )->get_data();
		$this->assertSame( 'litespeed', $data['audited']['id'] );
		$this->assertSame( 'recommend', array_column( $data['findings'], null, 'id' )['minify_css']['status'] );
	}

	public function test_maintenance_requires_confirmation(): void {
		$this->login( 'administrator' );
		$report = $this->call( 'GET', '/speed/maintenance' )->get_data();
		$this->assertArrayHasKey( 'autoload', $report );
		$this->assertArrayHasKey( 'groups', $report['action_scheduler'] );

		set_transient( 'seoc_test_old', 'x', 60 );
		update_option( '_transient_timeout_seoc_test_old', time() - 10 );

		$denied = $this->call( 'POST', '/speed/maintenance/expired_transients' );
		$this->assertSame( 400, $denied->get_status() );
		$this->assertSame( 'seoc_confirm_required', $denied->get_data()['code'] );
		$this->assertSame( 1, ( new \SEOConsole\Modules\Speed\Maintenance() )->expired_transients_count() );

		$done = $this->call( 'POST', '/speed/maintenance/expired_transients', array( 'confirm' => true ) )->get_data();
		$this->assertSame( 1, $done['result']['removed'] );
		$this->assertFalse( $done['result']['undoable'] );
		$this->assertSame( 400, $this->call( 'POST', '/speed/maintenance/drop_tables', array( 'confirm' => true ) )->get_status() );
	}

	public function test_urls_validation(): void {
		$this->login( 'administrator' );
		$state = $this->call( 'GET', '/speed/urls' )->get_data();
		$this->assertSame( 'auto', $state['url_mode'] );
		$this->assertSame( 'home', $state['auto'][0]['type'] );

		$bad = $this->call(
			'POST',
			'/speed/urls',
			array(
				'url_mode'    => 'custom',
				'custom_urls' => array( '/about/', 'https://evil.example.com/' ),
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'seoc_invalid_urls', $bad->get_data()['code'] );
		$this->assertCount( 1, $bad->get_data()['data']['errors'] );
		$this->assertSame( 'auto', Plugin::instance()->settings()->get( 'speed', 'url_mode' ) );

		$this->assertSame( 400, $this->call( 'POST', '/speed/urls', array( 'url_mode' => 'custom' ) )->get_status() );

		$ok = $this->call(
			'POST',
			'/speed/urls',
			array(
				'url_mode'     => 'custom',
				'custom_urls'  => array( '/about/', home_url( '/shop/' ) ),
				'strategies'   => 'mobile',
				'psi_schedule' => 'weekly',
			)
		)->get_data();
		$this->assertSame( array( home_url( '/about/' ), home_url( '/shop/' ) ), $ok['custom_urls'] );
		$this->assertSame( array( home_url( '/about/' ), home_url( '/shop/' ) ), wp_list_pluck( $ok['effective'], 'url' ) );
		$this->assertSame( 'mobile', $ok['strategies'] );
		$this->assertSame( array( 'mobile' ), $this->runner()->strategies() );
		$this->assertSame( 400, $this->call( 'POST', '/speed/urls', array( 'psi_schedule' => 'hourly' ) )->get_status() );
	}

	public function test_crux_without_and_with_key(): void {
		$this->login( 'administrator' );
		$state = $this->call( 'GET', '/speed/crux' )->get_data();
		$this->assertFalse( $state['has_key'] );
		$this->assertNull( $state['origin']['phone'] );

		$refresh = $this->call( 'POST', '/speed/crux' );
		$this->assertSame( 400, $refresh->get_status() );
		$this->assertSame( 'seoc_crux_no_key', $refresh->get_data()['code'] );
		$this->assertSame( array(), $this->http_requests );

		Plugin::instance()->secrets()->set( 'google_api_key', 'AIzaTESTKEY' );
		$state = $this->call( 'POST', '/speed/crux' )->get_data();
		$this->assertTrue( $state['has_key'] );
		$this->assertSame( 'fail', $state['origin']['phone']['data']['assessment'] );
		$this->assertCount( 4, $state['history']['phone']['data']['periods'] );
		$this->assertSame( 3120, $state['urls'][0]['phone']['data']['metrics']['lcp']['p75'] );
		$this->assertStringNotContainsString( 'AIzaTESTKEY', wp_json_encode( $state ) );
	}

	public function test_environment_route_includes_speed_checks(): void {
		$this->login( 'administrator' );
		$data = $this->call( 'GET', '/environment' )->get_data();
		$ids  = wp_list_pluck( $data['checks'], 'id' );
		$this->assertContains( 'ttfb', $ids );
		$this->assertContains( 'opcache', $ids );
		$this->assertContains( 'cache_plugins', $ids );
	}
}
