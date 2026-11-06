<?php
/**
 * Store: `_seoc_schema` round trip.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Modules\Schema\Store;
use WP_UnitTestCase;

class StoreTest extends WP_UnitTestCase {

	public function test_round_trip_and_tolerant_reads(): void {
		$id    = self::factory()->post->create();
		$nodes = array(
			array(
				'@type' => 'Person',
				'name'  => 'Lan "Ly" \\ Nguyễn',
				'url'   => 'https://example.org/lan/',
			),
		);
		$this->assertNull( Store::raw( $id ) );
		$this->assertSame( array(), Store::get( $id ) );

		$this->assertTrue( Store::set( $id, $nodes ) );
		$this->assertSame( $nodes, Store::get( $id ) );
		$this->assertTrue( Store::set( $id, $nodes ), 'Saving the same value is not a failure.' );
		$this->assertStringContainsString( 'https://example.org/lan/', get_post_meta( $id, Store::META_KEY, true ) );
		$this->assertSame( array( 'Person' ), Store::types( Store::get( $id ) ) );

		update_post_meta( $id, Store::META_KEY, wp_slash( $nodes[0] ) );
		$this->assertSame( $nodes, Store::get( $id ), 'A single node stored as an array is read as a list.' );

		update_post_meta( $id, Store::META_KEY, 'not json' );
		$this->assertNull( Store::raw( $id ) );

		Store::set( $id, array() );
		$this->assertSame( '', get_post_meta( $id, Store::META_KEY, true ) );
	}
}
