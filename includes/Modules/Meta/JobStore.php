<?php
/**
 * Queue of posts waiting for AI suggestions (table seoc_meta_jobs). Background jobs read from it; GET meta/status
 * reports on it. Long-running state lives here, not in transients (ARCHITECTURE.md §8).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

defined( 'ABSPATH' ) || exit;

final class JobStore {

	const TABLE = 'seoc_meta_jobs';

	/** Option holding why the queue stopped (budget, key…), or nothing. */
	const STOPPED_OPTION = 'seoc_meta_queue_stopped';

	/** Finished rows are kept this many days for the status report. */
	const KEEP_DAYS = 7;

	const STATUSES = array( 'queued', 'done', 'failed' );

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * CREATE TABLE for dbDelta.
	 *
	 * @return string
	 */
	public static function table_sql(): string {
		return 'CREATE TABLE {prefix}' . self::TABLE . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  fields varchar(100) NOT NULL DEFAULT '',
  origin varchar(40) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'queued',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  created_count smallint(5) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_id (status,id),
  KEY post_id (post_id)
) {charset};";
	}

	/**
	 * Queues posts. A post already queued gets the union of fields instead of a second row.
	 *
	 * @param array  $items  [ 'object_type' => 'post', 'object_id' => int, 'fields' => string[] ] (object_type other
	 *                       than 'post' is ignored).
	 * @param string $origin Who asked ('rest', 'audit', 'publish'…).
	 * @return int Number of posts newly queued or updated.
	 */
	public function add( array $items, string $origin ): int {
		global $wpdb;
		$count = 0;
		$now   = current_time( 'mysql', true );
		foreach ( $items as $item ) {
			$type = isset( $item['object_type'] ) ? (string) $item['object_type'] : 'post';
			$id   = isset( $item['object_id'] ) ? absint( $item['object_id'] ) : 0;
			if ( 'post' !== $type || ! $id ) {
				continue;
			}
			$fields = self::clean_fields( isset( $item['fields'] ) ? (array) $item['fields'] : array() );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, fields FROM %i WHERE post_id = %d AND status = 'queued' LIMIT 1", self::table(), $id ), ARRAY_A );
			if ( $row ) {
				$merged = self::clean_fields( array_merge( explode( ',', (string) $row['fields'] ), $fields ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update(
					self::table(),
					array(
						'fields'     => implode( ',', $merged ),
						'updated_at' => $now,
					),
					array( 'id' => (int) $row['id'] )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
				$wpdb->insert(
					self::table(),
					array(
						'post_id'    => $id,
						'fields'     => implode( ',', $fields ),
						'origin'     => substr( sanitize_key( $origin ), 0, 40 ),
						'status'     => 'queued',
						'created_by' => get_current_user_id(),
						'created_at' => $now,
						'updated_at' => $now,
					)
				);
			}
			++$count;
		}
		if ( $count ) {
			delete_option( self::STOPPED_OPTION );
		}
		return $count;
	}

	/**
	 * Next queued items, oldest first, as MetaSuggester batch items (with job_id).
	 *
	 * @param int $limit Maximum.
	 * @return array[]
	 */
	public function next( int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'queued' ORDER BY id ASC LIMIT %d", self::table(), max( 1, $limit ) ), ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'job_id'      => (int) $row['id'],
				'object_type' => 'post',
				'object_id'   => (int) $row['post_id'],
				'fields'      => self::clean_fields( explode( ',', (string) $row['fields'] ) ),
				'attempts'    => (int) $row['attempts'],
			);
		}
		return $items;
	}

	/**
	 * Marks a job finished.
	 *
	 * @param int    $job_id  Job.
	 * @param string $status  'done'|'failed'.
	 * @param string $message Summary for the status report.
	 * @param int    $created Suggestions created.
	 */
	public function finish( int $job_id, string $status, string $message = '', int $created = 0 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			self::table(),
			array(
				'status'        => in_array( $status, self::STATUSES, true ) ? $status : 'failed',
				'message'       => substr( $message, 0, 1000 ),
				'created_count' => max( 0, $created ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id )
		);
	}

	/**
	 * Keeps a job queued for a later try.
	 *
	 * @param int    $job_id   Job.
	 * @param int    $attempts Attempts so far.
	 * @param string $message  Last error.
	 */
	public function retry_later( int $job_id, int $attempts, string $message ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			self::table(),
			array(
				'attempts'   => $attempts,
				'message'    => substr( $message, 0, 1000 ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id )
		);
	}

	/**
	 * Number of queued posts.
	 *
	 * @return int
	 */
	public function queued_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'queued'", self::table() ) );
	}

	/**
	 * Status summary: counts (queued now, done/failed in the last KEEP_DAYS days) and the latest failures.
	 *
	 * @return array{queued: int, done: int, failed: int, suggestions_created: int, recent_failures: array, stopped: array|null}
	 */
	public function summary(): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(created_count) AS created FROM %i WHERE status = 'queued' OR updated_at >= %s GROUP BY status", self::table(), $since ), ARRAY_A );
		$counts = array(
			'queued'              => 0,
			'done'                => 0,
			'failed'              => 0,
			'suggestions_created' => 0,
		);
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
			$counts['suggestions_created'] += (int) $row['created'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$failures = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, message, updated_at FROM %i WHERE status = 'failed' AND updated_at >= %s ORDER BY id DESC LIMIT 10", self::table(), $since ), ARRAY_A );
		$recent   = array();
		foreach ( $failures as $row ) {
			$recent[] = array(
				'post_id' => (int) $row['post_id'],
				'title'   => html_entity_decode( (string) get_the_title( (int) $row['post_id'] ), ENT_QUOTES, 'UTF-8' ),
				'message' => (string) $row['message'],
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['updated_at'] . ' UTC' ) ),
			);
		}
		$stopped                   = get_option( self::STOPPED_OPTION );
		$counts['recent_failures'] = $recent;
		$counts['stopped']         = is_array( $stopped ) ? $stopped : null;
		return $counts;
	}

	/**
	 * Records why the queue stopped (shown until new work is queued).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	public function set_stopped( string $code, string $message ): void {
		update_option(
			self::STOPPED_OPTION,
			array(
				'code'    => $code,
				'message' => $message,
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			false
		);
	}

	/**
	 * Clears the stopped state.
	 */
	public function clear_stopped(): void {
		delete_option( self::STOPPED_OPTION );
	}

	/**
	 * Deletes finished rows older than KEEP_DAYS.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status <> 'queued' AND updated_at < %s", self::table(), $cutoff ) );
	}

	/**
	 * Deletes every queued row (module switched off).
	 */
	public function clear_queue(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status = 'queued'", self::table() ) );
	}

	/**
	 * Allowed field names, unique, in a stable order.
	 *
	 * @param array $fields Fields.
	 * @return string[]
	 */
	public static function clean_fields( array $fields ): array {
		$fields = array_values( array_intersect( MetaSuggester::FIELDS, array_map( 'strval', $fields ) ) );
		return $fields ? $fields : MetaSuggester::DEFAULT_FIELDS;
	}
}
