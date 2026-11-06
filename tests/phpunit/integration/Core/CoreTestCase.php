<?php
/**
 * Shared setup for core integration tests: clean SEO Console tables (inside the test transaction), default
 * settings, and an HTTP mock that blocks every real request.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Plugin;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/core/FakeModule.php';

abstract class CoreTestCase extends WP_UnitTestCase {

	/** @var array<int, array{url: string, args: array}> */
	protected $http_requests = array();

	/** @var callable|null fn( string $url, array $args ) → array|WP_Error response. */
	protected $http_handler;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( Schema::CORE_TABLES as $key ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) ); // phpcs:ignore
		}
		delete_option( 'seoc_settings' );
		delete_option( 'seoc_secrets' );
		delete_option( 'seoc_loopback_ok' );
		$this->http_requests = array();
		$this->http_handler  = null;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		foreach ( array( 'settings', 'secrets', 'claude', 'usage' ) as $id ) {
			// Settings keeps registered module schemas: do not reset it.
			if ( 'settings' !== $id ) {
				Plugin::instance()->reset_service( $id );
			}
		}
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		parent::tear_down();
	}

	/**
	 * pre_http_request: records the request and answers from $http_handler, or 200 "<html>ok</html>".
	 *
	 * @param mixed  $pre  Pre value.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( $this->http_handler ) {
			return call_user_func( $this->http_handler, $url, $args );
		}
		return self::response( 200, '<html><body>ok</body></html>' );
	}

	/**
	 * Builds a fake HTTP response.
	 *
	 * @param int    $code    Status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 */
	public static function response( int $code, string $body, array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Reads a core fixture.
	 *
	 * @param string $name File name in tests/phpunit/fixtures/core/.
	 */
	public static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/core/' . $name ); // phpcs:ignore
	}

	/**
	 * Makes the loopback health check fail with HTTP 500.
	 */
	protected function break_loopback(): void {
		$home               = home_url();
		$this->http_handler = static function ( $url ) use ( $home ) {
			if ( 0 === strpos( $url, $home ) ) {
				return CoreTestCase::response( 500, 'Internal Server Error' );
			}
			return CoreTestCase::response( 200, 'ok' );
		};
	}

	/**
	 * Uses Yoast meta keys regardless of detection.
	 */
	protected function force_seo_plugin( string $id ): void {
		Plugin::instance()->settings()->set( 'general', 'seo_plugin', $id );
	}

	/**
	 * Logs in a user with a role.
	 */
	protected function login( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}
}
