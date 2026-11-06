<?php
/**
 * Validator: one valid node per type, and each missing required property.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Unit\Schema;

use SEOConsole\Modules\Schema\Types;
use SEOConsole\Modules\Schema\Validator;
use WP_UnitTestCase;

class ValidatorTest extends WP_UnitTestCase {

	/** @var Validator */
	private $validator;

	public function set_up(): void {
		parent::set_up();
		$this->validator = new Validator();
	}

	/**
	 * A valid example per top-level type.
	 *
	 * @return array<string,array>
	 */
	public static function valid_nodes(): array {
		$offer = array(
			'@type'         => 'Offer',
			'price'         => '19.99',
			'priceCurrency' => 'USD',
			'availability'  => 'https://schema.org/InStock',
			'url'           => 'https://example.org/shop/mug/',
		);
		return array(
			'Article'        => array(
				array(
					'@type'         => 'Article',
					'headline'      => 'How we roast coffee',
					'image'         => 'https://example.org/img.jpg',
					'datePublished' => '2026-03-15T09:00:00+07:00',
					'dateModified'  => '2026-03-16',
					'author'        => array(
						'@type' => 'Person',
						'name'  => 'Lan',
					),
				),
			),
			'BlogPosting'    => array(
				array(
					'@type'    => 'BlogPosting',
					'headline' => 'Hello',
				),
			),
			'NewsArticle'    => array(
				array(
					'@type'    => 'NewsArticle',
					'headline' => 'News',
				),
			),
			'FAQPage'        => array(
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
			),
			'HowTo'          => array(
				array(
					'@type' => 'HowTo',
					'name'  => 'How to brew',
					'step'  => array(
						array(
							'@type' => 'HowToStep',
							'text'  => 'Boil water.',
						),
						array(
							'@type' => 'HowToStep',
							'text'  => 'Pour.',
						),
					),
				),
			),
			'Product'        => array(
				array(
					'@type'           => 'Product',
					'name'            => 'Mug',
					'offers'          => $offer,
					'aggregateRating' => array(
						'@type'       => 'AggregateRating',
						'ratingValue' => 4.5,
						'reviewCount' => 12,
					),
					'review'          => array(
						array(
							'@type'        => 'Review',
							'author'       => array(
								'@type' => 'Person',
								'name'  => 'Minh',
							),
							'reviewRating' => array(
								'@type'       => 'Rating',
								'ratingValue' => '5',
							),
						),
					),
				),
			),
			'BreadcrumbList' => array(
				array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => array(
						array(
							'@type'    => 'ListItem',
							'position' => 1,
							'name'     => 'Home',
							'item'     => 'https://example.org/',
						),
						array(
							'@type'    => 'ListItem',
							'position' => 2,
							'name'     => 'Post',
						),
					),
				),
			),
			'Organization'   => array(
				array(
					'@type'  => 'Organization',
					'name'   => 'Acme',
					'url'    => 'https://example.org/',
					'logo'   => 'https://example.org/logo.png',
					'sameAs' => array( 'https://facebook.com/acme' ),
				),
			),
			'LocalBusiness'  => array(
				array(
					'@type'                     => 'Restaurant',
					'name'                      => 'Pho 24',
					'address'                   => array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => '1 Le Loi',
						'addressLocality' => 'Hanoi',
						'addressCountry'  => 'VN',
					),
					'geo'                       => array(
						'@type'     => 'GeoCoordinates',
						'latitude'  => 21.03,
						'longitude' => 105.85,
					),
					'openingHoursSpecification' => array(
						array(
							'@type'     => 'OpeningHoursSpecification',
							'dayOfWeek' => array( 'Monday', 'https://schema.org/Tuesday' ),
							'opens'     => '09:00',
							'closes'    => '21:30',
						),
					),
					'priceRange'                => '$$',
				),
			),
			'Person'         => array(
				array(
					'@type' => 'Person',
					'name'  => 'Lan Nguyen',
				),
			),
			'VideoObject'    => array(
				array(
					'@type'        => 'VideoObject',
					'name'         => 'Our roastery',
					'thumbnailUrl' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg',
					'uploadDate'   => '2025-01-02',
					'duration'     => 'PT2M30S',
					'embedUrl'     => 'https://www.youtube.com/embed/abcdefghijk',
				),
			),
			'Event'          => array(
				array(
					'@type'               => 'Event',
					'name'                => 'Cupping night',
					'startDate'           => '2026-12-01T19:00:00+07:00',
					'endDate'             => '2026-12-01T21:00:00+07:00',
					'eventStatus'         => 'EventScheduled',
					'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
					'location'            => array(
						'@type'   => 'Place',
						'name'    => 'Roastery',
						'address' => '1 Le Loi, Hanoi',
					),
				),
			),
			'Recipe'         => array(
				array(
					'@type'              => 'Recipe',
					'name'               => 'Egg coffee',
					'image'              => array( 'https://example.org/egg.jpg' ),
					'recipeIngredient'   => array( '2 eggs', 'coffee' ),
					'recipeInstructions' => array(
						array(
							'@type' => 'HowToStep',
							'text'  => 'Whisk.',
						),
					),
					'totalTime'          => 'PT15M',
				),
			),
			'Service'        => array(
				array(
					'@type'    => 'Service',
					'name'     => 'SEO audit',
					'provider' => array( '@id' => 'https://example.org/#organization' ),
				),
			),
			'WebSite'        => array(
				array(
					'@type' => 'WebSite',
					'name'  => 'Acme',
					'url'   => 'https://example.org/',
				),
			),
		);
	}

	/**
	 * @dataProvider valid_nodes
	 *
	 * @param array $node Node.
	 */
	public function test_valid_node_per_type( array $node ): void {
		$result = $this->validator->validate_node( $node );
		$this->assertTrue( $result['valid'], implode( "\n", $result['errors'] ) );
	}

	/**
	 * Every required property, removed one at a time, makes the node invalid.
	 */
	public function test_each_missing_required_property_is_an_error(): void {
		$checked = 0;
		foreach ( self::valid_nodes() as $name => $args ) {
			$node = $args[0];
			$type = Types::primary( $node['@type'] );
			foreach ( Types::required( $type ) as $prop ) {
				$broken = $node;
				unset( $broken[ $prop ] );
				$result = $this->validator->validate_node( $broken );
				$this->assertFalse( $result['valid'], "$name without $prop should be invalid" );
				$this->assertStringContainsString( 'required', implode( ' ', $result['errors'] ) );

				$empty          = $node;
				$empty[ $prop ] = '';
				$this->assertFalse( $this->validator->validate_node( $empty )['valid'], "$name with empty $prop should be invalid" );
				++$checked;
			}
		}
		$this->assertGreaterThan( 15, $checked );
	}

	public function test_nested_required_properties(): void {
		$cases = array(
			'Question.acceptedAnswer' => array( 'FAQPage', 'mainEntity', 0, 'acceptedAnswer' ),
			'Question.name'           => array( 'FAQPage', 'mainEntity', 0, 'name' ),
			'HowToStep.text'          => array( 'HowTo', 'step', 0, 'text' ),
			'ListItem.position'       => array( 'BreadcrumbList', 'itemListElement', 1, 'position' ),
			'OpeningHours.opens'      => array( 'LocalBusiness', 'openingHoursSpecification', 0, 'opens' ),
			'Review.author'           => array( 'Product', 'review', 0, 'author' ),
		);
		foreach ( $cases as $label => $case ) {
			list( $key, $prop, $index, $required ) = $case;
			$node                                  = self::valid_nodes()[ $key ][0];
			unset( $node[ $prop ][ $index ][ $required ] );
			$this->assertFalse( $this->validator->validate_node( $node )['valid'], $label );
		}
	}

	public function test_product_needs_offer_review_or_rating(): void {
		$result = $this->validator->validate_node(
			array(
				'@type' => 'Product',
				'name'  => 'Mug',
			)
		);
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'at least one of', $result['errors'][0] );
	}

	public function test_value_types(): void {
		$cases = array(
			'relative url'         => array(
				'@type'  => 'Organization',
				'name'   => 'Acme',
				'url'    => '/about',
			),
			'bad date'             => array(
				'@type'         => 'Article',
				'datePublished' => '15/03/2026',
			),
			'impossible date'      => array(
				'@type'         => 'Article',
				'datePublished' => '2026-02-30',
			),
			'price with symbol'    => array(
				'@type'  => 'Product',
				'name'   => 'Mug',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => '$19.99',
					'priceCurrency' => 'USD',
				),
			),
			'missing currency'     => array(
				'@type'  => 'Product',
				'name'   => 'Mug',
				'offers' => array(
					'@type' => 'Offer',
					'price' => 10,
				),
			),
			'bad currency'         => array(
				'@type'  => 'Product',
				'name'   => 'Mug',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => 10,
					'priceCurrency' => 'dollars',
				),
			),
			'rating out of range'  => array(
				'@type'           => 'Product',
				'name'            => 'Mug',
				'aggregateRating' => array(
					'@type'       => 'AggregateRating',
					'ratingValue' => 7,
					'ratingCount' => 3,
				),
			),
			'zero ratings'         => array(
				'@type'           => 'Product',
				'name'            => 'Mug',
				'aggregateRating' => array(
					'@type'       => 'AggregateRating',
					'ratingValue' => 4,
					'ratingCount' => 0,
				),
			),
			'html in text'         => array(
				'@type'       => 'Person',
				'name'        => 'Lan',
				'description' => 'Hi <script>alert(1)</script>',
			),
			'bad duration'         => array(
				'@type'     => 'Recipe',
				'name'      => 'Egg coffee',
				'image'     => 'https://example.org/a.jpg',
				'totalTime' => '15 minutes',
			),
			'bad enum'             => array(
				'@type'     => 'Event',
				'name'      => 'X',
				'startDate' => '2026-12-01',
				'location'  => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.org/live',
				),
				'eventStatus' => 'Happening',
			),
			'end before start'     => array(
				'@type'     => 'Event',
				'name'      => 'X',
				'startDate' => '2026-12-02',
				'endDate'   => '2026-12-01',
				'location'  => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.org/live',
				),
			),
			'bad latitude'         => array(
				'@type'   => 'LocalBusiness',
				'name'    => 'X',
				'address' => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => 'Hanoi',
				),
				'geo'     => array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => 120,
					'longitude' => 10,
				),
			),
			'address as text for LocalBusiness' => array(
				'@type'   => 'LocalBusiness',
				'name'    => 'X',
				'address' => '1 Le Loi',
			),
			'wrong nested type'    => array(
				'@type'  => 'Product',
				'name'   => 'Mug',
				'offers' => array(
					'@type' => 'Person',
					'name'  => 'Bob',
				),
			),
			'unknown type'         => array(
				'@type' => 'Spaceship',
				'name'  => 'X',
			),
			'nested-only on top'   => array(
				'@type'         => 'Offer',
				'price'         => 1,
				'priceCurrency' => 'USD',
			),
			'breadcrumb item link' => array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => 'Home',
					),
					array(
						'@type'    => 'ListItem',
						'position' => 2,
						'name'     => 'Post',
					),
				),
			),
			'no type'              => array( 'name' => 'X' ),
			'bad keyword'          => array(
				'@type'  => 'Person',
				'name'   => 'X',
				'@graph' => array(),
			),
		);
		foreach ( $cases as $label => $node ) {
			$result = $this->validator->validate_node( $node );
			$this->assertFalse( $result['valid'], $label );
			$this->assertNotEmpty( $result['errors'], $label );
		}
	}

	public function test_warnings_do_not_block(): void {
		$result = $this->validator->validate_node(
			array(
				'@type'    => 'Article',
				'headline' => str_repeat( 'Long headline ', 10 ),
				'foo'      => 'bar',
			)
		);
		$this->assertTrue( $result['valid'] );
		$text = implode( ' ', $result['warnings'] );
		$this->assertStringContainsString( 'cut it off', $text );
		$this->assertStringContainsString( '"foo"', $text );
		$this->assertStringContainsString( 'recommended', $text );
	}

	public function test_faq_questions_must_be_visible_on_page(): void {
		$node    = self::valid_nodes()['FAQPage'][0];
		$visible = '<h2>Do you ship abroad?</h2><p>Yes, to 20 countries.</p>';
		$this->assertTrue( $this->validator->validate_node( $node, array( 'content' => $visible ) )['valid'] );

		$hidden = $this->validator->validate_node( $node, array( 'content' => '<p>We sell coffee.</p>' ) );
		$this->assertFalse( $hidden['valid'] );
		$this->assertStringContainsString( 'does not appear on the page', $hidden['errors'][0] );

		$answer_differs = $this->validator->validate_node( $node, array( 'content' => '<h2>Do you ship abroad?</h2><p>No.</p>' ) );
		$this->assertTrue( $answer_differs['valid'] );
		$this->assertStringContainsString( 'does not match', implode( ' ', $answer_differs['warnings'] ) );
	}

	public function test_graph_rules(): void {
		$faq    = self::valid_nodes()['FAQPage'][0];
		$result = $this->validator->validate_graph( array( $faq, $faq ) );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'only have one', $result['errors'][0] );

		$video = self::valid_nodes()['VideoObject'][0];
		$this->assertTrue( $this->validator->validate_graph( array( $video, $video ) )['valid'] );

		$huge         = $faq;
		$huge['name'] = str_repeat( 'x', 4000 );
		$many         = array_fill( 0, 20, $video );
		$many[0]      = $huge;
		$result       = $this->validator->validate_graph( $many );
		$this->assertTrue( $result['valid'] );

		$big                        = $video;
		$big['description']         = str_repeat( 'y', 4900 );
		$this->assertFalse( $this->validator->validate_graph( array_fill( 0, 15, $big ) )['valid'] );
	}

	public function test_too_many_items(): void {
		$node             = self::valid_nodes()['HowTo'][0];
		$node['step']     = array_fill(
			0,
			Validator::MAX_ITEMS + 1,
			array(
				'@type' => 'HowToStep',
				'text'  => 'x',
			)
		);
		$this->assertFalse( $this->validator->validate_node( $node )['valid'] );
	}

	public function test_strip_unknown_drops_invented_properties(): void {
		$result = $this->validator->strip_unknown(
			array(
				'@type'     => 'Service',
				'name'      => 'Audit',
				'secretSql' => 'DROP TABLE',
				'offers'    => array(
					'@type'         => 'Offer',
					'price'         => '10',
					'priceCurrency' => 'USD',
					'discountCode'  => 'X',
				),
			)
		);
		$this->assertSame( array( 'Service.secretSql', 'Service.offers.discountCode' ), $result['dropped'] );
		$this->assertArrayNotHasKey( 'secretSql', $result['node'] );
		$this->assertArrayNotHasKey( 'discountCode', $result['node']['offers'] );
		$this->assertSame( 'Audit', $result['node']['name'] );
	}

	public function test_helpers(): void {
		$this->assertTrue( Validator::is_iso_date( '2026-01-31' ) );
		$this->assertTrue( Validator::is_iso_date( '2026-01-31T10:00:00Z' ) );
		$this->assertFalse( Validator::is_iso_date( '2026-1-31' ) );
		$this->assertTrue( Validator::is_iso_duration( 'PT1H30M' ) );
		$this->assertFalse( Validator::is_iso_duration( 'P' ) );
		$this->assertFalse( Validator::is_iso_duration( 'P1DT' ) );
		$this->assertTrue( Validator::is_absolute_url( 'https://example.org/a?b=c' ) );
		$this->assertFalse( Validator::is_absolute_url( 'javascript:alert(1)' ) );
	}
}
