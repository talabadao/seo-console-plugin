<?php
/**
 * SeoPlugins / CachePlugins / HelperCompat. Stub-specific tests run with SEOC_TEST_STUB=rankmath|yoast|litespeed|wp-rocket.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Integrations\CachePlugins;
use SEOConsole\Integrations\SeoPlugins;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class IntegrationsTest extends CoreTestCase {

	private function seo(): SeoPlugins {
		return Plugin::instance()->seo();
	}

	public function test_forced_yoast_uses_yoast_keys(): void {
		$this->force_seo_plugin( 'yoast' );
		$post = self::factory()->post->create();
		$this->assertTrue( $this->seo()->set( $post, 'title', 'Yoast <b>title</b>' ) );
		$this->seo()->set( $post, 'description', 'Yoast description' );
		$this->seo()->set( $post, 'focus_keyword', 'shoes' );
		$this->assertSame( 'Yoast title', get_post_meta( $post, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Yoast description', get_post_meta( $post, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'shoes', get_post_meta( $post, '_yoast_wpseo_focuskw', true ) );
		$this->assertSame( 'Yoast description', $this->seo()->get( $post, 'description' ) );
		$this->seo()->set( $post, 'title', '' );
		$this->assertFalse( metadata_exists( 'post', $post, '_yoast_wpseo_title' ) );
	}

	public function test_forced_rankmath_keys_and_focus_keyword_list(): void {
		$this->force_seo_plugin( 'rankmath' );
		$post = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_focus_keyword', 'shoes,running shoes,trail' );
		$this->assertSame( 'shoes', $this->seo()->get( $post, 'focus_keyword' ) );
		$this->seo()->set( $post, 'focus_keyword', 'sneakers' );
		$this->assertSame( 'sneakers,running shoes,trail', get_post_meta( $post, 'rank_math_focus_keyword', true ) );
		$this->seo()->set( $post, 'focus_keyword', '' );
		$this->assertSame( 'running shoes,trail', get_post_meta( $post, 'rank_math_focus_keyword', true ) );
		$this->seo()->set( $post, 'title', 'RM title' );
		$this->seo()->set( $post, 'description', 'RM description' );
		$this->assertSame( 'RM title', get_post_meta( $post, 'rank_math_title', true ) );
		$this->assertSame( 'RM description', get_post_meta( $post, 'rank_math_description', true ) );
	}

	public function test_none_refuses_writes(): void {
		$this->force_seo_plugin( 'none' );
		$post = self::factory()->post->create();
		$this->assertSame( 'seoc_no_seo_plugin', $this->seo()->set( $post, 'title', 'x' )->get_error_code() );
		$this->assertSame( '', $this->seo()->get( $post, 'title' ) );
		$this->assertSame( 'seoc_invalid_field', $this->seo()->set( $post, 'robots', 'x' )->get_error_code() );
	}

	public function test_detection_with_rankmath_stub(): void {
		if ( 'rankmath' !== getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Run with SEOC_TEST_STUB=rankmath.' );
		}
		$this->assertSame( 'rankmath', $this->seo()->active() );
		$post = self::factory()->post->create( array( 'post_title' => 'Stub post' ) );
		update_post_meta( $post, 'rank_math_title', '%title% %sep% %sitename%' );
		$this->assertSame( 'Stub post - ' . get_bloginfo( 'name' ), $this->seo()->resolved_title( $post ) );
	}

	public function test_detection_with_yoast_stub(): void {
		if ( 'yoast' !== getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Run with SEOC_TEST_STUB=yoast.' );
		}
		$this->assertSame( 'yoast', $this->seo()->active() );
		$post = self::factory()->post->create( array( 'post_title' => 'Stub post' ) );
		update_option( 'wpseo_titles', array( 'title-post' => '%%title%% %%sep%% %%sitename%%' ) );
		$this->assertSame( 'Stub post - ' . get_bloginfo( 'name' ), $this->seo()->resolved_title( $post ) );
	}

	public function test_no_detection_without_stub(): void {
		if ( getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Only without stubs.' );
		}
		$this->assertSame( array(), $this->seo()->detected() );
		$this->assertSame( 'none', $this->seo()->active() );
		$post = self::factory()->post->create( array( 'post_title' => 'Plain' ) );
		$this->assertSame( 'Plain', $this->seo()->resolved_title( $post ) );
	}

	public function test_litespeed_detected_by_hook_and_purged(): void {
		$calls = array();
		$all   = static function () use ( &$calls ) {
			$calls[] = 'all';
		};
		$url   = static function ( $u ) use ( &$calls ) {
			$calls[] = $u;
		};
		add_action( 'litespeed_purge_all', $all );
		add_action( 'litespeed_purge_url', $url );
		$cache = new CachePlugins();
		$this->assertContains( 'litespeed', $cache->ids() );
		$this->assertContains( 'litespeed', $cache->purge_all() );
		$cache->purge_url( home_url( '/a/' ) );
		remove_action( 'litespeed_purge_all', $all );
		remove_action( 'litespeed_purge_url', $url );
		$this->assertContains( 'all', $calls );
		$this->assertContains( home_url( '/a/' ), $calls );
	}

	public function test_nothing_detected_without_plugins(): void {
		if ( getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Only without stubs.' );
		}
		$cache = new CachePlugins();
		$this->assertSame( array(), $cache->detect() );
		$this->assertSame( array(), $cache->purge_all() );
	}

	public function test_wp_rocket_stub(): void {
		if ( 'wp-rocket' !== getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Run with SEOC_TEST_STUB=wp-rocket.' );
		}
		$GLOBALS['seoc_stub_purges'] = array();
		$cache                       = new CachePlugins();
		$detected                    = $cache->detect();
		$this->assertSame( 'wp-rocket', $detected[0]['id'] );
		$this->assertSame( '3.17-stub', $detected[0]['version'] );
		$cache->purge_all();
		$cache->purge_url( home_url( '/x/' ) );
		$this->assertSame( array( 'wp-rocket:all', 'wp-rocket:' . home_url( '/x/' ) ), $GLOBALS['seoc_stub_purges'] );
	}

	public function test_litespeed_stub(): void {
		if ( 'litespeed' !== getenv( 'SEOC_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Run with SEOC_TEST_STUB=litespeed.' );
		}
		$GLOBALS['seoc_stub_purges'] = array();
		$cache                       = new CachePlugins();
		$this->assertSame( '7.0-stub', $cache->detect()[0]['version'] );
		$cache->purge_all();
		$this->assertSame( array( 'litespeed:all' ), $GLOBALS['seoc_stub_purges'] );
	}

	public function test_helper_meta_registered_in_rest(): void {
		do_action( 'init' );
		$registered = get_registered_meta_keys( 'post', 'post' );
		foreach ( SeoPlugins::all_meta_keys() as $key ) {
			$this->assertArrayHasKey( $key, $registered );
			$this->assertTrue( (bool) $registered[ $key ]['show_in_rest'] );
			$this->assertSame( 'string', $registered[ $key ]['type'] );
		}
		$editor = $this->login( 'editor' );
		$post   = self::factory()->post->create( array( 'post_author' => $editor ) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post );
		$request->set_body_params( array( 'meta' => array( '_yoast_wpseo_title' => 'From the web app <i>x</i>' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'From the web app x', get_post_meta( $post, '_yoast_wpseo_title', true ) );

		$this->login( 'subscriber' );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post );
		$request->set_body_params( array( 'meta' => array( '_yoast_wpseo_title' => 'Hacked' ) ) );
		$this->assertGreaterThanOrEqual( 400, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'From the web app x', get_post_meta( $post, '_yoast_wpseo_title', true ) );
	}

	public function test_status_route_permissions(): void {
		$this->login( 'editor' );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/seo-console/v1/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'seo-console', $data['plugin'] );
		$this->assertSame( SEOC_VERSION, $data['version'] );
		$this->assertContains( 'seo_meta_rest', $data['features'] );

		$this->login( 'subscriber' );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/seo-console/v1/status' ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/seo-console/v1/status' ) )->get_status() );
	}

	public function test_old_helper_detection_and_notice(): void {
		$compat = Plugin::instance()->helper_compat();
		$this->assertNull( $compat->old_helper_file() );
		update_option( 'active_plugins', array( 'seo-console-meta/seo-console-meta.php' ) );
		$this->assertSame( 'seo-console-meta/seo-console-meta.php', $compat->old_helper_file() );
		$this->assertStringContainsString( 'deactivate-plugin_seo-console-meta', urldecode( \SEOConsole\Integrations\HelperCompat::deactivate_url( 'seo-console-meta/seo-console-meta.php' ) ) . 'deactivate-plugin_seo-console-meta' );

		$this->login( 'administrator' );
		$notices = ( new \SEOConsole\Admin\Notices( Plugin::instance() ) )->collect();
		$this->assertNotEmpty( $notices );
		$this->assertStringContainsString( 'action=deactivate', $notices[0][1] );
	}
}
