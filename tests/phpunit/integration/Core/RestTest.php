<?php
/**
 * Core REST routes: shapes, validation and permissions.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Plugin;
use WP_REST_Request;

require_once __DIR__ . '/CoreTestCase.php';

class RestTest extends CoreTestCase {

	const KEY = 'sk-ant-api03-supersecretvalue-WXYZ';

	private function call( string $method, string $route, array $body = array(), array $query = array() ) {
		$request = new WP_REST_Request( $method, '/seo-console/v1' . $route );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function routes(): array {
		return array(
			array( 'GET', '/settings' ),
			array( 'POST', '/settings' ),
			array( 'POST', '/settings/test-ai' ),
			array( 'GET', '/suggestions' ),
			array( 'POST', '/suggestions/1/approve' ),
			array( 'POST', '/suggestions/1/reject' ),
			array( 'POST', '/suggestions/bulk' ),
			array( 'GET', '/changes' ),
			array( 'POST', '/changes/1/revert' ),
			array( 'GET', '/environment' ),
		);
	}

	public function test_routes_are_registered_with_permission_callbacks(): void {
		$routes = rest_get_server()->get_routes( 'seo-console/v1' );
		foreach ( array( '/seo-console/v1/settings', '/seo-console/v1/settings/test-ai', '/seo-console/v1/suggestions', '/seo-console/v1/suggestions/(?P<id>\d+)/approve', '/seo-console/v1/suggestions/(?P<id>\d+)/reject', '/seo-console/v1/suggestions/bulk', '/seo-console/v1/changes', '/seo-console/v1/changes/(?P<id>\d+)/revert', '/seo-console/v1/environment', '/seo-console/v1/status' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $routes[ $route ] as $endpoint ) {
				$this->assertIsCallable( $endpoint['permission_callback'] );
				$this->assertNotSame( '__return_true', $endpoint['permission_callback'] );
			}
		}
	}

	public function test_subscriber_and_logged_out_are_denied(): void {
		$body = array(
			'action' => 'reject',
			'ids'    => array( 1 ),
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
	}

	public function test_get_settings_never_contains_secrets(): void {
		$this->login( 'administrator' );
		Plugin::instance()->secrets()->set( 'claude_api_key', self::KEY );
		$response = $this->call( 'GET', '/settings' );
		$this->assertSame( 200, $response->get_status() );
		$data = json_decode( wp_json_encode( $response->get_data() ), true );
		$this->assertSame(
			array(
				'has'    => true,
				'masked' => 'sk-ant-…WXYZ',
				'source' => 'stored',
			),
			$data['secrets']['claude_api_key']
		);
		$this->assertFalse( $data['secrets']['google_api_key']['has'] );
		$this->assertStringNotContainsString( 'supersecretvalue', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'claude-opus-5-5', $data['general']['ai_model'] );
		$this->assertArrayHasKey( 'safe_mode', $data['general'] );
		$this->assertSame( 'off', $data['safe_mode']['source'] );
		$this->assertArrayHasKey( 'month_cost_usd', $data['ai_usage'] );
		$this->assertArrayHasKey( 'budget_usd', $data['ai_usage'] );
		$this->assertArrayHasKey( 'module_info', $data );
		$this->assertArrayHasKey( 'general', $data['schema'] );
	}

	public function test_post_settings_saves_and_handles_secrets(): void {
		$this->login( 'administrator' );
		$response = $this->call(
			'POST',
			'/settings',
			array(
				'general' => array(
					'ai_model'  => 'claude-sonnet-5-5',
					'safe_mode' => true,
				),
				'secrets' => array( 'claude_api_key' => self::KEY ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'claude-sonnet-5-5', $data['general']['ai_model'] );
		$this->assertTrue( $data['safe_mode']['on'] );
		$this->assertSame( 'setting', $data['safe_mode']['source'] );
		$this->assertTrue( $data['secrets']['claude_api_key']['has'] );
		$this->assertStringNotContainsString( 'supersecretvalue', wp_json_encode( $data ) );
		$this->assertSame( self::KEY, Plugin::instance()->secrets()->get( 'claude_api_key' ) );

		$logs = Plugin::instance()->logger()->recent();
		$this->assertStringNotContainsString( 'supersecretvalue', wp_json_encode( $logs ) );

		$response = $this->call( 'POST', '/settings', array( 'secrets' => array( 'claude_api_key' => '' ) ) );
		$this->assertFalse( $response->get_data()['secrets']['claude_api_key']['has'] );
	}

	public function test_post_settings_invalid_saves_nothing(): void {
		$this->login( 'administrator' );
		$response = $this->call(
			'POST',
			'/settings',
			array(
				'general' => array(
					'ai_model'              => 'claude-haiku-5-5',
					'ai_monthly_budget_usd' => -5,
				),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'seoc_invalid_settings', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'general.ai_monthly_budget_usd', $response->get_data()['data']['errors'] );
		$this->assertSame( 'claude-opus-5-5', Plugin::instance()->settings()->get( 'general', 'ai_model' ) );

		$response = $this->call( 'POST', '/settings', array( 'modules' => array( 'nope' => array( 'enabled' => true ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$response = $this->call( 'POST', '/settings', array( 'secrets' => array( 'other_key' => 'x' ) ) );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_test_ai_route(): void {
		$this->login( 'administrator' );
		$this->http_handler = static function () {
			return CoreTestCase::response( 200, CoreTestCase::fixture( 'claude-text.json' ) );
		};
		$response = $this->call(
			'POST',
			'/settings/test-ai',
			array(
				'model'   => 'claude-sonnet-5-5',
				'api_key' => 'sk-ant-unsaved-typed-key-1111',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertStringNotContainsString( 'unsaved-typed', wp_json_encode( $response->get_data() ) );
		$this->assertFalse( Plugin::instance()->secrets()->has( 'claude_api_key' ) );

		$this->http_handler = static function () {
			return CoreTestCase::response( 401, CoreTestCase::fixture( 'claude-error-401.json' ) );
		};
		$response = $this->call( 'POST', '/settings/test-ai', array( 'api_key' => 'sk-ant-wrong-key-00000000' ) );
		$this->assertSame( 'seoc_ai_auth', $response->get_data()['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/settings/test-ai', array( 'model' => 'gpt-5' ) )->get_status() );
		$this->assertSame( 'seoc_ai_no_key', $this->call( 'POST', '/settings/test-ai', array() )->get_data()['code'] );
	}

	public function test_suggestion_routes(): void {
		$this->login( 'administrator' );
		$this->force_seo_plugin( 'yoast' );
		$post    = self::factory()->post->create( array( 'post_title' => 'REST post' ) );
		$service = Plugin::instance()->suggestions();
		$a       = $service->create(
			array(
				'module'         => 'meta',
				'type'           => 'seo_meta',
				'object_type'    => 'post',
				'object_id'      => $post,
				'field'          => 'title',
				'proposed_value' => 'Proposed title',
			)
		);
		$b       = $service->create(
			array(
				'module'         => 'meta',
				'type'           => 'seo_meta',
				'object_type'    => 'post',
				'object_id'      => $post,
				'field'          => 'description',
				'proposed_value' => 'Proposed description',
			)
		);

		$response = $this->call( 'GET', '/suggestions', array(), array( 'per_page' => 1 ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 2, $data['total'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $data['counts']['pending'] );
		$this->assertSame( 'REST post', $data['items'][0]['object_title'] );
		$this->assertSame( 400, $this->call( 'GET', '/suggestions', array(), array( 'per_page' => 500 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/suggestions', array(), array( 'status' => 'bogus' ) )->get_status() );

		$response = $this->call( 'POST', "/suggestions/{$a}/approve", array( 'value' => 'Edited title' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'applied', $response->get_data()['suggestion']['status'] );
		$this->assertSame( 'Edited title', $response->get_data()['change']['after_value'] );
		$this->assertSame( 'Edited title', get_post_meta( $post, '_yoast_wpseo_title', true ) );

		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Changed elsewhere' );
		$response = $this->call( 'POST', "/suggestions/{$b}/approve", array() );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'seoc_stale', $response->get_data()['code'] );
		$this->assertSame( 'Changed elsewhere', $response->get_data()['data']['current_value'] );

		$response = $this->call( 'POST', "/suggestions/{$b}/reject" );
		$this->assertSame( 'rejected', $response->get_data()['suggestion']['status'] );
		$this->assertSame( 404, $this->call( 'POST', '/suggestions/999999/reject' )->get_status() );

		$response = $this->call(
			'POST',
			'/suggestions/bulk',
			array(
				'action' => 'reject',
				'ids'    => array( $a, 999999 ),
			)
		);
		$results  = $response->get_data()['results'];
		$this->assertFalse( $results[0]['ok'] );
		$this->assertSame( 'seoc_invalid_status', $results[0]['code'] );
		$this->assertSame( 'seoc_not_found', $results[1]['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/suggestions/bulk', array( 'action' => 'delete', 'ids' => array( 1 ) ) )->get_status() );
	}

	public function test_change_routes_and_conflict(): void {
		$this->login( 'administrator' );
		$this->force_seo_plugin( 'yoast' );
		$post   = self::factory()->post->create();
		$change = Plugin::instance()->changes()->apply( new ChangeRequest( 'seo_meta', 'post', $post, 'title', 'Logged title', array( 'module' => 'meta' ) ) );

		$response = $this->call( 'GET', '/changes', array(), array( 'module' => 'meta' ) );
		$data     = $response->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'SEO title', $data['items'][0]['field_label'] );
		$this->assertArrayHasKey( 'user_name', $data['items'][0] );
		$this->assertSame( 0, $this->call( 'GET', '/changes', array(), array( 'status' => 'reverted' ) )->get_data()['total'] );

		update_post_meta( $post, '_yoast_wpseo_title', 'Manual edit' );
		$response = $this->call( 'POST', "/changes/{$change->id}/revert" );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'seoc_conflict', $response->get_data()['code'] );
		$this->assertSame( 'Manual edit', $response->get_data()['data']['current_value'] );

		$response = $this->call( 'POST', "/changes/{$change->id}/revert", array( 'force' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'reverted', $response->get_data()['change']['status'] );
		$this->assertNotEmpty( $response->get_data()['change']['reverted_by_name'] );
	}

	public function test_health_failure_reports_checks(): void {
		$this->login( 'administrator' );
		$this->force_seo_plugin( 'yoast' );
		$post = self::factory()->post->create();
		$id   = Plugin::instance()->suggestions()->create(
			array(
				'type'           => 'seo_meta',
				'object_type'    => 'post',
				'object_id'      => $post,
				'field'          => 'title',
				'proposed_value' => 'Breaks the site',
			)
		);
		$this->break_loopback();
		$response = $this->call( 'POST', "/suggestions/{$id}/approve" );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'seoc_health_failed', $response->get_data()['code'] );
		$this->assertNotEmpty( $response->get_data()['data']['checks'] );
	}

	public function test_environment_route_and_filter(): void {
		$this->login( 'administrator' );
		$cb = static function ( $checks ) {
			$checks[] = array(
				'id'     => 'opcache',
				'label'  => 'OPcache',
				'value'  => 'on',
				'status' => 'good',
			);
			return $checks;
		};
		add_filter( 'seoc_environment_checks', $cb );
		$data = $this->call( 'GET', '/environment' )->get_data();
		remove_filter( 'seoc_environment_checks', $cb );
		$this->assertSame( PHP_VERSION, $data['facts']['php_version'] );
		$ids = wp_list_pluck( $data['checks'], 'id' );
		foreach ( array( 'php_version', 'wp_version', 'server_software', 'memory_limit', 'seo_plugin', 'cache_plugins', 'safe_mode', 'opcache' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
	}
}
