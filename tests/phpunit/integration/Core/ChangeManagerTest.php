<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Changes\Change;
use SEOConsole\Changes\ChangeRequest;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class ChangeManagerTest extends CoreTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->force_seo_plugin( 'yoast' );
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Running shoes' ) );
		$this->login( 'administrator' );
	}

	private function title_request( string $value ): ChangeRequest {
		return new ChangeRequest( 'seo_meta', 'post', $this->post_id, 'title', $value, array( 'module' => 'meta' ) );
	}

	public function test_apply_and_revert_round_trip(): void {
		update_post_meta( $this->post_id, '_yoast_wpseo_title', 'Old title' );
		$applied = array();
		$cb      = static function ( $change ) use ( &$applied ) {
			$applied[] = $change;
		};
		add_action( 'seoc_change_applied', $cb );

		$manager = Plugin::instance()->changes();
		$change  = $manager->apply( $this->title_request( 'New title' ) );
		remove_action( 'seoc_change_applied', $cb );

		$this->assertInstanceOf( Change::class, $change );
		$this->assertSame( 'New title', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Old title', $change->before_value );
		$this->assertSame( 'New title', $change->after_value );
		$this->assertSame( 'applied', $change->status );
		$this->assertSame( get_current_user_id(), $change->user_id );
		$this->assertCount( 1, $applied );
		$this->assertNotEmpty( $this->http_requests, 'health check ran' );

		$this->assertTrue( $manager->revert( $change->id ) );
		$this->assertSame( 'Old title', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$reverted = $manager->get( $change->id );
		$this->assertSame( 'reverted', $reverted->status );
		$this->assertSame( get_current_user_id(), $reverted->reverted_by );
		$this->assertNotNull( $reverted->reverted_at );
		$this->assertSame( 'seoc_not_applied', $manager->revert( $change->id )->get_error_code() );
	}

	public function test_revert_restores_missing_meta(): void {
		$manager = Plugin::instance()->changes();
		$change  = $manager->apply( $this->title_request( 'Brand new' ) );
		$this->assertTrue( $manager->revert( $change->id ) );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, '_yoast_wpseo_title' ) );
	}

	public function test_revert_conflict_and_force(): void {
		$manager = Plugin::instance()->changes();
		$change  = $manager->apply( $this->title_request( 'Applied title' ) );
		update_post_meta( $this->post_id, '_yoast_wpseo_title', 'Edited by hand' );
		$error = $manager->revert( $change->id );
		$this->assertSame( 'seoc_conflict', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'Edited by hand', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertTrue( $manager->revert( $change->id, true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
	}

	public function test_health_failure_auto_reverts(): void {
		update_post_meta( $this->post_id, '_yoast_wpseo_metadesc', 'Old description' );
		$this->break_loopback();
		$failed = array();
		$cb     = static function ( $change ) use ( &$failed ) {
			$failed[] = $change;
		};
		add_action( 'seoc_change_failed', $cb );
		$result = Plugin::instance()->changes()->apply( new ChangeRequest( 'seo_meta', 'post', $this->post_id, 'description', 'Broken site description' ) );
		remove_action( 'seoc_change_failed', $cb );

		$this->assertWPError( $result );
		$this->assertSame( 'seoc_health_failed', $result->get_error_code() );
		$this->assertSame( 'Old description', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
		$row = Plugin::instance()->changes()->get( $result->get_error_data()['change_id'] );
		$this->assertSame( 'failed', $row->status );
		$this->assertStringContainsString( '500', $row->error );
		$this->assertCount( 1, $failed );
	}

	public function test_invalid_requests(): void {
		$manager = Plugin::instance()->changes();
		$this->assertSame( 'seoc_unknown_applier', $manager->apply( new ChangeRequest( 'nope', 'post', 1, 'x', 'y' ) )->get_error_code() );
		$this->assertSame( 'seoc_invalid_value', $manager->apply( $this->title_request( str_repeat( 'a', 201 ) ) )->get_error_code() );
		$this->assertSame( 'seoc_invalid_value', $manager->apply( $this->title_request( '<script>x</script>' ) )->get_error_code() );
		$this->assertSame( 'seoc_not_found', $manager->apply( new ChangeRequest( 'seo_meta', 'post', 999999, 'title', 'x' ) )->get_error_code() );
		$bad_source = $this->title_request( 'ok' );
		$bad_source->source = 'hacker';
		$this->assertSame( 'seoc_invalid_source', $manager->apply( $bad_source )->get_error_code() );
		$this->assertSame( 0, $manager->query()['total'] );
		$this->assertSame( 'seoc_not_found', $manager->revert( 123456 )->get_error_code() );
	}

	public function test_no_seo_plugin_is_refused(): void {
		$this->force_seo_plugin( 'none' );
		$error = Plugin::instance()->changes()->apply( $this->title_request( 'x' ) );
		$this->assertSame( 'seoc_no_seo_plugin', $error->get_error_code() );
	}

	public function test_cache_is_purged_after_frontend_change(): void {
		$purges = 0;
		$cb     = static function () use ( &$purges ) {
			++$purges;
		};
		add_action( 'litespeed_purge_all', $cb );
		Plugin::instance()->changes()->apply( $this->title_request( 'Purge me' ) );
		remove_action( 'litespeed_purge_all', $cb );
		$this->assertSame( 1, $purges );
	}

	public function test_batch_applies_with_one_health_check(): void {
		$other = self::factory()->post->create();
		$batch = Plugin::instance()->changes()->apply_batch(
			array(
				$this->title_request( 'Batch one' ),
				new ChangeRequest( 'seo_meta', 'post', $other, 'title', 'Batch two' ),
			)
		);
		$this->assertTrue( $batch['ok'] );
		$this->assertCount( 2, $batch['changes'] );
		$this->assertSame( $batch['batch_id'], $batch['changes'][0]->batch_id );
		$this->assertSame( 'Batch two', get_post_meta( $other, '_yoast_wpseo_title', true ) );
		$home_hits = array_filter(
			$this->http_requests,
			static function ( $r ) {
				return 0 === strpos( $r['url'], home_url( '/?seoc_hc=' ) );
			}
		);
		$this->assertCount( 1, $home_hits );
	}

	public function test_batch_invalid_item_applies_nothing(): void {
		$batch = Plugin::instance()->changes()->apply_batch(
			array(
				$this->title_request( 'Valid' ),
				$this->title_request( str_repeat( 'x', 300 ) ),
			)
		);
		$this->assertFalse( $batch['ok'] );
		$this->assertArrayHasKey( 1, $batch['errors'] );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
	}

	public function test_batch_health_failure_reverts_all(): void {
		$other = self::factory()->post->create();
		update_post_meta( $other, '_yoast_wpseo_title', 'Keep me' );
		$this->break_loopback();
		$batch = Plugin::instance()->changes()->apply_batch(
			array(
				$this->title_request( 'Batch one' ),
				new ChangeRequest( 'seo_meta', 'post', $other, 'title', 'Batch two' ),
			)
		);
		$this->assertFalse( $batch['ok'] );
		$this->assertSame( 'seoc_health_failed', $batch['error']->get_error_code() );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Keep me', get_post_meta( $other, '_yoast_wpseo_title', true ) );
		$rows = Plugin::instance()->changes()->query( array( 'batch_id' => $batch['batch_id'] ) );
		$this->assertSame( 2, $rows['total'] );
		foreach ( $rows['items'] as $row ) {
			$this->assertSame( 'failed', $row->status );
		}
	}

	public function test_image_alt_round_trip_and_conflict(): void {
		$attachment = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'file'           => 'shoe.jpg',
			)
		);
		$manager = Plugin::instance()->changes();
		$change  = $manager->apply( new ChangeRequest( 'image_alt', 'attachment', $attachment, 'alt', 'Red running shoe' ) );
		$this->assertInstanceOf( Change::class, $change );
		$this->assertSame( 'Red running shoe', get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
		$this->assertTrue( $manager->revert( $change->id ) );
		$this->assertSame( '', get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );

		$change = $manager->apply( new ChangeRequest( 'image_alt', 'attachment', $attachment, 'alt', 'Blue shoe' ) );
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Manual' );
		$this->assertSame( 'seoc_conflict', $manager->revert( $change->id )->get_error_code() );

		$this->assertWPError( $manager->apply( new ChangeRequest( 'image_alt', 'attachment', $this->post_id, 'alt', 'x' ) ) );
	}

	public function test_module_setting_round_trip_and_conflict(): void {
		$manager = Plugin::instance()->changes();
		$change  = $manager->apply( new ChangeRequest( 'module_setting', 'site', null, 'general.ai_model', 'claude-sonnet-5-5' ) );
		$this->assertInstanceOf( Change::class, $change );
		$this->assertSame( 'claude-sonnet-5-5', Plugin::instance()->settings()->get( 'general', 'ai_model' ) );
		$this->assertSame( 'claude-opus-5-5', $change->before_value );
		$this->assertTrue( $manager->revert( $change->id ) );
		$this->assertSame( 'claude-opus-5-5', Plugin::instance()->settings()->get( 'general', 'ai_model' ) );

		$change = $manager->apply( new ChangeRequest( 'module_setting', 'site', null, 'general.ai_monthly_budget_usd', 5 ) );
		Plugin::instance()->settings()->set( 'general', 'ai_monthly_budget_usd', 7 );
		$this->assertSame( 'seoc_conflict', $manager->revert( $change->id )->get_error_code() );

		$this->assertWPError( $manager->apply( new ChangeRequest( 'module_setting', 'site', null, 'general.ai_model', 'gpt' ) ) );
		$this->assertWPError( $manager->apply( new ChangeRequest( 'module_setting', 'site', null, 'nodot', 1 ) ) );
	}

	public function test_post_meta_json_round_trip_and_key_whitelist(): void {
		$manager = Plugin::instance()->changes();
		$schema  = array(
			'@type' => 'Product',
			'name'  => 'Shoe',
		);
		$change  = $manager->apply( new ChangeRequest( 'post_meta_json', 'post', $this->post_id, '_seoc_schema', $schema ) );
		$this->assertInstanceOf( Change::class, $change );
		$this->assertSame( $schema, json_decode( get_post_meta( $this->post_id, '_seoc_schema', true ), true ) );
		$this->assertSame( $schema, $change->after_value );
		$this->assertNull( $change->before_value );
		$this->assertTrue( $manager->revert( $change->id ) );
		$this->assertFalse( metadata_exists( 'post', $this->post_id, '_seoc_schema' ) );

		$change = $manager->apply( new ChangeRequest( 'post_meta_json', 'post', $this->post_id, '_seoc_schema', $schema ) );
		update_post_meta( $this->post_id, '_seoc_schema', '{"@type":"Thing"}' );
		$this->assertSame( 'seoc_conflict', $manager->revert( $change->id )->get_error_code() );

		$error = $manager->apply( new ChangeRequest( 'post_meta_json', 'post', $this->post_id, '_yoast_wpseo_title', 'x' ) );
		$this->assertSame( 'seoc_invalid_field', $error->get_error_code() );
		$error = $manager->apply( new ChangeRequest( 'post_meta_json', 'post', $this->post_id, '_edit_lock', 'x' ) );
		$this->assertSame( 'seoc_invalid_field', $error->get_error_code() );
	}

	public function test_query_filters_and_rest_shape(): void {
		$manager = Plugin::instance()->changes();
		$manager->apply( $this->title_request( 'One' ) );
		$manager->apply( new ChangeRequest( 'module_setting', 'site', null, 'general.log_level', 'debug', array( 'module' => 'core' ) ) );
		$this->assertSame( 2, $manager->query()['total'] );
		$result = $manager->query( array( 'module' => 'meta' ) );
		$this->assertSame( 1, $result['total'] );
		$item = $manager->to_rest( $result['items'][0] );
		$this->assertSame( 'SEO title', $item['label'] );
		$this->assertSame( 'Running shoes', $item['object_title'] );
		$this->assertNotEmpty( $item['edit_link'] );
		$this->assertNotEmpty( $item['view_link'] );
		$this->assertTrue( $item['can_revert'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $item['created_at'] );
		$this->assertSame( 1, $manager->query( array( 'object_type' => 'post', 'object_id' => $this->post_id ) )['total'] );
	}

	public function test_values_equal(): void {
		$this->assertTrue( Change::values_equal( null, '' ) );
		$this->assertTrue( Change::values_equal( 5, '5' ) );
		$this->assertTrue( Change::values_equal( array( 'b' => 1, 'a' => 2 ), array( 'a' => 2, 'b' => 1 ) ) );
		$this->assertFalse( Change::values_equal( array( 1, 2 ), array( 2, 1 ) ) );
		$this->assertFalse( Change::values_equal( 'a', 'b' ) );
	}
}
