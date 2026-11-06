<?php
/**
 * CacheConfigAudit with seeded options for each supported cache plugin.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\CacheConfigAudit;
use WP_UnitTestCase;

class CacheConfigAuditTest extends WP_UnitTestCase {

	private static function by_id( array $findings ): array {
		return array_column( $findings, null, 'id' );
	}

	private function seed_litespeed( array $values ): void {
		foreach ( $values as $key => $value ) {
			// update_option() skips `false` for a missing option; LiteSpeed stores '' / 0 for off.
			update_option( 'litespeed.conf.' . $key, false === $value ? 0 : $value );
		}
	}

	public function test_litespeed_findings_and_features(): void {
		$this->seed_litespeed(
			array(
				'cache'          => true,
				'cache-browser'  => false,
				'cache-mobile'   => false,
				'object'         => false,
				'optm-css_min'   => true,
				'optm-js_min'    => 0,
				'optm-css_comb'  => true,
				'optm-js_comb'   => false,
				'optm-js_defer'  => 1,
				'optm-css_async' => false,
				'optm-ucss'      => false,
				'media-lazy'     => true,
				'media-vpi'      => false,
				'media-lazy_exc' => array(),
				'img_optm-webp'  => 2,
				'optm-emoji_rm'  => true,
				'crawler'        => false,
				'cdn'            => false,
				'cdn-quic'       => true,
			)
		);
		$audit = new CacheConfigAudit(
			'litespeed',
			array(
				'litespeed_server' => true,
				'ext_object_cache' => false,
			)
		);
		$f     = self::by_id( $audit->audit() );

		$this->assertSame( 'ok', $f['page_cache']['status'] );
		$this->assertSame( 'recommend', $f['browser_cache']['status'] );
		$this->assertSame( 'ok', $f['mobile_cache']['status'] );
		$this->assertSame( 'ok', $f['minify_css']['status'] );
		$this->assertSame( 'recommend', $f['minify_js']['status'] );
		$this->assertSame( 'ok', $f['combine_css']['status'] );
		$this->assertStringContainsString( 'turn this off first', $f['combine_css']['detail'] );
		$this->assertSame( 'ok', $f['defer_js']['status'] );
		$this->assertSame( 'recommend', $f['delay_js']['status'] );
		$this->assertSame( 'warning', $f['lazyload']['status'] );
		$this->assertSame( 'ok', $f['image_formats']['status'] );
		$this->assertSame( 'recommend', $f['critical_css']['status'] );
		$this->assertSame( 'recommend', $f['unused_css']['status'] );
		$this->assertSame( 'recommend', $f['preload']['status'] );
		$this->assertSame( 'recommend', $f['object_cache']['status'] );
		$this->assertSame( 'ok', $f['cdn']['status'] );
		$this->assertSame( 'unknown', $f['font_display']['status'] );

		foreach ( $f as $finding ) {
			$this->assertSame( array( 'id', 'status', 'title', 'detail', 'how_to', 'settings_url', 'impact' ), array_keys( $finding ) );
			$this->assertStringStartsWith( 'LiteSpeed Cache: ', $finding['how_to'] );
			$this->assertStringStartsWith( admin_url( 'admin.php?page=litespeed-' ), $finding['settings_url'] );
		}

		$this->assertTrue( $audit->does_feature( 'disable_emojis' ) );
		$this->assertTrue( $audit->does_feature( 'lazyload' ) );
		$this->assertTrue( $audit->does_feature( 'defer_js' ) );
		$this->assertFalse( $audit->does_feature( 'delay_js' ) );
		$this->assertTrue( $audit->does_feature( 'minify_css' ) );
		$this->assertFalse( $audit->does_feature( 'disable_embeds' ) );
		$this->assertFalse( $audit->does_feature( 'heartbeat' ) );
		$this->assertFalse( $audit->does_feature( 'no_such_feature' ) );
	}

	public function test_litespeed_vpi_excludes_lcp_and_non_litespeed_server(): void {
		$this->seed_litespeed(
			array(
				'cache'         => true,
				'media-lazy'    => true,
				'media-vpi'     => true,
				'optm-js_defer' => 2,
			)
		);
		$f = self::by_id( ( new CacheConfigAudit( 'litespeed', array( 'litespeed_server' => false ) ) )->audit() );
		$this->assertSame( 'ok', $f['lazyload']['status'] );
		$this->assertSame( 'warning', $f['page_cache']['status'] );
		$this->assertSame( 'ok', $f['delay_js']['status'] );
	}

	public function test_litespeed_without_stored_settings_is_unknown(): void {
		$audit = new CacheConfigAudit( 'litespeed' );
		$f     = self::by_id( $audit->audit() );
		$this->assertSame( 'unknown', $f['page_cache']['status'] );
		$this->assertSame( 'unknown', $f['minify_css']['status'] );
		$this->assertFalse( $audit->does_feature( 'page_cache' ) );
	}

	public function test_wp_rocket(): void {
		update_option(
			'wp_rocket_settings',
			array(
				'cache_mobile'        => 0,
				'minify_css'          => 1,
				'minify_js'           => 1,
				'defer_all_js'        => 1,
				'delay_js'            => 0,
				'async_css'           => 0,
				'remove_unused_css'   => 1,
				'lazyload'            => 1,
				'exclude_lazyload'    => array(),
				'emoji'               => 1,
				'embeds'              => 1,
				'control_heartbeat'   => 1,
				'manual_preload'      => 1,
				'cdn'                 => 0,
				'minify_google_fonts' => 1,
			)
		);
		$audit = new CacheConfigAudit(
			'wp-rocket',
			array(
				'wp_cache' => true,
				'version'  => '3.15.10',
			)
		);
		$f     = self::by_id( $audit->audit() );

		$this->assertSame( 'ok', $f['page_cache']['status'] );
		$this->assertSame( 'warning', $f['mobile_cache']['status'] );
		$this->assertSame( 'ok', $f['defer_js']['status'] );
		$this->assertSame( 'recommend', $f['delay_js']['status'] );
		$this->assertSame( 'recommend', $f['critical_css']['status'] );
		$this->assertSame( 'ok', $f['unused_css']['status'] );
		$this->assertSame( 'warning', $f['lazyload']['status'] );
		$this->assertSame( 'unknown', $f['image_formats']['status'] );
		$this->assertSame( 'ok', $f['preload']['status'] );
		$this->assertSame( 'recommend', $f['cdn']['status'] );
		$this->assertSame( 'ok', $f['font_display']['status'] );
		$this->assertSame( admin_url( 'options-general.php?page=wprocket#file_optimization' ), $f['defer_js']['settings_url'] );
		$this->assertStringStartsWith( 'WP Rocket: ', $f['defer_js']['how_to'] );

		$this->assertTrue( $audit->does_feature( 'disable_emojis' ) );
		$this->assertTrue( $audit->does_feature( 'disable_embeds' ) );
		$this->assertTrue( $audit->does_feature( 'heartbeat' ) );
		$this->assertTrue( $audit->does_feature( 'lazyload' ) );

		// 3.16+ excludes above-the-fold images automatically.
		$f = self::by_id(
			( new CacheConfigAudit(
				'wp-rocket',
				array(
					'wp_cache' => true,
					'version'  => '3.17.2',
				)
			) )->audit()
		);
		$this->assertSame( 'ok', $f['lazyload']['status'] );
	}

	public function test_wp_rocket_without_wp_cache(): void {
		update_option( 'wp_rocket_settings', array( 'cache_mobile' => 1 ) );
		$f = self::by_id( ( new CacheConfigAudit( 'wp-rocket', array( 'wp_cache' => false ) ) )->audit() );
		$this->assertSame( 'warning', $f['page_cache']['status'] );
		$this->assertStringContainsString( 'WP_CACHE', $f['page_cache']['detail'] );
		$this->assertSame( 'ok', $f['mobile_cache']['status'] );
	}

	public function test_siteground_optimizer(): void {
		$values = array(
			'enable_cache'              => 1,
			'enable_memcached'          => 0,
			'optimize_css'              => '1',
			'optimize_javascript'       => '0',
			'combine_css'               => 0,
			'optimize_javascript_async' => 1,
			'lazyload_images'           => 1,
			'webp_support'              => 0,
			'disable_emojis'            => 1,
			'optimize_web_fonts'        => 1,
			'preheat_cache'             => 0,
		);
		foreach ( $values as $key => $value ) {
			update_option( 'siteground_optimizer_' . $key, $value );
		}
		$audit = new CacheConfigAudit( 'sg-optimizer', array( 'ext_object_cache' => false ) );
		$f     = self::by_id( $audit->audit() );

		$this->assertSame( 'ok', $f['page_cache']['status'] );
		$this->assertSame( 'ok', $f['minify_css']['status'] );
		$this->assertSame( 'recommend', $f['minify_js']['status'] );
		$this->assertSame( 'ok', $f['defer_js']['status'] );
		$this->assertSame( 'warning', $f['lazyload']['status'] );
		$this->assertSame( 'recommend', $f['image_formats']['status'] );
		$this->assertSame( 'recommend', $f['object_cache']['status'] );
		$this->assertSame( 'recommend', $f['preload']['status'] );
		$this->assertSame( 'ok', $f['font_display']['status'] );
		$this->assertSame( 'unknown', $f['browser_cache']['status'] );
		// Not offered by SG Optimizer → not listed.
		$this->assertArrayNotHasKey( 'critical_css', $f );
		$this->assertArrayNotHasKey( 'delay_js', $f );
		$this->assertArrayNotHasKey( 'mobile_cache', $f );
		$this->assertSame( admin_url( 'admin.php?page=sgo_frontend' ), $f['minify_css']['settings_url'] );

		$this->assertTrue( $audit->does_feature( 'disable_emojis' ) );
		$this->assertFalse( $audit->does_feature( 'disable_embeds' ) );
	}

	public function test_external_object_cache_counts_for_any_plugin(): void {
		$audit = new CacheConfigAudit( 'wp-rocket', array( 'ext_object_cache' => true ) );
		$this->assertTrue( $audit->does_feature( 'object_cache' ) );
		$this->assertSame( 'ok', self::by_id( $audit->audit() )['object_cache']['status'] );
	}

	public function test_unsupported_plugin(): void {
		$audit = new CacheConfigAudit( 'w3tc' );
		$this->assertFalse( CacheConfigAudit::supports( 'w3tc' ) );
		$this->assertSame( array(), $audit->audit() );
		$this->assertFalse( $audit->does_feature( 'page_cache' ) );
	}

	public function test_audit_never_writes_options(): void {
		update_option( 'wp_rocket_settings', array( 'minify_css' => 0 ) );
		$this->seed_litespeed( array( 'cache' => false ) );
		$before = wp_load_alloptions();
		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'updated_option', $count );
		add_action( 'added_option', $count );
		foreach ( array_keys( CacheConfigAudit::PLUGINS ) as $id ) {
			( new CacheConfigAudit( $id ) )->audit();
		}
		$this->assertSame( 0, $writes );
		$this->assertSame( $before, wp_load_alloptions() );
	}
}
