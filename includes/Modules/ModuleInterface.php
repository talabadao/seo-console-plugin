<?php
/**
 * Contract for a feature module (see docs/ARCHITECTURE.md §5).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules;

use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/** Stable id: 'speed', 'audit', 'meta', 'schema', 'chat'. */
	public function id(): string;

	/** Translated title. */
	public function title(): string;

	/** Translated one-sentence description. */
	public function description(): string;

	/** Whether a fresh install enables this module. */
	public function default_enabled(): bool;

	/**
	 * Settings schema: [ key => [ 'type' => 'bool|int|string|enum|url|string_list|url_list', 'default' => mixed, ... ] ].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema(): array;

	/**
	 * CREATE TABLE statements for dbDelta. Use {prefix} for the table prefix and {charset} for the collation clause.
	 *
	 * @return string[]
	 */
	public function tables(): array;

	/** Hooks for admin, REST and background jobs. Called only when the module is enabled. */
	public function register( Plugin $plugin ): void;

	/** Public-page hooks. Called only when enabled, outside admin/REST/cron, and Safe Mode is off. */
	public function register_frontend( Plugin $plugin ): void;

	/**
	 * Admin pages: list of [ 'slug' => 'seo-console-speed', 'title' => 'Speed', 'entry' => 'speed', 'position' => 20 ].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function admin_pages(): array;

	/** Called when the module is switched off (unschedule jobs). */
	public function on_disable(): void;
}
