<?php
/**
 * Every rule with a positive and a negative case.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\AuditStore;
use SEOConsole\Modules\Audit\IssueBuilder;
use SEOConsole\Modules\Audit\Rules\Rule;
use SEOConsole\Modules\Audit\Rules\RuleContext;
use SEOConsole\Modules\Audit\Rules\Rules;
use WP_UnitTestCase;

class RulesTest extends WP_UnitTestCase {

	const URL  = 'http://example.org/page/';
	const HOME = 'http://example.org/';

	/**
	 * A page that passes every rule.
	 *
	 * @param array $over Overrides ('extra' is merged one level deep).
	 */
	public static function page( array $over = array() ): array {
		$base = array(
			'id'                 => 1,
			'url'                => self::URL,
			'crawl_state'        => 'crawled',
			'status_code'        => 200,
			'final_status'       => 200,
			'final_url'          => null,
			'redirect_chain'     => array(),
			'is_html'            => 1,
			'indexable'          => 1,
			'indexability'       => 'indexable',
			'in_sitemap'         => 1,
			'robots_blocked'     => 0,
			'title'              => 'A good, descriptive title for this page',
			'meta_description'   => 'A meta description that is long enough to be useful in search results and short enough to show.',
			'canonical'          => self::URL,
			'robots_meta'        => '',
			'x_robots'           => '',
			'h1_count'           => 1,
			'h1'                 => 'The main heading',
			'word_count'         => 600,
			'images_total'       => 2,
			'images_missing_alt' => array(),
			'internal_links_out' => 40,
			'external_links_out' => 3,
			'inbound_internal'   => 4,
			'hreflang'           => array(),
			'og'                 => array(
				'og:title' => 'OG title',
				'og:image' => 'http://example.org/og.jpg',
			),
			'post_id'            => 12,
			'depth'              => 2,
			'response_ms'        => 350,
			'bytes'              => 60000,
			'error'              => '',
			'extra'              => array(
				'viewport'             => 'width=device-width, initial-scale=1',
				'lang'                 => 'en',
				'mixed_content'        => array(),
				'images_missing_count' => 0,
				'images_no_dimensions' => array(),
				'images_no_dims_count' => 0,
				'schema_errors'        => 0,
				'canonical_relative'   => false,
			),
		);
		$page = array_merge( $base, $over );
		if ( isset( $over['extra'] ) ) {
			$page['extra'] = array_merge( $base['extra'], $over['extra'] );
		}
		$page['url_hash'] = AuditStore::hash( $page['url'] );
		return $page;
	}

	/**
	 * Context with the page plus other pages.
	 *
	 * @param array $page Page.
	 * @param array $args 'pages' (other pages) + RuleContext args.
	 */
	private static function ctx( array $page, array $args = array() ): RuleContext {
		$pages = array_merge( array( $page ), isset( $args['pages'] ) ? $args['pages'] : array() );
		unset( $args['pages'] );
		return new RuleContext( $pages, $args + array( 'home' => self::HOME ) );
	}

	/**
	 * rule id => [ positive: [ overrides, ctx args ], negative: [ overrides, ctx args ] ].
	 */
	public static function cases(): array {
		$other   = 'http://example.org/other/';
		$dup     = static function ( $field ) use ( $other ) {
			return array( 'pages' => array( self::page( array( 'id' => 2, 'url' => $other ) ) ) );
		};
		$link_to = static function ( $status, $state = 'crawled' ) {
			return array(
				'pages' => array(
					self::page(
						array(
							'id'          => 2,
							'url'         => 'http://example.org/target/',
							'status_code' => $status,
							'crawl_state' => $state,
							'final_url'   => 'http://example.org/final/',
						)
					),
				),
				'links' => array(
					1 => array(
						array(
							'to_url' => 'http://example.org/target/',
							'anchor' => 'Read more',
						),
					),
				),
			);
		};
		$vi = static function ( array $back ) {
			return array(
				'pages' => array(
					self::page(
						array(
							'id'       => 3,
							'url'      => 'http://example.org/vi/page/',
							'hreflang' => $back,
						)
					),
				),
			);
		};
		$hreflang = array(
			array(
				'lang' => 'en',
				'url'  => self::URL,
			),
			array(
				'lang' => 'vi',
				'url'  => 'http://example.org/vi/page/',
			),
		);

		return array(
			'http_4xx'              => array( array( array( 'status_code' => 404 ) ), array( array( 'status_code' => 200 ) ) ),
			'http_5xx'              => array( array( array( 'status_code' => 503 ) ), array( array( 'status_code' => 404 ) ) ),
			'fetch_failed'          => array( array( array( 'crawl_state' => 'error' ) ), array( array() ) ),
			'redirect_chain'        => array(
				array( array( 'status_code' => 301, 'redirect_chain' => array( array( 'url' => 'a', 'status' => 301 ), array( 'url' => 'b', 'status' => 302 ) ) ) ),
				array( array( 'status_code' => 301, 'redirect_chain' => array( array( 'url' => 'a', 'status' => 301 ) ) ) ),
			),
			'redirect_loop'         => array( array( array( 'extra' => array( 'redirect_loop' => true ) ) ), array( array( 'extra' => array( 'redirect_loop' => false ) ) ) ),
			'blocked_by_robots'     => array(
				array( array( 'crawl_state' => 'blocked', 'in_sitemap' => 0 ) ),
				array( array( 'crawl_state' => 'blocked', 'in_sitemap' => 1 ) ),
			),
			'canonical_missing'     => array( array( array( 'canonical' => '' ) ), array( array( 'canonical' => '', 'indexability' => 'noindex' ) ) ),
			'canonical_relative'    => array( array( array( 'extra' => array( 'canonical_relative' => true ) ) ), array( array() ) ),
			'canonical_other'       => array( array( array( 'canonical' => $other ) ), array( array( 'canonical' => self::URL ) ) ),
			'canonical_non_200'     => array(
				array( array( 'canonical' => $other ), array( 'pages' => array( self::page( array( 'id' => 2, 'url' => $other, 'status_code' => 301 ) ) ) ) ),
				array( array( 'canonical' => $other ), array( 'pages' => array( self::page( array( 'id' => 2, 'url' => $other ) ) ) ) ),
			),
			'noindex_in_sitemap'    => array(
				array( array( 'indexability' => 'noindex', 'indexable' => 0 ) ),
				array( array( 'indexability' => 'noindex', 'indexable' => 0, 'in_sitemap' => 0 ) ),
			),
			'sitemap_error_page'    => array( array( array( 'status_code' => 410 ) ), array( array( 'status_code' => 410, 'in_sitemap' => 0 ) ) ),
			'sitemap_redirect'      => array( array( array( 'status_code' => 301 ) ), array( array( 'status_code' => 301, 'in_sitemap' => 0 ) ) ),
			'sitemap_canonicalised' => array(
				array( array( 'indexability' => 'canonicalised', 'canonical' => $other ) ),
				array( array( 'indexability' => 'canonicalised', 'canonical' => $other, 'in_sitemap' => 0 ) ),
			),
			'sitemap_blocked'       => array( array( array( 'crawl_state' => 'blocked' ) ), array( array( 'crawl_state' => 'blocked', 'in_sitemap' => 0 ) ) ),
			'missing_from_sitemap'  => array(
				array( array( 'in_sitemap' => 0 ), array( 'have_sitemap' => true ) ),
				array( array( 'in_sitemap' => 0 ), array( 'have_sitemap' => false ) ),
			),
			'noindex_in_nav'        => array(
				array( array( 'indexability' => 'noindex', 'in_sitemap' => 0 ), array( 'nav' => array( AuditStore::hash( self::URL ) ) ) ),
				array( array( 'indexability' => 'noindex', 'in_sitemap' => 0 ), array( 'nav' => array() ) ),
			),
			'broken_internal_links' => array( array( array(), $link_to( 404 ) ), array( array(), $link_to( 200 ) ) ),
			'links_to_redirect'     => array( array( array(), $link_to( 301 ) ), array( array(), $link_to( 200 ) ) ),
			'link_slash_mismatch'   => array(
				array( array(), array( 'slash_mismatch' => array( AuditStore::hash( self::URL ) => array( 'count' => 2, 'pages' => array( 'http://example.org/', 'http://example.org/a/' ) ) ) ) ),
				array( array(), array( 'slash_mismatch' => array( AuditStore::hash( 'http://example.org/other/' ) => array( 'count' => 1, 'pages' => array() ) ) ) ),
			),
			'orphan_page'           => array( array( array( 'inbound_internal' => 0 ) ), array( array( 'inbound_internal' => 0, 'in_sitemap' => 0 ) ) ),
			'too_many_links'        => array( array( array( 'internal_links_out' => 290, 'external_links_out' => 20 ) ), array( array( 'internal_links_out' => 290, 'external_links_out' => 10 ) ) ),
			'deep_page'             => array( array( array( 'depth' => 5 ) ), array( array( 'depth' => 4 ) ) ),
			'title_missing'         => array( array( array( 'title' => '  ' ) ), array( array( 'title' => '', 'indexability' => 'noindex' ) ) ),
			'title_duplicate'       => array( array( array( 'title' => 'A GOOD, descriptive  title for this page' ), $dup( 'title' ) ), array( array( 'title' => 'Unique title for the first page here' ), $dup( 'title' ) ) ),
			'title_too_long'        => array( array( array( 'title' => str_repeat( 'é', 61 ) ) ), array( array( 'title' => str_repeat( 'é', 60 ) ) ) ),
			'title_too_short'       => array( array( array( 'title' => 'Short title' ) ), array( array( 'title' => '' ) ) ),
			'meta_desc_missing'     => array( array( array( 'meta_description' => null ) ), array( array() ) ),
			'meta_desc_duplicate'   => array(
				array( array(), $dup( 'meta_description' ) ),
				array( array( 'meta_description' => 'Another description, written only for this one page so that it is unique.' ), $dup( 'meta_description' ) ),
			),
			'meta_desc_too_long'    => array( array( array( 'meta_description' => str_repeat( 'a', 161 ) ) ), array( array( 'meta_description' => str_repeat( 'a', 160 ) ) ) ),
			'meta_desc_too_short'   => array( array( array( 'meta_description' => 'Too short.' ) ), array( array( 'meta_description' => str_repeat( 'a', 70 ) ) ) ),
			'h1_missing'            => array( array( array( 'h1_count' => 0 ) ), array( array( 'h1_count' => 1 ) ) ),
			'h1_multiple'           => array( array( array( 'h1_count' => 2 ) ), array( array( 'h1_count' => 1 ) ) ),
			'title_same_as_h1'      => array( array( array( 'h1' => 'A good, descriptive title for this page' ) ), array( array() ) ),
			'thin_content'          => array( array( array( 'word_count' => 120 ) ), array( array( 'word_count' => 120, 'post_id' => 0 ) ) ),
			'img_missing_alt'       => array(
				array( array( 'images_missing_alt' => array( 'http://example.org/a.jpg' ), 'extra' => array( 'images_missing_count' => 1 ) ) ),
				array( array( 'images_missing_alt' => array( 'http://example.org/a.jpg' ), 'extra' => array( 'images_missing_count' => 1 ), 'indexability' => 'noindex' ) ),
			),
			'img_no_dimensions'     => array( array( array( 'extra' => array( 'images_no_dimensions' => array( 'x.png' ), 'images_no_dims_count' => 1 ) ) ), array( array() ) ),
			'slow_response'         => array( array( array( 'response_ms' => 2500 ) ), array( array( 'response_ms' => 2500 ), array( 'settings' => array( 'slow_ms' => 3000 ) ) ) ),
			'large_html'            => array( array( array( 'bytes' => 2200000 ) ), array( array( 'bytes' => 2000000 ) ) ),
			'mixed_content'         => array( array( array( 'extra' => array( 'mixed_content' => array( 'http://example.org/a.jpg' ) ) ) ), array( array() ) ),
			'viewport_missing'      => array( array( array( 'extra' => array( 'viewport' => '' ) ) ), array( array() ) ),
			'html_lang_missing'     => array( array( array( 'extra' => array( 'lang' => '' ) ) ), array( array() ) ),
			'hreflang_invalid'      => array(
				array( array( 'hreflang' => array( array( 'lang' => 'en-uk', 'url' => self::URL ) ) ) ),
				array( array( 'hreflang' => array( array( 'lang' => 'en-GB', 'url' => self::URL ), array( 'lang' => 'x-default', 'url' => self::URL ) ) ) ),
			),
			'hreflang_no_return'    => array(
				array( array( 'hreflang' => $hreflang ), $vi( array( array( 'lang' => 'vi', 'url' => 'http://example.org/vi/page/' ) ) ) ),
				array( array( 'hreflang' => $hreflang ), $vi( $hreflang ) ),
			),
			'og_title_missing'      => array( array( array( 'og' => array( 'og:image' => 'x' ) ) ), array( array() ) ),
			'og_image_missing'      => array( array( array( 'og' => array( 'og:title' => 'x' ) ) ), array( array() ) ),
			'schema_invalid_json'   => array( array( array( 'extra' => array( 'schema_errors' => 1 ) ) ), array( array() ) ),
		);
	}

	public function test_every_page_rule_has_cases(): void {
		$this->assertEqualsCanonicalizing( array_keys( Rules::by_scope( 'page' ) ), array_keys( self::cases() ) );
	}

	public function test_clean_page_has_no_issues(): void {
		$page = self::page();
		$this->assertSame( array(), IssueBuilder::evaluate_page( $page, self::ctx( $page ) ) );
	}

	public function test_rules_positive_and_negative(): void {
		foreach ( self::cases() as $id => $case ) {
			$rule = Rules::get( $id );
			$this->assertInstanceOf( Rule::class, $rule, $id );

			list( $pos, $neg ) = $case;
			$page              = self::page( $pos[0] );
			$data              = $rule->check_page( $page, self::ctx( $page, isset( $pos[1] ) ? $pos[1] : array() ) );
			$this->assertIsArray( $data, "$id should fire" );

			$page = self::page( $neg[0] );
			$this->assertNull( $rule->check_page( $page, self::ctx( $page, isset( $neg[1] ) ? $neg[1] : array() ) ), "$id should not fire" );
		}
	}

	public function test_issue_data_is_useful(): void {
		$page = self::page( array( 'title' => str_repeat( 'x', 75 ) ) );
		$this->assertSame( array( 'length' => 75 ), Rules::get( 'title_too_long' )->check_page( $page, self::ctx( $page ) ) );

		$case = self::cases()['broken_internal_links'][0];
		$page = self::page( $case[0] );
		$data = Rules::get( 'broken_internal_links' )->check_page( $page, self::ctx( $page, $case[1] ) );
		$this->assertSame( 1, $data['count'] );
		$this->assertSame(
			array(
				'url'    => 'http://example.org/target/',
				'status' => 404,
				'anchor' => 'Read more',
			),
			$data['links'][0]
		);

		$case = self::cases()['title_duplicate'][0];
		$page = self::page( $case[0] );
		$data = Rules::get( 'title_duplicate' )->check_page( $page, self::ctx( $page, $case[1] ) );
		$this->assertSame( array( 'http://example.org/other/' ), $data['others'] );
	}

	public function test_orphan_never_flags_home(): void {
		$page = self::page(
			array(
				'url'              => self::HOME,
				'inbound_internal' => 0,
			)
		);
		$this->assertNull( Rules::get( 'orphan_page' )->check_page( $page, self::ctx( $page ) ) );
	}

	public function test_run_rules_read_checks_from_context(): void {
		$run_ids = array_keys( Rules::by_scope( 'run' ) );
		$this->assertContains( 'loopback_failed', $run_ids );
		$this->assertContains( 'robots_disallow_all', $run_ids );
		$this->assertContains( 'sitemap_missing', $run_ids );

		$ctx    = new RuleContext(
			array(),
			array(
				'run_checks' => array(
					array(
						'rule' => 'robots_missing',
						'data' => array( 'status' => 404 ),
					),
					array(
						'rule' => 'sitemap_invalid',
						'data' => array( 'files' => array() ),
					),
				),
			)
		);
		$issues = IssueBuilder::evaluate_run( $ctx );
		$this->assertSame( array( 'robots_missing', 'sitemap_invalid' ), array_column( $issues, 'rule_id' ) );
		$this->assertNull( $issues[0]['page_id'] );
		$this->assertSame( 'warning', $issues[0]['severity'] );
		$this->assertSame( array( 'status' => 404 ), $issues[0]['data'] );

		$this->assertSame( array(), Rules::get( 'robots_missing' )->check_run( new RuleContext() ) );
	}

	public function test_metadata_is_complete(): void {
		foreach ( Rules::describe() as $id => $meta ) {
			$this->assertSame( $id, $meta['id'] );
			$this->assertContains( $meta['severity'], Rule::SEVERITIES, $id );
			$this->assertContains( $meta['category'], Rule::CATEGORIES, $id );
			$this->assertNotEmpty( $meta['title'], $id );
			$this->assertNotEmpty( $meta['why'], $id );
			$this->assertNotEmpty( $meta['how_to_fix'], $id );
			$this->assertTrue( null === $meta['fixable_by'] || in_array( $meta['fixable_by'], Rule::FIXABLE_BY, true ), $id );
		}
		$this->assertSame( 'ai', Rules::get( 'meta_desc_missing' )->fixable_by );
		$this->assertSame( 'notice', Rules::get( 'title_same_as_h1' )->severity );
	}

	public function test_valid_hreflang(): void {
		foreach ( array( 'en', 'vi', 'en-GB', 'zh-Hant-TW', 'es-419', 'x-default', 'fil' ) as $ok ) {
			$this->assertTrue( Rules::valid_hreflang( $ok ), $ok );
		}
		foreach ( array( 'en-UK', 'english', 'en_US', 'e', '', 'en-EU' ) as $bad ) {
			$this->assertFalse( Rules::valid_hreflang( $bad ), $bad );
		}
	}

	public function test_definitions_are_filterable(): void {
		Rules::reset();
		add_filter(
			'seoc_audit_rule_definitions',
			static function ( $definitions ) {
				$definitions[] = array(
					'id'         => 'custom_rule',
					'severity'   => 'notice',
					'category'   => 'content',
					'title'      => 'Custom',
					'why'        => 'Because',
					'how_to_fix' => 'Fix it',
					'check'      => '__return_null',
				);
				return $definitions;
			}
		);
		$this->assertNotNull( Rules::get( 'custom_rule' ) );
		Rules::reset();
		remove_all_filters( 'seoc_audit_rule_definitions' );
		$this->assertNull( Rules::get( 'custom_rule' ) );
	}
}
