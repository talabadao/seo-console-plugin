<?php
/**
 * Storage for speed runs, per-URL PSI results and CrUX records.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Tables (see SpeedModule::tables()): `{prefix}seoc_speed_runs`, `{prefix}seoc_speed_results`, `{prefix}seoc_speed_crux`.
 * Dates are stored as UTC 'Y-m-d H:i:s'. JSON columns are decoded on read.
 */
class SpeedStore {

	/** Table keys (whitelist). */
	const TABLES = array( 'runs', 'results', 'crux' );

	/** Run statuses that mean "still working". */
	const ACTIVE = array( 'queued', 'running' );

	/** Result statuses that mean "not finished". */
	const OPEN = array( 'pending', 'retry' );

	/**
	 * Full table name.
	 *
	 * @param string $key runs|results|crux.
	 * @return string
	 */
	public static function table( string $key ): string {
		global $wpdb;
		if ( ! in_array( $key, self::TABLES, true ) ) {
			$key = 'runs';
		}
		return $wpdb->prefix . 'seoc_speed_' . $key;
	}

	/**
	 * CREATE TABLE statements for dbDelta.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		return array(
			'CREATE TABLE {prefix}seoc_speed_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  trigger_source varchar(20) NOT NULL DEFAULT \'schedule\',
  change_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT \'queued\',
  urls_total smallint(5) unsigned NOT NULL DEFAULT 0,
  urls_done smallint(5) unsigned NOT NULL DEFAULT 0,
  summary longtext,
  error text,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY status (status)
) {charset};',
			'CREATE TABLE {prefix}seoc_speed_results (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  url varchar(2048) NOT NULL,
  url_hash char(32) NOT NULL,
  url_type varchar(20) NOT NULL DEFAULT \'custom\',
  object_id bigint(20) unsigned DEFAULT NULL,
  label varchar(255) NOT NULL DEFAULT \'\',
  strategy varchar(10) NOT NULL,
  status varchar(20) NOT NULL DEFAULT \'pending\',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  fetched_at datetime DEFAULT NULL,
  score tinyint(3) unsigned DEFAULT NULL,
  lcp_ms int(10) unsigned DEFAULT NULL,
  fcp_ms int(10) unsigned DEFAULT NULL,
  tbt_ms int(10) unsigned DEFAULT NULL,
  cls decimal(6,3) DEFAULT NULL,
  si_ms int(10) unsigned DEFAULT NULL,
  ttfb_ms int(10) unsigned DEFAULT NULL,
  field_lcp_ms int(10) unsigned DEFAULT NULL,
  field_inp_ms int(10) unsigned DEFAULT NULL,
  field_cls decimal(6,3) DEFAULT NULL,
  field_category varchar(10) DEFAULT NULL,
  result longtext,
  error_code varchar(50) DEFAULT NULL,
  error text,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY url_strategy (url_hash,strategy,status)
) {charset};',
			'CREATE TABLE {prefix}seoc_speed_crux (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fetched_at datetime NOT NULL,
  kind varchar(10) NOT NULL,
  scope varchar(10) NOT NULL,
  target varchar(2048) NOT NULL,
  target_hash char(32) NOT NULL,
  form_factor varchar(10) NOT NULL,
  has_data tinyint(1) NOT NULL DEFAULT 0,
  period_end date DEFAULT NULL,
  lcp_p75 int(10) unsigned DEFAULT NULL,
  inp_p75 int(10) unsigned DEFAULT NULL,
  cls_p75 decimal(6,3) DEFAULT NULL,
  fcp_p75 int(10) unsigned DEFAULT NULL,
  ttfb_p75 int(10) unsigned DEFAULT NULL,
  assessment varchar(10) DEFAULT NULL,
  data longtext,
  PRIMARY KEY  (id),
  KEY target_kind (target_hash,kind,form_factor,fetched_at)
) {charset};',
		);
	}

	/**
	 * Current UTC datetime.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Hash used to group results of one URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_hash( string $url ): string {
		return md5( strtolower( untrailingslashit( $url ) ) );
	}

	/* ------------------------------------------------------------------ runs */

