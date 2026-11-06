<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Core\Logger;
use SEOConsole\Core\Secrets;

require_once __DIR__ . '/CoreTestCase.php';

class SecretsTest extends CoreTestCase {

	const KEY = 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz3fA9';

	public function test_round_trip_is_encrypted_at_rest(): void {
		$secrets = new Secrets();
		$this->assertSame( 'none', $secrets->source( 'claude_api_key' ) );
		$this->assertFalse( $secrets->has( 'claude_api_key' ) );
		$this->assertTrue( $secrets->set( 'claude_api_key', '  ' . self::KEY . ' ' ) );
		$this->assertSame( self::KEY, $secrets->get( 'claude_api_key' ) );
		$this->assertSame( 'stored', $secrets->source( 'claude_api_key' ) );
		$this->assertSame( 'sk-ant-…3fA9', $secrets->masked( 'claude_api_key' ) );

		$raw = get_option( Secrets::OPTION );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', maybe_serialize( $raw ) );
		$this->assertStringStartsWith( 'v1:', $raw['claude_api_key'] );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$this->assertTrue( $secrets->set( 'claude_api_key', '' ) );
		$this->assertNull( $secrets->get( 'claude_api_key' ) );
		$this->assertSame( 'none', $secrets->source( 'claude_api_key' ) );
	}

	public function test_invalid_input(): void {
		$secrets = new Secrets();
		$this->assertWPError( $secrets->set( 'Bad Name', 'x' ) );
		$this->assertWPError( $secrets->set( 'claude_api_key', "sk-ant-with\nnewline" ) );
		$this->assertNull( $secrets->get( '../etc' ) );
	}

	public function test_constant_overrides_storage(): void {
		if ( ! defined( 'SEOC_TEST_CONST_KEY' ) ) {
			define( 'SEOC_TEST_CONST_KEY', 'sk-ant-constant-value-9876' );
		}
		$secrets = new Secrets();
		$this->assertSame( 'constant', $secrets->source( 'test_const_key' ) );
		$this->assertSame( 'sk-ant-constant-value-9876', $secrets->get( 'test_const_key' ) );
		$error = $secrets->set( 'test_const_key', 'other' );
		$this->assertSame( 'seoc_secret_constant', $error->get_error_code() );
		$this->assertSame(
			array(
				'has'    => true,
				'masked' => 'sk-ant-…9876',
				'source' => 'constant',
			),
			$secrets->describe( 'test_const_key' )
		);
	}

	public function test_changed_salt_makes_secret_undecryptable(): void {
		$secrets = new Secrets();
		$secrets->set( 'claude_api_key', self::KEY );
		$salt = static function () {
			return 'a-completely-different-salt';
		};
		add_filter( 'salt', $salt );
		$this->assertNull( $secrets->get( 'claude_api_key' ) );
		$this->assertSame( array( 'claude_api_key' ), $secrets->undecryptable() );
		$this->assertSame( 'stored', $secrets->source( 'claude_api_key' ) );
		remove_filter( 'salt', $salt );
		$this->assertSame( self::KEY, $secrets->get( 'claude_api_key' ) );
		$this->assertSame( array(), $secrets->undecryptable() );
	}

	public function test_garbage_value_is_undecryptable(): void {
		update_option( Secrets::OPTION, array( 'google_api_key' => 'v1:bm90IGEgY2lwaGVy' ), false );
		$secrets = new Secrets();
		$this->assertNull( $secrets->get( 'google_api_key' ) );
		$this->assertContains( 'google_api_key', $secrets->undecryptable() );
	}

	public function test_logger_never_writes_secrets(): void {
		$context = Logger::redact(
			array(
				'api_key' => self::KEY,
				'nested'  => array( 'x-api-key' => self::KEY ),
				'message' => 'used key ' . self::KEY,
			)
		);
		$this->assertStringNotContainsString( 'abcdefghijklmnop', wp_json_encode( $context ) );
	}
}
