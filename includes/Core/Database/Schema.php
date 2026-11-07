<?php
/**
 * Database tables and migrations (see docs/ARCHITECTURE.md §4, §7).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Core tables + every module's tables() through dbDelta. Statements use {prefix} (the table prefix) and {charset}
 * (the charset/collation clause). The stored version `seoc_db_version` is the core schema version plus a hash of
 * all statements, so a module that adds or changes a table triggers a migration automatically.
 */
final class Schema {

	/** Bump when a core table changes. */
	const VERSION = '1';

	const OPTION = 'seoc_db_version';

	/** Whitelist of core table keys (without prefix). */
	const CORE_TABLES = array( 'changes', 'suggestions', 'log', 'ai_usage' );

	/**
	 * Full name of a core table, e.g. Schema::table( 'changes' ) → wp_seoc_changes.
	 *
	 * @param string $key One of CORE_TABLES.
	 * @throws \InvalidArgumentException For unknown tables.
	 */
	public static function table( string $key ): string {
		global $wpdb;
		if ( ! in_array( $key, self::CORE_TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown SEO Console table.' );
		}
		return $wpdb->prefix . 'seoc_' . $key;
	}

	/**
	 * CREATE TABLE statements of the core tables (placeholders not replaced).
	 *
	 * @return string[]
	 */
	public static function core_statements(): array {
		return array(
			"CREATE TABLE {prefix}seoc_changes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'user',
  module varchar(40) NOT NULL DEFAULT '',
  type varchar(60) NOT NULL,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  field varchar(191) NOT NULL,
  before_value longtext,
  after_value longtext,
  status varchar(20) NOT NULL DEFAULT 'applied',
  error text,
  suggestion_id bigint(20) unsigned DEFAULT NULL,
  batch_id varchar(40) DEFAULT NULL,
  note text,
  reverted_at datetime DEFAULT NULL,
  reverted_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY object_ref (object_type,object_id),
  KEY status (status),
  KEY module (module),
  KEY batch_id (batch_id),
  KEY created_at (created_at)
) {charset};",
			"CREATE TABLE {prefix}seoc_suggestions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  module varchar(40) NOT NULL DEFAULT '',
  type varchar(60) NOT NULL,
  object_type varchar(20) NOT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  field varchar(191) NOT NULL,
  current_value longtext,
  proposed_value longtext,
  final_value longtext,
  rationale text,
  confidence float DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'ai',
  model varchar(80) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  change_id bigint(20) unsigned DEFAULT NULL,
  error text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  meta longtext,
  PRIMARY KEY  (id),
  KEY status_module (status,module),
  KEY object_ref (object_type,object_id),
  KEY type_field (type,field)
) {charset};",
			"CREATE TABLE {prefix}seoc_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL DEFAULT 'info',
  module varchar(40) NOT NULL DEFAULT 'core',
  message text NOT NULL,
  context longtext,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY level (level)
) {charset};",
			"CREATE TABLE {prefix}seoc_ai_usage (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  model varchar(80) NOT NULL DEFAULT '',
  module varchar(40) NOT NULL DEFAULT '',
  context varchar(80) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'ok',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cache_creation_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cache_read_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cost_usd decimal(12,6) NOT NULL DEFAULT 0,
  request_id varchar(100) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {charset};",
		);
	}

	/**
	 * Replaces {prefix} and {charset}.
	 *
	 * @param string $sql Statement.
	 */
	public static function prepare_statement( string $sql ): string {
		global $wpdb;
		return str_replace( array( '{prefix}', '{charset}' ), array( $wpdb->prefix, $wpdb->get_charset_collate() ), $sql );
	}

	/**
	 * Version string for a set of module statements.
	 *
	 * @param string[] $module_statements Module CREATE TABLE statements.
	 */
	public static function version( array $module_statements = array() ): string {
		return self::VERSION . '-' . substr( md5( implode( "\n", array_merge( self::core_statements(), $module_statements ) ) ), 0, 10 );
	}

	/**
	 * Runs install() when the stored version differs.
	 *
	 * @param string[] $module_statements Module CREATE TABLE statements.
	 * @return bool Whether a migration ran.
	 */
	public static function maybe_migrate( array $module_statements = array() ): bool {
		if ( get_option( self::OPTION ) === self::version( $module_statements ) ) {
			return false;
		}
		self::install( $module_statements );
		return true;
	}

	/**
	 * Creates or updates all tables with dbDelta and stores the version.
	 *
	 * @param string[] $module_statements Module CREATE TABLE statements.
	 */
	public static function install( array $module_statements = array() ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( array_merge( self::core_statements(), $module_statements ) as $sql ) {
			if ( ! is_string( $sql ) || ! preg_match( '/^\s*CREATE TABLE\s+\{prefix\}seoc_[a-z0-9_]+\s/i', $sql ) ) {
				continue; // Only our own, prefixed tables.
			}
			dbDelta( self::prepare_statement( $sql ) );
		}
		update_option( self::OPTION, self::version( $module_statements ), true );
	}

	/**
	 * Names of every existing SEO Console table (core and modules), for uninstall.
	 *
	 * @return string[]
	 */
	public static function existing_tables(): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'seoc_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema introspection.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return array_values(
			array_filter(
				(array) $tables,
				static function ( $name ) use ( $wpdb ) {
					return (bool) preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'seoc_[a-z0-9_]+$/', (string) $name );
				}
			)
		);
	}

	/**
	 * Drops every SEO Console table and the version option.
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::existing_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names from SHOW TABLES, validated against our prefix.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( self::OPTION );
	}
}
