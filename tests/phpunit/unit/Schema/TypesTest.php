<?php
/**
 * Types registry.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Unit\Schema;

use SEOConsole\Modules\Schema\Types;
use WP_UnitTestCase;

class TypesTest extends WP_UnitTestCase {

	public function test_required_types_are_registered(): void {
		$expected = array( 'Article', 'BlogPosting', 'NewsArticle', 'FAQPage', 'HowTo', 'Product', 'Offer', 'AggregateRating', 'Review', 'BreadcrumbList', 'Organization', 'LocalBusiness', 'Restaurant', 'Store', 'ProfessionalService', 'OpeningHoursSpecification', 'GeoCoordinates', 'PostalAddress', 'Person', 'VideoObject', 'Event', 'Recipe', 'Service', 'WebSite', 'SearchAction' );
		foreach ( $expected as $type ) {
			$this->assertTrue( Types::is_known( $type ), $type );
		}
	}

	public function test_inheritance(): void {
		$this->assertSame( array( 'name', 'address' ), Types::required( 'Restaurant' ) );
		$this->assertContains( 'servesCuisine', Types::recommended( 'Restaurant' ) );
		$this->assertContains( 'logo', Types::allowed_properties( 'Restaurant' ) );
		$this->assertTrue( Types::is_a( 'Restaurant', 'Organization' ) );
		$this->assertSame( 'PostalAddress', Types::kind( 'Store', 'address' ) );
		$this->assertSame( 'PostalAddress|text', Types::kind( 'Place', 'address' ) );
		$this->assertSame( 'localbusiness', Types::id_slug( 'Dentist' ) );
		$this->assertSame( 'article', Types::id_slug( 'BlogPosting' ) );
	}

	public function test_groups_for_dedupe(): void {
		$this->assertSame( 'Article', Types::group( 'BlogPosting' ) );
		$this->assertSame( 'Article', Types::group( 'TechArticle' ) );
		$this->assertSame( 'LocalBusiness', Types::group( 'Restaurant' ) );
		$this->assertSame( 'Organization', Types::group( 'Organization' ) );
		$this->assertSame( 'WebPage', Types::group( 'CollectionPage' ) );
		$this->assertSame( 'FAQPage', Types::group( 'FAQPage' ) );
	}

	public function test_honest_rich_result_notes(): void {
		$this->assertSame( Types::RICH_LIMITED, Types::rich_result( 'FAQPage' ) );
		$this->assertStringContainsString( 'government and health', Types::note( 'FAQPage' ) );
		$this->assertSame( Types::RICH_REMOVED, Types::rich_result( 'HowTo' ) );
		$this->assertStringContainsString( 'removed', Types::note( 'HowTo' ) );
		$this->assertTrue( Types::get( 'WebSite' )['skip_with_seo_plugin'] );
		foreach ( Types::top_level_types() as $type ) {
			$this->assertNotSame( '', Types::note( $type ), $type );
		}
	}

	public function test_primary_picks_most_specific(): void {
		$this->assertSame( 'Restaurant', Types::primary( array( 'Organization', 'Restaurant' ) ) );
		$this->assertNull( Types::primary( 'Spaceship' ) );
	}
}
