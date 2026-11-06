<?php
/**
 * Schema module wired into core: boot, applier round trip, REST routes, background batch, output.
 *
 * Run also with SEOC_TEST_STUB=rankmath and SEOC_TEST_STUB=yoast.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Schema;

use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Modules\Schema\SchemaApplier;
use SEOConsole\Modules\Schema\SchemaModule;
use SEOConsole\Modules\Schema\SchemaService;
use SEOConsole\Modules\Schema\Store;
use SEOConsole\Plugin;
use SEOConsole\Tests\Fixtures\Schema\FakeClient;
use SEOConsole\Tests\Integration\Core\CoreTestCase;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/Core/CoreTestCase.php';
require_once dirname( __DIR__, 2 ) . '/fixtures/schema/FakeClient.php';

class ModuleTest extends CoreTestCase {

	const FAQ_CONTENT = '<h2>Do you ship abroad?</h2><p>Yes, to 20 countries.</p><h2>Can I return items?</h2><p>Within 30 days.</p>';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'seoc_health_check_pre', array( $this, 'healthy' ) );
		delete_option( SchemaService::BATCH_OPTION );
		Plugin::instance()->queue()->unschedule( SchemaService::BATCH_HOOK );
	}

	public function tear_down(): void {
		remove_filter( 'seoc_health_check_pre', array( $this, 'healthy' ) );
		parent::tear_down();
	}

	/** Health check short-circuit. */
	public function healthy(): array {
		return array( 'ok' => true );
	}

	private function call( string $method, string $route, array $body = array(), array $query = array() ) {
		$request = new WP_REST_Request( $method, '/seo-console/v1' . $route );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function post_id( string $content, array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_content' => wp_slash( $content ) ), $args ) );
	}

	private static function faq_nodes(): array {
		return array(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type'          => 'Question',
						'name'           => 'Do you ship abroad?',
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => 'Yes, to 20 countries.',
						),
					),
				),
			),
		);
	}

	private function module(): SchemaModule {
		$module = Plugin::instance()->module( 'schema' );
		$this->assertInstanceOf( SchemaModule::class, $module );
		return $module;
	}

	public function test_module_boots_with_settings_applier_page_and_routes(): void {
		$plugin = Plugin::instance();
		$this->module();
		$this->assertTrue( $plugin->settings()->is_enabled( 'schema' ) );
		$this->assertSame( 'none', $plugin->settings()->get( 'schema', 'business_type' ) );
		$this->assertTrue( $plugin->settings()->get( 'schema', 'output_enabled' ) );
		$this->assertInstanceOf( SchemaApplier::class, $plugin->changes()->applier( 'schema' ) );
		$this->assertSame( 'Structured data', $plugin->changes()->applier( 'schema' )->label( 'schema' ) );
		$this->assertSame( 40, $this->module()->admin_pages()[0]['position'] );

		$routes = rest_get_server()->get_routes( 'seo-console/v1' );
		foreach ( array( '/schema/posts', '/schema/posts/(?P<id>\d+)', '/schema/posts/(?P<id>\d+)/remove', '/schema/suggest', '/schema/validate', '/schema/site', '/schema/types' ) as $route ) {
			$this->assertArrayHasKey( '/seo-console/v1' . $route, $routes );
			foreach ( $routes[ '/seo-console/v1' . $route ] as $endpoint ) {
				$this->assertSame( 'can_manage', $endpoint['permission_callback'][1] );
			}
		}
	}

	public function test_applier_apply_and_revert_round_trip(): void {
		$this->login( 'administrator' );
		$id      = $this->post_id( self::FAQ_CONTENT );
		$changes = Plugin::instance()->changes();
		$change  = $changes->apply(
			new ChangeRequest(
				'schema',
				'post',
				$id,
				'schema',
				self::faq_nodes(),
				array(
					'source' => 'user',
					'module' => 'schema',
				)
			)
		);
		$this->assertNotWPError( $change );
		$this->assertSame( self::faq_nodes(), Store::get( $id ) );
		$this->assertNull( $change->before_value );
		$this->assertSame( self::faq_nodes(), $change->after_value );

		$this->assertTrue( $changes->revert( $change->id ) );
		$this->assertNull( Store::raw( $id ) );
	}

	public function test_applier_rejects_invalid_values(): void {
		$applier = new SchemaApplier();
		$id      = $this->post_id( '<p>Nothing about shipping.</p>' );
		$error   = $applier->validate( self::faq_nodes(), 'post', $id, 'schema' );
		$this->assertWPError( $error );
		$this->assertSame( 'seoc_invalid_schema', $error->get_error_code() );
		$this->assertStringContainsString( 'does not appear on the page', $error->get_error_data()['errors'][0] );
		$this->assertTrue( $applier->validate( null, 'post', $id, 'schema' ) );
		$this->assertWPError( $applier->validate( self::faq_nodes(), 'term', $id, 'schema' ) );
		$this->assertWPError( $applier->validate( self::faq_nodes(), 'post', 999999, 'schema' ) );
		$this->assertWPError( $applier->validate( 'text', 'post', $id, 'schema' ) );
	}

	public function test_rest_permissions(): void {
		$id     = $this->post_id( 'x' );
		$routes = array(
			array( 'GET', '/schema/posts' ),
			array( 'GET', '/schema/posts/' . $id ),
			array( 'POST', '/schema/posts/' . $id . '/remove' ),
			array( 'POST', '/schema/suggest' ),
			array( 'POST', '/schema/validate' ),
			array( 'GET', '/schema/site' ),
			array( 'GET', '/schema/types' ),
		);
		$body   = array(
			'post_ids' => array( $id ),
			'nodes'    => array(),
		);
		$this->login( 'subscriber' );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->call( $route[0], $route[1], 'POST' === $route[0] ? $body : array() )->get_status(), implode( ' ', $route ) );
		}
		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->call( $route[0], $route[1], 'POST' === $route[0] ? $body : array() )->get_status(), implode( ' ', $route ) );
		}
	}

	public function test_suggest_inline_rules_then_approve(): void {
		$this->login( 'administrator' );
		$id       = $this->post_id( self::FAQ_CONTENT, array( 'post_title' => 'Shipping' ) );
		$response = $this->call( 'POST', '/schema/suggest', array( 'post_ids' => array( $id ) ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['queued'] );
		$result = $data['results'][0];
		$this->assertSame( 'suggested', $result['status'] );
		$this->assertContains( 'FAQPage', $result['types'] );

		$suggestion = Plugin::instance()->suggestions()->get( $result['suggestion_id'] );
		$this->assertSame( 'schema', $suggestion['module'] );
		$this->assertSame( 'rule', $suggestion['source'] );
		$this->assertContains( 'FAQPage', $suggestion['meta']['types'] );

		$list = $this->call( 'GET', '/schema/posts', array(), array( 'status' => 'none' ) )->get_data();
		$row  = current( wp_list_filter( $list['items'], array( 'id' => $id ) ) );
		$this->assertSame( $result['suggestion_id'], $row['pending_suggestion']['id'] );

		$change = Plugin::instance()->suggestions()->approve( $result['suggestion_id'] );
		$this->assertNotWPError( $change );
		$this->assertContains( 'FAQPage', Store::types( Store::get( $id ) ) );

		$list = $this->call( 'GET', '/schema/posts', array(), array( 'status' => 'has_ours' ) )->get_data();
		$this->assertSame( array( $id ), wp_list_pluck( $list['items'], 'id' ) );
		$this->assertSame( '1', $this->call( 'GET', '/schema/posts', array(), array( 'status' => 'has_ours' ) )->get_headers()['X-WP-Total'] );

		// Nothing new the second time.
		$again = $this->call( 'POST', '/schema/suggest', array( 'post_ids' => array( $id ) ) )->get_data();
		$this->assertSame( 'nothing_new', $again['results'][0]['status'] );
	}

	public function test_needs_input_and_detail_preview(): void {
		$this->login( 'administrator' );
		$id     = $this->post_id( ExtractorsTest::fixture( 'youtube-embed.html' ) );
		$result = $this->call( 'POST', '/schema/suggest', array( 'post_ids' => array( $id ) ) )->get_data()['results'][0];
		$this->assertContains( $result['status'], array( 'needs_input', 'suggested' ) );
		$this->assertSame( 'VideoObject', $result['incomplete'][0]['type'] );
		$this->assertContains( 'upload date', $result['incomplete'][0]['missing'] );

		$list = $this->call( 'GET', '/schema/posts', array(), array( 'status' => 'needs_input' ) )->get_data();
		$this->assertSame( array( $id ), wp_list_pluck( $list['items'], 'id' ) );

		Store::set( $id, self::faq_nodes() );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( self::FAQ_CONTENT ),
			)
		);
		$detail = $this->call( 'GET', '/schema/posts/' . $id )->get_data();
		$this->assertSame( array( 'FAQPage' ), $detail['post']['ours'] );
		$this->assertTrue( $detail['validation']['valid'] );
		$this->assertStringContainsString( '#seoc-faq', $detail['preview']['json'] );
		$this->assertCount( 1, $detail['preview']['pieces'] );
		$this->assertNotEmpty( $detail['incomplete'] );

		$this->assertSame( 404, $this->call( 'GET', '/schema/posts/999999' )->get_status() );
	}

	public function test_preview_hides_types_the_seo_plugin_outputs(): void {
		$this->login( 'administrator' );
		$this->force_seo_plugin( 'yoast' );
		$id = $this->post_id( self::FAQ_CONTENT );
		Store::set(
			$id,
			array_merge(
				self::faq_nodes(),
				array(
					array(
						'@type'    => 'Article',
						'headline' => 'x',
					),
				)
			)
		);
		$preview = $this->call( 'GET', '/schema/posts/' . $id )->get_data()['preview'];
		$this->assertSame( 'yoast', $preview['mode'] );
		$this->assertSame( array( 'FAQPage' ), wp_list_pluck( $preview['pieces'], '@type' ) );
		$this->assertSame( 'Article', $preview['hidden'][0]['type'] );
		$this->assertStringContainsString( 'Yoast', $preview['hidden'][0]['reason'] );
		$this->assertSame( array( '@id' => get_permalink( $id ) ), $preview['pieces'][0]['isPartOf'] );
	}

	public function test_suggest_ai_inline_and_batch_with_fake_client(): void {
		$this->login( 'administrator' );
		$fake = new FakeClient(
			array(
				'candidates' => array(
					array(
						'type'   => 'Service',
						'reason' => 'The page sells an audit.',
						'data'   => array(
							'name'           => 'Technical SEO audit',
							'description'    => null,
							'service_type'   => null,
							'area_served'    => 'Hanoi',
							'price'          => null,
							'price_currency' => null,
						),
					),
				),
			)
		);
		Plugin::instance()->replace_service( 'claude', $fake );
		$content = ExtractorsTest::fixture( 'ai-service-page.html' );
		$one     = $this->post_id( $content, array( 'post_type' => 'page' ) );

		$data = $this->call(
			'POST',
			'/schema/suggest',
			array(
				'post_ids' => array( $one ),
				'use_ai'   => true,
			)
		)->get_data();
		$this->assertFalse( $data['queued'] );
		$this->assertSame( 'suggested', $data['results'][0]['status'] );
		$this->assertSame( 'ai', Plugin::instance()->suggestions()->get( $data['results'][0]['suggestion_id'] )['source'] );
		$this->assertSame( 'low', $fake->calls[0]['opts']['effort'] );

		// More than one post with AI goes to the background.
		$two  = $this->post_id( $content, array( 'post_type' => 'page' ) );
		$data = $this->call(
			'POST',
			'/schema/suggest',
			array(
				'post_ids' => array( $one, $two ),
				'use_ai'   => true,
			)
		)->get_data();
		$this->assertTrue( $data['queued'] );
		$this->assertSame( 2, $data['count'] );
		$this->assertTrue( Plugin::instance()->queue()->is_scheduled( SchemaService::BATCH_HOOK ) );

		Plugin::instance()->queue()->unschedule( SchemaService::BATCH_HOOK );
		do_action( SchemaService::BATCH_HOOK, array( $one, $two ), true );
		$this->assertCount( 3, $fake->calls );
		$status = $this->module()->service()->batch_status();
		$this->assertSame( 2, $status['done'] );
		$this->assertArrayHasKey( 'finished_at', $status );
		$this->assertNotNull( Plugin::instance()->suggestions()->store()->find_pending( 'schema', 'post', $two, 'schema' ) );

		// AI unavailable.
		$fake->available = false;
		$error           = $this->call(
			'POST',
			'/schema/suggest',
			array(
				'post_ids' => array( $one ),
				'use_ai'   => true,
			)
		);
		$this->assertSame( 409, $error->get_status() );
		$this->assertSame( 'seoc_ai_unavailable', $error->get_data()['code'] );
	}

	public function test_many_posts_with_rules_are_queued_and_batch_reenqueues(): void {
		$this->login( 'administrator' );
		$ids  = self::factory()->post->create_many( 12, array( 'post_content' => self::FAQ_CONTENT ) );
		$data = $this->call( 'POST', '/schema/suggest', array( 'post_ids' => $ids ) )->get_data();
		$this->assertTrue( $data['queued'] );
		$this->assertSame( 12, $data['count'] );

		Plugin::instance()->queue()->unschedule( SchemaService::BATCH_HOOK );
		add_filter( 'seoc_time_budget', '__return_zero' );
		do_action( SchemaService::BATCH_HOOK, $ids, false );
		remove_filter( 'seoc_time_budget', '__return_zero' );
		$this->assertSame( 1, $this->module()->service()->batch_status()['done'], 'At least one post per run.' );
		$this->assertTrue( Plugin::instance()->queue()->is_scheduled( SchemaService::BATCH_HOOK, array( array_slice( $ids, 1 ), false ) ) );

		$this->assertSame( 400, $this->call( 'POST', '/schema/suggest', array( 'post_ids' => array() ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/schema/suggest', array( 'post_ids' => array( 999999 ) ) )->get_status() );
	}

	public function test_remove_is_logged_and_undoable(): void {
		$this->login( 'administrator' );
		$id = $this->post_id( self::FAQ_CONTENT );
		$this->assertSame( 409, $this->call( 'POST', '/schema/posts/' . $id . '/remove' )->get_status() );

		Store::set( $id, self::faq_nodes() );
		$response = $this->call( 'POST', '/schema/posts/' . $id . '/remove' );
		$this->assertSame( 200, $response->get_status() );
		$change = $response->get_data()['change'];
		$this->assertSame( 'schema', $change['type'] );
		$this->assertNull( $change['after_value'] );
		$this->assertNull( Store::raw( $id ) );

		$this->assertTrue( Plugin::instance()->changes()->revert( $change['id'] ) );
		$this->assertSame( self::faq_nodes(), Store::get( $id ) );
	}

	public function test_validate_route(): void {
		$this->login( 'administrator' );
		$id   = $this->post_id( '<p>Other text</p>' );
		$data = $this->call(
			'POST',
			'/schema/validate',
			array(
				'nodes'   => self::faq_nodes(),
				'post_id' => $id,
			)
		)->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertStringContainsString( 'does not appear on the page', $data['errors'][0] );

		$single = $this->call(
			'POST',
			'/schema/validate',
			array(
				'nodes' => array(
					'@type' => 'Person',
					'name'  => 'Lan',
				),
			)
		)->get_data();
		$this->assertTrue( $single['valid'] );
	}

	public function test_site_preview(): void {
		$this->login( 'administrator' );
		$this->assertFalse( $this->call( 'GET', '/schema/site' )->get_data()['will_output'] );

		Plugin::instance()->settings()->update(
			'schema',
			array(
				'business_type'    => 'Restaurant',
				'business_name'    => 'Pho 24',
				'address_street'   => '1 Le Loi',
				'address_locality' => 'Hanoi',
				'opening_hours'    => "Mo-Fr 08:00-17:30\nnonsense",
			)
		);
		$this->force_seo_plugin( 'none' );
		$site = $this->call( 'GET', '/schema/site' )->get_data();
		$this->assertTrue( $site['will_output'] );
		$this->assertStringContainsString( '#seoc-localbusiness', $site['json'] );
		$this->assertStringContainsString( 'nonsense', $site['errors'][0] );

		$this->force_seo_plugin( 'rankmath' );
		$site = $this->call( 'GET', '/schema/site' )->get_data();
		$this->assertTrue( $site['will_output'] );
		$this->assertStringContainsString( '"parentOrganization"', $site['json'] );

		Plugin::instance()->settings()->set( 'schema', 'business_type', 'Organization' );
		$site = $this->call( 'GET', '/schema/site' )->get_data();
		$this->assertFalse( $site['will_output'] );
		$this->assertStringContainsString( 'Rank Math already describes', $site['reason'] );

		$types = $this->call( 'GET', '/schema/types' )->get_data()['items'];
		$faq   = current( wp_list_filter( $types, array( 'type' => 'FAQPage' ) ) );
		$this->assertSame( 'limited', $faq['rich_result'] );
	}

	public function test_dashboard_card(): void {
		$id = $this->post_id( self::FAQ_CONTENT );
		Store::set( $id, self::faq_nodes() );
		$cards = apply_filters( 'seoc_dashboard_cards', array() );
		$card  = current( wp_list_filter( $cards, array( 'id' => 'schema' ) ) );
		$this->assertSame( 1, $card['value'] );
		$this->assertSame( 'good', $card['status'] );
	}

	public function test_frontend_output_through_the_module(): void {
		$plugin = Plugin::instance();
		$mode   = $plugin->seo()->active();
		$id     = $this->post_id( self::FAQ_CONTENT );
		Store::set( $id, self::faq_nodes() );
		remove_all_filters( 'rank_math/json_ld' );
		remove_all_filters( 'wpseo_schema_graph' );
		remove_all_actions( 'wp_head' );

		$this->module()->register_frontend( $plugin );
		$this->go_to( get_permalink( $id ) );
		$url = get_permalink( $id );

		if ( 'rankmath' === $mode ) {
			$data = apply_filters(
				'rank_math/json_ld',
				array(
					'WebPage'   => array(
						'@type' => 'WebPage',
						'@id'   => $url . '#webpage',
					),
					'faq-block' => array( '@type' => 'BlogPosting' ),
				),
				null
			);
			$json = wp_json_encode( $data );
		} elseif ( 'yoast' === $mode ) {
			$graph = apply_filters(
				'wpseo_schema_graph',
				array(
					array(
						'@type' => 'WebPage',
						'@id'   => $url,
					),
				),
				null
			);
			$json  = wp_json_encode( $graph );
		} else {
			ob_start();
			do_action( 'wp_head' );
			$json = ob_get_clean();
		}
		$this->assertSame( 1, substr_count( $json, '"FAQPage"' ), $mode );
		$this->assertSame( 1, substr_count( $json, '#seoc-faq"' ), $mode );

		// Switched off in settings: nothing registered.
		remove_all_filters( 'rank_math/json_ld' );
		remove_all_filters( 'wpseo_schema_graph' );
		remove_all_actions( 'wp_head' );
		$plugin->settings()->set( 'schema', 'output_enabled', false );
		$this->module()->register_frontend( $plugin );
		$this->assertFalse( has_filter( 'rank_math/json_ld' ) || has_filter( 'wpseo_schema_graph' ) || has_action( 'wp_head' ) );
	}
}
