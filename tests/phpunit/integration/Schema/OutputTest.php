<?php
/**
 * Output in the three modes: Rank Math graph, Yoast graph, own script tag.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\Output;
use SEOConsole\Modules\Schema\SettingsFields;
use SEOConsole\Modules\Schema\Store;
use SEOConsole\Modules\Schema\Types;
use WP_UnitTestCase;

class OutputTest extends WP_UnitTestCase {

	/** @var int */
	private $post_id;

	/** @var string */
	private $url;

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'rank_math/json_ld' );
		remove_all_filters( 'wpseo_schema_graph' );
		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Coffee FAQ',
				'post_content' => '<h2>Do you ship abroad?</h2><p>Yes, to 20 countries.</p>',
			)
		);
		Store::set(
			$this->post_id,
			array(
				array(
					'@type'      => 'FAQPage',
					'mainEntity' => array(
						array(
							'@type'          => 'Question',
							'name'           => 'Do you ship abroad?',
							'acceptedAnswer' => array(
								'@type' => 'Answer',
								'text'  => 'Yes, to 20 countries.',
							),
						),
					),
				),
				array(
					'@type'    => 'BlogPosting',
					'headline' => 'Coffee FAQ',
				),
				array(
					'@type'        => 'VideoObject',
					'name'         => 'Roasting </script><script>alert(1)</script>',
					'thumbnailUrl' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
					'uploadDate'   => '2025-01-02',
				),
			)
		);
		$this->url = (string) get_permalink( $this->post_id );
	}

	/**
	 * @param array $settings Settings overrides.
	 * @return callable
	 */
	private static function settings( array $settings = array() ): callable {
		return static function () use ( $settings ) {
			return array_merge( SettingsFields::defaults(), $settings );
		};
	}

	/**
	 * Counts nodes per de-duplication group.
	 *
	 * @param array[] $graph Graph.
	 * @return array<string,int>
	 */
	private static function groups( array $graph ): array {
		$out = array();
		foreach ( $graph as $node ) {
			$groups = array();
			foreach ( (array) $node['@type'] as $type ) {
				$groups[ Types::group( $type ) ] = true;
			}
			foreach ( array_keys( $groups ) as $group ) {
				$out[ $group ] = isset( $out[ $group ] ) ? $out[ $group ] + 1 : 1;
			}
		}
		return $out;
	}

	public function test_rank_math_graph(): void {
		$this->go_to( $this->url );
		( new Output( 'rankmath', null, self::settings() ) )->register();
		$data = array(
			'WebSite'     => array(
				'@type' => 'WebSite',
				'@id'   => home_url( '/' ) . '#website',
			),
			'publisher'   => array(
				'@type' => array( 'Person', 'Organization' ),
				'@id'   => home_url( '/' ) . '#organization',
			),
			'WebPage'     => array(
				'@type' => 'WebPage',
				'@id'   => $this->url . '#webpage',
			),
			'richSnippet' => array(
				'@type' => 'BlogPosting',
				'@id'   => $this->url . '#richSnippet',
			),
		);
		$data = apply_filters( 'rank_math/json_ld', $data, new \stdClass() );

		$this->assertArrayHasKey( 'seoc_seoc-faq', $data );
		$this->assertArrayHasKey( 'seoc_seoc-video', $data );
		$json = wp_json_encode( array_values( $data ) );
		$this->assertSame( 1, substr_count( $json, '#seoc-faq"' ) );
		$this->assertStringNotContainsString( '#seoc-article', $json, 'Rank Math already outputs the article.' );
		$groups = self::groups( array_values( $data ) );
		$this->assertSame( 1, $groups['Article'] );
		$this->assertSame( 1, $groups['FAQPage'] );
		$this->assertSame( array( '@id' => $this->url . '#webpage' ), $data['seoc_seoc-faq']['isPartOf'] );
	}

	public function test_yoast_graph_skips_faq_already_in_webpage(): void {
		$this->go_to( $this->url );
		( new Output( 'yoast', null, self::settings() ) )->register();
		$graph = array(
			array(
				'@type' => array( 'WebPage', 'FAQPage' ),
				'@id'   => $this->url,
			),
			array(
				'@type' => 'Article',
				'@id'   => $this->url . '#article',
			),
			array(
				'@type' => 'BreadcrumbList',
				'@id'   => $this->url . '#breadcrumb',
			),
			array(
				'@type' => 'Organization',
				'@id'   => home_url( '/' ) . '#organization',
			),
		);
		$graph = apply_filters( 'wpseo_schema_graph', $graph, new \stdClass() );

		$this->assertCount( 5, $graph );
		$ours = end( $graph );
		$this->assertSame( 'VideoObject', $ours['@type'] );
		$this->assertSame( array( '@id' => $this->url ), $ours['isPartOf'], 'Linked to the Yoast WebPage @id.' );
		$groups = self::groups( $graph );
		$this->assertSame( 1, $groups['FAQPage'] );
		$this->assertSame( 1, $groups['Article'] );
	}

	public function test_yoast_graph_adds_faq_once(): void {
		$this->go_to( $this->url );
		( new Output( 'yoast', null, self::settings() ) )->register();
		$graph = apply_filters(
			'wpseo_schema_graph',
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => $this->url,
				),
			),
			null
		);
		$json = wp_json_encode( $graph );
		$this->assertSame( 1, substr_count( $json, '"FAQPage"' ) );
		$this->assertSame( 1, substr_count( $json, '#seoc-faq"' ) );
		$this->assertSame( 1, substr_count( $json, '"BlogPosting"' ) );
	}

	public function test_no_seo_plugin_prints_one_escaped_script(): void {
		$this->go_to( $this->url );
		$output = new Output( 'none', null, self::settings() );
		$output->register();
		$this->assertSame( 30, has_action( 'wp_head', array( $output, 'print_script' ) ) );

		ob_start();
		$output->print_script();
		$html = ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<script type="application/ld+json" class="seoc-schema">' ) );
		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Content cannot close the script element.' );
		$this->assertStringContainsString( 'Roasting <\/script>', $html );
		$this->assertStringContainsString( 'https://i.ytimg.com/vi/', $html, 'Slashes are not escaped.' );

		preg_match( '#<script[^>]*>(.*)</script>#s', $html, $m );
		$decoded = json_decode( $m[1], true );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
		$this->assertSame( array( 'FAQPage', 'BlogPosting', 'VideoObject' ), wp_list_pluck( $decoded['@graph'], '@type' ) );
		$this->assertSame( $this->url, $decoded['@graph'][0]['url'] );
		$this->assertSame( 1, self::groups( $decoded['@graph'] )['FAQPage'] );
	}

	public function test_nothing_on_other_pages_or_when_disabled(): void {
		$other = self::factory()->post->create();
		$this->go_to( get_permalink( $other ) );
		$this->assertSame( array(), ( new Output( 'none', null, self::settings() ) )->pieces( array() ) );

		$this->go_to( $this->url );
		ob_start();
		( new Output( 'none', null, self::settings( array( 'output_enabled' => false ) ) ) )->print_script();
		$this->assertSame( '', ob_get_clean() );

		$this->go_to( '/?s=coffee' );
		$this->assertSame( array(), ( new Output( 'none', null, self::settings() ) )->pieces( array() ) );
	}

	public function test_front_page_business(): void {
		$restaurant = array(
			'business_type'    => 'Restaurant',
			'business_name'    => 'Pho 24',
			'address_street'   => '1 Le Loi',
			'address_locality' => 'Hanoi',
			'opening_hours'    => array( 'Mo-Su 07:00-22:00' ),
		);
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_front_page() );

		// Rank Math outputs a generic Organization: our LocalBusiness is added and points to it.
		$output = new Output( 'rankmath', null, self::settings( $restaurant ) );
		$data   = $output->filter_rank_math(
			array(
				'publisher' => array(
					'@type' => 'Organization',
					'@id'   => home_url( '/' ) . '#organization',
				),
			)
		);
		$this->assertSame( 'Restaurant', $data['seoc_seoc-localbusiness']['@type'] );
		$this->assertSame( array( '@id' => home_url( '/' ) . '#organization' ), $data['seoc_seoc-localbusiness']['parentOrganization'] );

		// Rank Math Local SEO already outputs a LocalBusiness: nothing added.
		$data = $output->filter_rank_math(
			array(
				'publisher' => array(
					'@type' => array( 'Restaurant', 'Organization' ),
					'@id'   => home_url( '/' ) . '#organization',
				),
			)
		);
		$this->assertCount( 1, $data );

		// A plain Organization is never duplicated in a SEO plugin graph...
		$org   = array(
			'business_type' => 'Organization',
			'business_name' => 'Acme',
		);
		$graph = ( new Output( 'yoast', null, self::settings( $org ) ) )->filter_yoast(
			array(
				array(
					'@type' => 'Organization',
					'@id'   => home_url( '/' ) . '#organization',
				),
			)
		);
		$this->assertCount( 1, $graph );

		// ...but is printed when there is no SEO plugin.
		ob_start();
		( new Output( 'none', null, self::settings( $org ) ) )->print_script();
		$html = ob_get_clean();
		$this->assertStringContainsString( '"@id":"' . home_url( '/' ) . '#seoc-organization"', $html );

		// Invalid settings (LocalBusiness without address) output nothing.
		$this->assertSame(
			array(),
			( new Output(
				'none',
				null,
				self::settings(
					array(
						'business_type' => 'Store',
						'business_name' => 'X',
					)
				)
			) )->pieces( array() )
		);
	}

	public function test_merge_and_encode(): void {
		$ours = array(
			array(
				'@type' => 'FAQPage',
				'@id'   => 'a',
			),
			array(
				'@type' => 'FAQPage',
				'@id'   => 'b',
			),
			array(
				'@type' => 'NewsArticle',
				'@id'   => 'c',
			),
			array( '@type' => 'Product' ),
		);
		$this->assertSame( array( 'a' ), wp_list_pluck( Output::merge( array( array( '@type' => 'TechArticle' ) ), $ours ), '@id' ) );
		$this->assertSame( '{"a":"<\/b><\/script><!--"}', Output::encode( array( 'a' => '</b></script><!--' ) ) );
	}
}
