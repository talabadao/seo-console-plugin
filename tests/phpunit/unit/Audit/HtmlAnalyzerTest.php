<?php
/**
 * HTML analyzer on fixtures.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\HtmlAnalyzer;
use WP_UnitTestCase;

require_once __DIR__ . '/MockHttp.php';

class HtmlAnalyzerTest extends WP_UnitTestCase {

	private function analyze( string $fixture, string $url, array $headers = array() ): array {
		return ( new HtmlAnalyzer() )->analyze( MockHttp::fixture( $fixture ), $url, $headers );
	}

	public function test_clean_page(): void {
		$f = $this->analyze( 'clean.html', 'https://example.org/clean/', array( 'x-robots-tag' => 'max-snippet:-1' ) );

		$this->assertSame( 'Clean Page Title For Testing The Analyzer', $f['title'] );
		$this->assertSame( 1, $f['title_count'] );
		$this->assertStringStartsWith( 'A clean, well-formed page', $f['meta_description'] );
		$this->assertSame( 'https://example.org/clean/', $f['canonical'] );
		$this->assertFalse( $f['canonical_relative'] );
		$this->assertSame( 'index, follow, max-image-preview:large', $f['robots_meta'] );
		$this->assertFalse( $f['noindex'] );
		$this->assertSame( 1, $f['h1_count'] );
		$this->assertSame( 'Clean Page Heading', $f['h1'] );
		$this->assertSame( array( array( 1, 'Clean Page Heading' ), array( 2, 'Section one' ), array( 3, 'Sub section' ) ), $f['headings'] );
		$this->assertSame( 'width=device-width, initial-scale=1', $f['viewport'] );
		$this->assertSame( 'en-US', $f['lang'] );
		$this->assertSame( 'utf-8', $f['charset'] );
		$this->assertSame( array( 'Article' ), $f['schema_types'] );
		$this->assertSame( 'Clean OG Title', $f['og']['og:title'] );
		$this->assertSame( 'summary_large_image', $f['og']['twitter:card'] );
		$this->assertCount( 3, $f['hreflang'] );
		$this->assertSame( array( 'lang' => 'vi', 'url' => 'https://example.org/vi/clean/' ), $f['hreflang'][1] );
		$this->assertSame( array(), $f['mixed_content'] );

		// Images: both have alt (one empty = decorative), both have dimensions.
		$this->assertSame( 2, $f['images_total'] );
		$this->assertSame( 0, $f['images_missing_count'] );
		$this->assertSame( 1, $f['images_empty_alt'] );
		$this->assertSame( 0, $f['images_no_dims_count'] );
		$this->assertSame( 1, $f['images_lazy'] );

		// Main text only: header/nav/footer/script are excluded.
		$expected = HtmlAnalyzer::count_words( 'Clean Page Heading This is the main text of the clean page. It has exactly a known number of words for the counter. Section one Read the related post or the partner offer. Sub section Mail us or click or top.' );
		$this->assertSame( $expected, $f['word_count'] );

		$links = array();
		foreach ( $f['links'] as $link ) {
			$links[ $link['url'] ] = $link;
		}
		$this->assertArrayHasKey( 'https://example.org/', $links );
		$this->assertTrue( $links['https://example.org/about/']['in_nav'] );
		$this->assertArrayHasKey( 'https://example.org/contact/', $links, 'Fragments are stripped.' );
		$this->assertSame( 'related post', $links['https://example.org/clean/related-post/']['anchor'] );
		$this->assertFalse( $links['https://example.org/clean/related-post/']['in_nav'] );
		$this->assertTrue( $links['https://other.example.com/page']['nofollow'] );
		$this->assertArrayHasKey( 'https://example.org/privacy/', $links );
		$this->assertCount( 6, $links, 'mailto:, javascript: and #fragment links are ignored.' );
	}

	public function test_messy_html(): void {
		$f = $this->analyze( 'messy.html', 'http://example.org/messy/' );

		$this->assertSame( 'Messy page title', $f['title'] );
		$this->assertSame( 'Short', $f['meta_description'] );
		$this->assertTrue( $f['canonical_relative'] );
		$this->assertSame( '/messy/', $f['canonical_raw'] );
		$this->assertSame( 'http://example.org/messy/', $f['canonical'] );
		$this->assertTrue( $f['noindex'] );
		$this->assertTrue( $f['nofollow'] );
		$this->assertSame( 2, $f['h1_count'] );
		$this->assertSame( '', $f['lang'] );
		$this->assertSame( '', $f['viewport'] );
		$this->assertSame( 1, $f['schema_errors'] );
		$this->assertSame( array( 'lang' => 'en-UK', 'url' => 'http://example.org/en/' ), $f['hreflang'][0] );

		$this->assertSame( 3, $f['images_total'] );
		$this->assertSame( 1, $f['images_missing_count'] );
		$this->assertSame( array( 'http://example.org/base/dir/pic.png' ), $f['images_missing_alt'] );
		$this->assertSame( 3, $f['images_no_dims_count'] );
		$this->assertContains( 'http://example.org/base/dir/lazy.png', $f['images_no_dimensions'] );

		$urls = array_column( $f['links'], 'url' );
		$this->assertContains( 'http://example.org/base/up/', $urls, 'Resolved against <base href>.' );
		$this->assertContains( 'http://example.org/base/dir/child', $urls );
		$this->assertContains( 'http://example.org/proto-relative', $urls );
		$this->assertContains( 'http://example.org/base/dir/?page=2', $urls );
		$anchors = array_column( $f['links'], 'anchor', 'url' );
		$this->assertSame( 'Image anchor', $anchors['http://example.org/in-table/'] );
	}

	public function test_latin1_document_is_converted(): void {
		$f = $this->analyze( 'latin1.html', 'http://example.org/fr/' );
		$this->assertSame( 'iso-8859-1', $f['charset'] );
		$this->assertSame( 'Café crème brûlée', $f['title'] );
		$this->assertSame( 'Déjà vu', $f['h1'] );
		$this->assertStringContainsString( 'encodée', $f['meta_description'] );
		$this->assertSame( 'fr', $f['lang'] );

		// Header charset wins over a missing meta.
		$g = ( new HtmlAnalyzer() )->analyze( "<html><head><title>Caf\xe9</title></head></html>", 'http://example.org/', array( 'content-type' => 'text/html; charset=ISO-8859-1' ) );
		$this->assertSame( 'Café', $g['title'] );
	}

	public function test_invalid_utf8_without_declaration_falls_back(): void {
		$f = ( new HtmlAnalyzer() )->analyze( "<html><head><title>Na\xefve</title></head><body></body></html>", 'http://example.org/' );
		$this->assertSame( 'Naïve', $f['title'] );
	}

	public function test_json_ld_graph_and_mixed_content(): void {
		$f = $this->analyze( 'jsonld-graph.html', 'https://example.org/faq/' );
		$this->assertSame( array( 'Organization', 'WebPage', 'FAQPage', 'BreadcrumbList', 'Product' ), $f['schema_types'] );
		$this->assertSame( 0, $f['schema_errors'] );
		$this->assertSame( 'Trang có dữ liệu cấu trúc', $f['title'] );
		$this->assertSame( 13, $f['word_count'], 'Vietnamese syllables count as words (H1 4 + paragraph 9).' );
		$this->assertEqualsCanonicalizing(
			array( 'http://example.org/wp-content/uploads/insecure.jpg', 'http://cdn.example.net/lib.js', 'http://example.org/a-300.jpg' ),
			$f['mixed_content']
		);

		// No mixed-content check on http pages.
		$g = $this->analyze( 'jsonld-graph.html', 'http://example.org/faq/' );
		$this->assertSame( array(), $g['mixed_content'] );
	}

	public function test_robots_flags(): void {
		$this->assertTrue( HtmlAnalyzer::robots_flags( '', 'noindex' )['noindex'] );
		$this->assertTrue( HtmlAnalyzer::robots_flags( '', 'googlebot: noindex, nofollow' )['noindex'] );
		$this->assertFalse( HtmlAnalyzer::robots_flags( '', 'bingbot: noindex' )['noindex'] );
		$this->assertFalse( HtmlAnalyzer::robots_flags( 'index, follow', 'unavailable_after: 2030-01-01' )['noindex'] );
		$this->assertTrue( HtmlAnalyzer::robots_flags( 'none', '' )['nofollow'] );
	}

	public function test_empty_document(): void {
		$f = ( new HtmlAnalyzer() )->analyze( '', 'http://example.org/' );
		$this->assertTrue( $f['parse_error'] );
		$this->assertNull( $f['title'] );
	}
}
