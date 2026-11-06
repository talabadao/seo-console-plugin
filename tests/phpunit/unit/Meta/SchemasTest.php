<?php
/**
 * Schemas: strict objects only, and only keywords structured outputs support.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Unit\Meta;

use SEOConsole\Modules\Meta\Schemas;
use WP_UnitTestCase;

class SchemasTest extends WP_UnitTestCase {

	public function schemas(): array {
		return array(
			'meta'         => array( Schemas::meta() ),
			'alternatives' => array( Schemas::alternatives() ),
			'images'       => array( Schemas::images() ),
		);
	}

	/**
	 * @dataProvider schemas
	 */
	public function test_every_object_is_strict( array $schema ): void {
		$this->walk( $schema );
	}

	public function test_shapes(): void {
		$this->assertSame( array( 'title', 'description', 'focus_keyword_used', 'rationale' ), Schemas::meta()['required'] );
		$this->assertSame( 'boolean', Schemas::meta()['properties']['focus_keyword_used']['type'] );
		$this->assertSame( Schemas::meta(), Schemas::alternatives()['properties']['options']['items'] );
		$this->assertSame( array( 'attachment_id', 'src', 'alt', 'rationale' ), Schemas::images()['properties']['images']['items']['required'] );
	}

	private function walk( array $node ): void {
		foreach ( array( 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems' ) as $unsupported ) {
			$this->assertArrayNotHasKey( $unsupported, $node );
		}
		if ( 'object' === $node['type'] ) {
			$this->assertFalse( $node['additionalProperties'] );
			$this->assertSame( array_keys( $node['properties'] ), $node['required'] );
			foreach ( $node['properties'] as $child ) {
				$this->walk( $child );
			}
		}
		if ( 'array' === $node['type'] ) {
			$this->walk( $node['items'] );
		}
	}
}
