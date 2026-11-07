<?php
/**
 * Plugin boot/modules, admin pages/assets, uninstall.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Admin\Assets;
use SEOConsole\Admin\Menu;
use SEOConsole\Core\Uninstaller;
use SEOConsole\Plugin;
use SEOConsole\Tests\Fixtures\FakeModule;

require_once __DIR__ . '/CoreTestCase.php';

class PluginAdminTest extends CoreTestCase {

	/** @var callable[] */
	private $filters = array();

	public function set_up(): void {
		parent::set_up();
		FakeModule::$calls = array();
		$builtin = static function () {
			// Only the fake modules: real built-in modules (speed, audit…) would change page lists and tables.
			return array( FakeModule::class, 'SEOConsole\\Modules\\DoesNot\\ExistModule' );
		};
		$extra   = static function ( $modules ) {
			$second                     = new FakeModule();
			$second->module_id          = 'fake2';
			$second->enabled_by_default = false;
			$modules['fake2']           = $second;
			$modules['bad']             = new \stdClass();
			return $modules;
		};
		add_filter( 'seoc_builtin_modules', $builtin );
		add_filter( 'seoc_modules', $extra );
		$this->filters = array( $builtin, $extra );
		Plugin::instance()->load_modules();
	}

	public function tear_down(): void {
		remove_filter( 'seoc_builtin_modules', $this->filters[0] );
		remove_filter( 'seoc_modules', $this->filters[1] );
		Plugin::instance()->load_modules();
		parent::tear_down();
	}

	public function test_modules_loaded_from_builtin_list_and_filter(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( FakeModule::class, $plugin->module( 'fake' ) );
		$this->assertInstanceOf( FakeModule::class, $plugin->module( 'fake2' ) );
		$this->assertNull( $plugin->module( 'bad' ) );
		$this->assertTrue( $plugin->settings()->is_enabled( 'fake' ) );
		$this->assertFalse( $plugin->settings()->is_enabled( 'fake2' ) );
		$this->assertTrue( $plugin->module_enabled( 'fake' ) );
		$this->assertStringContainsString( 'seoc_fake_items', implode( "\n", $plugin->module_tables() ) );
	}

	public function test_register_only_enabled_and_frontend_only_outside_safe_mode(): void {
		$plugin = Plugin::instance();
		$plugin->register_modules();
		$this->assertSame( array( 'fake:register' ), FakeModule::$calls, 'CLI request: no frontend hooks' );

		FakeModule::$calls = array();
		add_filter( 'seoc_is_frontend_request', '__return_true' );
		$plugin->register_modules();
		$this->assertSame( array( 'fake:register', 'fake:register_frontend' ), FakeModule::$calls );

		FakeModule::$calls = array();
		$plugin->settings()->set( 'general', 'safe_mode', true );
		$plugin->register_modules();
		remove_filter( 'seoc_is_frontend_request', '__return_true' );
		$this->assertSame( array( 'fake:register' ), FakeModule::$calls );
	}

	public function test_disabling_module_calls_on_disable(): void {
		Plugin::instance()->settings()->set_enabled( 'fake', false );
		$this->assertContains( 'fake:on_disable', FakeModule::$calls );
		Plugin::instance()->settings()->set_enabled( 'fake2', true );
		$this->assertSame( '1', (string) get_option( Plugin::JOBS_PENDING_OPTION ) );
	}

	public function test_accessors(): void {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( \SEOConsole\Core\Settings::class, $plugin->settings() );
		$this->assertInstanceOf( \SEOConsole\Core\Secrets::class, $plugin->secrets() );
		$this->assertInstanceOf( \SEOConsole\Core\Logger::class, $plugin->logger() );
		$this->assertInstanceOf( \SEOConsole\Changes\ChangeManager::class, $plugin->changes() );
		$this->assertInstanceOf( \SEOConsole\Suggestions\SuggestionService::class, $plugin->suggestions() );
		$this->assertInstanceOf( \SEOConsole\Jobs\Queue::class, $plugin->queue() );
		$this->assertInstanceOf( \SEOConsole\Integrations\SeoPlugins::class, $plugin->seo() );
		$this->assertInstanceOf( \SEOConsole\Integrations\CachePlugins::class, $plugin->cache() );
		$this->assertInstanceOf( \SEOConsole\AI\ClientInterface::class, $plugin->claude() );
		$this->assertInstanceOf( \SEOConsole\Core\HealthCheck::class, $plugin->health() );
		$this->assertFalse( $plugin->safe_mode() );
		foreach ( array( 'seo_meta', 'image_alt', 'module_setting', 'post_meta_json' ) as $type ) {
			$this->assertNotNull( $plugin->changes()->applier( $type ) );
		}
	}

	public function test_activation_installs_defaults(): void {
		delete_option( 'seoc_settings' );
		delete_option( Plugin::JOBS_PENDING_OPTION );
		Plugin::activate();
		$option = get_option( 'seoc_settings' );
		$this->assertSame( 'claude-opus-5-5', $option['general']['ai_model'] );
		$this->assertTrue( $option['modules']['fake']['enabled'] );
		$this->assertFalse( $option['modules']['fake2']['enabled'] );
		$this->assertNotEmpty( get_option( Plugin::JOBS_PENDING_OPTION ) );
	}

	public function test_admin_pages_sorted_and_render(): void {
		$this->login( 'administrator' );
		Plugin::instance()->settings()->set_enabled( 'fake2', true );
		$menu  = new Menu( Plugin::instance() );
		$core  = array( 'seo-console', 'seo-console-suggestions', 'seo-console-fake', 'seo-console-changes', 'seo-console-settings' );
		// Real modules may add pages too; check the order of the core + fake ones.
		$slugs = array_values( array_intersect( wp_list_pluck( $menu->pages(), 'slug' ), $core ) );
		$this->assertSame( $core, $slugs );

		$_GET['page'] = 'seo-console-settings';
		set_current_screen( 'dashboard' );
		ob_start();
		$menu->render();
		$html = ob_get_clean();
		unset( $_GET['page'] );
		$this->assertStringContainsString( '<div id="seoc-root"', $html );
	}

	public function test_assets_only_on_our_pages_with_config(): void {
		$this->login( 'administrator' );
		$dir = SEOC_DIR . 'build';
		$created_dir = ! is_dir( $dir );
		if ( $created_dir ) {
			mkdir( $dir );
		}
		file_put_contents( $dir . '/seoctestentry.js', '// test' );
		file_put_contents( $dir . '/seoctestentry.asset.php', "<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc' );" );

		$assets = new Assets( Plugin::instance() );
		$this->assertFalse( $assets->is_built( 'nope' ) );
		$assets->enqueue(
			array(
				'slug'  => 'seo-console',
				'entry' => 'seoctestentry',
			),
			Menu::core_pages()
		);
		unlink( $dir . '/seoctestentry.js' );
		unlink( $dir . '/seoctestentry.asset.php' );
		if ( $created_dir ) {
			rmdir( $dir );
		}

		$this->assertTrue( wp_script_is( 'seoc-seoctestentry', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );
		$inline = wp_scripts()->get_data( 'seoc-seoctestentry', 'before' );
		$this->assertStringContainsString( 'window.seocConfig', implode( '', (array) $inline ) );

		$config = $assets->config( array( 'entry' => 'dashboard' ), Menu::core_pages() );
		foreach ( array( 'restNamespace', 'adminUrl', 'pluginUrl', 'version', 'page', 'modules', 'moduleInfo', 'seoPlugin', 'cachePlugins', 'safeMode', 'aiConfigured', 'googleConfigured', 'dashboardCards', 'checklist', 'pages' ) as $key ) {
			$this->assertArrayHasKey( $key, $config );
		}
		$this->assertSame( 'seo-console/v1', $config['restNamespace'] );
		$this->assertFalse( $config['checklist']['speedTested'] );
		$this->assertSame( 'seo-console-fake', $config['moduleInfo']->fake['page'] );

		$menu = new Menu( Plugin::instance() );
		$menu->enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'seoc-dashboard', 'enqueued' ) );
	}

	public function test_uninstall_removes_data_unless_kept(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_seoc_schema', '{}' );
		update_option( 'seoc_custom_option', 1 );
		Plugin::instance()->settings()->set( 'general', 'keep_data_on_uninstall', true );
		$this->assertFalse( Uninstaller::run_site() );
		$this->assertSame( '1', (string) get_option( 'seoc_custom_option' ) );

		Plugin::instance()->settings()->set( 'general', 'keep_data_on_uninstall', false );
		$this->assertTrue( Uninstaller::run_site() );
		$this->assertFalse( get_option( 'seoc_custom_option' ) );
		$this->assertFalse( get_option( 'seoc_settings' ) );
		$this->assertFalse( metadata_exists( 'post', $post, '_seoc_schema' ) );
	}
}
