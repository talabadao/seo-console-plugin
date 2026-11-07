<?php
/**
 * Maintenance: counts and cleanups with seeded data.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Speed;

use SEOConsole\Modules\Speed\Maintenance;
use WP_UnitTestCase;

class MaintenanceTest extends WP_UnitTestCase {

	public function test_autoload_report_counts_new_and_legacy_values(): void {
		$m      = new Maintenance();
		$before = $m->autoload_report();

		add_option( 'seoc_test_big_on', str_repeat( 'a', 200000 ), '', true );
		update_option( 'seoc_test_big_yes', str_repeat( 'b', 150000 ) );
		global $wpdb;
		// Legacy 'yes' and WP 6.6 'auto' values both count; 'off' does not.
		$wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => 'seoc_test_big_yes' ) );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'seoc_test_auto',
				'option_value' => str_repeat( 'c', 1000 ),
				'autoload'     => 'auto',
			)
		);
		add_option( 'seoc_test_off', str_repeat( 'd', 900000 ), '', false );

		$after = $m->autoload_report();
		$this->assertSame( 351000, $after['total_bytes'] - $before['total_bytes'] );
		$this->assertSame( 3, $after['count'] - $before['count'] );
		$this->assertCount( 20, $after['top'] );
		$this->assertSame( 'seoc_test_big_on', $after['top'][0]['name'] );
		$this->assertSame( 200000, $after['top'][0]['bytes'] );
		$this->assertSame( 'seoc_test_big_yes', $after['top'][1]['name'] );
		$this->assertSame( 'yes', $after['top'][1]['autoload'] );
		$this->assertNotContains( 'seoc_test_off', wp_list_pluck( $after['top'], 'name' ) );
		$this->assertSame( 'good', $after['verdict'] );

		add_option( 'seoc_test_huge', str_repeat( 'e', 700000 ), '', true );
		$this->assertSame( 'needs-work', $m->autoload_report()['verdict'] );
		$this->assertCount( 5, $m->autoload_report( 5 )['top'] );
	}

	public function test_expired_transients(): void {
		$m    = new Maintenance();
		$base = $m->expired_transients_count();
		set_transient( 'seoc_t_live', 'x', HOUR_IN_SECONDS );
		set_transient( 'seoc_t_old1', 'x', HOUR_IN_SECONDS );
		set_transient( 'seoc_t_old2', 'x', HOUR_IN_SECONDS );
		update_option( '_transient_timeout_seoc_t_old1', time() - 100 );
		update_option( '_transient_timeout_seoc_t_old2', time() - 100 );

		$this->assertSame( $base + 2, $m->expired_transients_count() );
		$result = $m->cleanup_expired_transients();
		$this->assertSame( 'expired_transients', $result['action'] );
		$this->assertSame( $base + 2, $result['removed'] );
		$this->assertFalse( $result['undoable'] );
		$this->assertSame( 0, $m->expired_transients_count() );
		global $wpdb;
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%seoc_t_%' ORDER BY option_name" );
		$this->assertSame( array( '_transient_seoc_t_live', '_transient_timeout_seoc_t_live' ), $left );
	}

	public function test_revisions_count(): void {
		$m    = new Maintenance();
		$base = $m->revisions_count();
		$post = self::factory()->post->create();
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'v2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'v3',
			)
		);
		$this->assertSame( $base + 2, $m->revisions_count() );
	}

	public function test_orphaned_postmeta(): void {
		global $wpdb;
		$m    = new Maintenance();
		$base = $m->orphaned_postmeta_count();
		$post = self::factory()->post->create();
		add_post_meta( $post, 'seoc_keep', '1' );
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => 999999 + $i,
					'meta_key'   => 'seoc_orphan',
					'meta_value' => 'x',
				)
			);
		}
		$this->assertSame( $base + 5, $m->orphaned_postmeta_count() );

		$first = $m->cleanup_orphaned_postmeta( 3 );
		$this->assertSame( 3, $first['removed'] );
		$this->assertTrue( $first['more'] );
		$this->assertFalse( $first['undoable'] );
		$second = $m->cleanup_orphaned_postmeta( 100 );
		$this->assertSame( $base + 2, $second['removed'] );
		$this->assertFalse( $second['more'] );
		$this->assertSame( 0, $m->orphaned_postmeta_count() );
		$this->assertSame( '1', get_post_meta( $post, 'seoc_keep', true ) );
	}

	public function test_action_scheduler_stats_and_cleanup(): void {
		global $wpdb;
		$m     = new Maintenance();
		$stats = $m->action_scheduler_stats();
		if ( ! $stats['available'] ) {
			$this->markTestSkipped( 'Action Scheduler tables are not installed in the test database.' );
		}
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$logs  = $wpdb->prefix . 'actionscheduler_logs';
		$old   = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$new   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$rows  = array(
			array( 'complete', $old, $old ),
			array( 'complete', $old, $old ),
			array( 'failed', $old, $old ),
			array( 'canceled', $old, '0000-00-00 00:00:00' ),
			array( 'complete', $new, $new ),
			array( 'pending', $old, '0000-00-00 00:00:00' ),
		);
		$ids   = array();
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'hook'                 => 'seoc_test_hook',
					'status'               => $row[0],
					'scheduled_date_gmt'   => $row[1],
					'scheduled_date_local' => $row[1],
					'last_attempt_gmt'     => $row[2],
					'last_attempt_local'   => $row[2],
					'args'                 => '[]',
					'group_id'             => 0,
				)
			);
			$ids[] = (int) $wpdb->insert_id;
			$wpdb->insert(
				$logs,
				array(
					'action_id'     => (int) $wpdb->insert_id,
					'message'       => 'test',
					'log_date_gmt'  => $row[1],
					'log_date_local' => $row[1],
				)
			);
		}

		$after = $m->action_scheduler_stats();
		$this->assertSame( $stats['complete'] + 3, $after['complete'] );
		$this->assertSame( $stats['failed'] + 1, $after['failed'] );
		$this->assertSame( $stats['canceled'] + 1, $after['canceled'] );
		$this->assertSame( $stats['old_finished'] + 3, $after['old_finished'] );
		$this->assertNotEmpty( $after['groups'] );
		$this->assertSame( $after['old_finished'], array_sum( wp_list_pluck( $after['groups'], 'old_finished' ) ) );

		$result = $m->cleanup_action_scheduler( 30, 1000 );
		$this->assertSame( $stats['old_finished'] + 3, $result['removed'] );
		$this->assertGreaterThanOrEqual( 3, $result['logs_removed'] );
		$this->assertFalse( $result['undoable'] );
		$this->assertSame( 0, $m->action_scheduler_stats()['old_finished'] );

		$left = array_map( 'intval', $wpdb->get_col( "SELECT action_id FROM {$table} WHERE hook = 'seoc_test_hook' ORDER BY action_id" ) );
		$this->assertSame( array( $ids[2], $ids[4], $ids[5] ), $left, 'Failed, recent and pending actions are kept.' );
	}

	public function test_report_shape(): void {
		$report = ( new Maintenance() )->report();
		$this->assertSame( array( 'autoload', 'transients', 'revisions', 'action_scheduler', 'orphaned_postmeta' ), array_keys( $report ) );
		$this->assertContains( $report['autoload']['verdict'], array( 'good', 'needs-work', 'poor' ) );
	}
}
