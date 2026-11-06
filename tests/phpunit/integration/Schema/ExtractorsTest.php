<?php
/**
 * Deterministic extractors on fixture content.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\Extractors\ArticleExtractor;
use SEOConsole\Modules\Schema\Extractors\BreadcrumbExtractor;
use SEOConsole\Modules\Schema\Extractors\BusinessExtractor;
use SEOConsole\Modules\Schema\Extractors\FaqExtractor;
use SEOConsole\Modules\Schema\Extractors\HowToExtractor;
use SEOConsole\Modules\Schema\Extractors\ProductExtractor;
use SEOConsole\Modules\Schema\Extractors\VideoExtractor;
use SEOConsole\Modules\Schema\Validator;
use WP_UnitTestCase;

class ExtractorsTest extends WP_UnitTestCase {

	/**
	 * @param string $name Fixture file name.
	 */
	public static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/schema/' . $name );
	}

	/**
	 * @param string $content Content.
	 * @param array  $args    Extra post args.
	 */
	public function post( string $content, array $args = array() ): \WP_Post {
		// Administrators keep unfiltered HTML (iframes, scripts); wp_insert_post expects slashed data.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		return get_post( self::factory()->post->create( array_merge( array( 'post_content' => wp_slash( $content ) ), $args ) ) );
	}

	/**
	 * @param array $candidate Candidate.
	 * @return array [question => answer]
	 */
	private static function qa( array $candidate ): array {
		$out = array();
		foreach ( $candidate['node']['mainEntity'] as $q ) {
			$out[ $q['name'] ] = $q['acceptedAnswer']['text'];
		}
		return $out;
	}

	/**
	 * Asserts a candidate validates against the content it came from.
	 *
	 * @param array  $candidate Candidate.
	 * @param string $content   Content.
	 */
	private function assertValidCandidate( array $candidate, string $content ): void {
		$result = ( new Validator() )->validate_node( $candidate['node'], array( 'content' => $content ) );
		$this->assertTrue( $result['valid'], implode( "\n", $result['errors'] ) );
	}

	public function test_faq_from_core_details_blocks(): void {
		$content = self::fixture( 'gutenberg-details.html' );
		$found   = ( new FaqExtractor() )->extract( $this->post( $content ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'FAQPage', $found[0]['type'] );
		$this->assertSame( 'rule', $found[0]['source'] );
		$this->assertSame(
			array(
				'Do you ship abroad?'      => 'Yes, we ship to 20 countries within 7 days.',
				'How fresh is the coffee?' => 'We roast every Monday and ship the same week.',
			),
			self::qa( $found[0] )
		);
		$this->assertValidCandidate( $found[0], $content );
	}

	public function test_faq_from_yoast_block_ignores_other_headings(): void {
		$content = self::fixture( 'yoast-faq.html' );
		$found   = ( new FaqExtractor() )->extract( $this->post( $content ) );
		$this->assertSame(
			array(
				'What is cold brew?' => 'Coffee steeped in cold water for 12 hours.',
				'Is it stronger?'    => 'It has more caffeine per cup.',
			),
			self::qa( $found[0] )
		);
		$this->assertStringContainsString( 'Yoast FAQ block', $found[0]['evidence'] );
		$this->assertValidCandidate( $found[0], $content );
	}

	public function test_faq_from_rank_math_block_skips_hidden(): void {
		$content = self::fixture( 'rankmath-faq.html' );
		$found   = ( new FaqExtractor() )->extract( $this->post( $content ) );
		$this->assertSame(
			array(
				'Can I cancel my subscription?' => 'Yes, any time from your account page.',
				'Do you offer refunds?'         => 'Within 30 days of purchase.',
			),
			self::qa( $found[0] )
		);
		$this->assertValidCandidate( $found[0], $content );
	}

	public function test_faq_from_rank_math_block_html_fallback(): void {
		$content = preg_replace( '/<!-- wp:rank-math\/faq-block \{.*\} -->/', '<!-- wp:rank-math/faq-block -->', self::fixture( 'rankmath-faq.html' ) );
		$found   = ( new FaqExtractor() )->extract( $this->post( $content ) );
		$this->assertCount( 2, self::qa( $found[0] ) );
	}

	public function test_faq_from_question_headings_and_definition_lists(): void {
		$content = self::fixture( 'headings-questions.html' );
		$found   = ( new FaqExtractor() )->extract( $this->post( $content ) );
		$qa      = self::qa( $found[0] );
		$this->assertSame( 'Delivery takes 2–3 working days in Vietnam. Express delivery is available in Hanoi.', $qa['How long does delivery take?'] );
		$this->assertSame( 'Yes, cash on delivery is accepted. Card payment too.', $qa['Can I pay on delivery?'] );
		$this->assertSame( 'A manual brewing method.', $qa['What is a pour-over?'] );
		$this->assertArrayNotHasKey( 'Glossary term', $qa );
		$this->assertCount( 3, $qa );
		$this->assertValidCandidate( $found[0], $content );
	}

	public function test_single_question_heading_is_not_a_faq(): void {
		$found = ( new FaqExtractor() )->extract( $this->post( '<h2>Why does speed matter?</h2><p>Because users leave.</p><h2>Tips</h2><p>Use a cache.</p>' ) );
		$this->assertSame( array(), $found );
	}

	public function test_howto_from_ordered_list_under_how_to_heading(): void {
		$content = self::fixture( 'howto-list.html' );
		$found   = ( new HowToExtractor() )->extract( $this->post( $content ) );
		$this->assertCount( 1, $found );
		$node = $found[0]['node'];
		$this->assertSame( 'How to brew a pour-over', $node['name'] );
		$this->assertCount( 3, $node['step'] );
		$this->assertSame( 'Heat the water', $node['step'][0]['name'] );
		$this->assertSame( 'Heat the water: bring it to 94°C.', $node['step'][0]['text'] );
		$this->assertSame( 'Rinse the filter', $node['step'][1]['name'] );
		$this->assertArrayNotHasKey( 'name', $node['step'][2] );
		$this->assertValidCandidate( $found[0], $content );
	}

	public function test_no_howto_without_heading(): void {
		$this->assertSame( array(), ( new HowToExtractor() )->extract( $this->post( '<ol><li>a</li><li>b</li></ol>' ) ) );
	}

	public function test_video_from_youtube_embed_and_vimeo_iframe(): void {
		$found = ( new VideoExtractor() )->extract( $this->post( self::fixture( 'youtube-embed.html' ) ) );
		$this->assertCount( 2, $found );

		$youtube = $found[0];
		$this->assertSame( 'VideoObject', $youtube['type'] );
		$this->assertSame( 'How we roast our beans', $youtube['node']['name'] );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $youtube['node']['embedUrl'] );
		$this->assertSame( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $youtube['node']['thumbnailUrl'] );
		$this->assertContains( 'uploadDate', $youtube['missing'] );
		$this->assertArrayNotHasKey( 'uploadDate', $youtube['node'] );

		$vimeo = $found[1];
		$this->assertSame( 'https://player.vimeo.com/video/123456789', $vimeo['node']['embedUrl'] );
		$this->assertContains( 'name', $vimeo['missing'], 'Generic iframe title is not used as the video name.' );
		$this->assertContains( 'thumbnailUrl', $vimeo['missing'] );
	}

	public function test_video_title_from_oembed_cache(): void {
		$post = $this->post( "Intro\n\nhttps://youtu.be/dQw4w9WgXcQ\n\nOutro" );
		add_post_meta( $post->ID, '_oembed_' . md5( 'x' ), '<iframe title="Never Gonna Give You Up" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"></iframe>' );
		$found = ( new VideoExtractor() )->extract( $post );
		$this->assertSame( 'Never Gonna Give You Up', $found[0]['node']['name'] );
		$this->assertSame( array( 'description', 'uploadDate' ), $found[0]['missing'] );
	}

	public function test_product_from_provider_data(): void {
		$extractor = new ProductExtractor(
			static function () {
				return array(
					'name'           => 'Ceramic mug',
					'description'    => '<p>Hand made.</p>',
					'sku'            => 'MUG-1',
					'image'          => 'https://example.org/mug.jpg',
					'url'            => 'https://example.org/shop/mug/',
					'currency'       => 'vnd',
					'price'          => '150000',
					'availability'   => 'instock',
					'rating_average' => 4.5,
					'rating_count'   => 2,
					'review_count'   => 2,
					'reviews'        => array(
						array(
							'author' => 'Minh',
							'rating' => 5,
							'body'   => 'Great',
							'date'   => '2026-01-02',
						),
					),
				);
			}
		);
		$found     = $extractor->extract( $this->post( 'x', array( 'post_type' => 'page' ) ) );
		$node      = $found[0]['node'];
		$this->assertSame( 'Hand made.', $node['description'] );
		$this->assertSame( '150000', $node['offers']['price'] );
		$this->assertSame( 'VND', $node['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
		$this->assertSame( 4.5, $node['aggregateRating']['ratingValue'] );
		$this->assertSame( 'Minh', $node['review'][0]['author']['name'] );
		$this->assertValidCandidate( $found[0], '' );
	}

	public function test_product_without_ratings_has_no_rating_and_variable_price_range(): void {
		$extractor = new ProductExtractor(
			static function () {
				return array(
					'name'           => 'Beans',
					'currency'       => 'USD',
					'min_price'      => '9.5',
					'max_price'      => '20',
					'rating_average' => 0,
					'rating_count'   => 0,
				);
			}
		);
		$node      = $extractor->extract( $this->post( 'x' ) )[0]['node'];
		$this->assertArrayNotHasKey( 'aggregateRating', $node );
		$this->assertArrayNotHasKey( 'review', $node );
		$this->assertSame( 'AggregateOffer', $node['offers']['@type'] );
		$this->assertSame( '9.5', $node['offers']['lowPrice'] );
		$this->assertTrue( ( new Validator() )->validate_node( $node )['valid'] );
	}

	public function test_product_without_woocommerce_returns_nothing(): void {
		$this->assertSame( array(), ( new ProductExtractor() )->extract( $this->post( 'x' ) ) );
		$empty = new ProductExtractor(
			static function () {
				return array(
					'name'     => 'No price',
					'currency' => 'USD',
				);
			}
		);
		$this->assertContains( 'offers', $empty->extract( $this->post( 'x' ) )[0]['missing'] );
	}

	public function test_article_and_breadcrumb(): void {
		$cat  = self::factory()->category->create( array( 'name' => 'Brewing' ) );
		$user = self::factory()->user->create( array( 'display_name' => 'Lan' ) );
		$post = $this->post(
			'Body',
			array(
				'post_title'    => 'Cold brew guide',
				'post_author'   => $user,
				'post_category' => array( $cat ),
				'post_date'     => '2026-03-01 10:00:00',
			)
		);

		$article = ( new ArticleExtractor() )->extract( $post )[0];
		$this->assertSame( 'BlogPosting', $article['type'] );
		$this->assertSame( 'Cold brew guide', $article['node']['headline'] );
		$this->assertSame( 'Lan', $article['node']['author']['name'] );
		$this->assertTrue( Validator::is_iso_date( $article['node']['datePublished'] ) );
		$this->assertValidCandidate( $article, '' );

		$crumbs = ( new BreadcrumbExtractor() )->extract( $post )[0];
		$names  = wp_list_pluck( $crumbs['node']['itemListElement'], 'name' );
		$this->assertSame( array( 'Home', 'Brewing', 'Cold brew guide' ), $names );
		$this->assertValidCandidate( $crumbs, '' );

		$this->assertSame( array(), ( new ArticleExtractor() )->extract( $this->post( 'x', array( 'post_type' => 'page' ) ) ) );
	}

	public function test_business_from_settings(): void {
		$result = ( new BusinessExtractor() )->build(
			array(
				'business_type'       => 'Restaurant',
				'business_name'       => 'Pho 24',
				'business_logo'       => 'https://example.org/logo.png',
				'business_phone'      => '+84 24 1234 5678',
				'address_street'      => '1 Le Loi',
				'address_locality'    => 'Hanoi',
				'address_country'     => 'vn',
				'geo_latitude'        => '21.0285',
				'geo_longitude'       => '105.8542',
				'opening_hours'       => array( 'Mo-Fr 08:00-17:30', 'Sa,Su 9:00-12:00', 'Su-Mo 20:00-23:00', 'whenever' ),
				'same_as'             => array( 'https://facebook.com/pho24' ),
				'price_range'         => '$$',
			)
		);
		$node   = $result['node'];
		$this->assertSame( 'Restaurant', $node['@type'] );
		$this->assertSame( 'VN', $node['address']['addressCountry'] );
		$this->assertSame( 21.0285, $node['geo']['latitude'] );
		$this->assertSame( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ), $node['openingHoursSpecification'][0]['dayOfWeek'] );
		$this->assertSame( '09:00', $node['openingHoursSpecification'][1]['opens'] );
		$this->assertSame( array( 'Sunday', 'Monday' ), $node['openingHoursSpecification'][2]['dayOfWeek'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( home_url( '/' ), $node['url'] );
		$this->assertTrue( ( new Validator() )->validate_node( $node )['valid'] );

		$this->assertNull( ( new BusinessExtractor() )->build( array( 'business_type' => 'none' ) )['node'] );
		$this->assertNull( ( new BusinessExtractor() )->build( array( 'business_type' => 'Organization' ) )['node'] );

		$org = ( new BusinessExtractor() )->build(
			array(
				'business_type'  => 'Organization',
				'business_name'  => 'Acme',
				'opening_hours'  => array( 'Mo 09:00-10:00' ),
				'geo_latitude'   => '1',
				'geo_longitude'  => '2',
			)
		)['node'];
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $org );
		$this->assertArrayNotHasKey( 'geo', $org );
	}
}
