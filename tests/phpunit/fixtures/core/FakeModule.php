<?php
/**
 * Fake module used by core tests.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Fixtures;

use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;

class FakeModule extends AbstractModule {

	/** @var array<int, string> Calls recorded across instances. */
	public static $calls = array();

	/** @var string */
	public $module_id = 'fake';

	/** @var bool */
	public $enabled_by_default = true;

	public function id(): string {
		return $this->module_id;
	}

	public function title(): string {
		return 'Fake';
	}

	public function description(): string {
		return 'A fake module for tests.';
	}

	public function default_enabled(): bool {
		return $this->enabled_by_default;
	}

	public function settings_schema(): array {
		return array(
			'flag'  => array(
				'type'    => 'bool',
				'default' => false,
			),
			'count' => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 10,
			),
			'mode'  => array(
				'type'    => 'enum',
				'default' => 'a',
				'enum'    => array( 'a', 'b' ),
			),
			'urls'  => array(
				'type'    => 'url_list',
				'default' => array(),
			),
			'words' => array(
				'type'    => 'string_list',
				'default' => array(),
			),
		);
	}

	public function tables(): array {
		return array(
			"CREATE TABLE {prefix}seoc_fake_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id)
) {charset};",
		);
	}

	public function register( Plugin $plugin ): void {
		self::$calls[] = $this->module_id . ':register';
	}

	public function register_frontend( Plugin $plugin ): void {
		self::$calls[] = $this->module_id . ':register_frontend';
	}

	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-fake',
				'title'    => 'Fake',
				'entry'    => 'fake',
				'position' => 20,
			),
		);
	}

	public function on_disable(): void {
		self::$calls[] = $this->module_id . ':on_disable';
	}
}
