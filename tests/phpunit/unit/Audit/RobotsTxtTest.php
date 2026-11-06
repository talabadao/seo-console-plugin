<?php
/**
 * robots.txt parsing and Google matching rules.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\RobotsTxt;
use WP_UnitTestCase;

class RobotsTxtTest extends WP_UnitTestCase {

	private function allowed( string $rules, string $path, string $ua = 'Googlebot' ): bool {
		return RobotsTxt::parse( "user-agent: *\n" . $rules )->is_allowed( 'http://example.org' . $path, $ua );
	}

	/**
	 * Examples from Google's "How Google interprets the robots.txt specification".
	 *
	 * @dataProvider google_precedence_cases
	 */
	public function test_google_precedence( string $rules, string $path, bool $expected ): void {
		$this->assertSame( $expected, $this->allowed( $rules, $path ) );
	}

	public function google_precedence_cases(): array {
		return array(
			'longest match wins (allow)'   => array( "allow: /p\ndisallow: /", '/page', true ),
			'tie: allow wins'              => array( "allow: /folder\ndisallow: /folder", '/folder/page', true ),
			'longest match wins (wildcard)' => array( "allow: /page\ndisallow: /*.htm", '/page.htm', false ),
			'$ anchors: root allowed'      => array( "allow: /$\ndisallow: /", '/', true ),
			'$ anchors: other blocked'     => array( "allow: /$\ndisallow: /", '/page.htm', false ),
			'no rule matches'              => array( 'disallow: /private/', '/public/', true ),
			'empty disallow allows all'    => array( 'disallow:', '/anything', true ),
		);
	}

	/**
	 * Path matching examples (Google spec, "URL matching based on path values").
	 *
	 * @dataProvider pattern_cases
	 */
	public function test_pattern_matching( string $pattern, string $path, bool $matches ): void {
		$this->assertSame( $matches, RobotsTxt::pattern_matches( $pattern, $path ), "$pattern vs $path" );
	}

	public function pattern_cases(): array {
		return array(
			array( '/', '/anything', true ),
			array( '/*', '/anything', true ),
			array( '/fish', '/fish', true ),
			array( '/fish', '/fish.html', true ),
			array( '/fish', '/fishheads/yummy.html', true ),
			array( '/fish', '/Fish.asp', false ),
			array( '/fish', '/catfish', false ),
			array( '/fish*', '/fish.php?id=anything', true ),
			array( '/fish/', '/fish/salmon.htm', true ),
			array( '/fish/', '/fish', false ),
			array( '/fish/', '/fish.html', false ),
			array( '/*.php', '/folder/filename.php', true ),
			array( '/*.php', '/filename.php?parameters', true ),
			array( '/*.php', '/windows.PHP', false ),
			array( '/*.php$', '/filename.php', true ),
			array( '/*.php$', '/filename.php?parameters', false ),
			array( '/*.php$', '/filename.php/', false ),
			array( '/fish*.php', '/fishheads/catfish.php?parameters', true ),
			array( '/fish*.php', '/Fish.PHP', false ),
			array( 'fish', '/fish', true ),
			array( '/*?', '/page?x=1', true ),
			array( '/caf%C3%A9', '/café', true ),
		);
	}

	public function test_groups_and_user_agents(): void {
		$robots = RobotsTxt::parse(
			"# comment\nUser-agent: *\nDisallow: /private/\nCrawl-delay: 3\n\nUser-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /no-google/\n\nuser-agent: googlebot\nallow: /no-google/ok\n\nUser-agent: Googlebot-News\nDisallow: /\n\nSitemap: https://example.org/sitemap_index.xml\n"
		);
		$this->assertCount( 4, $robots->groups() );
		$this->assertSame( array( 'googlebot', 'bingbot' ), $robots->groups()[1]['agents'] );

		// Googlebot uses only its own groups (merged), not '*'.
		$this->assertTrue( $robots->is_allowed( '/private/x', 'Googlebot' ) );
		$this->assertFalse( $robots->is_allowed( '/no-google/x', 'Googlebot/2.1 (+http://www.google.com/bot.html)' ) );
		$this->assertTrue( $robots->is_allowed( '/no-google/ok', 'Googlebot' ) );
		$this->assertFalse( $robots->is_allowed( '/no-google/x', 'bingbot' ) );
		// Googlebot-News has its own group; Googlebot-Image falls back to googlebot.
		$this->assertFalse( $robots->is_allowed( '/', 'Googlebot-News' ) );
		$this->assertFalse( $robots->is_allowed( '/no-google/x', 'Googlebot-Image' ) );
		$this->assertTrue( $robots->is_allowed( '/private/x', 'Googlebot-Image' ) );
		// Unknown bots use '*'.
		$this->assertFalse( $robots->is_allowed( '/private/x', 'SEOConsole-Audit/1.0 (+http://example.org/)' ) );
		$this->assertSame( 3.0, $robots->crawl_delay( 'SEOConsole-Audit/1.0' ) );
		$this->assertNull( $robots->crawl_delay( 'Googlebot' ) );

		$this->assertSame( array( 'https://example.org/sitemap_index.xml' ), $robots->sitemaps() );
		$this->assertSame( array(), $robots->errors() );
		$this->assertTrue( $robots->is_allowed( '/robots.txt', 'Googlebot-News' ), 'robots.txt itself is always allowed.' );

		$rule = $robots->matching_rule( 'http://example.org/no-google/x', 'Googlebot' );
		$this->assertSame( 'disallow', $rule['type'] );
		$this->assertSame( '/no-google/', $rule['pattern'] );
		$this->assertSame( 8, $rule['line'] );
	}

	public function test_syntax_errors(): void {
		$robots = RobotsTxt::parse( "Disallow: /early/\nUser-agent: *\nDisalow: /typo-accepted/\nNoindex: /x/\nFoo: bar\nthis line has no colon\nCrawl-delay: soon\nHost: example.org\n" );
		$codes  = array_column( $robots->errors(), 'code' );
		$this->assertSame( array( 'rule_before_user_agent', 'unsupported_noindex', 'unknown_directive', 'no_colon', 'invalid_crawl_delay' ), $codes );
		$this->assertSame( 1, $robots->errors()[0]['line'] );
		$this->assertFalse( $robots->is_allowed( '/typo-accepted/x' ), 'Common misspellings are accepted like Google does.' );
		$this->assertTrue( $robots->is_allowed( '/early/x' ), 'Rules before any user-agent are ignored.' );
	}

	public function test_empty_file_allows_everything(): void {
		$robots = RobotsTxt::parse( '' );
		$this->assertTrue( $robots->is_allowed( '/anything' ) );
		$this->assertSame( array(), $robots->groups() );
	}
}
