<?php
/**
 * Plugin log in table seoc_log (see docs/ARCHITECTURE.md §4).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

use SEOConsole\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Levels debug < info < warning < error; entries below the `log_level` setting are dropped.
 * Context keys that look like secrets are redacted; logs are kept 30 days (daily housekeeping).
 */
final class Logger {

	const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	const RETENTION_DAYS = 30;

	/**
	 * Settings service (optional, for the minimum level).
	 *
	 * @var Settings|null
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings service.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Debug entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context (JSON-serialisable).
	 * @param string $module  Module id.
	 */
	public function debug( string $message, array $context = array(), string $module = 'core' ): void {
		$this->log( 'debug', $message, $context, $module );
	}

	/**
	 * Info entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param string $module  Module id.
	 */
	public function info( string $message, array $context = array(), string $module = 'core' ): void {
		$this->log( 'info', $message, $context, $module );
	}

	/**
	 * Warning entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param string $module  Module id.
	 */
	public function warning( string $message, array $context = array(), string $module = 'core' ): void {
		$this->log( 'warning', $message, $context, $module );
	}

	/**
	 * Error entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param string $module  Module id.
	 */
	public function error( string $message, array $context = array(), string $module = 'core' ): void {
		$this->log( 'error', $message, $context, $module );
	}

	/**
	 * Writes one entry.
	 *
	 * @param string $level   debug|info|warning|error.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param string $module  Module id.
	 * @return bool Whether the entry was written.
	 */
	public function log( string $level, string $message, array $context = array(), string $module = 'core' ): bool {
		global $wpdb;
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'info';
		}
		$min = $this->settings ? (string) $this->settings->get( Settings::GENERAL, 'log_level' ) : 'info';
		if ( isset( self::LEVELS[ $min ] ) && self::LEVELS[ $level ] < self::LEVELS[ $min ] ) {
			return false;
		}
		$json = empty( $context ) ? null : wp_json_encode( self::redact( $context ) );
		if ( is_string( $json ) && strlen( $json ) > 65000 ) {
			$json = wp_json_encode( array( 'truncated' => true ) );
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			Schema::table( 'log' ),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'level'      => $level,
				'module'     => substr( sanitize_key( $module ), 0, 40 ),
				'message'    => mb_substr( wp_strip_all_tags( $message ), 0, 2000 ),
				'context'    => $json,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppress );
		return false !== $ok;
	}

	/**
	 * Latest entries, newest first.
	 *
	 * @param array $args level (minimum), module, limit (≤ 500).
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( array $args = array() ): array {
		global $wpdb;
		$table  = Schema::table( 'log' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['level'] ) && isset( self::LEVELS[ $args['level'] ] ) ) {
			$levels = array();
			foreach ( self::LEVELS as $name => $rank ) {
				if ( $rank >= self::LEVELS[ $args['level'] ] ) {
					$levels[] = $name;
				}
			}
			$where[] = 'level IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
			$params  = array_merge( $params, $levels );
		}
		if ( ! empty( $args['module'] ) ) {
			$where[]  = 'module = %s';
			$params[] = sanitize_key( $args['module'] );
		}
		$limit    = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 50;
		$params[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table from whitelist, placeholders built above.
		$sql  = $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $params );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['id']      = (int) $row['id'];
			$row['context'] = null === $row['context'] ? null : json_decode( $row['context'], true );
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * Deletes entries older than the retention period.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function purge( int $days = self::RETENTION_DAYS ): int {
		global $wpdb;
		$table  = Schema::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table from whitelist.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
		return (int) $deleted;
	}

	/**
	 * Replaces values whose key looks like a secret and strings that look like API keys.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	public static function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/(api[_-]?key|secret|token|password|authorization|x-api-key|salt)/i', $key ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = preg_replace( '/\b(sk-ant-[A-Za-z0-9_\-]{4})[A-Za-z0-9_\-]+/', '$1…[redacted]', $value );
				$context[ $key ] = preg_replace( '/\bAIza[0-9A-Za-z_\-]{20,}/', 'AIza…[redacted]', $context[ $key ] );
			}
		}
		return $context;
	}
}
