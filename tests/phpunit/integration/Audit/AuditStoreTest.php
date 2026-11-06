<?php
/**
 * Storage layer.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Audit;

use SEOConsole\Modules\Audit\AuditStore;

require_once __DIR__ . '/AuditTestCase.php';

class AuditStoreTest extends AuditTestCase {

	public function test_tables_exist_and_schema_uses_placeholders(): void {
		global $wpdb;
		foreach ( array_keys( AuditStore::TABLES ) as $key ) {
			$table = $this->store->table( $key );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		foreach ( AuditStore::schema() as $sql ) {
			$this->assertStringContainsString( '{prefix}seoc_audit_', $sql );
			$this->assertStringContainsString( '{charset}', $sql );
		}
		$this->expectException( \InvalidArgumentException::class );
		$this->store->table( 'users' );
	}

	public function test_run_lifecycle(): void {
		$run_id = $this->store->create_run( array( 'max_pages' => 10 ) );
		$this->assertGreaterThan( 0, $run_id );
		$run = $this->store->get_run( $run_id );
		$this->assertSame( 'queued', $run['status'] );
		$this->assertSame( array( 'max_pages' => 10 ), $run['settings'] );
		$this->assertSame( $run_id, $this->store->active_run_id() );

		$this->store->update_run(
			$run_id,
			array(
				'status'  => 'done',
				'score'   => 80,
				'summary' => array( 'score' => 80 ),
				'bogus'   => 'ignored',
			)
		);
		$run = $this->store->get_run( $run_id );
		$this->assertSame( 80, $run['score'] );
		$this->assertSame( array( 'score' => 80 ), $run['summary'] );
		$this->assertSame( 0, $this->store->active_run_id() );
		$this->assertSame( $run_id, $this->store->list_runs( 5, 'done' )[0]['id'] );
	}

	public function test_enqueue_dedupes_and_lowers_depth(): void {
		$run_id = $this->store->create_run( array() );
		$added  = $this->store->enqueue(
			$run_id,
			array(
				array(
					'url'        => 'http://example.org/a/',
					'depth'      => null,
					'found_via'  => 'sitemap',
					'in_sitemap' => true,
				),
				array(
					'url'   => 'http://example.org/b/',
					'depth' => 3,
				),
				array(
					'url'   => 'http://example.org/b/',
					'depth' => 3,
				),
			)
		);
		$this->assertSame( 2, $added );
		$added = $this->store->enqueue(
			$run_id,
			array(
				array(
					'url'   => 'http://example.org/a/',
					'depth' => 2,
				),
				array(
					'url'        => 'http://example.org/b/',
					'depth'      => 5,
					'in_sitemap' => true,
				),
				array(
					'url'   => 'http://example.org/c/',
					'depth' => 1,
				),
				array(
					'url'   => 'http://example.org/d/',
					'depth' => 1,
				),
			),
			1
		);
		$this->assertSame( 1, $added, 'The limit caps new rows.' );

		$a = $this->page( $run_id, '/a/' );
		$this->assertSame( 2, $a['depth'] );
		$this->assertSame( 1, $a['in_sitemap'] );
		$this->assertSame( 'sitemap', $a['found_via'] );
		$b = $this->page( $run_id, '/b/' );
		$this->assertSame( 3, $b['depth'], 'Depth is never raised.' );
		$this->assertSame( 1, $b['in_sitemap'] );
		$this->assertNull( $this->page( $run_id, '/d/' ) );

		// Shallowest first, unknown depth last.
		$this->store->enqueue(
			$run_id,
			array(
				array(
					'url'   => 'http://example.org/z/',
					'depth' => null,
				),
			)
		);
		$this->assertSame( array( 'http://example.org/c/', 'http://example.org/a/', 'http://example.org/b/', 'http://example.org/z/' ), array_column( $this->store->next_queued( $run_id, 10 ), 'url' ) );
	}

	public function test_compute_inbound_counts_links_through_redirects(): void {
		$run_id = $this->store->create_run( array() );
		$urls   = array( '/', '/a/', '/old/', '/new/' );
		$this->store->enqueue(
			$run_id,
			array_map(
				static function ( $path ) {
					return array(
						'url'   => 'http://example.org' . $path,
						'depth' => 1,
					);
				},
				$urls
			)
		);
		$id = array();
		foreach ( $urls as $path ) {
			$id[ $path ] = $this->page( $run_id, $path )['id'];
			$this->store->update_page(
				$id[ $path ],
				array(
					'crawl_state' => 'crawled',
					'status_code' => '/old/' === $path ? 301 : 200,
					'final_url'   => '/old/' === $path ? 'http://example.org/new/' : null,
				)
			);
		}
		$link = static function ( $path, $internal = true ) {
			return array(
				'url'      => 'http://example.org' . $path,
				'anchor'   => 'x',
				'internal' => $internal,
			);
		};
		$this->store->replace_links( $run_id, $id['/'], array( $link( '/a/' ), $link( '/old/' ), $link( '/' ) ) );
		$this->store->replace_links( $run_id, $id['/a/'], array( $link( '/a/' ), $link( '/new/' ), $link( '/' ) ) );
		$this->store->replace_links( $run_id, $id['/new/'], array( $link( '/' ) ) );
		$this->store->compute_inbound( $run_id );

		$this->assertSame( 2, $this->page( $run_id, '/' )['inbound_internal'], 'Self-links do not count.' );
		$this->assertSame( 1, $this->page( $run_id, '/a/' )['inbound_internal'] );
		$this->assertSame( 2, $this->page( $run_id, '/new/' )['inbound_internal'], 'Home links via the redirect, /a/ directly.' );

		// replace_links replaces.
		$this->store->replace_links( $run_id, $id['/'], array() );
		$this->assertArrayNotHasKey( $id['/'], $this->store->internal_links_from( $run_id, array( $id['/'] ) ) );
	}

	public function test_issues_paging_filters_and_delete(): void {
		$run_id = $this->store->create_run( array() );
		$rows   = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$rows[] = array(
				'rule_id'  => $i < 3 ? 'title_missing' : 'robots_missing',
				'severity' => $i < 3 ? 'error' : 'warning',
				'category' => $i < 3 ? 'content' : 'indexability',
				'page_id'  => null,
				'url_hash' => '',
				'data'     => array( 'i' => $i ),
			);
		}
		$this->store->insert_issues( $run_id, $rows );
		$page = $this->store->issues(
			$run_id,
			array(
				'rule_id'  => 'title_missing',
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( array( 'i' => 2 ), $page['items'][0]['data'] );
		$this->assertSame( 2, $this->store->issues( $run_id, array( 'severity' => 'warning' ) )['total'] );

		$this->store->delete_run( $run_id );
		$this->assertNull( $this->store->get_run( $run_id ) );
		$this->assertSame( 0, $this->store->issues( $run_id )['total'] );
	}

	public function test_prune_keeps_newest_runs(): void {
		$ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->store->create_run( array() );
		}
		$before = count( $this->store->list_runs( 100 ) );
		$this->assertSame( $before - 2, $this->store->prune( 2 ) );
		$this->assertSame( array( $ids[3], $ids[2] ), array_column( $this->store->list_runs( 100 ), 'id' ) );
	}
}
