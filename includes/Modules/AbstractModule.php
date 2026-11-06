<?php
/**
 * Base class with empty defaults for modules.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules;

use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule implements ModuleInterface {

	/** {@inheritDoc} */
	public function default_enabled(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function tables(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
	}

	/** {@inheritDoc} */
	public function register_frontend( Plugin $plugin ): void {
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function on_disable(): void {
	}
}
