<?php
/**
 * PsiParser against hand-written PSI fixtures (Lighthouse 12 mobile, Lighthouse 13 desktop).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\PsiParser;
use WP_UnitTestCase;

require_once __DIR__ . '/SpeedTestTrait.php';

class PsiParserTest extends WP_UnitTestCase {

	use SpeedTestTrait;

	private function mobile(): array {
		return ( new PsiParser() )->parse( $this->fixture( 'psi-mobile-image-lcp.json' ), 'mobile' );
	}

	private function desktop(): array {
		return ( new PsiParser() )->parse( $this->fixture( 'psi-desktop-text-lcp.json' ) );
	}

	public function test_basic_fields_and_score(): void {
		$r = $this->mobile();
		$this->assertSame( 1, $r['schema'] );
		$this->assertSame( 'https://example.org/', $r['url'] );
		$this->assertSame( 'mobile', $r['strategy'] );
		$this->assertSame( 34, $r['score'] );
		$this->assertSame( '12.6.1', $r['lighthouse_version'] );
		$this->assertSame( '2026-11-05T02:31:12.004Z', $r['fetched_at'] );
		$this->assertCount( 1, $r['warnings'] );

		$d = $this->desktop();
		$this->assertSame( 'desktop', $d['strategy'] );
		$this->assertSame( 91, $d['score'] );
	}

	public function test_lab_metrics(): void {
		$lab = $this->mobile()['lab'];
		$this->assertEquals( 6821, $lab['lcp']['value'] );
		$this->assertSame( '6.8 s', $lab['lcp']['display'] );
		$this->assertEquals( 0.08, $lab['lcp']['score'] );
		$this->assertEquals( 3050, $lab['fcp']['value'] );
		$this->assertEquals( 480, $lab['tbt']['value'] );
		$this->assertEquals( 0.126, $lab['cls']['value'] );
		$this->assertEquals( 6110, $lab['si']['value'] );
		$this->assertEquals( 1240, $lab['ttfb']['value'] );

		// Lighthouse 13: no server-response-time; TTFB comes from the LCP breakdown.
		$this->assertEquals( 420, $this->desktop()['lab']['ttfb']['value'] );
	}

	public function test_field_data_url_and_origin(): void {
		$field = $this->mobile()['field'];
		$this->assertSame( 'https://example.org/', $field['url']['id'] );
		$this->assertSame( 'poor', $field['url']['overall'] );
		$this->assertSame( 4300, $field['url']['metrics']['lcp']['p75'] );
		$this->assertSame( 'poor', $field['url']['metrics']['lcp']['category'] );
		$this->assertEquals( 0.48, $field['url']['metrics']['lcp']['good'] );
		$this->assertEquals( 0.27, $field['url']['metrics']['lcp']['poor'] );
		$this->assertSame( 180, $field['url']['metrics']['inp']['p75'] );
		$this->assertSame( 'good', $field['url']['metrics']['inp']['category'] );
		$this->assertEquals( 0.12, $field['url']['metrics']['cls']['p75'] );
		$this->assertSame( 'ni', $field['url']['metrics']['cls']['category'] );
		$this->assertSame( 2400, $field['url']['metrics']['fcp']['p75'] );
		$this->assertSame( 1450, $field['url']['metrics']['ttfb']['p75'] );

		$this->assertSame( 'https://example.org', $field['origin']['id'] );
		$this->assertSame( 'ni', $field['origin']['overall'] );
		$this->assertEquals( 0.04, $field['origin']['metrics']['cls']['p75'] );
		$this->assertSame( 'good', $field['origin']['metrics']['cls']['category'] );
	}

	public function test_without_field_data(): void {
		$field = $this->desktop()['field'];
		$this->assertNull( $field['url'] );
		$this->assertNull( $field['origin'] );
	}

	public function test_origin_fallback_is_not_url_data(): void {
		$raw                                         = $this->fixture( 'psi-mobile-image-lcp.json' );
		$raw['loadingExperience']['origin_fallback'] = true;
		$r = ( new PsiParser() )->parse( $raw );
		$this->assertNull( $r['field']['url'] );
		$this->assertNotNull( $r['field']['origin'] );
	}

	public function test_image_lcp_element(): void {
		$lcp = $this->mobile()['lcp_element'];
		$this->assertSame( 'image', $lcp['type'] );
		$this->assertSame( 'https://example.org/wp-content/uploads/2026/05/hero-768x410.jpg', $lcp['image_url'] );
		$this->assertSame( 'div.site-content > section.hero > img.hero-image', $lcp['selector'] );
		$this->assertStringStartsWith( '<img', $lcp['snippet'] );
		$this->assertSame( 'Our team', $lcp['label'] );
		$this->assertTrue( $lcp['lazy_loaded'] );
		$this->assertFalse( $lcp['priority_hinted'] );
		$this->assertSame(
			array(
				'ttfb'         => 1240,
				'load_delay'   => 2980,
				'load_time'    => 2250,
				'render_delay' => 351,
			),
			$lcp['phases']
		);
	}

	public function test_text_lcp_element_lighthouse_13(): void {
		$lcp = $this->desktop()['lcp_element'];
		$this->assertSame( 'text', $lcp['type'] );
		$this->assertSame( '', $lcp['image_url'] );
		$this->assertSame( 'main.site-main > article > header > h1.entry-title', $lcp['selector'] );
		$this->assertSame( 'About our agency', $lcp['label'] );
		$this->assertFalse( $lcp['lazy_loaded'] );
		$this->assertNull( $lcp['priority_hinted'] );
		$this->assertSame( 420, $lcp['phases']['ttfb'] );
		$this->assertSame( 890, $lcp['phases']['render_delay'] );
	}

	public function test_lcp_image_from_snippet_relative_and_lh13_discovery(): void {
		$raw = array(
			'lighthouseResult' => array(
				'finalUrl' => 'https://example.org/blog/post/',
				'audits'   => array(
					'lcp-discovery-insight' => array(
						'score'            => 0,
						'scoreDisplayMode' => 'metricSavings',
						'details'          => array(
							'type'  => 'list',
							'items' => array(
								array(
									'type'  => 'checklist',
									'items' => array(
										'priorityHinted'      => array( 'value' => false ),
										'eagerlyLoaded'       => array( 'value' => false ),
										'requestDiscoverable' => array( 'value' => true ),
									),
								),
								array(
									'type'     => 'node',
									'selector' => 'figure > img',
									'snippet'  => '<img src="/wp-content/uploads/a.webp" srcset="/wp-content/uploads/a.webp 800w" sizes="100vw">',
								),
							),
						),
					),
				),
			),
		);
		$lcp = ( new PsiParser() )->parse( $raw )['lcp_element'];
		$this->assertSame( 'image', $lcp['type'] );
		$this->assertSame( 'https://example.org/wp-content/uploads/a.webp', $lcp['image_url'] );
		$this->assertTrue( $lcp['lazy_loaded'] );
		$this->assertFalse( $lcp['priority_hinted'] );
		$this->assertSame( '/wp-content/uploads/a.webp 800w', $lcp['srcset'] );
		$this->assertSame( '100vw', $lcp['sizes'] );
	}

	public function test_third_parties_and_preconnect(): void {
		$r      = $this->mobile();
		$third  = $r['third_parties'];
		$names  = wp_list_pluck( $third, 'entity' );
		$this->assertSame( array( 'Google Tag Manager', 'Facebook', 'Google Fonts' ), $names );
		$this->assertSame( array( 'https://www.googletagmanager.com' ), $third[0]['origins'] );
		$this->assertSame( 210, $third[0]['blocking_ms'] );
		$this->assertSame( array( 'https://fonts.googleapis.com', 'https://fonts.gstatic.com' ), $third[2]['origins'] );

		$pre = $r['preconnect_candidates'];
		$this->assertSame( 'https://fonts.gstatic.com', $pre[0] );
		$this->assertContains( 'https://fonts.googleapis.com', $pre );
		$this->assertLessThanOrEqual( 4, count( $pre ) );
		foreach ( $pre as $origin ) {
			$this->assertStringNotContainsString( 'example.org', $origin );
		}

		// Lighthouse 13 insight: the site's own entity is excluded.
		$d = $this->desktop();
		$this->assertSame( array( 'Google Fonts' ), wp_list_pluck( $d['third_parties'], 'entity' ) );
		$this->assertSame( array( 'https://fonts.gstatic.com', 'https://fonts.googleapis.com' ), $d['preconnect_candidates'] );
	}

	public function test_third_parties_fallback_to_network_requests(): void {
		$raw = $this->fixture( 'psi-mobile-image-lcp.json' );
		unset( $raw['lighthouseResult']['audits']['third-party-summary'] );
		$third = ( new PsiParser() )->parse( $raw )['third_parties'];
		$this->assertSame(
			array( 'https://fonts.gstatic.com', 'https://cdn.example-cdn.net', 'https://fonts.googleapis.com' ),
			array_merge( ...wp_list_pluck( $third, 'origins' ) )
		);
	}

	public function test_failing_audits(): void {
		$audits = $this->mobile()['audits'];
		$ids    = wp_list_pluck( $audits, 'id' );

		foreach ( array( 'render-blocking-resources', 'unused-css-rules', 'modern-image-formats', 'prioritize-lcp-image', 'lcp-lazy-loaded', 'server-response-time', 'third-party-summary', 'dom-size', 'bootup-time', 'uses-long-cache-ttl', 'font-display', 'layout-shift-elements', 'unsized-images', 'legacy-javascript', 'total-byte-weight', 'uses-rel-preconnect' ) as $id ) {
			$this->assertContains( $id, $ids, $id );
		}
		// Passing, metric and informational audits are left out.
		foreach ( array( 'uses-text-compression', 'redirects', 'unminified-javascript', 'largest-contentful-paint', 'network-requests', 'performance-budget', 'largest-contentful-paint-element' ) as $id ) {
			$this->assertNotContains( $id, $ids, $id );
		}

		$by_id = array_column( $audits, null, 'id' );
		$rbr   = $by_id['render-blocking-resources'];
		$this->assertSame( 'opportunity', $rbr['kind'] );
		$this->assertSame( 1520, $rbr['savings_ms'] );
		$this->assertSame( array( 'FCP' => 1520, 'LCP' => 1520 ), $rbr['metric_savings'] );
		$this->assertCount( 5, $rbr['items'] );
		$this->assertSame( 930, $rbr['items'][0]['wastedMs'] );
		$this->assertSame( 90112, $by_id['unused-css-rules']['savings_bytes'] );
		$this->assertSame( 'diagnostic', $by_id['dom-size']['kind'] );
		$this->assertSame( 1840, $by_id['dom-size']['items'][0]['value'] );
		$this->assertSame( 'Google Tag Manager', $by_id['third-party-summary']['items'][0]['entity'] );
		$this->assertSame( 'Facebook', $by_id['third-party-summary']['items'][1]['entity'] );
		$this->assertSame( 'header.site-header', $by_id['layout-shift-elements']['items'][0]['selector'] );

		// Biggest win first.
		$this->assertSame( 'prioritize-lcp-image', $audits[0]['id'] );

		$d_ids = wp_list_pluck( $this->desktop()['audits'], 'id' );
		$this->assertContains( 'render-blocking-insight', $d_ids );
		$this->assertContains( 'document-latency-insight', $d_ids );
		$this->assertContains( 'cache-insight', $d_ids );
		$this->assertNotContains( 'third-parties-insight', $d_ids );
	}

	public function test_size_budget(): void {
		$this->assertLessThan( PsiParser::MAX_BYTES, strlen( wp_json_encode( $this->mobile() ) ) );

		// A bloated response still fits.
		$raw = $this->fixture( 'psi-mobile-image-lcp.json' );
		for ( $i = 0; $i < 60; $i++ ) {
			$items = array();
			for ( $j = 0; $j < 50; $j++ ) {
				$items[] = array(
					'url'         => 'https://example.org/' . str_repeat( 'x', 900 ) . $j,
					'wastedBytes' => 1000 + $j,
				);
			}
			$raw['lighthouseResult']['audits'][ 'fake-audit-' . $i ] = array(
				'title'            => str_repeat( 'Long title ', 80 ),
				'score'            => 0,
				'scoreDisplayMode' => 'metricSavings',
				'details'          => array(
					'type'                => 'opportunity',
					'items'               => $items,
					'overallSavingsBytes' => 5000,
				),
			);
		}
		$r = ( new PsiParser() )->parse( $raw );
		$this->assertLessThanOrEqual( PsiParser::MAX_BYTES, strlen( wp_json_encode( $r ) ) );
		$this->assertLessThanOrEqual( PsiParser::MAX_AUDITS, count( $r['audits'] ) );
		$this->assertNotNull( $r['lab']['lcp'] );
	}

	public function test_empty_input_is_safe(): void {
		$r = ( new PsiParser() )->parse( array() );
		$this->assertNull( $r['score'] );
		$this->assertNull( $r['lab']['lcp'] );
		$this->assertNull( $r['lcp_element'] );
		$this->assertSame( array(), $r['audits'] );
	}
}
