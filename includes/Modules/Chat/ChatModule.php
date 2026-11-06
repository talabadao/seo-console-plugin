<?php
/**
 * AI Chat module: an admin chat with Claude that knows the site's context. Text only: it explains, prioritises
 * and proposes; changes still go through the Suggestions queue.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Chat;

use SEOConsole\Integrations\SeoPlugins;
use SEOConsole\Modules\AbstractModule;
use SEOConsole\Plugin;

defined( 'ABSPATH' ) || exit;

final class ChatModule extends AbstractModule {

	/**
	 * Plugin.
	 *
	 * @var Plugin|null
	 */
	private $plugin;

	/** {@inheritDoc} */
	public function id(): string {
		return 'chat';
	}

	/** {@inheritDoc} */
	public function title(): string {
		return __( 'AI Chat', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function description(): string {
		return __( 'Ask Claude about your site\'s SEO and speed; it already knows your setup and latest reports.', 'seo-console' );
	}

	/** {@inheritDoc} */
	public function settings_schema(): array {
		return array(
			'max_tokens' => array(
				'type'        => 'int',
				'default'     => 8000,
				'min'         => 1000,
				'max'         => 32000,
				'label'       => __( 'Maximum answer length (tokens)', 'seo-console' ),
				'description' => __( 'Upper limit for one answer, including Claude\'s thinking.', 'seo-console' ),
			),
			'effort'     => array(
				'type'        => 'enum',
				'default'     => 'low',
				'enum'        => array( 'low', 'medium' ),
				'label'       => __( 'Answer depth', 'seo-console' ),
				'description' => __( 'Low answers faster and costs less. Medium thinks longer; on hosting with a 30-second limit it can time out.', 'seo-console' ),
			),
		);
	}

	/** {@inheritDoc} */
	public function tables(): array {
		return array( Conversation::table_sql() );
	}

	/** {@inheritDoc} */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		add_action(
			'rest_api_init',
			function () use ( $plugin ) {
				( new ChatController( $plugin, $this ) )->register_routes();
			}
		);
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );
	}

	/** {@inheritDoc} */
	public function admin_pages(): array {
		return array(
			array(
				'slug'     => 'seo-console-chat',
				'title'    => __( 'AI Chat', 'seo-console' ),
				'entry'    => 'chat',
				'position' => 60,
			),
		);
	}

	/**
	 * Deletes a removed user's conversations.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_user_deleted( $user_id ): void {
		Conversation::delete_user_data( absint( $user_id ) );
	}

	/**
	 * Plugin (the booted instance when register() has not run).
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin ? $this->plugin : Plugin::instance();
	}

	/**
	 * Site context with the SEO and cache plugins as core detects them.
	 *
	 * @return ContextBuilder
	 */
	public function context_builder(): ContextBuilder {
		$plugin = $this->plugin();
		$cache  = array();
		foreach ( $plugin->cache()->detect() as $item ) {
			$cache[] = trim( $item['name'] . ( empty( $item['version'] ) ? '' : ' ' . $item['version'] ) );
		}
		$seo = $plugin->seo()->active();
		return new ContextBuilder(
			array(
				'seo_plugin'    => 'none' === $seo ? 'None' : trim( SeoPlugins::name( $seo ) . ' ' . SeoPlugins::version( $seo ) ),
				'cache_plugins' => $cache,
			)
		);
	}

	/**
	 * Conversation service wired to core.
	 *
	 * @return Conversation
	 */
	public function conversation(): Conversation {
		$plugin = $this->plugin();
		return new Conversation(
			$plugin->claude(),
			$this->context_builder(),
			array(
				'model'      => (string) $plugin->settings()->get( 'general', 'ai_model' ),
				'max_tokens' => (int) $plugin->settings()->get( 'chat', 'max_tokens' ),
				'effort'     => (string) $plugin->settings()->get( 'chat', 'effort' ),
			)
		);
	}

	/**
	 * Task writer wired to core.
	 *
	 * @return TaskWriter
	 */
	public function task_writer(): TaskWriter {
		return new TaskWriter( $this->plugin()->claude(), $this->context_builder() );
	}
}
