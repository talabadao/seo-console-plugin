<?php
/**
 * Table access for seoc_suggestions (see docs/ARCHITECTURE.md §7.3).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Suggestions;

use SEOConsole\Changes\Change;
use SEOConsole\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Rows are returned as arrays with JSON columns decoded.
 */
final class SuggestionStore {

	const STATUSES = array( 'pending', 'applied', 'rejected', 'failed', 'stale' );

	const JSON_COLUMNS = array( 'current_value', 'proposed_value', 'final_value', 'meta' );

	/**
	 * Inserts a row.
	 *
	 * @param array $data Columns (JSON columns as PHP values).
	 * @return int Id, 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert( Schema::table( 'suggestions' ), $this->encode( $data ) );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns (JSON columns as PHP values).
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return false !== $wpdb->update( Schema::table( 'suggestions' ), $this->encode( $data ), array( 'id' => $id ) );
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * The pending suggestion for the same target, if any.
	 *
	 * @param string   $type        Applier id.
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param string   $field       Field.
	 * @return array<string, mixed>|null
	 */
	public function find_pending( string $type, string $object_type, ?int $object_id, string $field ): ?array {
		global $wpdb;
		$table = Schema::table( 'suggestions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		if ( null === $object_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND type = %s AND object_type = %s AND object_id IS NULL AND field = %s ORDER BY id DESC LIMIT 1", $table, $type, $object_type, $field ),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND type = %s AND object_type = %s AND object_id = %d AND field = %s ORDER BY id DESC LIMIT 1", $table, $type, $object_type, $object_id, $field ),
				ARRAY_A
			);
		}
		// phpcs:enable
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Filtered, paged rows (newest first).
	 *
	 * @param array $args status, module, type, object_type, object_id, field, page, per_page (≤ 100).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array( Schema::table( 'suggestions' ) );
		$this->filters( $args, $where, $params );
		$where_sql = implode( ' AND ', $where );
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql holds only literal placeholder fragments (see filters()).
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where_sql, $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->decode( $row );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Number of suggestions per status (with the same filters except status).
	 *
	 * @param array $args module, type, object_type, object_id.
	 * @return array<string, int>
	 */
	public function counts( array $args = array() ): array {
		global $wpdb;
		unset( $args['status'] );
		$where  = array( '1=1' );
		$params = array( Schema::table( 'suggestions' ) );
		$this->filters( $args, $where, $params );
		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql holds only literal placeholder fragments (see filters()).
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE ' . $where_sql . ' GROUP BY status', $params ), ARRAY_A );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Deletes rejected/stale/failed suggestions older than some days.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function purge_closed( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('rejected','stale','failed') AND updated_at < %s", Schema::table( 'suggestions' ), $cutoff ) );
	}

	/**
	 * Appends filter conditions: each condition is a literal fragment ('%i IN (%s,…)', '%i = %d'); column names and
	 * values are added to $params in placeholder order.
	 *
	 * @param array    $args   Filters.
	 * @param string[] $where  Conditions (by reference).
	 * @param array    $params Placeholder values (by reference).
	 */
	private function filters( array $args, array &$where, array &$params ): void {
		foreach ( array( 'status', 'module', 'type', 'object_type', 'field', 'source' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] || 'all' === $args[ $key ] ) {
				continue;
			}
			$values   = array_values( (array) $args[ $key ] );
			$where[]  = '%i IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params[] = $key;
			foreach ( $values as $value ) {
				$params[] = (string) $value;
			}
		}
		if ( isset( $args['object_id'] ) && '' !== $args['object_id'] ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}
	}

	/**
	 * Encodes JSON columns.
	 *
	 * @param array $data Columns.
	 */
	private function encode( array $data ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$data[ $column ] = Change::encode( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * Decodes a row.
	 *
	 * @param array $row Row.
	 * @return array<string, mixed>
	 */
	private function decode( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			$row[ $column ] = isset( $row[ $column ] ) ? Change::decode( $row[ $column ] ) : null;
		}
		foreach ( array( 'id', 'created_by' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( array( 'object_id', 'change_id' ) as $column ) {
			$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
		}
		$row['confidence'] = null === $row['confidence'] ? null : round( (float) $row['confidence'], 3 );
		return $row;
	}
}
