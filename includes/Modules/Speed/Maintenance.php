<?php
/**
 * Database health checks and one-off cleanups.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only health report plus explicit cleanups. Cleanups delete data permanently:
 * every cleanup result says `undoable => false` so the UI can ask for confirmation.
 *
 * Cleanup result: [ 'action' => string, 'removed' => int, 'more' => bool, 'undoable' => false, ... ].
 */
class Maintenance {

	/** Batch size for one cleanup call (keeps each request short on shared hosting). */
	const BATCH = 2000;

	/** Finished Action Scheduler statuses that may be cleaned up (failed actions are kept for diagnosis). */
	const AS_DONE = array( 'complete', 'canceled' );

	/**
	 * Full report.
	 *
	 * @return array
	 */
	public function report(): array {
		$expired   = $this->expired_transients_count();
		$revisions = $this->revisions_count();
		$orphans   = $this->orphaned_postmeta_count();
		return array(
			'autoload'          => $this->autoload_report(),
			'transients'        => array(
				'expired' => $expired,
				'verdict' => $expired > 1000 ? 'needs-work' : 'good',
			),
			'revisions'         => array(
				'count'   => $revisions,
				'verdict' => $revisions > 5000 ? 'needs-work' : 'good',
			),
			'action_scheduler'  => $this->action_scheduler_stats(),
			'orphaned_postmeta' => array(
				'count'   => $orphans,
				'verdict' => $orphans > 1000 ? 'needs-work' : 'good',
			),
		);
	}

