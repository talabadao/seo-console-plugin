<?php
/**
 * URL scope and normalisation.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\Url;
use WP_UnitTestCase;

class UrlTest extends WP_UnitTestCase {

	public function test_resolve_relative_references(): void {
		$base = 'http://example.org/blog/post/';
		$this->assertSame( 'http://example.org/blog/post/child', Url::resolve( 'child', $base ) );
		$this->assertSame( 'http://example.org/blog/up/', Url::resolve( '../up/', $base ) );
		$this->assertSame( 'http://example.org/root', Url::resolve( '/root', $base ) );
		$this->assertSame( 'https://cdn.example.org/x', Url::resolve( '//cdn.example.org/x', 'https://example.org/' ) );
		$this->assertSame( 'http://example.org/blog/post/?page=2', Url::resolve( '?page=2', $base ) );
		$this->assertSame( '', Url::resolve( 'mailto:a@b.c', $base ) );
		$this->assertSame( '', Url::resolve( 'javascript:void(0)', $base ) );
		$this->assertSame( 'http://example.org/a/c', Url::resolve( './b/../c', 'http://example.org/a/x' ) );
	}

	public function test_normalize(): void {
		$url = new Url( 'http://example.org/', true );
		$this->assertSame( 'http://example.org/about/', $url->normalize( 'HTTP://Example.ORG:80/about#team' ) );
		$this->assertSame( 'http://example.org/shop/?a=1&b=2', $url->normalize( 'http://example.org/shop?b=2&utm_source=x&a=1&fbclid=1' ) );
		$this->assertSame( 'http://example.org/file.pdf', $url->normalize( 'http://example.org/file.pdf' ) );
		$this->assertSame( 'https://other.com/x', $url->normalize( 'https://other.com/x' ), 'External URLs keep their slash.' );
		$this->assertSame( 'http://example.org/', $url->normalize( 'http://example.org' ) );
		$this->assertSame( '', $url->normalize( 'ftp://example.org/' ) );

		$no_slash = new Url( 'http://example.org/', false );
		$this->assertSame( 'http://example.org/about', $no_slash->normalize( 'http://example.org/about/' ) );
	}

	public function test_internal_and_host_variants(): void {
		$url = new Url( 'https://www.example.org/' );
		$this->assertTrue( $url->is_internal( 'http://example.org/page/' ) );
		$this->assertTrue( $url->is_internal( 'https://www.example.org/page/' ) );
		$this->assertFalse( $url->is_internal( 'https://shop.example.org/' ) );
		$this->assertTrue( $url->is_canonical_host( 'https://www.example.org/x' ) );
		$this->assertFalse( $url->is_canonical_host( 'http://www.example.org/x' ) );
		$this->assertFalse( $url->is_canonical_host( 'https://example.org/x' ) );

		$sub = new Url( 'http://example.org/blog/' );
		$this->assertTrue( $sub->is_internal( 'http://example.org/blog/post/' ) );
		$this->assertFalse( $sub->is_internal( 'http://example.org/shop/' ) );
	}

	public function test_skip_reasons(): void {
		$url = new Url( 'http://example.org/', true );
		$this->assertSame( '', $url->skip_reason( 'http://example.org/about/' ) );
		$this->assertSame( '', $url->skip_reason( 'http://example.org/category/news/page/2/' ) );
		$this->assertSame( 'external', $url->skip_reason( 'https://other.com/' ) );
		$this->assertSame( 'extension', $url->skip_reason( 'http://example.org/wp-content/uploads/a.JPG' ) );
		$this->assertSame( 'extension', $url->skip_reason( 'http://example.org/file.pdf' ) );
		foreach ( array( 'wp-admin/', 'wp-json/wp/v2/posts', 'wp-login.php', 'feed/', 'category/news/feed/', 'search/term/', 'post/embed/', 'cart/', 'post/comment-page-2/' ) as $path ) {
			$this->assertSame( 'excluded_path', $url->skip_reason( 'http://example.org/' . $path ), $path );
		}
		foreach ( array( '?replytocom=5', '?s=shoes', '?add-to-cart=12', 'shop/?filter_color=red', '?preview=true' ) as $query ) {
			$this->assertSame( 'excluded_query', $url->skip_reason( 'http://example.org/' . $query ), $query );
		}
	}
}
