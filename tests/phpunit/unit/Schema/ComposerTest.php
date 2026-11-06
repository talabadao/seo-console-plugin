<?php
/**
 * Composer: stable @ids and links to the SEO plugin's nodes.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Unit\Schema;

use SEOConsole\Modules\Schema\Composer;
use WP_UnitTestCase;

class ComposerTest extends WP_UnitTestCase {

	const URL = 'https://example.org/coffee/';

	private static function nodes(): array {
		return array(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => array(),
			),
			array(
				'@type' => 'VideoObject',
				'name'  => 'A',
			),
			array(
				'@type' => 'VideoObject',
				'name'  => 'B',
			),
			array(
				'@type' => 'Product',
				'name'  => 'Mug',
			),
			array(
				'@type'        => 'BlogPosting',
				'headline'     => 'Hi',
				'dateModified' => '2020-01-01',
			),
			array(
				'@type' => 'Service',
				'name'  => 'Audit',
			),
			array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(),
			),
		);
	}

	public function test_conventions(): void {
		$this->assertSame( self::URL . '#webpage', Composer::webpage_id( 'rankmath', self::URL ) );
		$this->assertSame( self::URL, Composer::webpage_id( 'yoast', self::URL ) );
		$this->assertNull( Composer::webpage_id( 'none', self::URL ) );
		$this->assertSame( home_url( '/' ) . '#organization', Composer::organization_id( 'yoast' ) );
		$this->assertSame( home_url( '/' ) . '#seoc-organization', Composer::organization_id( 'none' ) );
	}

	public function test_ids_and_links_with_seo_plugin_webpage(): void {
		$webpage = Composer::webpage_id( 'rankmath', self::URL );
		$org     = Composer::organization_id( 'rankmath' );
		$out     = ( new Composer() )->compose(
			self::nodes(),
			array(
				'url'             => self::URL,
				'webpage_id'      => $webpage,
				'organization_id' => $org,
				'date_modified'   => '2026-11-01T10:00:00+00:00',
			)
		);
		$ids = wp_list_pluck( $out, '@id' );
		$this->assertSame(
			array(
				self::URL . '#seoc-faq',
				self::URL . '#seoc-video',
				self::URL . '#seoc-video-2',
				self::URL . '#seoc-product',
				self::URL . '#seoc-article',
				self::URL . '#seoc-service',
				self::URL . '#seoc-breadcrumb',
			),
			$ids
		);
		$this->assertArrayNotHasKey( '@context', $out[0] );
		$this->assertSame( array( '@id' => $webpage ), $out[0]['isPartOf'] );
		$this->assertSame( array( '@id' => $webpage ), $out[1]['isPartOf'] );
		$this->assertSame( array( '@id' => $webpage ), $out[3]['mainEntityOfPage'] );
		$this->assertSame( array( '@id' => $webpage ), $out[4]['mainEntityOfPage'] );
		$this->assertSame( array( '@id' => $webpage ), $out[4]['isPartOf'] );
		$this->assertSame( array( '@id' => $org ), $out[4]['publisher'] );
		$this->assertSame( '2026-11-01T10:00:00+00:00', $out[4]['dateModified'] );
		$this->assertSame( array( '@id' => $org ), $out[5]['provider'] );
		$this->assertArrayNotHasKey( 'isPartOf', $out[6] );
		$this->assertArrayNotHasKey( 'mainEntityOfPage', $out[6] );
		$this->assertSame( array( '@type', '@id' ), array_slice( array_keys( $out[0] ), 0, 2 ) );
	}

	public function test_without_seo_plugin_uses_url(): void {
		$out = ( new Composer() )->compose( self::nodes(), array( 'url' => self::URL ) );
		$this->assertSame( self::URL, $out[0]['url'] );
		$this->assertArrayNotHasKey( 'isPartOf', $out[0] );
		$this->assertSame( self::URL, $out[3]['mainEntityOfPage'] );
		$this->assertArrayNotHasKey( 'publisher', $out[4] );
	}

	public function test_existing_links_are_kept(): void {
		$out = ( new Composer() )->compose(
			array(
				array(
					'@type'            => 'Product',
					'name'             => 'Mug',
					'mainEntityOfPage' => 'https://example.org/other/',
					'@id'              => 'https://evil.example/#x',
				),
			),
			array(
				'url'        => self::URL,
				'webpage_id' => self::URL,
			)
		);
		$this->assertSame( 'https://example.org/other/', $out[0]['mainEntityOfPage'] );
		$this->assertSame( self::URL . '#seoc-product', $out[0]['@id'], 'Stored @ids are replaced by stable ones.' );
	}

	public function test_site_node_links_parent_organization(): void {
		$composer = new Composer();
		$local    = $composer->compose_site(
			array(
				'@type' => 'Restaurant',
				'name'  => 'Pho',
			),
			array( 'parent_organization_id' => home_url( '/' ) . '#organization' )
		);
		$this->assertSame( home_url( '/' ) . '#seoc-localbusiness', $local['@id'] );
		$this->assertSame( array( '@id' => home_url( '/' ) . '#organization' ), $local['parentOrganization'] );

		$org = $composer->compose_site(
			array(
				'@type' => 'Organization',
				'name'  => 'Acme',
			)
		);
		$this->assertSame( home_url( '/' ) . '#seoc-organization', $org['@id'] );
		$this->assertArrayNotHasKey( 'parentOrganization', $org );
	}
}
