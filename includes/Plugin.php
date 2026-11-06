<?php
/**
 * Plugin service locator and boot sequence (see docs/ARCHITECTURE.md §4).
 *
 * SCAFFOLD: the core agent replaces this minimal version with the full one.
 *
 * @package SEOConsole
 */

namespace SEOConsole;

use SEOConsole\Modules\ModuleInterface;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance;

	/** @var ModuleInterface[] */
	private $modules = array();

	/** Returns the booted instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** plugins_loaded callback. */
	public static function boot(): void {
		$plugin = self::instance();
		/**
		 * Filters the module list.
		 *
		 * @param ModuleInterface[] $modules Modules keyed by id.
		 */
		$plugin->modules = apply_filters( 'seoc_modules', array() );
	}

	/** Activation hook. */
	public static function activate(): void {
	}

	/** Deactivation hook. */
	public static function deactivate(): void {
	}

	/**
	 * @return ModuleInterface[]
	 */
	public function modules(): array {
		return $this->modules;
	}
}
