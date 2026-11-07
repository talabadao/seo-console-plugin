<?php
/**
 * Shared setup for Speed module integration tests: clean tables/options and a mocked internet.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\SpeedModule;
use SEOConsole\Modules\Speed\SpeedRunner;
use SEOConsole\Modules\Speed\SpeedStore;
use SEOConsole\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/unit/Speed/SpeedTestTrait.php';

abstract class SpeedTestCase extends WP_UnitTestCase {

	use SpeedTestTrait;

	const HERO_HTML = '<html><head><title>Home</title></head><body><img src="https://example.org/wp-content/uploads/2026/05/hero.jpg" srcset="https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg 768w, https://example.org/wp-content/uploads/2026/05/hero.jpg 1600w" sizes="(max-width: 768px) 100vw, 1600px" width="1600" height="854"></body></html>';

	/**
	 * Responses by URL pattern (regex => callable|array response). Checked in order.
	 *
	 * @var array
	 */
	protected $routes = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( SpeedStore::TABLES as $key ) {
			$wpdb->query( 'DELETE FROM ' . SpeedStore::table( $key ) ); // phpcs:ignore
		}
		delete_option( 'seoc_settings' );
		delete_option( 'seoc_secrets' );
		delete_option( SpeedRunner::RUNTIME_OPTION );
		delete_option( SpeedRunner::RETEST_OPTION );
		Plugin::instance()->reset_service( 'secrets' );
		$this->module()->reset_cache_features();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'seo-console' );
		}
		$this->routes = array();
		$this->mock_http( array( $this, 'route_http' ) );
	}

	/**
	 * Default fake internet: PSI fixtures by strategy, CrUX fixture, site pages return the hero HTML.
	 */
	public function route_http( $url, $args ) {
		foreach ( $this->routes as $pattern => $response ) {
			if ( preg_match( $pattern, $url ) ) {
				return is_callable( $response ) ? $response( $url, $args ) : $response;
			}
		}
		if ( false !== strpos( $url, 'pagespeedonline' ) ) {
			$fixture = false !== strpos( $url, 'strategy=desktop' ) ? 'psi-desktop-text-lcp.json' : 'psi-mobile-image-lcp.json';
			return self::response( 200, $this->fixture_raw( $fixture ) );
		}
		if ( false !== strpos( $url, 'chromeuxreport' ) ) {
			return self::response( 200, $this->fixture_raw( false !== strpos( $url, 'History' ) ? 'crux-history-origin-phone.json' : 'crux-record-origin-phone.json' ) );
		}
		return self::response( 200, self::HERO_HTML, array( 'content-encoding' => 'gzip' ) );
	}

	protected function module(): SpeedModule {
		$module = Plugin::instance()->module( 'speed' );
		$this->assertInstanceOf( SpeedModule::class, $module );
		return $module;
	}

	protected function runner(): SpeedRunner {
		return $this->module()->runner();
	}

	protected function call( string $method, string $route, array $body = array(), array $query = array() ) {
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

	protected function login( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Starts a manual run and runs every queued URL test synchronously.
	 */
	protected function complete_run(): array {
		$run = $this->runner()->start_run( 'manual' );
		$this->assertIsArray( $run );
		foreach ( $this->runner()->store()->results_for_run( $run['id'] ) as $row ) {
			$this->runner()->test_url( $row['id'] );
		}
		return $this->runner()->store()->get_run( $run['id'] );
	}
}