	/**
	 * Creates a run.
	 *
	 * @param array $data Columns.
	 * @return int Id (0 on failure).
	 */
	public function create_run( array $data ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'created_at'     => self::now(),
				'trigger_source' => 'schedule',
				'status'         => 'queued',
			),
			self::encode( $data, array( 'summary' ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		return $wpdb->insert( self::table( 'runs' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a run.
	 *
	 * @param int   $id   Run id.
	 * @param array $data Columns.
	 */
	public function update_run( int $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( self::table( 'runs' ), self::encode( $data, array( 'summary' ) ), array( 'id' => $id ) );
	}

	/**
	 * One run.
	 *
	 * @param int $id Run id.
	 * @return array|null
	 */
	public function get_run( int $id ) {
		global $wpdb;
		$table = self::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::decode_run( $row ) : null;
	}

	/**
	 * Paged run history (newest first).
	 *
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array [ 'items' => array[], 'total' => int ]
	 */
	public function runs( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$table    = self::table( 'runs' );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( __CLASS__, 'decode_run' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Latest run that is still queued/running (runs stuck for 3 hours are closed as failed first).
	 *
	 * @return array|null
	 */
	public function active_run() {
		global $wpdb;
		$table = self::table( 'runs' );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', finished_at = %s, error = %s WHERE status IN ( 'queued', 'running' ) AND created_at < %s",
				self::now(),
				'Timed out: background jobs did not finish within 3 hours.',
				$stale
			)
		);
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status IN ( 'queued', 'running' ) ORDER BY id DESC LIMIT 1", ARRAY_A );
		// phpcs:enable
		return $row ? self::decode_run( $row ) : null;
	}

	/**
	 * Most recent finished run (done or partial), optionally of a trigger source.
	 *
	 * @param string|null $trigger Trigger source filter.
	 * @return array|null
	 */
	public function last_finished_run( ?string $trigger = null ) {
		global $wpdb;
		$table = self::table( 'runs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( null === $trigger ) {
			$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status IN ( 'done', 'partial' ) ORDER BY id DESC LIMIT 1", ARRAY_A );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ( 'done', 'partial' ) AND trigger_source = %s ORDER BY id DESC LIMIT 1", $trigger ), ARRAY_A );
		}
		// phpcs:enable
		return $row ? self::decode_run( $row ) : null;
	}

	/* --------------------------------------------------------------- results */

	/**
	 * Creates a pending result row.
	 *
	 * @param array $data Columns.
	 * @return int Id.
	 */
	public function create_result( array $data ): int {
		global $wpdb;
		$url = isset( $data['url'] ) ? (string) $data['url'] : '';
		$row = array_merge(
			array(
				'status'   => 'pending',
				'url_hash' => self::url_hash( $url ),
			),
			self::encode( $data, array( 'result' ) )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		return $wpdb->insert( self::table( 'results' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a result row.
	 *
	 * @param int   $id   Result id.
	 * @param array $data Columns.
	 */
	public function update_result( int $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( self::table( 'results' ), self::encode( $data, array( 'result' ) ), array( 'id' => $id ) );
	}

	/**
	 * One result.
	 *
	 * @param int $id Result id.
	 * @return array|null
	 */
	public function get_result( int $id ) {
		global $wpdb;
		$table = self::table( 'results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::decode_result( $row ) : null;
	}

	/**
	 * Results of one run.
	 *
	 * @param int  $run_id   Run id.
	 * @param bool $with_raw Include the compact PSI JSON.
	 * @return array[]
	 */
	public function results_for_run( int $run_id, bool $with_raw = false ): array {
		global $wpdb;
		$table = self::table( 'results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", $run_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! $with_raw ) {
				unset( $row['result'] );
			}
			$out[] = self::decode_result( $row );
		}
		return $out;
	}

	/**
	 * Status counts of one run's results.
	 *
	 * @param int $run_id Run id.
	 * @return array status => count
	 */
	public function result_counts( int $run_id ): array {
		global $wpdb;
		$table = self::table( 'results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE run_id = %d GROUP BY status", $run_id ), ARRAY_A );
		$counts = array(
			'pending' => 0,
			'retry'   => 0,
			'done'    => 0,
			'failed'  => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['cnt'];
		}
		return $counts;
	}

	/**
	 * Latest successful result per URL × strategy (with the compact PSI JSON).
	 *
	 * @param string[]|null $urls Restrict to these URLs (null = all).
	 * @return array[]
	 */
	public function latest_results( ?array $urls = null ): array {
		global $wpdb;
		$table = self::table( 'results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no variables.
		$rows = $wpdb->get_results( "SELECT r.* FROM {$table} r INNER JOIN ( SELECT MAX(id) AS id FROM {$table} WHERE status = 'done' GROUP BY url_hash, strategy ) x ON x.id = r.id ORDER BY r.id ASC", ARRAY_A );
		$keep = null;
		if ( null !== $urls ) {
			$keep = array_flip( array_map( array( __CLASS__, 'url_hash' ), $urls ) );
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( null !== $keep && ! isset( $keep[ $row['url_hash'] ] ) ) {
				continue;
			}
			$out[] = self::decode_result( $row );
		}
		return $out;
	}

	/**
	 * Whether any successful result exists.
	 *
	 * @return bool
	 */
	public function has_results(): bool {
		global $wpdb;
		$table = self::table( 'results' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'done' LIMIT 1" );
	}

	/**
	 * Per-run averages of lab metrics (oldest first).
	 *
	 * @param string $strategy mobile|desktop.
	 * @param int    $limit    Max runs.
	 * @return array[] [ 'run_id', 'date', 'trigger', 'change_id', 'score', 'lcp_ms', 'cls', 'tbt_ms', 'urls' ]
	 */
	public function lab_trend( string $strategy = 'mobile', int $limit = 90 ): array {
		global $wpdb;
		$results = self::table( 'results' );
		$runs    = self::table( 'runs' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.run_id, MIN(r.fetched_at) AS fetched_at, AVG(r.score) AS score, AVG(r.lcp_ms) AS lcp_ms, AVG(r.cls) AS cls, AVG(r.tbt_ms) AS tbt_ms, COUNT(*) AS urls, MAX(u.trigger_source) AS trigger_source, MAX(u.change_id) AS change_id
				FROM {$results} r LEFT JOIN {$runs} u ON u.id = r.run_id
				WHERE r.status = 'done' AND r.strategy = %s GROUP BY r.run_id ORDER BY r.run_id DESC LIMIT %d",
				$strategy,
				max( 1, min( 365, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( array_reverse( (array) $rows ) as $row ) {
			$out[] = array(
				'run_id'    => (int) $row['run_id'],
				'date'      => self::iso( $row['fetched_at'] ),
				'trigger'   => (string) $row['trigger_source'],
				'change_id' => $row['change_id'] ? (int) $row['change_id'] : null,
				'score'     => null === $row['score'] ? null : (int) round( (float) $row['score'] ),
				'lcp_ms'    => null === $row['lcp_ms'] ? null : (int) round( (float) $row['lcp_ms'] ),
				'cls'       => null === $row['cls'] ? null : round( (float) $row['cls'], 3 ),
				'tbt_ms'    => null === $row['tbt_ms'] ? null : (int) round( (float) $row['tbt_ms'] ),
				'urls'      => (int) $row['urls'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ crux */

	/**
	 * Stores a CrUX record or history result.
	 *
	 * @param string $kind   record|history.
	 * @param array  $result CruxClient result.
	 * @return int Id.
	 */
	public function insert_crux( string $kind, array $result ): int {
		global $wpdb;
		$m   = isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array();
		$row = array(
			'fetched_at'  => self::now(),
			'kind'        => 'history' === $kind ? 'history' : 'record',
			'scope'       => isset( $result['scope'] ) ? (string) $result['scope'] : 'origin',
			'target'      => isset( $result['id'] ) ? (string) $result['id'] : '',
			'target_hash' => self::url_hash( isset( $result['id'] ) ? (string) $result['id'] : '' ),
			'form_factor' => isset( $result['form_factor'] ) ? (string) $result['form_factor'] : 'PHONE',
			'has_data'    => empty( $result['has_data'] ) ? 0 : 1,
			'data'        => wp_json_encode( $result ),
		);
		if ( 'record' === $row['kind'] ) {
			$row['period_end'] = isset( $result['period']['last'] ) ? $result['period']['last'] : null;
			$row['assessment'] = isset( $result['assessment'] ) ? $result['assessment'] : null;
			foreach ( array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ) as $key ) {
				$row[ $key . '_p75' ] = isset( $m[ $key ]['p75'] ) ? $m[ $key ]['p75'] : null;
			}
		} elseif ( ! empty( $result['periods'] ) ) {
			$row['period_end'] = end( $result['periods'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		return $wpdb->insert( self::table( 'crux' ), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Latest stored CrUX entry.
	 *
	 * @param string $kind        record|history.
	 * @param string $target      Origin or URL.
	 * @param string $form_factor PHONE|DESKTOP|ALL.
	 * @return array|null [ 'fetched_at' (ISO), 'data' => CruxClient result ]
	 */
	public function latest_crux( string $kind, string $target, string $form_factor ) {
		global $wpdb;
		$table = self::table( 'crux' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT fetched_at, data FROM {$table} WHERE target_hash = %s AND kind = %s AND form_factor = %s ORDER BY id DESC LIMIT 1",
				self::url_hash( $target ),
				$kind,
				$form_factor
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'fetched_at' => self::iso( $row['fetched_at'] ),
			'data'       => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * When the latest CrUX entry of a kind was stored (any target), or null.
	 *
	 * @param string $kind record|history.
	 * @return int|null Unix timestamp.
	 */
	public function last_crux_fetch( string $kind ) {
		global $wpdb;
		$table = self::table( 'crux' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(fetched_at) FROM {$table} WHERE kind = %s", $kind ) );
		return $value ? (int) strtotime( $value . ' UTC' ) : null;
	}

	/**
	 * Deletes runs/results/CrUX rows older than $days.
	 *
	 * @param int $days Retention.
	 * @return array [ 'runs' => n, 'results' => n, 'crux' => n ]
	 */
	public function prune( int $days ): array {
		global $wpdb;
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - max( 7, $days ) * DAY_IN_SECONDS );
		$runs    = self::table( 'runs' );
		$results = self::table( 'results' );
		$crux    = self::table( 'crux' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$deleted_results = (int) $wpdb->query( $wpdb->prepare( "DELETE r FROM {$results} r INNER JOIN {$runs} u ON u.id = r.run_id WHERE u.created_at < %s AND u.status NOT IN ( 'queued', 'running' )", $cutoff ) );
		$deleted_runs    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE created_at < %s AND status NOT IN ( 'queued', 'running' )", $cutoff ) );
		$deleted_crux    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$crux} WHERE fetched_at < %s", $cutoff ) );
		// phpcs:enable
		return array(
			'runs'    => $deleted_runs,
			'results' => $deleted_results,
			'crux'    => $deleted_crux,
		);
	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * JSON-encodes array columns.
	 *
	 * @param array    $data    Data.
	 * @param string[] $columns JSON columns.
	 * @return array
	 */
	private static function encode( array $data, array $columns ): array {
		foreach ( $columns as $column ) {
			if ( array_key_exists( $column, $data ) && is_array( $data[ $column ] ) ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * UTC datetime → ISO 8601 (or null).
	 *
	 * @param mixed $value Datetime.
	 * @return string|null
	 */
	public static function iso( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$ts = strtotime( (string) $value . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null;
	}

	/**
	 * Typed run row.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	public static function decode_run( array $row ): array {
		$summary = isset( $row['summary'] ) ? json_decode( (string) $row['summary'], true ) : null;
		return array(
			'id'             => (int) $row['id'],
			'created_at'     => self::iso( $row['created_at'] ),
			'started_at'     => self::iso( $row['started_at'] ),
			'finished_at'    => self::iso( $row['finished_at'] ),
			'trigger_source' => (string) $row['trigger_source'],
			'change_id'      => $row['change_id'] ? (int) $row['change_id'] : null,
			'status'         => (string) $row['status'],
			'urls_total'     => (int) $row['urls_total'],
			'urls_done'      => (int) $row['urls_done'],
			'summary'        => is_array( $summary ) ? $summary : null,
			'error'          => isset( $row['error'] ) ? (string) $row['error'] : '',
		);
	}

	/**
	 * Typed result row.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	public static function decode_result( array $row ): array {
		$int = static function ( $value ) {
			return null === $value ? null : (int) $value;
		};
		$out = array(
			'id'             => (int) $row['id'],
			'run_id'         => (int) $row['run_id'],
			'url'            => (string) $row['url'],
			'url_type'       => (string) $row['url_type'],
			'object_id'      => $int( $row['object_id'] ),
			'label'          => (string) $row['label'],
			'strategy'       => (string) $row['strategy'],
			'status'         => (string) $row['status'],
			'attempts'       => (int) $row['attempts'],
			'fetched_at'     => self::iso( $row['fetched_at'] ),
			'score'          => $int( $row['score'] ),
			'lcp_ms'         => $int( $row['lcp_ms'] ),
			'fcp_ms'         => $int( $row['fcp_ms'] ),
			'tbt_ms'         => $int( $row['tbt_ms'] ),
			'cls'            => null === $row['cls'] ? null : (float) $row['cls'],
			'si_ms'          => $int( $row['si_ms'] ),
			'ttfb_ms'        => $int( $row['ttfb_ms'] ),
			'field_lcp_ms'   => $int( $row['field_lcp_ms'] ),
			'field_inp_ms'   => $int( $row['field_inp_ms'] ),
			'field_cls'      => null === $row['field_cls'] ? null : (float) $row['field_cls'],
			'field_category' => null === $row['field_category'] ? null : (string) $row['field_category'],
			'error_code'     => null === $row['error_code'] ? null : (string) $row['error_code'],
			'error'          => null === $row['error'] ? null : (string) $row['error'],
		);
		if ( array_key_exists( 'result', $row ) ) {
			$decoded       = null === $row['result'] ? null : json_decode( (string) $row['result'], true );
			$out['result'] = is_array( $decoded ) ? $decoded : null;
		}
		return $out;
	}
}
