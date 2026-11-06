<?php
/**
 * Smoke test: the plugin loads inside WordPress.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration;

use SEOConsole\Plugin;
use WP_UnitTestCase;

class BootTest extends WP_UnitTestCase {

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'SEOC_VERSION' ) );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}
}
