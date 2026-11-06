<?php
/**
 * Action Scheduler wrapper (see docs/ARCHITECTURE.md §8).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Every action goes to the group `seo-console`. Hooks are named `seoc_<module>_<job>`. Scheduling methods are
 * idempotent: an action with the same hook and args that is already pending is not scheduled twice.
 * Before Action Scheduler is initialised (activation request, early hooks) the methods return 0/false.
 */
final class Queue {

	const GROUP = 'seo-console';

	/**
	 * Whether Action Scheduler is loaded and its data store initialised.
	 */
	public function available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& class_exists( 'ActionScheduler' )
			&& \ActionScheduler::is_initialized();
	}

	/**
	 * Runs daily at a site-local time.
	 *
	 * @param string $hook       Hook.
	 * @param array  $args       Args.
	 * @param string $local_time 'HH:MM' in the site's timezone.
	 * @return int Action id (0 when not scheduled; the existing action id is not looked up).
	 */
	public function schedule_daily( string $hook, array $args = array(), string $local_time = '02:30' ): int {
		if ( ! $this->available() || $this->is_scheduled( $hook, $args ) ) {
			return 0;
		}
		return (int) as_schedule_recurring_action( self::next_local_time( $local_time ), DAY_IN_SECONDS, $hook, $args, self::GROUP, true );
	}

	/**
	 * Runs every N seconds, starting now.
	 *
	 * @param string $hook    Hook.
	 * @param int    $seconds Interval (≥ 60).
	 * @param array  $args    Args.
	 * @return int Action id (0 when not scheduled).
	 */
	public function schedule_recurring( string $hook, int $seconds, array $args = array() ): int {
		if ( ! $this->available() || $this->is_scheduled( $hook, $args ) ) {
			return 0;
		}
		return (int) as_schedule_recurring_action( time() + 10, max( 60, $seconds ), $hook, $args, self::GROUP, true );
	}

	/**
	 * Runs once at a timestamp.
	 *
	 * @param string $hook      Hook.
	 * @param int    $timestamp Unix timestamp.
	 * @param array  $args      Args.
	 * @return int Action id (0 when not scheduled).
	 */
	public function schedule_single( string $hook, int $timestamp, array $args = array() ): int {
		if ( ! $this->available() ) {
			return 0;
		}
		return (int) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, true );
	}

	/**
	 * Runs as soon as possible in the background (unique per hook + args).
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return int Action id (0 when not scheduled, e.g. already pending).
	 */
	public function enqueue( string $hook, array $args = array() ): int {
		if ( ! $this->available() ) {
			return 0;
		}
		return (int) as_enqueue_async_action( $hook, $args, self::GROUP, true );
	}

	/**
	 * Removes every pending action of a hook (all args when $args is null).
	 *
	 * @param string     $hook Hook.
	 * @param array|null $args Args.
	 */
	public function unschedule( string $hook, ?array $args = null ): void {
		if ( ! $this->available() ) {
			return;
		}
		as_unschedule_all_actions( $hook, null === $args ? array() : $args, self::GROUP );
	}

	/**
	 * Removes every pending SEO Console action.
	 */
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Whether a pending (or running) action exists for a hook (any args when $args is null).
	 *
	 * @param string     $hook Hook.
	 * @param array|null $args Args.
	 */
	public function is_scheduled( string $hook, ?array $args = null ): bool {
		if ( ! $this->available() ) {
			return false;
		}
		return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Timestamp of the next run, true when running/async, false when none.
	 *
	 * @param string     $hook Hook.
	 * @param array|null $args Args.
	 * @return int|bool
	 */
	public function next( string $hook, ?array $args = null ) {
		if ( ! $this->available() ) {
			return false;
		}
		return as_next_scheduled_action( $hook, $args, self::GROUP );
	}

	/**
	 * Next Unix timestamp of a site-local 'HH:MM' (today if still ahead, else tomorrow).
	 *
	 * @param string   $local_time 'HH:MM'.
	 * @param int|null $now        Current timestamp (tests).
	 */
	public static function next_local_time( string $local_time, ?int $now = null ): int {
		$now = null === $now ? time() : $now;
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $local_time, $m ) ) {
			$m = array( '', '02', '30' );
		}
		$tz   = wp_timezone();
		$date = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( (int) $m[1], (int) $m[2], 0 );
		if ( $date->getTimestamp() <= $now ) {
			$date = $date->modify( '+1 day' );
		}
		return $date->getTimestamp();
	}
}
