<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Core\Settings;

require_once __DIR__ . '/CoreTestCase.php';

class SettingsTest extends CoreTestCase {

	/** @var Settings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
		$this->settings->register_module( 'fake', ( new \SEOConsole\Tests\Fixtures\FakeModule() )->settings_schema(), true );
	}

	public function test_general_defaults(): void {
		$this->assertSame( 'claude-opus-5-5', $this->settings->get( 'general', 'ai_model' ) );
		$this->assertSame( 20, $this->settings->get( 'general', 'ai_monthly_budget_usd' ) );
		$this->assertFalse( $this->settings->get( 'general', 'keep_data_on_uninstall' ) );
		$this->assertSame( 'auto', $this->settings->get( 'general', 'seo_plugin' ) );
		$this->assertNull( $this->settings->get( 'general', 'nope' ) );
	}

	public function test_set_and_get_valid_values(): void {
		$this->assertTrue( $this->settings->set( 'general', 'ai_model', 'claude-haiku-5-5' ) );
		$this->assertTrue( $this->settings->set( 'general', 'ai_monthly_budget_usd', '50' ) );
		$this->assertTrue( $this->settings->set( 'fake', 'flag', '1' ) );
		$this->assertSame( 'claude-haiku-5-5', $this->settings->get( 'general', 'ai_model' ) );
		$this->assertSame( 50, $this->settings->get( 'general', 'ai_monthly_budget_usd' ) );
		$this->assertTrue( $this->settings->get( 'fake', 'flag' ) );
	}

	public function test_invalid_values_are_rejected(): void {
		$this->assertWPError( $this->settings->set( 'general', 'ai_model', 'gpt-4' ) );
		$this->assertWPError( $this->settings->set( 'general', 'ai_monthly_budget_usd', -1 ) );
		$this->assertWPError( $this->settings->set( 'fake', 'count', 11 ) );
		$this->assertWPError( $this->settings->set( 'fake', 'count', 'abc' ) );
		$this->assertWPError( $this->settings->set( 'fake', 'flag', 'maybe' ) );
		$this->assertWPError( $this->settings->set( 'fake', 'urls', array( 'not a url' ) ) );
		$error = $this->settings->set( 'fake', 'unknown', 1 );
		$this->assertSame( 'seoc_unknown_setting', $error->get_error_code() );
		$this->assertSame( 'seoc_unknown_module', $this->settings->set( 'nomodule', 'x', 1 )->get_error_code() );
	}

	public function test_update_is_all_or_nothing(): void {
		$result = $this->settings->update(
			'fake',
			array(
				'count' => 3,
				'mode'  => 'zzz',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 5, $this->settings->get( 'fake', 'count' ) );
	}

	public function test_lists_are_sanitized(): void {
		$this->settings->set( 'fake', 'words', "alpha\n<b>beta</b>\n\nalpha" );
		$this->assertSame( array( 'alpha', 'beta' ), $this->settings->get( 'fake', 'words' ) );
		$this->settings->set( 'fake', 'urls', array( 'https://example.org/a', ' https://example.org/b ' ) );
		$this->assertSame( array( 'https://example.org/a', 'https://example.org/b' ), $this->settings->get( 'fake', 'urls' ) );
	}

	public function test_enable_disable_fires_toggle_action(): void {
		$seen = array();
		$cb   = static function ( $module, $enabled ) use ( &$seen ) {
			$seen[] = array( $module, $enabled );
		};
		add_action( 'seoc_module_toggled', $cb, 10, 2 );
		$this->assertTrue( $this->settings->is_enabled( 'fake' ) );
		$this->settings->set_enabled( 'fake', false );
		$this->assertFalse( $this->settings->is_enabled( 'fake' ) );
		$this->settings->set_enabled( 'fake', false );
		remove_action( 'seoc_module_toggled', $cb, 10 );
		$this->assertSame( array( array( 'fake', false ) ), $seen );
		$this->assertFalse( $this->settings->is_enabled( 'unknown' ) );
	}

	public function test_unknown_stored_keys_are_dropped(): void {
		update_option(
			Settings::OPTION,
			array(
				'general' => array( 'evil' => 1 ),
				'modules' => array( 'fake' => array( 'junk' => 1 ) ),
			)
		);
		$this->settings->set( 'fake', 'flag', true );
		$option = get_option( Settings::OPTION );
		$this->assertArrayNotHasKey( 'evil', $option['general'] );
		$this->assertArrayNotHasKey( 'junk', $option['modules']['fake'] );
		$this->assertSame( array( 'flag' => true ), $option['modules']['fake'] );
	}

	public function test_install_defaults(): void {
		$this->settings->install_defaults();
		$option = get_option( Settings::OPTION );
		$this->assertSame( 'claude-opus-5-5', $option['general']['ai_model'] );
		$this->assertTrue( $option['modules']['fake']['enabled'] );
	}
}
