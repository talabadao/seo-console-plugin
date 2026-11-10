<?php
/**
 * The browser-driven speed runner: next/ingest/report-failure/advance/retry-failed.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\PsiClient;

require_once __DIR__ . '/SpeedTestCase.php';

class BrowserRunnerTest extends SpeedTestCase {

	/** Starts a manual run and returns it. */
	private function start(): array {
		$this->login( 'administrator' );
		$run = $this->call( 'POST', '/speed/runs' )->get_data()['run'];
		return $run;
	}

	public function test_next_lists_open_tests_with_keyless_psi_urls(): void {
		$run  = $this->start();
		$data = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data();
		$this->assertCount( 2, $data['tests'] );
		foreach ( $data['tests'] as $test ) {
			$this->assertStringContainsString( 'pagespeedonline', $test['psi_url'] );
			$this->assertStringNotContainsString( 'key=', $test['psi_url'] );
			$this->assertContains( $test['strategy'], array( 'mobile', 'desktop' ) );
		}
	}

	public function test_ingest_stores_a_browser_fetched_result(): void {
		$run    = $this->start();
		$tests  = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data()['tests'];
		$mobile = null;
		foreach ( $tests as $test ) {
			if ( 'mobile' === $test['strategy'] ) {
				$mobile = $test;
			}
		}
		$this->assertNotNull( $mobile );
		$body     = $this->fixture( 'psi-mobile-image-lcp.json' );
		$response = $this->call( 'POST', '/speed/results/' . $mobile['result_id'] . '/ingest', $body );
		$this->assertSame( 200, $response->get_status() );
		$row = null;
		foreach ( $response->get_data()['results'] as $r ) {
			if ( $r['id'] === $mobile['result_id'] ) {
				$row = $r;
			}
		}
		$this->assertSame( 'done', $row['status'] );
		$this->assertSame( 34, $row['score'] );
		// The server never called PageSpeed Insights for this test — the browser supplied the result.
		// (A same-host page fetch for the LCP-image boost is allowed and is not a PSI call.)
		foreach ( $this->http_requests as $req ) {
			$this->assertStringNotContainsString( 'pagespeedonline', $req['url'] );
		}
	}

	public function test_ingest_rejects_a_result_for_another_url_or_device(): void {
		$run    = $this->start();
		$tests  = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data()['tests'];
		$mobile = 'mobile' === $tests[0]['strategy'] ? $tests[0] : $tests[1];

		$other                                     = $this->fixture( 'psi-mobile-image-lcp.json' );
		$other['lighthouseResult']['finalUrl']     = 'https://evil.example.com/';
		$other['lighthouseResult']['requestedUrl'] = 'https://evil.example.com/';
		$this->assertSame( 422, $this->call( 'POST', '/speed/results/' . $mobile['result_id'] . '/ingest', $other )->get_status() );

		$wrong = $this->fixture( 'psi-mobile-image-lcp.json' );
		$wrong['lighthouseResult']['configSettings']['formFactor'] = 'desktop';
		$this->assertSame( 422, $this->call( 'POST', '/speed/results/' . $mobile['result_id'] . '/ingest', $wrong )->get_status() );

		$this->assertSame( 400, $this->call( 'POST', '/speed/results/' . $mobile['result_id'] . '/ingest', array( 'nope' => 1 ) )->get_status() );
	}

	public function test_report_failure_falls_back_to_server_when_a_key_exists(): void {
		update_option( 'seoc_secrets', array() );
		\SEOConsole\Plugin::instance()->secrets()->set( 'google_api_key', 'AIzaTESTKEY' );
		$run   = $this->start();
		$tests = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data()['tests'];
		$first = $tests[0];
		$data  = $this->call(
			'POST',
			'/speed/results/' . $first['result_id'] . '/report-failure',
			array(
				'code'        => 'quota',
				'http_status' => 429,
			)
		)->get_data();
		$this->assertTrue( $data['fall_back'] );
		$this->assertSame( 'pending', $data['result']['status'] );

		// The server pull then runs it with the keyed client (mocked internet returns the fixture).
		$advance = $this->call( 'POST', '/speed/runs/' . $run['id'] . '/advance' )->get_data();
		$this->assertTrue( $advance['advanced'] );
		$done = null;
		foreach ( $advance['results'] as $r ) {
			if ( $r['id'] === $first['result_id'] ) {
				$done = $r;
			}
		}
		$this->assertSame( 'done', $done['status'] );
	}

	public function test_report_failure_without_key_marks_quota_failed_with_advice(): void {
		$run   = $this->start();
		$tests = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data()['tests'];
		$data  = $this->call( 'POST', '/speed/results/' . $tests[0]['result_id'] . '/report-failure', array( 'code' => 'quota' ) )->get_data();
		$this->assertFalse( $data['fall_back'] );
		$this->assertSame( 'failed', $data['result']['status'] );
		$this->assertSame( 'seoc_psi_quota', $data['result']['error_code'] );
		$this->assertStringContainsString( 'Google API key', $data['result']['error'] );
	}

	public function test_retry_failed_requeues_and_reopens_the_run(): void {
		$run   = $this->start();
		$tests = $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_data()['tests'];
		foreach ( $tests as $test ) {
			$this->call( 'POST', '/speed/results/' . $test['result_id'] . '/report-failure', array( 'code' => 'unknown' ) );
		}
		$closed = $this->call( 'GET', '/speed/runs/' . $run['id'] )->get_data();
		$this->assertSame( 'failed', $closed['run']['status'] );

		$retry = $this->call( 'POST', '/speed/runs/' . $run['id'] . '/retry-failed' )->get_data();
		$this->assertSame( 2, $retry['retried'] );
		$this->assertSame( 'running', $retry['run']['status'] );
		$this->assertSame( array( 'pending', 'pending' ), wp_list_pluck( $retry['results'], 'status' ) );
	}

	public function test_non_public_urls_are_failed_up_front(): void {
		$this->assertFalse( PsiClient::is_public_url( 'http://localhost/' ) );
		$this->assertFalse( PsiClient::is_public_url( 'http://127.0.0.1/' ) );
		$this->assertFalse( PsiClient::is_public_url( 'https://mysite.test/' ) );
		$this->assertTrue( PsiClient::is_public_url( 'https://example.org/page/' ) );
	}

	public function test_browser_runner_routes_require_manage(): void {
		$run = $this->start();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/speed/runs/' . $run['id'] . '/next' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/speed/runs/' . $run['id'] . '/advance' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/speed/results/1/ingest', $this->fixture( 'psi-mobile-image-lcp.json' ) )->get_status() );
	}
}
