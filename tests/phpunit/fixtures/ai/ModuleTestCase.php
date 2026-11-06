<?php
/**
 * Base for Meta/Chat module tests: core test setup (clean tables, HTTP blocked) plus a fake Claude client
 * installed as the plugin's 'claude' service.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Fixtures\AI;

use SEOConsole\Plugin;
use SEOConsole\Tests\Integration\Core\CoreTestCase;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/integration/Core/CoreTestCase.php';
require_once __DIR__ . '/FakeClient.php';

abstract class ModuleTestCase extends CoreTestCase {

	/** @var FakeClient */
	protected $client;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( 'seoc_meta_jobs', 'seoc_chat_messages' ) as $table ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . $table ); // phpcs:ignore
		}
		delete_option( 'seoc_meta_queue_stopped' );
		delete_option( 'seoc_meta_batch_lock' );
		delete_transient( 'seoc_meta_coverage' );
		$this->client = new FakeClient( array() );
		Plugin::instance()->replace_service( 'claude', $this->client );
	}

	public function tear_down(): void {
		Plugin::instance()->reset_service( 'claude' );
		parent::tear_down();
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under seo-console/v1 (leading slash).
	 * @param array  $body   JSON body.
	 * @param array  $query  Query.
	 * @return \WP_REST_Response
	 */
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
}
