<?php
/**
 * Plugin service locator and boot sequence (see docs/ARCHITECTURE.md §4).
 *
 * @package SEOConsole
 */

namespace SEOConsole;

use SEOConsole\Admin\Menu;
use SEOConsole\Admin\Notices;
use SEOConsole\AI\ClaudeClient;
use SEOConsole\AI\ClientInterface;
use SEOConsole\AI\Usage;
use SEOConsole\Changes\Appliers\ImageAltApplier;
use SEOConsole\Changes\Appliers\ModuleSettingApplier;
use SEOConsole\Changes\Appliers\PostMetaJsonApplier;
use SEOConsole\Changes\Appliers\SeoMetaApplier;
use SEOConsole\Changes\ChangeManager;
use SEOConsole\Core\Database\Schema;
use SEOConsole\Core\Environment;
use SEOConsole\Core\HealthCheck;
use SEOConsole\Core\Logger;
use SEOConsole\Core\SafeMode;
use SEOConsole\Core\Secrets;
use SEOConsole\Core\Settings;
use SEOConsole\Integrations\CachePlugins;
use SEOConsole\Integrations\HelperCompat;
use SEOConsole\Integrations\SeoPlugins;
use SEOConsole\Jobs\Queue;
use SEOConsole\Modules\ModuleInterface;
use SEOConsole\Rest\ChangesController;
use SEOConsole\Rest\EnvironmentController;
use SEOConsole\Rest\SettingsController;
use SEOConsole\Rest\SuggestionsController;
use SEOConsole\Suggestions\SuggestionService;
use SEOConsole\Suggestions\SuggestionStore;

defined( 'ABSPATH' ) || exit;

/**
 * Builds services lazily, loads modules, registers hooks.
 */
final class Plugin {

	const REST_NAMESPACE = 'seo-console/v1';

	/** Daily core housekeeping job (log retention, usage + closed suggestion cleanup). */
	const HOUSEKEEPING_HOOK = 'seoc_core_housekeeping';

	/** Option set when recurring jobs must be (re)scheduled on the next Action Scheduler init. */
	const JOBS_PENDING_OPTION = 'seoc_jobs_pending';

	/** Built-in module classes (filter `seoc_builtin_modules`); classes that do not exist are skipped. */
	const BUILTIN_MODULES = array(
		'SEOConsole\\Modules\\Speed\\SpeedModule',
		'SEOConsole\\Modules\\Audit\\AuditModule',
		'SEOConsole\\Modules\\Meta\\MetaModule',
		'SEOConsole\\Modules\\Chat\\ChatModule',
		'SEOConsole\\Modules\\Schema\\SchemaModule',
	);

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Whether modules were loaded.
	 *
	 * @var bool
	 */
	private $modules_loaded = false;

	/**
	 * Modules keyed by id.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Built services keyed by id.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Returns the instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * plugins_loaded (priority 5) callback.
	 */
	public static function boot(): void {
		$plugin = self::instance();
		if ( $plugin->booted ) {
			return;
		}
		$plugin->booted = true;

		$plugin->load_modules();
		Schema::maybe_migrate( $plugin->module_tables() );

		$plugin->register_modules();
		$plugin->register_hooks();
	}

