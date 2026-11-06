<?php
/**
 * Database access for audit runs, crawled pages, links and issues.
 *
 * All four tables are owned by the Audit module. Table names come from a fixed whitelist and every query with
 * variables goes through $wpdb->prepare (identifiers through %i).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- custom tables, data changes every crawl step.

/**
 * Storage for the Audit module.
 */
class AuditStore {

	const RUN_STATUSES = array( 'queued', 'running', 'done', 'failed', 'cancelled' );

	/**
	 * Page columns stored as JSON (decoded on read).
	 */
	const PAGE_JSON = array( 'redirect_chain', 'headings', 'images_missing_alt', 'hreflang', 'og', 'schema_types', 'extra' );

	/**
	 * Every writable page column (besides id/run_id/url/url_hash).
	 */
	const PAGE_COLUMNS = array(
		'crawl_state',
		'found_via',
		'status_code',
		'final_status',
		'redirect_chain',
		'final_url',
		'content_type',
		'is_html',
		'response_ms',
		'bytes',
		'depth',
		'in_sitemap',
		'robots_blocked',
		'indexable',
		'indexability',
		'title',
		'meta_description',
		'canonical',
		'robots_meta',
		'x_robots',
		'h1_count',
		'h1',
		'headings',
		'word_count',
		'images_total',
		'images_missing_alt',
		'internal_links_out',
		'external_links_out',
		'inbound_internal',
		'hreflang',
		'og',
		'schema_types',
		'post_id',
		'error',
		'extra',
		'crawled_at',
	);

	/**
	 * Table name suffixes (without the WordPress prefix).
	 */
	const TABLES = array(
		'runs'   => 'seoc_audit_runs',
		'pages'  => 'seoc_audit_pages',
		'links'  => 'seoc_audit_links',
		'issues' => 'seoc_audit_issues',
	);

