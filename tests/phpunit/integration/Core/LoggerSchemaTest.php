<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Core\Logger;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class LoggerSchemaTest extends CoreTestCase {

	public function test_core_tables_exist(): void {
		global $wpdb;
		foreach ( Schema::CORE_TABLES as $key ) {
			$table = Schema::table( $key );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		$this->assertNotEmpty( get_option( Schema::OPTION ) );
	}

	public function test_unknown_table_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Schema::table( 'users' );
	}

	public function test_version_changes_with_module_tables_and_migrates(): void {
		$module = new \SEOConsole\Tests\Fixtures\FakeModule();
		$this->assertNotSame( Schema::version(), Schema::version( $module->tables() ) );
		update_option( Schema::OPTION, 'old' );
		$this->assertTrue( Schema::maybe_migrate( $module->tables() ) );
		$this->assertSame( Schema::version( $module->tables() ), get_option( Schema::OPTION ) );
		$this->assertFalse( Schema::maybe_migrate( $module->tables() ) );
		global $wpdb;
		// The test suite turns CREATE TABLE into temporary tables: DESCRIBE still sees them.
		$columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}seoc_fake_items" ); // phpcs:ignore
		$this->assertContains( 'name', $columns );
	}

	public function test_foreign_statements_are_ignored(): void {
		Schema::install( array( 'CREATE TABLE {prefix}posts_evil (id int) {charset};' ) );
		global $wpdb;
		$wpdb->suppress_errors( true );
		$this->assertFalse( (bool) $wpdb->get_col( "DESCRIBE {$wpdb->prefix}posts_evil" ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
	}

	public function test_logger_levels_and_purge(): void {
		$logger = Plugin::instance()->logger();
		$this->assertTrue( $logger->log( 'warning', 'Something odd', array( 'n' => 1 ), 'speed' ) );
		$this->assertFalse( $logger->log( 'debug', 'Below threshold' ) );
		Plugin::instance()->settings()->set( 'general', 'log_level', 'debug' );
		$this->assertTrue( $logger->log( 'debug', 'Now visible' ) );

		$rows = $logger->recent( array( 'level' => 'warning' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'speed', $rows[0]['module'] );
		$this->assertSame( array( 'n' => 1 ), $rows[0]['context'] );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'log' ) . ' SET created_at = %s', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$this->assertSame( 2, $logger->purge( Logger::RETENTION_DAYS ) );
		$this->assertSame( array(), $logger->recent() );
	}

	public function test_logger_redacts_secrets_in_rows(): void {
		$logger = Plugin::instance()->logger();
		$logger->error( 'Failed', array( 'api_key' => 'sk-ant-api03-secretsecretsecret' ) );
		$rows = $logger->recent();
		$this->assertSame( '[redacted]', $rows[0]['context']['api_key'] );
	}
}
