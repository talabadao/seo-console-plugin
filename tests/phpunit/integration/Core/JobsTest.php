<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Jobs\Queue;
use SEOConsole\Jobs\TimeBudget;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class JobsTest extends CoreTestCase {

	public function test_queue_is_available_in_tests(): void {
		$this->assertTrue( ( new Queue() )->available() );
	}

	public function test_schedule_daily_is_idempotent_and_unschedules(): void {
		$queue = new Queue();
		$queue->unschedule( 'seoc_test_daily' );
		$this->assertGreaterThan( 0, $queue->schedule_daily( 'seoc_test_daily', array(), '04:00' ) );
		$this->assertSame( 0, $queue->schedule_daily( 'seoc_test_daily', array(), '04:00' ) );
		$this->assertTrue( $queue->is_scheduled( 'seoc_test_daily' ) );
		$next = $queue->next( 'seoc_test_daily' );
		$this->assertIsInt( $next );
		$local = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
		$this->assertSame( '04:00', $local->format( 'H:i' ) );
		$this->assertSame( Queue::GROUP, \ActionScheduler::store()->fetch_action( as_get_scheduled_actions( array( 'hook' => 'seoc_test_daily', 'status' => 'pending' ), 'ids' )[0] )->get_group() );
		$queue->unschedule( 'seoc_test_daily' );
		$this->assertFalse( $queue->is_scheduled( 'seoc_test_daily' ) );
	}

	public function test_enqueue_and_recurring(): void {
		$queue = new Queue();
		$queue->unschedule( 'seoc_test_async' );
		$queue->unschedule( 'seoc_test_recurring' );
		$this->assertGreaterThan( 0, $queue->enqueue( 'seoc_test_async', array( 'batch' => 1 ) ) );
		$this->assertTrue( $queue->is_scheduled( 'seoc_test_async', array( 'batch' => 1 ) ) );
		$this->assertFalse( $queue->is_scheduled( 'seoc_test_async', array( 'batch' => 2 ) ) );
		$this->assertGreaterThan( 0, $queue->schedule_recurring( 'seoc_test_recurring', 3600 ) );
		$queue->unschedule_all();
		$this->assertFalse( $queue->is_scheduled( 'seoc_test_async' ) );
		$this->assertFalse( $queue->is_scheduled( 'seoc_test_recurring' ) );
	}

	public function test_next_local_time(): void {
		update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
		$now  = ( new \DateTimeImmutable( '2026-11-06 10:00:00', wp_timezone() ) )->getTimestamp();
		$next = Queue::next_local_time( '02:30', $now );
		$this->assertSame( '2026-11-07 02:30', ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' ) );
		$next = Queue::next_local_time( '23:00', $now );
		$this->assertSame( '2026-11-06 23:00', ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' ) );
		$this->assertSame( Queue::next_local_time( '02:30', $now ), Queue::next_local_time( 'garbage', $now ) );
	}

	public function test_time_budget(): void {
		$budget = new TimeBudget( 0.05 );
		$this->assertTrue( $budget->has_time( 0.01 ) );
		$this->assertFalse( $budget->has_time( 1 ) );
		usleep( 60000 );
		$this->assertTrue( $budget->exceeded() );
		$this->assertSame( 0.0, $budget->remaining() );
		$this->assertEqualsWithDelta( 20.0, ( new TimeBudget() )->seconds(), 20.0 );
	}

	public function test_activation_flag_schedules_housekeeping(): void {
		$plugin = Plugin::instance();
		$plugin->queue()->unschedule( Plugin::HOUSEKEEPING_HOOK );
		$fired = 0;
		$cb    = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'seoc_ensure_jobs', $cb );
		update_option( Plugin::JOBS_PENDING_OPTION, 1 );
		$plugin->maybe_ensure_jobs();
		$plugin->maybe_ensure_jobs();
		remove_action( 'seoc_ensure_jobs', $cb );
		$this->assertSame( 1, $fired );
		$this->assertTrue( $plugin->queue()->is_scheduled( Plugin::HOUSEKEEPING_HOOK ) );
		$this->assertFalse( get_option( Plugin::JOBS_PENDING_OPTION ) );

		Plugin::deactivate();
		$this->assertFalse( $plugin->queue()->is_scheduled( Plugin::HOUSEKEEPING_HOOK ) );
	}
}