	/**
	 * Full table name from the whitelist.
	 *
	 * @param string $key runs|pages|links|issues.
	 */
	public function table( string $key ): string {
		global $wpdb;
		if ( ! isset( self::TABLES[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Unknown audit table.' );
		}
		return $wpdb->prefix . self::TABLES[ $key ];
	}

	/**
	 * CREATE TABLE statements for dbDelta, with {prefix} and {charset} placeholders (ModuleInterface::tables()).
	 *
	 * @return string[]
	 */
	public static function schema(): array {
		return array(
			'CREATE TABLE {prefix}seoc_audit_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT \'queued\',
  phase varchar(20) NOT NULL DEFAULT \'seed\',
  created_at datetime NOT NULL,
  started_at datetime NULL DEFAULT NULL,
  finished_at datetime NULL DEFAULT NULL,
  pages_found int(10) unsigned NOT NULL DEFAULT 0,
  pages_crawled int(10) unsigned NOT NULL DEFAULT 0,
  score tinyint(3) unsigned NULL DEFAULT NULL,
  summary longtext NULL,
  settings longtext NULL,
  state longtext NULL,
  error varchar(191) NOT NULL DEFAULT \'\',
  PRIMARY KEY  (id),
  KEY status (status),
  KEY finished_at (finished_at)
) {charset};',
			'CREATE TABLE {prefix}seoc_audit_pages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  url text NOT NULL,
  url_hash char(32) NOT NULL,
  crawl_state varchar(20) NOT NULL DEFAULT \'queued\',
  found_via varchar(20) NOT NULL DEFAULT \'link\',
  status_code smallint(5) unsigned NULL DEFAULT NULL,
  final_status smallint(5) unsigned NULL DEFAULT NULL,
  redirect_chain text NULL,
  final_url text NULL,
  content_type varchar(100) NOT NULL DEFAULT \'\',
  is_html tinyint(1) NOT NULL DEFAULT 0,
  response_ms int(10) unsigned NULL DEFAULT NULL,
  bytes int(10) unsigned NULL DEFAULT NULL,
  depth smallint(5) unsigned NULL DEFAULT NULL,
  in_sitemap tinyint(1) NOT NULL DEFAULT 0,
  robots_blocked tinyint(1) NOT NULL DEFAULT 0,
  indexable tinyint(1) NOT NULL DEFAULT 0,
  indexability varchar(30) NOT NULL DEFAULT \'\',
  title text NULL,
  meta_description text NULL,
  canonical text NULL,
  robots_meta varchar(191) NOT NULL DEFAULT \'\',
  x_robots varchar(191) NOT NULL DEFAULT \'\',
  h1_count smallint(5) unsigned NOT NULL DEFAULT 0,
  h1 text NULL,
  headings longtext NULL,
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  images_total int(10) unsigned NOT NULL DEFAULT 0,
  images_missing_alt text NULL,
  internal_links_out int(10) unsigned NOT NULL DEFAULT 0,
  external_links_out int(10) unsigned NOT NULL DEFAULT 0,
  inbound_internal int(10) unsigned NOT NULL DEFAULT 0,
  hreflang text NULL,
  og text NULL,
  schema_types text NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  error varchar(191) NOT NULL DEFAULT \'\',
  extra longtext NULL,
  crawled_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY run_url (run_id,url_hash),
  KEY run_state (run_id,crawl_state,depth),
  KEY run_post (run_id,post_id)
) {charset};',
			'CREATE TABLE {prefix}seoc_audit_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  from_page_id bigint(20) unsigned NOT NULL,
  to_url_hash char(32) NOT NULL,
  to_url text NOT NULL,
  anchor varchar(191) NOT NULL DEFAULT \'\',
  nofollow tinyint(1) NOT NULL DEFAULT 0,
  is_internal tinyint(1) NOT NULL DEFAULT 0,
  in_nav tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY run_to (run_id,to_url_hash),
  KEY run_from (run_id,from_page_id)
) {charset};',
			'CREATE TABLE {prefix}seoc_audit_issues (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  page_id bigint(20) unsigned NULL DEFAULT NULL,
  url_hash char(32) NOT NULL DEFAULT \'\',
  rule_id varchar(50) NOT NULL,
  severity varchar(10) NOT NULL,
  category varchar(30) NOT NULL,
  data longtext NULL,
  PRIMARY KEY  (id),
  KEY run_rule (run_id,rule_id),
  KEY run_severity (run_id,severity),
  KEY run_page (run_id,page_id)
) {charset};',
		);
	}

	/**
	 * Creates or upgrades the tables with dbDelta. Phase B moves this into the module's tables() + core Schema.
	 */
	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = array();
		foreach ( self::schema() as $statement ) {
			$sql[] = str_replace( array( '{prefix}', '{charset}' ), array( $wpdb->prefix, $wpdb->get_charset_collate() ), $statement );
		}
		dbDelta( $sql );
	}

	/**
	 * Drops every audit table (uninstall).
	 */
	public function uninstall(): void {
		global $wpdb;
		foreach ( array_keys( self::TABLES ) as $key ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table( $key ) ) );
		}
	}

	/** Current UTC time as a MySQL datetime. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Stable hash used to index URLs. */
	public static function hash( string $url ): string {
		return md5( $url );
	}

	/**
	 * JSON-encodes a value for storage.
	 *
	 * @param mixed $value Value.
	 */
	public static function json( $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $json ? 'null' : $json;
	}

	/**
	 * Decodes a stored JSON value.
	 *
	 * @param mixed $value    Stored value.
	 * @param mixed $fallback Value when empty/invalid.
	 * @return mixed
	 */
	public static function unjson( $value, $fallback = array() ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $fallback;
		}
		$decoded = json_decode( $value, true );
		return null === $decoded ? $fallback : $decoded;
	}

	/* ------------------------------------------------------------------ Runs */

	/**
	 * Inserts a run.
	 *
	 * @param array $settings Run settings.
	 * @return int Run id (0 on failure).
	 */
	public function create_run( array $settings ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			$this->table( 'runs' ),
			array(
				'status'     => 'queued',
				'phase'      => 'seed',
				'created_at' => self::now(),
				'settings'   => self::json( $settings ),
				'state'      => self::json( new \stdClass() ),
				'summary'    => self::json( new \stdClass() ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Reads a run with decoded settings/state/summary.
	 *
	 * @param int $run_id Run id.
	 */
	public function get_run( int $run_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'runs' ), $run_id ), ARRAY_A );
		return $row ? $this->decode_run( $row ) : null;
	}

	/**
	 * Updates run columns. Arrays for settings/state/summary are JSON-encoded.
	 *
	 * @param int   $run_id Run id.
	 * @param array $data   Column => value.
	 */
	public function update_run( int $run_id, array $data ): void {
		global $wpdb;
		$allowed = array( 'status', 'phase', 'started_at', 'finished_at', 'pages_found', 'pages_crawled', 'score', 'summary', 'settings', 'state', 'error' );
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		foreach ( array( 'summary', 'settings', 'state' ) as $json_column ) {
			if ( array_key_exists( $json_column, $data ) && ! is_string( $data[ $json_column ] ) ) {
				$data[ $json_column ] = self::json( $data[ $json_column ] );
			}
		}
		if ( $data ) {
			$wpdb->update( $this->table( 'runs' ), $data, array( 'id' => $run_id ) );
		}
	}

	/**
	 * Lists runs, newest first.
	 *
	 * @param int    $limit  Max rows.
	 * @param string $status Optional status filter.
	 * @return array[]
	 */
	public function list_runs( int $limit = 20, string $status = '' ): array {
		global $wpdb;
		if ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, phase, created_at, started_at, finished_at, pages_found, pages_crawled, score, error FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', $this->table( 'runs' ), $status, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, phase, created_at, started_at, finished_at, pages_found, pages_crawled, score, error FROM %i ORDER BY id DESC LIMIT %d', $this->table( 'runs' ), $limit ), ARRAY_A );
		}
		return array_map( array( $this, 'cast_run' ), (array) $rows );
	}

	/**
	 * The most recent finished run before $run_id (for diffs).
	 *
	 * @param int $run_id Run id.
	 */
	public function previous_done_run_id( int $run_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'done' AND id < %d ORDER BY id DESC LIMIT 1", $this->table( 'runs' ), $run_id ) );
	}

	/** Id of a queued/running run, or 0. */
	public function active_run_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1", $this->table( 'runs' ) ) );
	}

	/**
	 * Deletes a run and its rows.
	 *
	 * @param int $run_id Run id.
	 */
	public function delete_run( int $run_id ): void {
		global $wpdb;
		foreach ( array( 'issues', 'links', 'pages' ) as $key ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id = %d', $this->table( $key ), $run_id ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table( 'runs' ), $run_id ) );
	}

	/**
	 * Keeps the newest $keep runs (any status) and deletes the rest.
	 *
	 * @param int $keep Runs to keep.
	 * @return int Number of deleted runs.
	 */
	public function prune( int $keep = 5 ): int {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1000 OFFSET %d', $this->table( 'runs' ), max( 1, $keep ) ) );
		foreach ( $ids as $id ) {
			$this->delete_run( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Decodes JSON columns and casts numbers.
	 *
	 * @param array $row Raw row.
	 */
	private function decode_run( array $row ): array {
		$row             = $this->cast_run( $row );
		$row['settings'] = self::unjson( $row['settings'] ?? '' );
		$row['state']    = self::unjson( $row['state'] ?? '' );
		$row['summary']  = self::unjson( $row['summary'] ?? '' );
		return $row;
	}

	/**
	 * Casts numeric run columns.
	 *
	 * @param array $row Raw row.
	 */
	private function cast_run( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['pages_found']   = (int) $row['pages_found'];
		$row['pages_crawled'] = (int) $row['pages_crawled'];
		$row['score']         = null === $row['score'] ? null : (int) $row['score'];
		return $row;
	}

	/* ----------------------------------------------------------------- Pages */

	/**
	 * Adds URLs to the frontier, ignoring ones already known for the run. Existing rows get their depth lowered
	 * and the sitemap flag set when applicable.
	 *
	 * @param int   $run_id Run id.
	 * @param array $items  List of [ 'url' => normalised URL, 'depth' => int|null, 'found_via' => string, 'in_sitemap' => bool ].
	 * @param int   $limit  Max new rows to insert (0 = no limit).
	 * @return int Number of new rows.
	 */
	public function enqueue( int $run_id, array $items, int $limit = 0 ): int {
		global $wpdb;
		if ( ! $items ) {
			return 0;
		}
		$by_hash = array();
		foreach ( $items as $item ) {
			$hash = self::hash( $item['url'] );
			if ( isset( $by_hash[ $hash ] ) ) {
				$by_hash[ $hash ]['in_sitemap'] = $by_hash[ $hash ]['in_sitemap'] || ! empty( $item['in_sitemap'] );
				continue;
			}
			$by_hash[ $hash ] = array(
				'url'        => $item['url'],
				'depth'      => isset( $item['depth'] ) ? (int) $item['depth'] : null,
				'found_via'  => $item['found_via'] ?? 'link',
				'in_sitemap' => ! empty( $item['in_sitemap'] ),
			);
		}

		$inserted = 0;
		foreach ( array_chunk( $by_hash, 200, true ) as $chunk ) {
			$hashes   = array_keys( $chunk );
			$existing = $this->rows_by_hash( $run_id, $hashes, 'id, url_hash, depth, in_sitemap' );
			$values   = array();
			$args     = array();
			foreach ( $chunk as $hash => $item ) {
				if ( isset( $existing[ $hash ] ) ) {
					$row     = $existing[ $hash ];
					$changes = array();
					if ( null !== $item['depth'] && ( null === $row['depth'] || (int) $row['depth'] > $item['depth'] ) ) {
						$changes['depth'] = $item['depth'];
					}
					if ( $item['in_sitemap'] && ! (int) $row['in_sitemap'] ) {
						$changes['in_sitemap'] = 1;
					}
					if ( $changes ) {
						$wpdb->update( $this->table( 'pages' ), $changes, array( 'id' => (int) $row['id'] ) );
					}
					continue;
				}
				if ( $limit > 0 && $inserted + count( $values ) >= $limit ) {
					continue;
				}
				if ( null === $item['depth'] ) {
					$values[] = '(%d, %s, %s, %s, %s, NULL, %d)';
					array_push( $args, $run_id, $item['url'], $hash, 'queued', $item['found_via'], $item['in_sitemap'] ? 1 : 0 );
				} else {
					$values[] = '(%d, %s, %s, %s, %s, %d, %d)';
					array_push( $args, $run_id, $item['url'], $hash, 'queued', $item['found_via'], $item['depth'], $item['in_sitemap'] ? 1 : 0 );
				}
			}
			if ( $values ) {
				$sql = 'INSERT IGNORE INTO %i (run_id, url, url_hash, crawl_state, found_via, depth, in_sitemap) VALUES ' . implode( ', ', $values );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
				$inserted += (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $this->table( 'pages' ) ), $args ) ) );
			}
		}
		return $inserted;
	}

	/**
	 * Rows keyed by url_hash.
	 *
	 * @param int      $run_id  Run id.
	 * @param string[] $hashes  Hashes.
	 * @param string   $columns Column list (internal constant strings only).
	 * @return array<string, array>
	 */
	private function rows_by_hash( int $run_id, array $hashes, string $columns ): array {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$sql          = "SELECT {$columns} FROM %i WHERE run_id = %d AND url_hash IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns are internal constants.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $this->table( 'pages' ), $run_id ), $hashes ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['url_hash'] ] = $row;
		}
		return $out;
	}

	/**
	 * Next queued pages in crawl order (shallowest first, sitemap-only URLs last).
	 *
	 * @param int $run_id Run id.
	 * @param int $limit  Max rows.
	 * @return array[] Rows with id, url, url_hash, depth, in_sitemap, found_via.
	 */
	public function next_queued( int $run_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, url_hash, depth, in_sitemap, found_via FROM %i WHERE run_id = %d AND crawl_state = 'queued' ORDER BY depth IS NULL, depth, id LIMIT %d",
				$this->table( 'pages' ),
				$run_id,
				$limit
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/**
	 * Updates a page row; arrays for JSON columns are encoded.
	 *
	 * @param int   $page_id Page id.
	 * @param array $data    Column => value.
	 */
	public function update_page( int $page_id, array $data ): void {
		global $wpdb;
		$data = array_intersect_key( $data, array_flip( self::PAGE_COLUMNS ) );
		foreach ( self::PAGE_JSON as $column ) {
			if ( array_key_exists( $column, $data ) && ! is_string( $data[ $column ] ) && null !== $data[ $column ] ) {
				$data[ $column ] = self::json( $data[ $column ] );
			}
		}
		if ( $data ) {
			$wpdb->update( $this->table( 'pages' ), $data, array( 'id' => $page_id ) );
		}
	}

	/**
	 * Inserts a page row (when a page is reached through a redirect and was not queued) or returns the existing id.
	 *
	 * @param int    $run_id    Run id.
	 * @param string $url       Normalised URL.
	 * @param int    $depth     Depth or -1 for unknown.
	 * @param string $found_via Origin.
	 */
	public function ensure_page( int $run_id, string $url, int $depth, string $found_via ): int {
		$this->enqueue(
			$run_id,
			array(
				array(
					'url'       => $url,
					'depth'     => $depth >= 0 ? $depth : null,
					'found_via' => $found_via,
				),
			)
		);
		$row = $this->get_page_by_url( $run_id, $url );
		return $row ? (int) $row['id'] : 0;
	}

	/**
	 * A page row by id with JSON decoded.
	 *
	 * @param int $page_id Page id.
	 */
	public function get_page( int $page_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'pages' ), $page_id ), ARRAY_A );
		return $row ? self::decode_page( $row ) : null;
	}

	/**
	 * A page row by URL with JSON decoded.
	 *
	 * @param int    $run_id Run id.
	 * @param string $url    Normalised URL.
	 */
	public function get_page_by_url( int $run_id, string $url ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND url_hash = %s', $this->table( 'pages' ), $run_id, self::hash( $url ) ), ARRAY_A );
		return $row ? self::decode_page( $row ) : null;
	}

	/**
	 * Pages of a run (JSON decoded), paged by id.
	 *
	 * @param int      $run_id   Run id.
	 * @param int      $after_id Return ids greater than this.
	 * @param int      $limit    Max rows.
	 * @param string[] $states   Optional crawl_state filter.
	 * @return array[]
	 */
	public function pages( int $run_id, int $after_id = 0, int $limit = 100, array $states = array() ): array {
		global $wpdb;
		$sql  = 'SELECT * FROM %i WHERE run_id = %d AND id > %d';
		$args = array( $this->table( 'pages' ), $run_id, $after_id );
		if ( $states ) {
			$sql .= ' AND crawl_state IN (' . implode( ', ', array_fill( 0, count( $states ), '%s' ) ) . ')';
			$args = array_merge( $args, $states );
		}
		$sql   .= ' ORDER BY id LIMIT %d';
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode_page' ), (array) $rows );
	}

	/**
	 * Light index of the pages that were visited (for cross-page rules).
	 *
	 * @param int $run_id Run id.
	 * @return array<string, array> Keyed by url_hash.
	 */
	public function page_index( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, url_hash, crawl_state, status_code, final_status, final_url, is_html, indexable, indexability, in_sitemap, robots_blocked, canonical, title, meta_description, depth, inbound_internal, post_id, hreflang FROM %i WHERE run_id = %d AND crawl_state IN ('crawled','blocked','error')",
				$this->table( 'pages' ),
				$run_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['hreflang']         = self::unjson( $row['hreflang'] );
			$out[ $row['url_hash'] ] = self::cast_page( $row );
		}
		return $out;
	}

	/**
	 * Counts pages by crawl state.
	 *
	 * @param int $run_id Run id.
	 * @return array<string, int>
	 */
	public function count_pages_by_state( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT crawl_state, COUNT(*) AS n FROM %i WHERE run_id = %d GROUP BY crawl_state', $this->table( 'pages' ), $run_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['crawl_state'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Marks every still-queued page as not crawled (limit reached / cancelled).
	 *
	 * @param int $run_id Run id.
	 */
	public function close_frontier( int $run_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET crawl_state = 'not_crawled' WHERE run_id = %d AND crawl_state = 'queued'", $this->table( 'pages' ), $run_id ) );
	}

	/**
	 * Computes inbound_internal: distinct other pages linking to the URL, plus links to URLs that redirect to it.
	 *
	 * @param int $run_id Run id.
	 */
	public function compute_inbound( int $run_id ): void {
		global $wpdb;
		$pages = $this->table( 'pages' );
		$links = $this->table( 'links' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i p SET p.inbound_internal = (SELECT COUNT(DISTINCT l.from_page_id) FROM %i l WHERE l.run_id = p.run_id AND l.to_url_hash = p.url_hash AND l.is_internal = 1 AND l.from_page_id <> p.id) WHERE p.run_id = %d',
				$pages,
				$links,
				$run_id
			)
		);
		// Links that point at a redirecting URL count for the redirect's internal target.
		$redirects = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url_hash, final_url FROM %i WHERE run_id = %d AND crawl_state = 'crawled' AND status_code BETWEEN 300 AND 399 AND final_url IS NOT NULL AND final_url <> ''",
				$pages,
				$run_id
			),
			ARRAY_A
		);
		foreach ( (array) $redirects as $redirect ) {
			$target = $this->get_page_by_url( $run_id, (string) $redirect['final_url'] );
			if ( ! $target || (int) $target['id'] === (int) $redirect['id'] ) {
				continue;
			}
			$sources = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT from_page_id FROM %i WHERE run_id = %d AND is_internal = 1 AND (to_url_hash = %s OR to_url_hash = %s) AND from_page_id <> %d',
					$links,
					$run_id,
					$redirect['url_hash'],
					$target['url_hash'],
					(int) $target['id']
				)
			);
			$wpdb->update( $pages, array( 'inbound_internal' => count( $sources ) ), array( 'id' => (int) $target['id'] ) );
		}
	}

	/**
	 * Decodes a page row.
	 *
	 * @param array $row Raw row.
	 */
	public static function decode_page( array $row ): array {
		foreach ( self::PAGE_JSON as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = self::unjson( $row[ $column ] );
			}
		}
		return self::cast_page( $row );
	}

	/**
	 * Casts numeric page columns.
	 *
	 * @param array $row Row.
	 */
	public static function cast_page( array $row ): array {
		$ints = array( 'id', 'run_id', 'is_html', 'in_sitemap', 'robots_blocked', 'indexable', 'h1_count', 'word_count', 'images_total', 'internal_links_out', 'external_links_out', 'inbound_internal', 'post_id' );
		foreach ( $ints as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		foreach ( array( 'status_code', 'final_status', 'response_ms', 'bytes', 'depth' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}
		return $row;
	}

	/* ----------------------------------------------------------------- Links */

	/**
	 * Replaces the outgoing links of a page.
	 *
	 * @param int   $run_id  Run id.
	 * @param int   $page_id Source page id.
	 * @param array $links   List of [ 'url', 'anchor', 'nofollow', 'internal', 'in_nav' ].
	 */
	public function replace_links( int $run_id, int $page_id, array $links ): void {
		global $wpdb;
		$table = $this->table( 'links' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id = %d AND from_page_id = %d', $table, $run_id, $page_id ) );
		foreach ( array_chunk( $links, 100 ) as $chunk ) {
			$values = array();
			$args   = array( $table );
			foreach ( $chunk as $link ) {
				$values[] = '(%d, %d, %s, %s, %s, %d, %d, %d)';
				array_push(
					$args,
					$run_id,
					$page_id,
					self::hash( $link['url'] ),
					$link['url'],
					self::truncate( (string) ( $link['anchor'] ?? '' ), 191 ),
					empty( $link['nofollow'] ) ? 0 : 1,
					empty( $link['internal'] ) ? 0 : 1,
					empty( $link['in_nav'] ) ? 0 : 1
				);
			}
			$sql = 'INSERT INTO %i (run_id, from_page_id, to_url_hash, to_url, anchor, nofollow, is_internal, in_nav) VALUES ' . implode( ', ', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Internal outgoing links for a set of pages.
	 *
	 * @param int   $run_id   Run id.
	 * @param int[] $page_ids Source page ids.
	 * @return array<int, array[]> Keyed by from_page_id.
	 */
	public function internal_links_from( int $run_id, array $page_ids ): array {
		global $wpdb;
		$page_ids = array_values( array_filter( array_map( 'intval', $page_ids ) ) );
		if ( ! $page_ids ) {
			return array();
		}
		$sql = 'SELECT from_page_id, to_url_hash, to_url, anchor, nofollow, in_nav FROM %i WHERE run_id = %d AND is_internal = 1 AND from_page_id IN (' . implode( ', ', array_fill( 0, count( $page_ids ), '%d' ) ) . ') ORDER BY id';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $this->table( 'links' ), $run_id ), $page_ids ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['from_page_id'] ][] = $row;
		}
		return $out;
	}

	/**
	 * Hashes of URLs linked from navigation (nav/header menus).
	 *
	 * @param int $run_id Run id.
	 * @return array<string, true>
	 */
	public function nav_linked_hashes( int $run_id ): array {
		global $wpdb;
		$hashes = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT to_url_hash FROM %i WHERE run_id = %d AND is_internal = 1 AND in_nav = 1', $this->table( 'links' ), $run_id ) );
		return array_fill_keys( array_map( 'strval', $hashes ), true );
	}

	/**
	 * Pages linking to a URL (for "where is this link" in the UI).
	 *
	 * @param int    $run_id Run id.
	 * @param string $url    Normalised target URL.
	 * @param int    $limit  Max rows.
	 * @return array[] [ 'url', 'anchor' ].
	 */
	public function linking_pages( int $run_id, string $url, int $limit = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.url, l.anchor FROM %i l INNER JOIN %i p ON p.id = l.from_page_id WHERE l.run_id = %d AND l.to_url_hash = %s ORDER BY l.id LIMIT %d',
				$this->table( 'links' ),
				$this->table( 'pages' ),
				$run_id,
				self::hash( $url ),
				$limit
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/* ---------------------------------------------------------------- Issues */

	/**
	 * Inserts issues.
	 *
	 * @param int   $run_id Run id.
	 * @param array $issues List of [ 'rule_id', 'severity', 'category', 'page_id' (int|null), 'url_hash', 'data' ].
	 */
	public function insert_issues( int $run_id, array $issues ): void {
		global $wpdb;
		foreach ( array_chunk( $issues, 100 ) as $chunk ) {
			$values = array();
			$args   = array( $this->table( 'issues' ) );
			foreach ( $chunk as $issue ) {
				$page_id = empty( $issue['page_id'] ) ? null : (int) $issue['page_id'];
				if ( null === $page_id ) {
					$values[] = '(%d, NULL, %s, %s, %s, %s, %s)';
					array_push( $args, $run_id );
				} else {
					$values[] = '(%d, %d, %s, %s, %s, %s, %s)';
					array_push( $args, $run_id, $page_id );
				}
				array_push( $args, (string) ( $issue['url_hash'] ?? '' ), $issue['rule_id'], $issue['severity'], $issue['category'], self::json( $issue['data'] ?? array() ) );
			}
			$sql = 'INSERT INTO %i (run_id, page_id, url_hash, rule_id, severity, category, data) VALUES ' . implode( ', ', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Deletes the issues of a run (re-analysis).
	 *
	 * @param int $run_id Run id.
	 */
	public function delete_issues( int $run_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id = %d', $this->table( 'issues' ), $run_id ) );
	}

	/**
	 * Issues of a run, joined with the page URL and post id.
	 *
	 * @param int   $run_id Run id.
	 * @param array $args   Filters: rule_id, severity, category, page_id; paging: page (1-based), per_page (≤ 500).
	 * @return array{items: array[], total: int}
	 */
	public function issues( int $run_id, array $args = array() ): array {
		global $wpdb;
		$where  = 'i.run_id = %d';
		$params = array( $run_id );
		foreach ( array( 'rule_id', 'severity', 'category' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$where   .= " AND i.{$filter} = %s";
				$params[] = (string) $args[ $filter ];
			}
		}
		if ( isset( $args['page_id'] ) ) {
			$where   .= ' AND i.page_id = %d';
			$params[] = (int) $args['page_id'];
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 5000, (int) $args['per_page'] ) ) : 5000;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds placeholders only.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i i WHERE {$where}", array_merge( array( $this->table( 'issues' ) ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.page_id, i.url_hash, i.rule_id, i.severity, i.category, i.data, p.url, p.post_id FROM %i i LEFT JOIN %i p ON p.id = i.page_id WHERE {$where} ORDER BY i.id LIMIT %d OFFSET %d",
				array_merge( array( $this->table( 'issues' ), $this->table( 'pages' ) ), $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'       => (int) $row['id'],
				'page_id'  => null === $row['page_id'] ? null : (int) $row['page_id'],
				'url_hash' => (string) $row['url_hash'],
				'url'      => null === $row['url'] ? '' : (string) $row['url'],
				'post_id'  => (int) $row['post_id'],
				'rule_id'  => $row['rule_id'],
				'severity' => $row['severity'],
				'category' => $row['category'],
				'data'     => self::unjson( $row['data'] ),
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Issue counts grouped by rule.
	 *
	 * @param int $run_id Run id.
	 * @return array[] [ 'rule_id', 'severity', 'category', 'n' ].
	 */
	public function issue_counts( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT rule_id, severity, category, COUNT(*) AS n FROM %i WHERE run_id = %d GROUP BY rule_id, severity, category', $this->table( 'issues' ), $run_id ), ARRAY_A );
		return array_map(
			static function ( $row ) {
				$row['n'] = (int) $row['n'];
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Issue keys (rule + URL hash) for diffs.
	 *
	 * @param int $run_id Run id.
	 * @return array<string, array> key => [ 'rule_id', 'url_hash', 'page_id', 'severity', 'url' ].
	 */
	public function issue_keys( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.rule_id, i.url_hash, i.page_id, i.severity, i.category, p.url FROM %i i LEFT JOIN %i p ON p.id = i.page_id WHERE i.run_id = %d',
				$this->table( 'issues' ),
				$this->table( 'pages' ),
				$run_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['rule_id'] . '|' . $row['url_hash'] ] = array(
				'rule_id'  => $row['rule_id'],
				'url_hash' => (string) $row['url_hash'],
				'page_id'  => null === $row['page_id'] ? null : (int) $row['page_id'],
				'severity' => $row['severity'],
				'category' => $row['category'],
				'url'      => (string) $row['url'],
			);
		}
		return $out;
	}

	/**
	 * Truncates a string to a byte-safe character length.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 */
	public static function truncate( string $text, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}
		return substr( $text, 0, $length );
	}
}