	/**
	 * Autoload values that mean "loaded on every request" (WP 6.6+: on/auto/auto-on; older: yes).
	 *
	 * @return string[]
	 */
	public static function autoload_values(): array {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return array_values( array_unique( array_merge( wp_autoload_values_to_autoload(), array( 'yes' ) ) ) );
		}
		return array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Autoloaded options size and the biggest ones.
	 *
	 * @param int $top Number of options to list.
	 * @return array [ 'total_bytes', 'count', 'limit_bytes', 'verdict', 'advice', 'top' => [ [ 'name', 'bytes', 'autoload', 'is_transient' ] ] ]
	 */
	public function autoload_report( int $top = 20 ): array {
		global $wpdb;
		$values       = self::autoload_values();
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- live sizes needed; placeholders built from a fixed count.
		$totals = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM %i WHERE autoload IN ( {$placeholders} )", array_merge( array( $wpdb->options ), $values ) ),
			ARRAY_A
		);
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM %i WHERE autoload IN ( {$placeholders} ) ORDER BY bytes DESC LIMIT %d",
				array_merge( array( $wpdb->options ), $values, array( max( 1, min( 100, $top ) ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		/** This filter is documented in wp-admin/includes/class-wp-site-health.php */
		$limit = (int) apply_filters( 'site_status_autoloaded_options_size_limit', 800000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$total = isset( $totals['bytes'] ) ? (int) $totals['bytes'] : 0;

		if ( $total <= $limit ) {
			$verdict = 'good';
			$advice  = __( 'Autoloaded options are a healthy size.', 'seo-console' );
		} elseif ( $total <= 3 * $limit ) {
			$verdict = 'needs-work';
			$advice  = __( 'WordPress loads these settings on every page view, so a large total slows down every uncached request. Look at the biggest entries below: they often belong to plugins you no longer use or to logs/caches stored in the wrong place.', 'seo-console' );
		} else {
			$verdict = 'poor';
			$advice  = __( 'Autoloaded options are very large and slow down every uncached request (high TTFB). Remove leftovers of deleted plugins and ask a developer to switch big entries to autoload "off".', 'seo-console' );
		}

		$list = array();
		foreach ( (array) $rows as $row ) {
			$name   = (string) $row['option_name'];
			$list[] = array(
				'name'         => $name,
				'bytes'        => (int) $row['bytes'],
				'autoload'     => (string) $row['autoload'],
				'is_transient' => 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ),
			);
		}
		return array(
			'total_bytes' => $total,
			'count'       => isset( $totals['cnt'] ) ? (int) $totals['cnt'] : 0,
			'limit_bytes' => $limit,
			'verdict'     => $verdict,
			'advice'      => $advice,
			'top'         => $list,
		);
	}

	/**
	 * Number of expired transients stored in the options table.
	 *
	 * @return int
	 */
	public function expired_transients_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d',
				$wpdb->options,
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				time()
			)
		);
	}

	/**
	 * Deletes expired transients (core's own routine, forced to the database).
	 *
	 * @return array Cleanup result.
	 */
	public function cleanup_expired_transients(): array {
		$before = $this->expired_transients_count();
		delete_expired_transients( true );
		$after = $this->expired_transients_count();
		return array(
			'action'   => 'expired_transients',
			'removed'  => max( 0, $before - $after ),
			'more'     => $after > 0,
			'undoable' => false,
		);
	}

	/**
	 * Number of post revisions.
	 *
	 * @return int
	 */
	public function revisions_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'revision' ) );
	}

	/**
	 * Action Scheduler table names, or null when its tables do not exist.
	 *
	 * @return array|null [ 'actions' => string, 'logs' => string|null ]
	 */
	private function as_tables() {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		$has_actions = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) === $actions;
		$has_logs    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) ) === $logs;
		// phpcs:enable
		if ( ! $has_actions ) {
			return null;
		}
		return array(
			'actions' => $actions,
			'logs'    => $has_logs ? $logs : null,
		);
	}

	/**
	 * Action Scheduler counts by status, and how many finished actions are older than $days.
	 *
	 * @param int $days Age in days.
	 * @return array [ 'available' => bool, 'complete', 'failed', 'canceled', 'pending', 'old_finished', 'days', 'verdict',
	 *                 'groups' => [ [ 'group' => slug ('' = none), 'old_finished' => int ] ] (biggest first, max 20) ]
	 */
	public function action_scheduler_stats( int $days = 30 ): array {
		global $wpdb;
		$stats  = array(
			'available'    => false,
			'complete'     => 0,
			'failed'       => 0,
			'canceled'     => 0,
			'pending'      => 0,
			'old_finished' => 0,
			'days'         => $days,
			'verdict'      => 'good',
			'groups'       => array(),
		);
		$tables = $this->as_tables();
		if ( null === $tables ) {
			return $stats;
		}
		$stats['available'] = true;
		$table              = $tables['actions'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from $wpdb->prefix + fixed suffix.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS cnt FROM %i GROUP BY status', $table ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$status = (string) $row['status'];
			if ( isset( $stats[ $status ] ) && 'available' !== $status ) {
				$stats[ $status ] = (int) $row['cnt'];
			}
		}
		$cutoff                = $this->cutoff( $days );
		$stats['old_finished'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status IN ( %s, %s ) AND scheduled_date_gmt < %s AND ( last_attempt_gmt IS NULL OR last_attempt_gmt < %s )',
				array_merge( array( $table ), self::AS_DONE, array( $cutoff, $cutoff ) )
			)
		);
		if ( $stats['old_finished'] > 0 ) {
			$groups_table = $wpdb->prefix . 'actionscheduler_groups';
			$has_groups   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups_table ) ) ) === $groups_table;
			if ( $has_groups ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT COALESCE(g.slug, '') AS slug, COUNT(*) AS cnt FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.status IN ( %s, %s ) AND a.scheduled_date_gmt < %s AND ( a.last_attempt_gmt IS NULL OR a.last_attempt_gmt < %s ) GROUP BY slug ORDER BY cnt DESC LIMIT 20",
						array_merge( array( $table, $groups_table ), self::AS_DONE, array( $cutoff, $cutoff ) )
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT '' AS slug, COUNT(*) AS cnt FROM %i a WHERE a.status IN ( %s, %s ) AND a.scheduled_date_gmt < %s AND ( a.last_attempt_gmt IS NULL OR a.last_attempt_gmt < %s ) GROUP BY slug ORDER BY cnt DESC LIMIT 20",
						array_merge( array( $table ), self::AS_DONE, array( $cutoff, $cutoff ) )
					),
					ARRAY_A
				);
			}
			foreach ( (array) $rows as $row ) {
				$stats['groups'][] = array(
					'group'        => (string) $row['slug'],
					'old_finished' => (int) $row['cnt'],
				);
			}
		}
		// phpcs:enable
		$stats['verdict'] = $stats['old_finished'] > 10000 ? 'needs-work' : 'good';
		return $stats;
	}

	/**
	 * Deletes finished (complete/canceled) Action Scheduler actions of every group older than $days and their logs.
	 * Pending, running and failed actions are never touched.
	 *
	 * @param int $days  Age in days (min 7).
	 * @param int $limit Max actions per call.
	 * @return array Cleanup result (+ 'logs_removed').
	 */
	public function cleanup_action_scheduler( int $days = 30, int $limit = self::BATCH ): array {
		global $wpdb;
		$result = array(
			'action'       => 'action_scheduler',
			'removed'      => 0,
			'logs_removed' => 0,
			'more'         => false,
			'undoable'     => false,
		);
		$tables = $this->as_tables();
		if ( null === $tables ) {
			return $result;
		}
		$days   = max( 7, $days );
		$limit  = max( 1, min( 10000, $limit ) );
		$cutoff = $this->cutoff( $days );
		$table  = $tables['actions'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names from $wpdb->prefix + fixed suffix; id lists are integers.
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT action_id FROM %i WHERE status IN ( %s, %s ) AND scheduled_date_gmt < %s AND ( last_attempt_gmt IS NULL OR last_attempt_gmt < %s ) ORDER BY action_id ASC LIMIT %d',
					array_merge( array( $table ), self::AS_DONE, array( $cutoff, $cutoff, $limit + 1 ) )
				)
			)
		);
		if ( count( $ids ) > $limit ) {
			$result['more'] = true;
			$ids            = array_slice( $ids, 0, $limit );
		}
		if ( empty( $ids ) ) {
			return $result;
		}
		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		if ( null !== $tables['logs'] ) {
			$result['logs_removed'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ( {$in} )", array_merge( array( $tables['logs'] ), $ids ) ) );
		}
		$result['removed'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ( {$in} )", array_merge( array( $table ), $ids ) ) );
		// phpcs:enable
		return $result;
	}

	/**
	 * Post meta rows whose post no longer exists.
	 *
	 * @return int
	 */
	public function orphaned_postmeta_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL', $wpdb->postmeta, $wpdb->posts ) );
	}

	/**
	 * Deletes orphaned post meta in batches.
	 *
	 * @param int $limit Max rows per call.
	 * @return array Cleanup result.
	 */
	public function cleanup_orphaned_postmeta( int $limit = self::BATCH ): array {
		global $wpdb;
		$limit = max( 1, min( 10000, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- id list is integers.
		$ids    = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT pm.meta_id FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL ORDER BY pm.meta_id ASC LIMIT %d',
					$wpdb->postmeta,
					$wpdb->posts,
					$limit + 1
				)
			)
		);
		$result = array(
			'action'   => 'orphaned_postmeta',
			'removed'  => 0,
			'more'     => count( $ids ) > $limit,
			'undoable' => false,
		);
		$ids    = array_slice( $ids, 0, $limit );
		if ( $ids ) {
			$in                = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$result['removed'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE meta_id IN ( {$in} )", array_merge( array( $wpdb->postmeta ), $ids ) ) );
		}
		// phpcs:enable
		return $result;
	}

	/**
	 * GMT datetime $days ago.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private function cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
	}
}
