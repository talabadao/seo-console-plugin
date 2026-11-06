<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\Changes\Change;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class SuggestionServiceTest extends CoreTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->force_seo_plugin( 'yoast' );
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Trail shoes' ) );
		update_post_meta( $this->post_id, '_yoast_wpseo_metadesc', 'Old description' );
		$this->login( 'administrator' );
	}

	private function data( array $extra = array() ): array {
		return array_merge(
			array(
				'module'         => 'meta',
				'type'           => 'seo_meta',
				'object_type'    => 'post',
				'object_id'      => $this->post_id,
				'field'          => 'description',
				'proposed_value' => 'A better description for trail shoes.',
				'rationale'      => 'Adds the keyword.',
				'confidence'     => 0.8,
				'source'         => 'ai',
				'model'          => 'claude-opus-5-5',
			),
			$extra
		);
	}

	public function test_create_and_query_items(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		$this->assertIsInt( $id );
		$result = $service->query( array( 'status' => 'pending' ) );
		$this->assertSame( 1, $result['total'] );
		$item = $result['items'][0];
		$this->assertSame( 'Old description', $item['current_value'] );
		$this->assertSame( 'Meta description', $item['label'] );
		$this->assertSame( 'Trail shoes', $item['object_title'] );
		$this->assertNotEmpty( $item['edit_link'] );
		$this->assertNotEmpty( $item['view_link'] );
		$this->assertSame( 0.8, $item['confidence'] );
		$this->assertSame( 0, $service->query( array( 'module' => 'audit' ) )['total'] );
	}

	public function test_dedupe_updates_pending(): void {
		$service = Plugin::instance()->suggestions();
		$first   = $service->create( $this->data() );
		$second  = $service->create( $this->data( array( 'proposed_value' => 'Second proposal.' ) ) );
		$this->assertSame( $first, $second );
		$result = $service->query();
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Second proposal.', $result['items'][0]['proposed_value'] );
	}

	public function test_invalid_input(): void {
		$service = Plugin::instance()->suggestions();
		$this->assertSame( 'seoc_unknown_applier', $service->create( $this->data( array( 'type' => 'nope' ) ) )->get_error_code() );
		$this->assertSame( 'seoc_invalid_value', $service->create( $this->data( array( 'proposed_value' => str_repeat( 'x', 500 ) ) ) )->get_error_code() );
		$this->assertSame( 'seoc_invalid_suggestion', $service->create( $this->data( array( 'confidence' => 2 ) ) )->get_error_code() );
		$this->assertSame( 'seoc_invalid_suggestion', $service->create( $this->data( array( 'source' => 'user' ) ) )->get_error_code() );
		$this->assertSame( 'seoc_no_change', $service->create( $this->data( array( 'proposed_value' => 'Old description' ) ) )->get_error_code() );
		$missing = $this->data();
		unset( $missing['proposed_value'] );
		$this->assertWPError( $service->create( $missing ) );
	}

	public function test_approve_with_edited_value(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		$change  = $service->approve( $id, 'Edited by the reviewer.' );
		$this->assertInstanceOf( Change::class, $change );
		$this->assertSame( 'Edited by the reviewer.', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'ai', $change->source );
		$this->assertSame( $id, $change->suggestion_id );
		$item = $service->get( $id );
		$this->assertSame( 'applied', $item['status'] );
		$this->assertSame( 'Edited by the reviewer.', $item['final_value'] );
		$this->assertSame( $change->id, $item['change_id'] );
		$this->assertSame( 'seoc_invalid_status', $service->approve( $id )->get_error_code() );
	}

	public function test_approve_with_invalid_edit_keeps_pending(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		$this->assertWPError( $service->approve( $id, '<b>html</b>' ) );
		$this->assertSame( 'pending', $service->get( $id )['status'] );
	}

	public function test_stale_detection_and_force(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		update_post_meta( $this->post_id, '_yoast_wpseo_metadesc', 'Changed meanwhile' );
		$error = $service->approve( $id );
		$this->assertSame( 'seoc_stale', $error->get_error_code() );
		$this->assertSame( 'Changed meanwhile', $error->get_error_data()['current_value'] );
		$this->assertSame( 'stale', $service->get( $id )['status'] );
		$this->assertSame( 'Changed meanwhile', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );

		$this->assertInstanceOf( Change::class, $service->approve( $id, null, true ) );
		$this->assertSame( 'A better description for trail shoes.', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
	}

	public function test_health_failure_marks_failed(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		$this->break_loopback();
		$this->assertSame( 'seoc_health_failed', $service->approve( $id )->get_error_code() );
		$item = $service->get( $id );
		$this->assertSame( 'failed', $item['status'] );
		$this->assertNotEmpty( $item['error'] );
		$this->assertSame( 'Old description', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
	}

	public function test_reject(): void {
		$service = Plugin::instance()->suggestions();
		$id      = $service->create( $this->data() );
		$this->assertTrue( $service->reject( $id ) );
		$this->assertSame( 'rejected', $service->get( $id )['status'] );
		$this->assertSame( 'seoc_invalid_status', $service->reject( $id )->get_error_code() );
		$this->assertSame( 'seoc_not_found', $service->reject( 999999 )->get_error_code() );
	}

	public function test_bulk_approve_with_one_stale(): void {
		$service = Plugin::instance()->suggestions();
		$other   = self::factory()->post->create();
		$a       = $service->create( $this->data() );
		$b       = $service->create( $this->data( array( 'object_id' => $other, 'field' => 'title', 'proposed_value' => 'Other title' ) ) );
		$c       = $service->create( $this->data( array( 'field' => 'title', 'proposed_value' => 'Trail title' ) ) );
		update_post_meta( $this->post_id, '_yoast_wpseo_title', 'Someone edited' );

		$results = $service->bulk( 'approve', array( $a, $b, $c, 424242 ) );
		$this->assertInstanceOf( Change::class, $results[ $a ] );
		$this->assertInstanceOf( Change::class, $results[ $b ] );
		$this->assertSame( 'seoc_stale', $results[ $c ]->get_error_code() );
		$this->assertSame( 'seoc_not_found', $results[424242]->get_error_code() );
		$this->assertSame( $results[ $a ]->batch_id, $results[ $b ]->batch_id );
		$this->assertSame( 'Other title', get_post_meta( $other, '_yoast_wpseo_title', true ) );

		$counts = $service->store()->counts();
		$this->assertSame( 2, $counts['applied'] );
		$this->assertSame( 1, $counts['stale'] );
	}

	public function test_bulk_reject(): void {
		$service = Plugin::instance()->suggestions();
		$a       = $service->create( $this->data() );
		$results = $service->bulk( 'reject', array( $a ) );
		$this->assertTrue( $results[ $a ] );
		$this->assertWPError( $service->bulk( 'explode', array( $a ) )[ $a ] );
	}
}
