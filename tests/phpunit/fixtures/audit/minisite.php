<?php
/**
 * Mocked mini-site for crawler tests: route URL => response (see MockHttp).
 *
 * Pages: home, about, contact (duplicate title, no description), blog, deep (depth 2), seoc-post (real post, image
 * without alt), seoc-thin (real post, thin), old-page → temp-page → new-page (2-hop chain), missing (404),
 * loop-a ↔ loop-b (loop), orphan (sitemap only), noindex-page (in sitemap + nav), api-thing (JSON),
 * /private/ (blocked by robots.txt).
 *
 * @package SEOConsole
 */

use SEOConsole\Tests\Audit\MockHttp;

$words = static function ( int $count ): string {
	$pool = array( 'garden', 'coffee', 'river', 'window', 'travel', 'market', 'summer', 'pencil', 'forest', 'little' );
	$out  = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = $pool[ $i % 10 ];
	}
	return implode( ' ', $out );
};

$page = static function ( string $path, string $title, ?string $description, string $body, string $head = '' ) {
	$desc = null === $description ? '' : '<meta name="description" content="' . $description . '">';
	return MockHttp::html(
		'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . $title . '</title>' . $desc
		. '<link rel="canonical" href="http://example.org' . $path . '">'
		. '<meta property="og:title" content="' . $title . '"><meta property="og:image" content="http://example.org/og.jpg">'
		. $head . '</head><body>'
		. '<header><nav><a href="/">Home</a> <a href="/about/">About</a> <a href="/contact/">Contact</a> <a href="/blog/">Blog</a> <a href="/noindex-page/">Members</a></nav></header>'
		. '<main>' . $body . '</main><footer><a href="/about/">About us</a></footer></body></html>'
	);
};

$desc = 'A helpful description of this page that is long enough for search results and still under the limit.';

return array(
	'http://example.org/robots.txt'          => MockHttp::text( "User-agent: *\nDisallow: /private/\n\nSitemap: http://example.org/sitemap_index.xml\n" ),
	'http://example.org/sitemap_index.xml'   => MockHttp::xml( MockHttp::fixture( 'sitemaps/sitemap_index.xml' ) ),
	'http://example.org/post-sitemap.xml'    => MockHttp::xml( MockHttp::fixture( 'sitemaps/post-sitemap.xml' ) ),
	'http://example.org/page-sitemap.xml'    => MockHttp::xml( MockHttp::fixture( 'sitemaps/page-sitemap.xml' ) ),

	'http://example.org/'                    => $page(
		'/',
		'Mini Site Home – Gardens, Coffee and Travel',
		$desc,
		'<h1>Welcome to the mini site</h1><p>' . $words( 260 ) . '</p>'
		. '<a href="/seoc-post/">Post</a> <a href="/seoc-post/#comments">Comments</a> <a href="/seoc-thin/">Thin</a> '
		. '<a href="/old-page/">Old</a> <a href="/missing/">Missing</a> <a href="/loop-a/">Loop</a> <a href="/private/secret/">Secret</a> '
		. '<a href="/wp-content/uploads/a.jpg">Image</a> <a href="https://external.example.com/">External</a> <a href="/wp-admin/">Admin</a> '
		. '<a href="/?replytocom=5">Reply</a> <a href="mailto:hi@example.org">Mail</a> <a href="/about">About again</a>'
	),
	'http://example.org/about/'              => $page( '/about/', 'About Us | Mini Site Company', $desc . ' About.', '<h1>About us</h1><p>' . $words( 300 ) . '</p><a href="/missing/">Old link</a> <a href="/deep/">Deep</a> <a href="/api-thing/">API</a>' ),
	'http://example.org/contact/'            => $page( '/contact/', 'About Us | Mini Site Company', null, '<h1>Contact</h1><p>' . $words( 300 ) . '</p>' ),
	'http://example.org/blog/'               => $page( '/blog/', 'Blog – Latest Articles From The Mini Site', $desc . ' Blog.', '<h1>Blog</h1><p>' . $words( 50 ) . '</p><a href="/seoc-post/">Post</a> <a href="/new-page/">New</a>' ),
	'http://example.org/deep/'               => $page( '/deep/', 'A Deeper Page Found Only Through About Us', $desc . ' Deep.', '<h1>Deep</h1><p>' . $words( 300 ) . '</p>' ),
	'http://example.org/seoc-post/'          => $page( '/seoc-post/', 'A Real Blog Post With An Image Missing Alt', $desc . ' Post.', '<article><h1>Real post</h1><p>' . $words( 400 ) . '</p><img src="/wp-content/uploads/photo.jpg" width="10" height="10"></article>' ),
	'http://example.org/seoc-thin/'          => $page( '/seoc-thin/', 'A Thin Post That Has Hardly Any Words In It', $desc . ' Thin.', '<h1>Thin post</h1><p>' . $words( 40 ) . '</p>' ),
	'http://example.org/old-page/'           => MockHttp::redirect( 'http://example.org/temp-page/', 301 ),
	'http://example.org/temp-page/'          => MockHttp::redirect( '/new-page/', 302 ),
	'http://example.org/new-page/'           => $page( '/new-page/', 'The New Page That Replaced The Old Page', $desc . ' New.', '<h1>New page</h1><p>' . $words( 300 ) . '</p>' ),
	'http://example.org/loop-a/'             => MockHttp::redirect( 'http://example.org/loop-b/', 301 ),
	'http://example.org/loop-b/'             => MockHttp::redirect( 'http://example.org/loop-a/', 301 ),
	'http://example.org/orphan/'             => $page( '/orphan/', 'An Orphan Page Nobody Links To At All', $desc . ' Orphan.', '<h1>Orphan</h1><p>' . $words( 300 ) . '</p>' ),
	'http://example.org/noindex-page/'       => $page( '/noindex-page/', 'Members Area Which Is Set To Noindex', $desc . ' Members.', '<h1>Members</h1><p>' . $words( 300 ) . '</p>', '<meta name="robots" content="noindex, follow">' ),
	'http://example.org/api-thing/'          => MockHttp::response( '{"ok":true}', 200, array( 'content-type' => 'application/json' ) ),
	'http://example.org/private/secret/'     => $page( '/private/secret/', 'Private Secret Page Blocked By Robots File', $desc, '<h1>Secret</h1><p>' . $words( 300 ) . '</p>' ),
	'http://example.org/private/listed/'     => $page( '/private/listed/', 'Private Listed Page Blocked By Robots File', $desc, '<h1>Listed</h1><p>' . $words( 300 ) . '</p>' ),
);
