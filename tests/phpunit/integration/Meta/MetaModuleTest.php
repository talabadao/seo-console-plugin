<?php
/**
 * Meta module: boot, REST routes, background batches, dashboard card, site context.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Meta;

use SEOConsole\Modules\Chat\ChatModule;
use SEOConsole\Modules\Meta\JobStore;
use SEOConsole\Modules\Meta\MetaModule;
use SEOConsole\Plugin;
use SEOConsole\Tests\Fixtures\AI\ModuleTestCase;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/fixtures/ai/ModuleTestCase.php';

class MetaModuleTest extends ModuleTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->force_seo_plugin( 'yoast' );
		delete_option( 'wpseo_titles' );
		update_option( 'blogname', 'Aqua Shop' );
	}

	private function module(): MetaModule {
		return Plugin::instance()->module( 'meta' );
	}

	private static function answer( array $overrides = array() ): array {
		return $overrides + array(
			'title'              => 'Water Filter Guide: How to Choose the Best One for Home',
			'description'        => 'Compare RO, UV and carbon water filters, learn what each removes and which suits your home and budget. Read the full guide before you buy.',
			'focus_keyword_used' => true,
			'rationale'          => 'Front-loads the keyword.',
		);
	}

	private function post( array $args = array() ): int {
		return self::factory()->post->create(
			$args + array(
				'post_title'   => 'Water filter guide',
				'post_content' => '<p>How to pick a water filter for your home.</p>',
			)
		);
	}

	public function test_modules_boot_with_settings_tables_applier_and_pages(): void {
		global $wpdb;
		$plugin = Plugin::instance();
		$this->assertInstanceOf( MetaModule::class, $plugin->module( 'meta' ) );
		$this->assertInstanceOf( ChatModule::class, $plugin->module( 'chat' ) );
		$this->assertTrue( $plugin->settings()->is_enabled( 'meta' ) );
		$this->assertSame( 'auto', $plugin->settings()->get( 'meta', 'brand_appended' ) );
		$this->assertSame( 'missing', $plugin->settings()->get( 'meta', 'image_scope' ) );
		$this->assertFalse( $plugin->settings()->get( 'meta', 'auto_on_publish' ) );
		$this->assertSame( 8000, $plugin->settings()->get( 'chat', 'max_tokens' ) );
		$this->assertSame( 'low', $plugin->settings()->get( 'chat', 'effort' ) );
		$this->assertNotNull( $plugin->changes()->applier( 'post_image_alt' ) );
		foreach ( array( 'seoc_meta_jobs', 'seoc_chat_messages' ) as $table ) {
			$this->assertSame( $wpdb->prefix . $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) );
		}
		$this->assertSame( 'seo-console-meta', $plugin->module( 'meta' )->admin_pages()[0]['slug'] );
		$this->assertSame( 30, $plugin->module( 'meta' )->admin_pages()[0]['position'] );
		$this->assertSame( 60, $plugin->module( 'chat' )->admin_pages()[0]['position'] );
		$this->assertTrue( has_action( 'seoc_request_ai_suggestions' ) );
	}

	public function routes(): array {
		return array(
			array( 'POST', '/meta/suggest' ),
			array( 'POST', '/meta/alternatives' ),
			array( 'GET', '/meta/status' ),
			array( 'GET', '/meta/posts' ),
			array( 'GET', '/chat/conversations' ),
			array( 'GET', '/chat/conversations/abcdefgh-1234' ),
			array( 'DELETE', '/chat/conversations/abcdefgh-1234' ),
			array( 'POST', '/chat/messages' ),
			array( 'POST', '/chat/task' ),
		);
	}

	public function test_subscriber_and_logged_out_are_denied(): void {
		$body = array(
			'post_ids' => array( 1 ),
			'post_id'  => 1,
			'text'     => 'Hi',
			'goal'     => 'Fix it',
		);
		$this->login( 'subscriber' );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 403, $this->call( $route[0], $route[1], 'GET' === $route[0] ? array() : $body )->get_status(), implode( ' ', $route ) );
		}
		wp_set_current_user( 0 );
		foreach ( $this->routes() as $route ) {
			$this->assertSame( 401, $this->call( $route[0], $route[1], 'GET' === $route[0] ? array() : $body )->get_status(), implode( ' ', $route ) );
		}
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_suggest_one_post_inline(): void {
		$this->login( 'administrator' );
		$post_id = $this->post();
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'water filter' );
		$this->client->answers = array( self::answer() );

		$response = $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( $post_id ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'inline', $data['mode'] );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 2, $data['created'] );
		$this->assertSame( array( 'title', 'description' ), wp_list_pluck( $data['suggestions'], 'field' ) );
		$this->assertSame( 'meta', $data['suggestions'][0]['module'] );
		$this->assertSame( 'claude-opus-5-5', $data['suggestions'][0]['model'] );
		// Yoast's default title template ends with the site name, so the stored title keeps that ending.
		$this->assertSame(
			array(
				'warnings'              => array(),
				'brand_suffix'          => ' %%sep%% %%sitename%%',
				'display_suffix'        => ' %%sep%% %%sitename%%',
				'brand_suffix_resolved' => ' - Aqua Shop',
			),
			$data['suggestions'][0]['meta']
		);
		$this->assertSame( array( 'warnings' => array() ), $data['suggestions'][1]['meta'] );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( 'low', $this->client->calls[0]['opts']['effort'] );
		$this->assertStringContainsString( 'Focus keyword: water filter', $this->client->last_user_text() );
	}

	public function test_brand_suffix_from_title_template(): void {
		$this->login( 'administrator' );
		update_option( 'wpseo_titles', array( 'title-post' => '%%title%% %%sep%% %%sitename%%' ) );
		$post_id = $this->post();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Old title %%sep%% %%sitename%%' );
		$this->client->answers = array( self::answer( array( 'title' => 'Water Filter Guide: Choose the Best One' ) ) );

		$data = $this->call(
			'POST',
			'/meta/suggest',
			array(
				'post_ids' => array( $post_id ),
				'fields'   => array( 'title' ),
			)
		)->get_data();

		$this->assertSame( 'Water Filter Guide: Choose the Best One %%sep%% %%sitename%%', $data['suggestions'][0]['proposed_value'] );
		$this->assertSame( ' %%sep%% %%sitename%%', $data['suggestions'][0]['meta']['brand_suffix'] );
		$sent = $this->client->last_user_text();
		$this->assertStringContainsString( 'do NOT include the brand', $sent );
		$this->assertStringContainsString( 'Current SEO title: Old title', $sent );
		$this->assertStringNotContainsString( 'Current SEO title: Old title %%sep%%', $sent );

		Plugin::instance()->settings()->set( 'meta', 'brand_appended', 'no' );
		$this->assertSame( '', $this->module()->brand_suffix( $post_id ) );
	}

	public function test_edited_title_gets_the_hidden_suffix_back_on_approve(): void {
		$this->login( 'administrator' );
		$post_id               = $this->post();
		$this->client->answers = array( self::answer( array( 'title' => 'Water Filter Guide: Choose the Best One' ) ) );
		$data                  = $this->call(
			'POST',
			'/meta/suggest',
			array(
				'post_ids' => array( $post_id ),
				'fields'   => array( 'title' ),
			)
		)->get_data();
		$id                    = $data['suggestions'][0]['id'];

		// The UI sends the edited text without the suffix it hides.
		$response = $this->call( 'POST', '/suggestions/' . $id . '/approve', array( 'value' => 'Edited water filter guide' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Edited water filter guide %%sep%% %%sitename%%', get_post_meta( $post_id, '_yoast_wpseo_title', true ) );

		// A value that already ends with the suffix is not doubled; other modules are untouched.
		$row = array(
			'module' => 'meta',
			'meta'   => array( 'display_suffix' => ' %sep% %sitename%' ),
		);
		$this->assertSame( 'A %sep% %sitename%', $this->module()->restore_display_suffix( 'A %sep% %sitename%', $row ) );
		$this->assertSame( 'A %sep% %sitename%', $this->module()->restore_display_suffix( 'A ', $row ) );
		$this->assertSame( 'A', $this->module()->restore_display_suffix( 'A', array( 'module' => 'schema' ) + $row ) );
	}

	public function test_resolved_suffix_uses_plugin_separator(): void {
		$post = get_post( $this->post() );
		update_option( 'rank-math-options-titles', array( 'title_separator' => '|' ) );
		// With Rank Math's own Helper loaded (stub run) its replace_vars decides the separator.
		$expected = class_exists( '\\RankMath\\Helper' ) ? \RankMath\Helper::replace_vars( ' %sep% %sitename%', $post ) : ' | Aqua Shop';
		$this->assertSame( $expected, \SEOConsole\Modules\Meta\TitleTemplate::resolve_suffix( 'rankmath', ' %sep% %sitename%', $post ) );
		update_option( 'wpseo_titles', array( 'separator' => 'sc-ndash' ) );
		$expected = function_exists( 'wpseo_replace_vars' ) ? wpseo_replace_vars( ' %%sep%% %%sitename%%', $post ) : ' – Aqua Shop';
		$this->assertSame( $expected, \SEOConsole\Modules\Meta\TitleTemplate::resolve_suffix( 'yoast', ' %%sep%% %%sitename%%', $post ) );
		$this->assertSame( ' Aqua Shop', \SEOConsole\Modules\Meta\TitleTemplate::resolve_suffix( 'yoast', ' %%page%% %%sitename%%', $post ) );
		$this->assertSame( '', \SEOConsole\Modules\Meta\TitleTemplate::resolve_suffix( 'yoast', '', $post ) );
	}

	public function test_without_seo_plugin_titles_are_skipped_before_any_ai_call(): void {
		$this->login( 'administrator' );
		$this->force_seo_plugin( 'none' );
		$post_id = $this->post();

		$response = $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( $post_id ) ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'seoc_no_seo_plugin', $response->get_data()['code'] );
		$this->assertSame( 409, $this->call( 'POST', '/meta/alternatives', array( 'post_id' => $post_id ) )->get_status() );
		$this->assertSame( array(), $this->client->calls );

		// Image alt texts still work, with a note about the skipped fields.
		$att = self::factory()->attachment->create_object( 'IMG_9.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<img class="wp-image-' . $att . '" src="http://example.org/wp-content/uploads/IMG_9.jpg">',
			)
		);
		$this->client->answers = array(
			array(
				'images' => array(
					array(
						'attachment_id' => $att,
						'src'           => '',
						'alt'           => 'Water filter cartridges laid out on a kitchen counter',
						'rationale'     => 'Describes it.',
					),
				),
			),
		);
		$data = $this->call(
			'POST',
			'/meta/suggest',
			array(
				'post_ids' => array( $post_id ),
				'fields'   => array( 'title', 'description', 'image_alt' ),
			)
		)->get_data();
		$this->assertSame( array( 'image_alt' ), $data['fields'] );
		$this->assertSame( 1, $data['created'] );
		$this->assertStringContainsString( 'Rank Math or Yoast SEO', $data['message'] );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( \SEOConsole\Modules\Meta\Schemas::images(), $this->client->calls[0]['schema'] );

		// The posts report and dashboard card say why, instead of "not set".
		$posts = $this->call( 'GET', '/meta/posts', array(), array( 'per_page' => 50 ) )->get_data();
		$this->assertFalse( $posts['seo_fields'] );
		$row = wp_list_filter( $posts['items'], array( 'id' => $post_id ) );
		$row = reset( $row );
		$this->assertSame( 'unavailable', $row['seo_title']['status'] );
		$this->assertSame( 'unavailable', $row['description']['status'] );
		$this->assertNotContains( 'missing_title', $row['issues'] );
		$card = wp_list_filter( apply_filters( 'seoc_dashboard_cards', array() ), array( 'id' => 'meta-coverage' ) );
		$this->assertSame( 'unknown', reset( $card )['status'] );
	}

	public function test_batch_without_seo_plugin_makes_no_title_call(): void {
		$this->force_seo_plugin( 'none' );
		$this->client->answers = array( self::answer() );
		$this->module()->enqueue( array( array( 'object_id' => $this->post() ) ), 'audit' );
		$summary = $this->module()->run_batch();
		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( array(), $this->client->calls );
		$this->assertSame( 1, $this->module()->store()->summary()['done'] );
	}

	public function test_suggest_without_key_or_unknown_post(): void {
		$this->login( 'administrator' );
		$this->client->available = false;
		$response                = $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( $this->post() ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'seoc_ai_no_key', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Add your Claude API key', $response->get_data()['message'] );

		$this->assertSame( 404, $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( 999999 ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array() ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( 1 ), 'fields' => array( 'body' ) ) )->get_status() );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_several_posts_go_to_the_background_job(): void {
		$this->login( 'administrator' );
		$a                     = $this->post();
		$b                     = $this->post( array( 'post_title' => 'Second' ) );
		$this->client->answers = array( self::answer() );

		$response = $this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( $a, $b ) ) );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['mode'] );
		$this->assertSame( 2, $response->get_data()['queued'] );
		$this->assertSame( 2, $response->get_data()['status']['queued'] );
		$this->assertSame( array(), $this->client->calls, 'No AI call inside the request.' );

		$summary = $this->module()->run_batch();
		$this->assertSame( 2, $summary['processed'] );
		$this->assertSame( 0, $summary['remaining'] );
		$this->assertCount( 2, $this->client->calls );

		$status = $this->call( 'GET', '/meta/status' )->get_data();
		$this->assertSame( 0, $status['queued'] );
		$this->assertSame( 2, $status['done'] );
		$this->assertSame( 4, $status['suggestions_created'] );
		$this->assertNull( $status['stopped'] );
		$this->assertSame( 4, Plugin::instance()->suggestions()->query( array( 'module' => 'meta' ) )['total'] );
	}

	public function test_batch_stops_on_budget_and_keeps_posts_queued(): void {
		$this->client->answers = array( new WP_Error( 'seoc_ai_budget', 'Budget' ) );
		$this->module()->enqueue(
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $this->post(),
				),
				array(
					'object_type' => 'post',
					'object_id'   => $this->post(),
				),
			),
			'test'
		);
		$summary = $this->module()->run_batch();
		$this->assertSame( 'seoc_ai_budget', $summary['stopped']['code'] );
		$store = $this->module()->store()->summary();
		$this->assertSame( 2, $store['queued'] );
		$this->assertSame( 'seoc_ai_budget', $store['stopped']['code'] );

		// Queuing new work clears the stop.
		$this->module()->enqueue( array( array( 'object_id' => $this->post() ) ), 'test' );
		$this->assertNull( $this->module()->store()->summary()['stopped'] );
	}

	public function test_batch_lock_prevents_parallel_runs(): void {
		$this->module()->enqueue( array( array( 'object_id' => $this->post() ) ), 'test' );
		add_option( MetaModule::LOCK_OPTION, time() );
		$this->assertSame( 0, $this->module()->run_batch()['processed'] );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_request_hook_queues_items_with_merged_fields(): void {
		$post_id = $this->post();
		do_action(
			'seoc_request_ai_suggestions',
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $post_id,
					'fields'      => array( 'description' ),
				),
				array(
					'object_type' => 'term',
					'object_id'   => 3,
				),
			),
			'audit'
		);
		do_action(
			'seoc_request_ai_suggestions',
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $post_id,
					'fields'      => array( 'title' ),
				),
			),
			'audit'
		);
		$items = ( new JobStore() )->next( 10 );
		$this->assertCount( 1, $items );
		$this->assertSame( $post_id, $items[0]['object_id'] );
		$this->assertSame( array( 'title', 'description' ), $items[0]['fields'] );
	}

	public function test_auto_on_publish(): void {
		Plugin::instance()->settings()->set( 'meta', 'auto_on_publish', true );
		$this->client->available = true;
		$post_id                 = $this->post( array( 'post_status' => 'draft' ) );
		$this->module()->on_publish( 'publish', 'draft', get_post( $post_id ) );
		$this->module()->on_publish( 'publish', 'publish', get_post( $post_id ) );
		$items = ( new JobStore() )->next( 10 );
		$this->assertCount( 1, $items );
		$this->assertSame( array( 'title', 'description', 'image_alt' ), $items[0]['fields'] );
	}

	public function test_posts_report_with_issue_filters(): void {
		$this->login( 'administrator' );
		$good = $this->post( array( 'post_title' => 'Good post' ) );
		update_post_meta( $good, '_yoast_wpseo_title', 'A perfectly fine SEO title for this good post' );
		update_post_meta( $good, '_yoast_wpseo_metadesc', str_repeat( 'Good description. ', 8 ) );
		$long = $this->post(
			array(
				'post_title'   => 'Long post',
				'post_content' => '<img src="http://example.org/wp-content/uploads/IMG_1.jpg" alt=""><img src="http://example.org/a.jpg" alt="A real description of the photo">',
			)
		);
		update_post_meta( $long, '_yoast_wpseo_title', str_repeat( 'Too long title ', 6 ) );

		$all = $this->call( 'GET', '/meta/posts', array(), array( 'per_page' => 50 ) );
		$this->assertSame( 200, $all->get_status() );
		$data = $all->get_data();
		$this->assertSame( 'yoast', $data['seo_plugin'] );
		$this->assertContains( 'post', wp_list_pluck( $data['post_types'], 'value' ) );
		$rows = array();
		foreach ( $data['items'] as $item ) {
			$rows[ $item['id'] ] = $item;
		}
		$this->assertSame( 'good', $rows[ $good ]['seo_title']['status'] );
		$this->assertSame( 'good', $rows[ $good ]['description']['status'] );
		$this->assertSame( array(), $rows[ $good ]['issues'] );
		$this->assertSame( 'long', $rows[ $long ]['seo_title']['status'] );
		$this->assertSame( 'missing', $rows[ $long ]['description']['status'] );
		$this->assertSame(
			array(
				'total'       => 2,
				'missing_alt' => 1,
			),
			$rows[ $long ]['images']
		);
		$this->assertSame( array( 'missing_description', 'too_long', 'missing_alt' ), $rows[ $long ]['issues'] );

		foreach ( array( 'missing_description', 'too_long', 'missing_alt' ) as $issue ) {
			$ids = wp_list_pluck( $this->call( 'GET', '/meta/posts', array(), array( 'issue' => $issue ) )->get_data()['items'], 'id' );
			$this->assertContains( $long, $ids, $issue );
			$this->assertNotContains( $good, $ids, $issue );
		}
		$this->assertSame( 400, $this->call( 'GET', '/meta/posts', array(), array( 'issue' => 'bogus' ) )->get_status() );
	}

	public function test_pending_suggestion_count_in_posts_report(): void {
		$this->login( 'administrator' );
		$post_id               = $this->post();
		$this->client->answers = array( self::answer() );
		$this->call( 'POST', '/meta/suggest', array( 'post_ids' => array( $post_id ) ) );
		$items = $this->call( 'GET', '/meta/posts', array(), array( 'per_page' => 50 ) )->get_data()['items'];
		$row   = wp_list_filter( $items, array( 'id' => $post_id ) );
		$this->assertSame( 2, reset( $row )['pending_suggestions'] );
	}

	public function test_alternatives_route(): void {
		$this->login( 'administrator' );
		$this->client->answers = array(
			array(
				'options' => array(
					self::answer(),
					self::answer( array( 'title' => 'Water Filters Compared: RO vs UV vs Carbon for Homes' ) ),
					self::answer( array( 'title' => 'Third Water Filter Option for Your Home Kitchen' ) ),
				),
			),
		);
		$data = $this->call( 'POST', '/meta/alternatives', array( 'post_id' => $this->post() ) )->get_data();
		$this->assertCount( 3, $data['options'] );
		$this->assertSame( ' %%sep%% %%sitename%%', $data['brand_suffix'] );
		$this->assertSame( $data['options'][0]['title'] . ' %%sep%% %%sitename%%', $data['options'][0]['title_value'] );
	}

	public function test_dashboard_card_and_site_context(): void {
		$this->post();
		$cards = apply_filters( 'seoc_dashboard_cards', array() );
		$card  = wp_list_filter( $cards, array( 'id' => 'meta-coverage' ) );
		$this->assertCount( 1, $card );
		$card = reset( $card );
		$this->assertGreaterThanOrEqual( 1, $card['value'] );
		$this->assertContains( $card['status'], array( 'poor', 'needs-work' ) );
		$this->assertStringContainsString( 'page=seo-console-meta', $card['link'] );

		$sections = apply_filters( 'seoc_ai_site_context', array() );
		$this->assertArrayHasKey( 'meta', $sections );
		$this->assertStringContainsString( 'Without a meta description:', $sections['meta'] );
	}

	public function test_on_disable_clears_queue(): void {
		$this->module()->enqueue( array( array( 'object_id' => $this->post() ) ), 'test' );
		$this->module()->on_disable();
		$this->assertSame( 0, $this->module()->store()->queued_count() );
	}
}
