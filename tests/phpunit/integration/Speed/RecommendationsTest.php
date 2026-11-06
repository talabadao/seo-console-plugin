<?php
/**
 * Recommendations: catalogue coverage and to-do list building.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\CacheConfigAudit;
use SEOConsole\Modules\Speed\PsiParser;
use SEOConsole\Modules\Speed\Recommendations;
use SEOConsole\Modules\Speed\Tweaks;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/unit/Speed/SpeedTestTrait.php';

class RecommendationsTest extends WP_UnitTestCase {

	use SpeedTestTrait;

	const REQUIRED = array(
		'render-blocking-resources',
		'unused-css-rules',
		'unused-javascript',
		'unminified-css',
		'unminified-javascript',
		'uses-optimized-images',
		'modern-image-formats',
		'uses-responsive-images',
		'offscreen-images',
		'prioritize-lcp-image',
		'lcp-lazy-loaded',
		'server-response-time',
		'uses-text-compression',
		'uses-long-cache-ttl',
		'font-display',
		'third-party-summary',
		'dom-size',
		'bootup-time',
		'mainthread-work-breakdown',
		'layout-shift-elements',
		'unsized-images',
		'uses-rel-preconnect',
		'redirects',
		'total-byte-weight',
		'legacy-javascript',
		// Lighthouse 13 insights.
		'render-blocking-insight',
		'image-delivery-insight',
		'document-latency-insight',
		'third-parties-insight',
		'cache-insight',
		'lcp-discovery-insight',
		'cls-culprits-insight',
		'dom-size-insight',
		'font-display-insight',
		'legacy-javascript-insight',
	);

	/**
	 * @dataProvider required_ids
	 */
	public function test_every_required_audit_is_covered( string $id ): void {
		$rec   = new Recommendations();
		$entry = $rec->get( $id );
		$this->assertNotNull( $entry, $id );
		foreach ( array( 'title', 'what', 'why', 'how' ) as $key ) {
			$this->assertIsString( $entry[ $key ] );
			$this->assertNotEmpty( $entry[ $key ], "$id.$key" );
		}
		$this->assertContains( $entry['impact'], array( 'high', 'medium', 'low' ) );
		$this->assertContains( $entry['effort'], array( 'high', 'medium', 'low' ) );
		$this->assertNotEmpty( $entry['fixers'] );

		$tweaks = Tweaks::definitions();
		$checks = array( 'page_cache', 'browser_cache', 'mobile_cache', 'minify_css', 'minify_js', 'combine_css', 'combine_js', 'defer_js', 'delay_js', 'lazyload', 'image_formats', 'critical_css', 'unused_css', 'font_display', 'preload', 'object_cache', 'cdn' );
		foreach ( $entry['fixers'] as $fixer ) {
			$this->assertContains( $fixer['type'], array( 'seo-console', 'cache-plugin', 'developer', 'hosting' ) );
			if ( 'seo-console' === $fixer['type'] ) {
				$this->assertArrayHasKey( $fixer['tweak'], $tweaks );
			} elseif ( 'cache-plugin' === $fixer['type'] ) {
				$this->assertContains( $fixer['check'], $checks );
			} else {
				$this->assertNotEmpty( $fixer['task'] );
			}
		}
	}

	public function required_ids(): array {
		return array_map(
			static function ( $id ) {
				return array( $id );
			},
			self::REQUIRED
		);
	}

	public function test_ids_include_aliases(): void {
		$ids = ( new Recommendations() )->ids();
		foreach ( self::REQUIRED as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertSame( 'modern-image-formats', ( new Recommendations() )->get( 'image-delivery-insight' )['id'] );
		$this->assertNull( ( new Recommendations() )->get( 'no-such-audit' ) );
	}

	private function mobile_result(): array {
		return ( new PsiParser() )->parse( $this->fixture( 'psi-mobile-image-lcp.json' ), 'mobile' );
	}

	public function test_for_result_without_context(): void {
		$actions = ( new Recommendations() )->for_result( $this->mobile_result() );
		$by_id   = array_column( $actions, null, 'id' );

		$this->assertSame( 'high', $actions[0]['impact'] );
		$this->assertArrayHasKey( 'prioritize-lcp-image', $by_id );
		$this->assertSame( 'seo-console', $by_id['prioritize-lcp-image']['fixer']['type'] );
		$this->assertSame( 'lcp_boost', $by_id['prioritize-lcp-image']['fixer']['tweak'] );
		$this->assertSame( 'seo-console', $by_id['uses-rel-preconnect']['fixer']['type'] );

		// No cache plugin → developer task with affected resources.
		$rbr = $by_id['render-blocking-resources'];
		$this->assertSame( 'developer', $rbr['fixer']['type'] );
		$this->assertStringContainsString( 'https://example.org/wp-content/themes/flavor/style.css?ver=2.1', $rbr['fixer']['task'] );
		$this->assertStringContainsString( 'render-blocking-resources', $rbr['fixer']['task'] );
		$this->assertSame( 'high', $rbr['impact'] );
		$this->assertSame( 1520, $rbr['savings_ms'] );

		$this->assertSame( 'hosting', $by_id['server-response-time']['fixer']['type'] );
		$this->assertSame( 'low', $by_id['legacy-javascript']['impact'] );

		// Impact ordering.
		$ranks = array_map(
			static function ( $a ) {
				return Recommendations::IMPACT_RANK[ $a['impact'] ];
			},
			$actions
		);
		$sorted = $ranks;
		rsort( $sorted );
		$this->assertSame( $sorted, $ranks );
	}

	public function test_for_result_with_cache_plugin_and_enabled_tweaks(): void {
		update_option(
			'wp_rocket_settings',
			array(
				'defer_all_js'      => 0,
				'async_css'         => 0,
				'remove_unused_css' => 1,
				'lazyload'          => 1,
				'delay_js'          => 0,
			)
		);
		$audit   = new CacheConfigAudit(
			'wp-rocket',
			array(
				'wp_cache' => true,
				'version'  => '3.15',
			)
		);
		$actions = ( new Recommendations() )->for_result(
			$this->mobile_result(),
			array(
				'cache_audit'    => $audit,
				'enabled_tweaks' => array( 'lcp_boost' ),
			)
		);
		$by_id   = array_column( $actions, null, 'id' );

		$rbr = $by_id['render-blocking-resources'];
		$this->assertSame( 'cache-plugin', $rbr['fixer']['type'] );
		$this->assertSame( 'defer_js', $rbr['fixer']['check'] );
		$this->assertStringStartsWith( 'WP Rocket: ', $rbr['fixer']['how_to'] );
		$this->assertSame( admin_url( 'options-general.php?page=wprocket#file_optimization' ), $rbr['fixer']['settings_url'] );
		$this->assertSame( 'critical_css', $rbr['alternatives'][0]['check'] );
		$this->assertSame( 'developer', $rbr['alternatives'][1]['type'] );

		// Already on in the cache plugin → developer next, with a note.
		$ucss = $by_id['unused-css-rules'];
		$this->assertSame( 'developer', $ucss['fixer']['type'] );
		$this->assertNotEmpty( $ucss['already_done'] );

		// Tweak already on → lazy-load exclusion in WP Rocket (warning) is next.
		$lcp = $by_id['prioritize-lcp-image'];
		$this->assertNotEmpty( $lcp['already_done'] );
		$this->assertSame( 'cache-plugin', $lcp['fixer']['type'] );
		$this->assertSame( 'lazyload', $lcp['fixer']['check'] );
	}

	public function test_lcp_problem_without_psi_audit_and_metric_entries(): void {
		$result = array(
			'final_url'   => 'https://example.org/',
			'strategy'    => 'mobile',
			'audits'      => array(),
			'lab'         => array( 'ttfb' => array( 'value' => 1400 ) ),
			'field'       => array(
				'url'    => array(
					'metrics' => array(
						'inp' => array(
							'p75'      => 320,
							'category' => 'ni',
						),
					),
				),
				'origin' => null,
			),
			'lcp_element' => array(
				'type'            => 'image',
				'lazy_loaded'     => true,
				'priority_hinted' => false,
			),
		);
		$ids    = wp_list_pluck( ( new Recommendations() )->for_result( $result ), 'id' );
		$this->assertContains( 'lcp-lazy-loaded', $ids );
		$this->assertContains( 'inp', $ids );
		$this->assertContains( 'server-response-time', $ids );
	}

	public function test_lighthouse_13_result(): void {
		$result  = ( new PsiParser() )->parse( $this->fixture( 'psi-desktop-text-lcp.json' ) );
		$actions = ( new Recommendations() )->for_result( $result );
		$by_id   = array_column( $actions, null, 'id' );
		$this->assertSame( 'render-blocking-insight', $by_id['render-blocking-resources']['audit_id'] );
		$this->assertArrayHasKey( 'server-response-time', $by_id );
		$this->assertArrayHasKey( 'uses-long-cache-ttl', $by_id );
		$this->assertArrayHasKey( 'modern-image-formats', $by_id );
		$this->assertArrayNotHasKey( 'prioritize-lcp-image', $by_id );
	}
}