	/**
	 * Calls register() on every enabled module and, on public page requests with Safe Mode off,
	 * register_frontend(). Called once by boot().
	 */
	public function register_modules(): void {
		$frontend = $this->is_frontend_request() && ! $this->safe_mode();
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->settings()->is_enabled( $id ) ) {
				continue;
			}
			$this->call_module( $module, 'register' );
			if ( $frontend ) {
				$this->call_module( $module, 'register_frontend' );
			}
		}
	}

	/**
	 * Activation hook: tables, default settings, jobs scheduled on the next Action Scheduler init.
	 */
	public static function activate(): void {
		$plugin = self::instance();
		$plugin->load_modules();
		Schema::install( $plugin->module_tables() );
		$plugin->settings()->install_defaults();
		update_option( self::JOBS_PENDING_OPTION, 1, true );
		$plugin->logger()->info( 'SEO Console activated.' );
	}

	/**
	 * Deactivation hook: removes every pending `seo-console` action.
	 */
	public static function deactivate(): void {
		$plugin = self::instance();
		$plugin->queue()->unschedule_all();
		delete_option( self::JOBS_PENDING_OPTION );
	}

	/**
	 * Loads modules: classes from `seoc_builtin_modules` that exist, then the `seoc_modules` filter.
	 * Safe to call again (tests): modules are reloaded and their settings schemas re-registered.
	 */
	public function load_modules(): void {
		/**
		 * Filters the built-in module class names. Classes that do not exist are skipped.
		 *
		 * @param string[] $classes Fully qualified class names.
		 */
		$classes = (array) apply_filters( 'seoc_builtin_modules', self::BUILTIN_MODULES );
		$modules = array();
		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			try {
				$module = new $class();
			} catch ( \Throwable $e ) {
				$this->logger()->error(
					'Module could not be created.',
					array(
						'class' => $class,
						'error' => $e->getMessage(),
					)
				);
				continue;
			}
			if ( $module instanceof ModuleInterface ) {
				$modules[ $module->id() ] = $module;
			}
		}

		/**
		 * Filters the module list.
		 *
		 * @param ModuleInterface[] $modules Modules keyed by id.
		 */
		$filtered = (array) apply_filters( 'seoc_modules', $modules );

		$this->modules = array();
		foreach ( $filtered as $module ) {
			if ( ! $module instanceof ModuleInterface ) {
				continue;
			}
			$id = $module->id();
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $id ) || Settings::GENERAL === $id ) {
				continue;
			}
			$this->modules[ $id ] = $module;
			$this->settings()->register_module( $id, (array) $module->settings_schema(), $module->default_enabled() );
		}
		$this->modules_loaded = true;
	}

	/**
	 * CREATE TABLE statements of every module.
	 *
	 * @return string[]
	 */
	public function module_tables(): array {
		$tables = array();
		foreach ( $this->modules as $module ) {
			foreach ( (array) $module->tables() as $sql ) {
				if ( is_string( $sql ) ) {
					$tables[] = $sql;
				}
			}
		}
		return $tables;
	}

	/**
	 * Modules keyed by id.
	 *
	 * @return ModuleInterface[]
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * One module.
	 *
	 * @param string $id Module id.
	 */
	public function module( string $id ): ?ModuleInterface {
		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}

	/**
	 * Whether a module is loaded and enabled.
	 *
	 * @param string $id Module id.
	 */
	public function module_enabled( string $id ): bool {
		return isset( $this->modules[ $id ] ) && $this->settings()->is_enabled( $id );
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Secrets.
	 */
	public function secrets(): Secrets {
		return $this->service(
			'secrets',
			static function () {
				return new Secrets();
			}
		);
	}

	/**
	 * Logger.
	 */
	public function logger(): Logger {
		return $this->service(
			'logger',
			function () {
				return new Logger( $this->settings() );
			}
		);
	}

	/**
	 * Change manager (core appliers registered).
	 */
	public function changes(): ChangeManager {
		return $this->service(
			'changes',
			function () {
				$manager = new ChangeManager( $this->cache(), $this->health(), $this->logger() );
				$manager->register_applier( new SeoMetaApplier( $this->seo() ) );
				$manager->register_applier( new ImageAltApplier() );
				$manager->register_applier( new ModuleSettingApplier( $this->settings() ) );
				$manager->register_applier( new PostMetaJsonApplier() );
				return $manager;
			}
		);
	}

	/**
	 * Suggestion service.
	 */
	public function suggestions(): SuggestionService {
		return $this->service(
			'suggestions',
			function () {
				return new SuggestionService( new SuggestionStore(), $this->changes() );
			}
		);
	}

	/**
	 * Background queue.
	 */
	public function queue(): Queue {
		return $this->service(
			'queue',
			static function () {
				return new Queue();
			}
		);
	}

	/**
	 * SEO plugin adapter.
	 */
	public function seo(): SeoPlugins {
		return $this->service(
			'seo',
			function () {
				return new SeoPlugins( $this->settings() );
			}
		);
	}

	/**
	 * Cache plugins.
	 */
	public function cache(): CachePlugins {
		return $this->service(
			'cache',
			static function () {
				return new CachePlugins();
			}
		);
	}

	/**
	 * Claude client (modules depend on the interface; tests can replace it).
	 */
	public function claude(): ClientInterface {
		return $this->service(
			'claude',
			function () {
				return new ClaudeClient( $this->secrets(), $this->settings(), $this->usage(), $this->logger() );
			}
		);
	}

	/**
	 * AI usage and budget.
	 */
	public function usage(): Usage {
		return $this->service(
			'usage',
			function () {
				return new Usage( $this->settings() );
			}
		);
	}

	/**
	 * Health check.
	 */
	public function health(): HealthCheck {
		return $this->service(
			'health',
			static function () {
				return new HealthCheck();
			}
		);
	}

	/**
	 * Whether Safe Mode is on (no frontend output).
	 */
	public function safe_mode(): bool {
		return $this->safe_mode_service()->is_on();
	}

	/**
	 * Safe Mode service (source, toggle).
	 */
	public function safe_mode_service(): SafeMode {
		return $this->service(
			'safe_mode',
			function () {
				return new SafeMode( $this->settings() );
			}
		);
	}

	/**
	 * Environment facts/checks.
	 */
	public function environment(): Environment {
		return $this->service(
			'environment',
			function () {
				return new Environment( $this->seo(), $this->cache(), $this->safe_mode_service() );
			}
		);
	}

	/**
	 * Helper-plugin compatibility.
	 */
	public function helper_compat(): HelperCompat {
		return $this->service(
			'helper_compat',
			function () {
				return new HelperCompat( $this );
			}
		);
	}

	/**
	 * Replaces a service (tests, or a site that wants a custom implementation). Ids: settings, secrets, logger,
	 * changes, suggestions, queue, seo, cache, claude, usage, health, safe_mode, environment, helper_compat.
	 *
	 * @param string $id      Service id.
	 * @param object $service Service.
	 */
	public function replace_service( string $id, $service ): void {
		$this->services[ $id ] = $service;
	}

	/**
	 * Drops a built service so the next accessor call rebuilds it (tests).
	 *
	 * @param string $id Service id.
	 */
	public function reset_service( string $id ): void {
		unset( $this->services[ $id ] );
	}

	/**
	 * Whether this request renders public pages (not admin/REST/AJAX/cron/CLI).
	 */
	public function is_frontend_request(): bool {
		$frontend = true;
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			$frontend = false;
		} else {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
			if ( isset( $_GET['rest_route'] ) || false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' ) ) {
				$frontend = false;
			}
		}
		/**
		 * Filters whether the current request is a public page request.
		 *
		 * @param bool $frontend Whether it is.
		 */
		return (bool) apply_filters( 'seoc_is_frontend_request', $frontend );
	}

	/**
	 * Core hooks.
	 */
	private function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		$this->helper_compat()->register();
		$this->safe_mode_service()->register();

		add_action( self::HOUSEKEEPING_HOOK, array( $this, 'housekeeping' ) );
		add_action( 'action_scheduler_init', array( $this, 'maybe_ensure_jobs' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_jobs' ) );
		add_action( 'seoc_module_toggled', array( $this, 'on_module_toggled' ), 10, 2 );

		if ( is_admin() ) {
			( new Menu( $this ) )->register();
			( new Notices( $this ) )->register();
		}
	}

	/**
	 * Registers the core REST routes.
	 */
	public function register_rest_routes(): void {
		( new SettingsController( $this ) )->register_routes();
		( new SuggestionsController( $this ) )->register_routes();
		( new ChangesController( $this ) )->register_routes();
		( new EnvironmentController( $this ) )->register_routes();
	}

	/**
	 * Ensures recurring jobs right after activation or a module toggle.
	 */
	public function maybe_ensure_jobs(): void {
		if ( get_option( self::JOBS_PENDING_OPTION ) ) {
			delete_option( self::JOBS_PENDING_OPTION );
			$this->ensure_jobs();
		}
	}

	/**
	 * Schedules core recurring jobs and lets modules schedule theirs (`seoc_ensure_jobs`). Idempotent.
	 */
	public function ensure_jobs(): void {
		$this->queue()->schedule_daily( self::HOUSEKEEPING_HOOK, array(), '03:15' );
		/**
		 * Fires when recurring jobs should be (re)scheduled: after activation, after a module was switched on, and
		 * once a day. Enabled modules schedule their recurring jobs here with Queue::schedule_daily() etc.
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'seoc_ensure_jobs', $this );
	}

	/**
	 * Daily housekeeping.
	 */
	public function housekeeping(): void {
		$deleted = $this->logger()->purge( Logger::RETENTION_DAYS );
		$this->usage()->purge();
		$this->suggestions()->store()->purge_closed( 90 );
		$this->logger()->debug( 'Housekeeping done.', array( 'log_rows_deleted' => $deleted ) );
	}

	/**
	 * Module switched on/off.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled New state.
	 */
	public function on_module_toggled( $id, $enabled ): void {
		$module = $this->module( (string) $id );
		if ( null === $module ) {
			return;
		}
		if ( $enabled ) {
			update_option( self::JOBS_PENDING_OPTION, 1, true );
			return;
		}
		$this->call_module( $module, 'on_disable' );
	}

	/**
	 * Calls a module method; a module that throws is logged instead of breaking the site.
	 *
	 * @param ModuleInterface $module Module.
	 * @param string          $method register|register_frontend|on_disable.
	 */
	private function call_module( ModuleInterface $module, string $method ): void {
		try {
			if ( 'on_disable' === $method ) {
				$module->on_disable();
			} else {
				$module->$method( $this );
			}
		} catch ( \Throwable $e ) {
			$this->logger()->error(
				'Module error.',
				array(
					'module' => $module->id(),
					'method' => $method,
					'error'  => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Returns a service, building it on first use.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}
}
