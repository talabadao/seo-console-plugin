<?php
/**
 * Detector with simulated Rank Math, Yoast and plain HTML.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\Detector;
use SEOConsole\Modules\Schema\Store;
use WP_UnitTestCase;

class DetectorTest extends WP_UnitTestCase {

	/**
	 * @param string $content Content.
	 * @param array  $args    Post args.
	 */
	private function post_id( string $content, array $args = array() ): int {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		return self::factory()->post->create( array_merge( array( 'post_content' => wp_slash( $content ) ), $args ) );
	}

	/**
	 * @param array[] $found Findings.
	 * @return string[] "Type@source".
	 */
	private static function pairs( array $found ): array {
		return array_map(
			static function ( $f ) {
				return $f['type'] . '@' . $f['source'];
			},
			$found
		);
	}

	public function test_rank_math_defaults_and_meta(): void {
		$detector = new Detector( 'rankmath' );
		$id       = $this->post_id( 'Hello' );
		$found    = $detector->detect( $id );
		$pairs    = self::pairs( $found );
		$this->assertContains( 'BlogPosting@rankmath', $pairs, 'Default Article schema for posts.' );
		$this->assertContains( 'WebPage@rankmath', $pairs );
		$this->assertContains( 'WebSite@rankmath', $pairs );
		$this->assertContains( 'Organization@rankmath', $pairs );
		$this->assertNotContains( 'BreadcrumbList@rankmath', $pairs );
		$this->assertTrue( Detector::has( $found, 'Article' ) );
		$this->assertFalse( Detector::has( $found, 'FAQPage' ) );

		// Explicit schema in post meta replaces the default.
		add_post_meta(
			$id,
			'rank_math_schema_Recipe',
			array(
				'@type'    => 'Recipe',
				'metadata' => array( 'type' => 'template' ),
			)
		);
		update_option(
			'rank-math-options-general',
			array( 'breadcrumbs' => 'on' )
		);
		update_option(
			'rank-math-options-titles',
			array( 'knowledgegraph_type' => 'person' )
		);
		$pairs = self::pairs( $detector->detect( $id ) );
		$this->assertContains( 'Recipe@rankmath', $pairs );
		$this->assertNotContains( 'BlogPosting@rankmath', $pairs );
		$this->assertContains( 'BreadcrumbList@rankmath', $pairs );
		$this->assertContains( 'Person@rankmath', $pairs );
	}

	public function test_rank_math_default_can_be_off_and_faq_block(): void {
		update_option(
			'rank-math-options-titles',
			array( 'pt_post_default_rich_snippet' => 'off' )
		);
		$id    = $this->post_id( ExtractorsTest::fixture( 'rankmath-faq.html' ) );
		$found = ( new Detector( 'rankmath' ) )->detect( $id );
		$this->assertFalse( Detector::has( $found, 'Article' ) );
		$this->assertSame( 'rankmath', Detector::find( $found, 'FAQPage' )['source'] );
	}

	public function test_yoast_graph_pieces_and_blocks(): void {
		$id    = $this->post_id( ExtractorsTest::fixture( 'yoast-faq.html' ) );
		$found = ( new Detector( 'yoast' ) )->detect( $id );
		$pairs = self::pairs( $found );
		foreach ( array( 'WebPage', 'Article', 'BreadcrumbList', 'WebSite', 'Organization', 'FAQPage' ) as $type ) {
			$this->assertContains( $type . '@yoast', $pairs );
		}

		$page  = $this->post_id( 'About', array( 'post_type' => 'page' ) );
		$found = ( new Detector( 'yoast' ) )->detect( $page );
		$this->assertFalse( Detector::has( $found, 'Article' ), 'Yoast outputs no Article for pages by default.' );

		update_post_meta( $page, '_yoast_wpseo_schema_article_type', 'NewsArticle' );
		update_post_meta( $page, '_yoast_wpseo_schema_page_type', 'AboutPage' );
		$pairs = self::pairs( ( new Detector( 'yoast' ) )->detect( $page ) );
		$this->assertContains( 'NewsArticle@yoast', $pairs );
		$this->assertContains( 'AboutPage@yoast', $pairs );
	}

	public function test_plain_html_jsonld_microdata_and_own_schema(): void {
		$id = $this->post_id( ExtractorsTest::fixture( 'plain-jsonld.html' ) );
		Store::set(
			$id,
			array(
				array(
					'@type' => 'HowTo',
					'name'  => 'x',
				),
			)
		);
		$found = ( new Detector( 'none' ) )->detect( $id, '<html><script type="application/ld+json">{"@type":"BreadcrumbList"}</script></html>' );
		$pairs = self::pairs( $found );
		$this->assertContains( 'Recipe@content_jsonld', $pairs );
		$this->assertContains( 'WebPage@content_jsonld', $pairs );
		$this->assertContains( 'Event@content_jsonld', $pairs, 'Typed mainEntity is found.' );
		$this->assertContains( 'Product@content_microdata', $pairs );
		$this->assertContains( 'BreadcrumbList@page_html', $pairs );
		$this->assertContains( 'HowTo@seoc', $pairs );
		$this->assertNotContains( 'FAQPage@content_jsonld', $pairs, 'Our own output (class seoc-schema) is ignored.' );
		$this->assertNotContains( 'WebSite@rankmath', $pairs );

		$this->assertFalse( Detector::has( $found, 'HowTo' ), 'Own schema is not counted as external by default.' );
		$this->assertTrue( Detector::has( $found, 'HowTo', true ) );
	}

	public function test_filter_can_add_findings(): void {
		$id = $this->post_id( 'x' );
		add_filter(
			'seoc_schema_detected',
			static function ( $found ) {
				$found[] = array(
					'type'   => 'VideoObject',
					'source' => 'theme',
					'detail' => 'Theme',
				);
				return $found;
			}
		);
		$this->assertTrue( Detector::has( ( new Detector() )->detect( $id ), 'VideoObject' ) );
	}

	public function test_active_seo_plugin_fallback(): void {
		$expected = defined( 'RANK_MATH_VERSION' ) ? 'rankmath' : ( defined( 'WPSEO_VERSION' ) ? 'yoast' : 'none' );
		$this->assertSame( $expected, Detector::active_seo_plugin() );
		$this->assertSame( 'none', ( new Detector( 'bogus' ) )->seo_plugin() );
	}
}
