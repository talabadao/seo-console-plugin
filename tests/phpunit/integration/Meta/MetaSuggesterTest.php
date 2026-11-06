<?php
/**
 * MetaSuggester with a fake Claude client.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Meta;

use SEOConsole\Modules\Meta\MetaSuggester;
use SEOConsole\Modules\Meta\Prompts;
use SEOConsole\Modules\Meta\Schemas;
use SEOConsole\Tests\Fixtures\AI\FakeClient;
use WP_Error;
use WP_UnitTestCase;

class MetaSuggesterTest extends WP_UnitTestCase {

	/** @var array Suggestions received by the sink. */
	private $sunk = array();

	/** @var array Meta (warnings) received by the sink. */
	private $sunk_meta = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/fixtures/ai/FakeClient.php';
	}

	public function set_up() {
		parent::set_up();
		$this->sunk      = array();
		$this->sunk_meta = array();
	}

	private function suggester( FakeClient $client, array $options = array() ): MetaSuggester {
		$sink = function ( array $suggestion, array $meta = array() ) {
			$this->sunk[]      = $suggestion;
			$this->sunk_meta[] = $meta;
			return count( $this->sunk );
		};
		return new MetaSuggester( $client, $sink, $options + array( 'model' => 'claude-opus-5-5' ) );
	}

	private function post(): int {
		return self::factory()->post->create(
			array(
				'post_title'   => 'Water filter guide',
				'post_content' => '<p>How to pick a water filter for your home.</p><h2>Types</h2><p>RO, UV and carbon.</p>',
			)
		);
	}

	private static function answer( array $overrides = array() ): array {
		return $overrides + array(
			'title'              => 'Water Filter Guide: How to Choose the Best One for Home',
			'description'        => 'Compare RO, UV and carbon water filters, learn what each removes and which suits your home and budget. Read the full guide before you buy.',
			'focus_keyword_used' => true,
			'rationale'          => 'Front-loads the keyword and states the benefit.',
		);
	}

	public function test_success_emits_seo_meta_suggestions(): void {
		$post_id = $this->post();
		$client  = new FakeClient( array( self::answer() ) );

		$result = $this->suggester( $client )->suggest_for_post(
			$post_id,
			array( 'title', 'description' ),
			array(
				'title'         => 'Old title',
				'description'   => '',
				'focus_keyword' => 'water filter',
			)
		);

		$this->assertSame( 'ok', $result['status'] );
		$this->assertCount( 2, $result['created'] );
		$this->assertFalse( $result['stop'] );
		$this->assertCount( 2, $this->sunk );
		$this->assertSame(
			array(
				'module'         => 'meta',
				'type'           => 'seo_meta',
				'object_type'    => 'post',
				'object_id'      => $post_id,
				'field'          => 'title',
				'current_value'  => 'Old title',
				'proposed_value' => 'Water Filter Guide: How to Choose the Best One for Home',
				'rationale'      => 'Front-loads the keyword and states the benefit.',
				'source'         => 'ai',
				'model'          => 'claude-opus-5-5',
			),
			$this->sunk[0]
		);
		$this->assertSame( 'description', $this->sunk[1]['field'] );
		$this->assertSame( '', $this->sunk[1]['current_value'] );
		$this->assertSame( array( 'warnings' => array() ), $this->sunk_meta[0] );

		$this->assertCount( 1, $client->calls );
		$call = $client->calls[0];
		$this->assertSame( 'structured', $call['method'] );
		$this->assertSame( Prompts::meta_system(), $call['system'] );
		$this->assertSame( Schemas::meta(), $call['schema'] );
		$this->assertSame( 'low', $call['opts']['effort'] );
		$this->assertTrue( $call['opts']['cache_system'] );
		$this->assertStringContainsString( 'Focus keyword: water filter', $client->last_user_text() );
		$this->assertStringContainsString( 'Current SEO title: Old title', $client->last_user_text() );
	}

	public function test_only_requested_field_is_emitted_and_warnings_are_passed(): void {
		$client = new FakeClient( array( self::answer( array( 'title' => 'Short title' ) ) ) );
		$result = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'title' ), array() );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertCount( 1, $this->sunk );
		$this->assertSame( 'title', $this->sunk[0]['field'] );
		$this->assertContains( 'too_short', wp_list_pluck( $this->sunk_meta[0]['warnings'], 'code' ) );
	}

	public function test_invalid_output_is_rejected(): void {
		$client = new FakeClient(
			array(
				self::answer(
					array(
						'title'       => 'Best filters at https://evil.example',
						'description' => str_repeat( 'Too long description. ', 10 ),
					)
				),
			)
		);
		$result = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'title', 'description' ), array() );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( array(), $this->sunk );
		$this->assertSame( array( 'seoc_meta_url', 'seoc_meta_too_long' ), wp_list_pluck( $result['rejected'], 'code' ) );
	}

	public function test_unchanged_value_rejected_while_other_field_passes(): void {
		$answer = self::answer();
		$client = new FakeClient( array( $answer ) );
		$result = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'title', 'description' ), array( 'description' => $answer['description'] ) );
		$this->assertSame( 'partial', $result['status'] );
		$this->assertCount( 1, $this->sunk );
		$this->assertSame( 'seoc_meta_unchanged', $result['rejected'][0]['code'] );
	}

	public function test_malformed_answer_is_an_error(): void {
		$result = $this->suggester( new FakeClient( array( 'not an array' ) ) )->suggest_for_post( $this->post(), array( 'title' ), array() );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'seoc_ai_invalid', $result['errors'][0]['code'] );
	}

	public function test_sink_error_is_reported(): void {
		$sink   = static function () {
			return new WP_Error( 'seoc_invalid', 'Applier said no' );
		};
		$s      = new MetaSuggester( new FakeClient( array( self::answer() ) ), $sink );
		$result = $s->suggest_for_post( $this->post(), array( 'title' ), array() );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'seoc_invalid', $result['rejected'][0]['code'] );
	}

	public function client_errors(): array {
		return array(
			'budget'      => array( 'seoc_ai_budget', true, false, 'budget' ),
			'auth'        => array( 'seoc_ai_auth', true, false, 'API key' ),
			'refusal'     => array( 'seoc_ai_refusal', false, false, 'declined' ),
			'truncated'   => array( 'seoc_ai_truncated', false, true, 'cut off' ),
			'unavailable' => array( 'seoc_ai_unavailable', true, true, 'could not be reached' ),
			'network'     => array( 'seoc_ai_network', true, true, 'could not be reached' ),
			'request'     => array( 'seoc_ai_request', false, false, 'could not process' ),
			'unknown'     => array( 'seoc_ai_weird', false, false, 'Boom' ),
		);
	}

	/**
	 * @dataProvider client_errors
	 */
	public function test_client_errors_are_mapped( string $code, bool $stop, bool $retryable, string $needle ): void {
		$client = new FakeClient( array( new WP_Error( $code, 'Boom <b>raw</b>' ) ) );
		$result = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'title', 'description', 'image_alt' ), array() );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( $code, $result['errors'][0]['code'] );
		$this->assertStringContainsString( $needle, $result['errors'][0]['message'] );
		$this->assertStringNotContainsString( '<b>', $result['errors'][0]['message'] );
		$this->assertSame( $stop, $result['stop'] );
		$this->assertSame( $retryable, $result['retryable'] );
		$this->assertSame( array(), $this->sunk );
	}

	public function test_unavailable_client_is_not_called(): void {
		$client            = new FakeClient( array( self::answer() ) );
		$client->available = false;
		$result            = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'title' ), array() );
		$this->assertTrue( $result['stop'] );
		$this->assertSame( 'seoc_ai_not_available', $result['errors'][0]['code'] );
		$this->assertSame( array(), $client->calls );
	}

	public function test_missing_post_and_no_fields(): void {
		$client = new FakeClient( array( self::answer() ) );
		$this->assertSame( 'seoc_meta_no_post', $this->suggester( $client )->suggest_for_post( 999999, array( 'title' ) )['errors'][0]['code'] );
		$this->assertSame( 'seoc_meta_no_fields', $this->suggester( $client )->suggest_for_post( $this->post(), array( 'body' ) )['errors'][0]['code'] );
		$this->assertSame( array(), $client->calls );
	}

	public function test_image_alt_suggestions_only_for_weak_media_library_images(): void {
		$post_id = $this->post();
		$weak    = self::factory()->attachment->create_object( 'IMG_0001.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$good    = self::factory()->attachment->create_object( 'ro-filter.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		$deco    = self::factory()->attachment->create_object( 'divider.png', $post_id, array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $good, '_wp_attachment_image_alt', 'Reverse osmosis filter with five cartridges' );
		$up = wp_get_upload_dir()['baseurl'];
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<h2>Types</h2><img class="wp-image-' . $weak . '" src="' . $up . '/IMG_0001.jpg">'
					. '<img class="wp-image-' . $good . '" src="' . $up . '/ro-filter.jpg">'
					. '<img class="wp-image-' . $deco . '" src="' . $up . '/divider.png">'
					. '<img src="https://cdn.example.com/x.jpg">',
			)
		);

		$client = new FakeClient(
			array(
				array(
					'images' => array(
						array(
							'attachment_id' => $weak,
							'src'           => '',
							'alt'           => 'Under-sink water filter installed in a white kitchen cabinet',
							'rationale'     => 'Describes the scene.',
						),
						array(
							'attachment_id' => 0,
							'src'           => $up . '/divider.png',
							'alt'           => '',
							'rationale'     => 'Decorative.',
						),
						array(
							'attachment_id' => 424242,
							'src'           => 'https://elsewhere.example/a.jpg',
							'alt'           => 'An image the model invented',
							'rationale'     => '',
						),
						array(
							'attachment_id' => $weak,
							'src'           => '',
							'alt'           => 'Duplicate entry',
							'rationale'     => '',
						),
					),
				),
			)
		);

		$result = $this->suggester( $client )->suggest_for_post( $post_id, array( 'image_alt' ), array() );

		$this->assertSame( 'ok', $result['status'] );
		$this->assertCount( 1, $this->sunk );
		$this->assertSame(
			array(
				'module'         => 'meta',
				'type'           => 'image_alt',
				'object_type'    => 'attachment',
				'object_id'      => $weak,
				'field'          => 'alt',
				'current_value'  => '',
				'proposed_value' => 'Under-sink water filter installed in a white kitchen cabinet',
				'rationale'      => 'Describes the scene.',
				'source'         => 'ai',
				'model'          => 'claude-opus-5-5',
			),
			$this->sunk[0]
		);
		$this->assertSame( array( 'external_image', 'decorative' ), wp_list_pluck( $result['skipped'], 'code' ) );

		$this->assertSame( Schemas::images(), $client->calls[0]['schema'] );
		$sent = $client->last_user_text();
		$this->assertStringContainsString( 'attachment_id: ' . $weak, $sent );
		$this->assertStringContainsString( 'attachment_id: ' . $deco, $sent );
		$this->assertStringNotContainsString( 'attachment_id: ' . $good, $sent, 'Images with a good alt are not sent by default.' );
	}

	public function test_no_images_means_no_call(): void {
		$client = new FakeClient( array( self::answer() ) );
		$result = $this->suggester( $client )->suggest_for_post( $this->post(), array( 'image_alt' ), array() );
		$this->assertSame( 'nothing', $result['status'] );
		$this->assertSame( array(), $client->calls );
	}

	public function test_batch_stops_when_time_budget_is_used(): void {
		$now    = 0.0;
		$client = new FakeClient(
			array(
				function () use ( &$now ) {
					$now += 5.0;
					return self::answer();
				},
			)
		);
		$s      = $this->suggester(
			$client,
			array(
				'clock' => function () use ( &$now ) {
					return $now;
				},
			)
		);
		$items  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$items[] = array(
				'object_type' => 'post',
				'object_id'   => $this->post(),
				'fields'      => array( 'title' ),
			);
		}

		$out = $s->suggest_batch( $items, 12.0 );

		$this->assertCount( 2, $out['results'] );
		$this->assertSame( array_slice( $items, 2 ), $out['remaining'] );
		$this->assertNull( $out['stopped'] );
		$this->assertCount( 2, $this->sunk );
	}

	public function test_batch_always_processes_one_item_and_uses_current_provider(): void {
		$now      = 0.0;
		$client   = new FakeClient(
			array(
				function () use ( &$now ) {
					$now += 100.0;
					return self::answer();
				},
			)
		);
		$post_id  = $this->post();
		$provided = array();
		$s        = $this->suggester(
			$client,
			array(
				'clock'            => function () use ( &$now ) {
					return $now;
				},
				'current_provider' => function ( $id ) use ( &$provided ) {
					$provided[] = $id;
					return array( 'title' => 'Stored title' );
				},
			)
		);
		$out      = $s->suggest_batch(
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $post_id,
				),
				array(
					'object_type' => 'post',
					'object_id'   => $post_id,
				),
			),
			1.0
		);
		$this->assertCount( 1, $out['results'] );
		$this->assertCount( 1, $out['remaining'] );
		$this->assertSame( array( $post_id ), $provided );
		$this->assertSame( 'Stored title', $this->sunk[0]['current_value'] );
		$this->assertSame( array( 'title', 'description' ), wp_list_pluck( $this->sunk, 'field' ), 'Default fields.' );
	}

	public function test_batch_stops_on_budget_and_keeps_items(): void {
		$client = new FakeClient( array( self::answer(), new WP_Error( 'seoc_ai_budget', 'Budget' ) ) );
		$a      = array(
			'object_type' => 'post',
			'object_id'   => $this->post(),
			'fields'      => array( 'title' ),
		);
		$b      = array( 'object_id' => $this->post() ) + $a;
		$c      = array( 'object_id' => $this->post() ) + $a;

		$out = $this->suggester( $client )->suggest_batch( array( $a, $b, $c ), 60.0 );

		$this->assertSame( 'seoc_ai_budget', $out['stopped']['code'] );
		$this->assertCount( 2, $out['results'] );
		$this->assertSame( array( $b + array( 'attempts' => 1 ), $c ), $out['remaining'] );
		$this->assertCount( 1, $this->sunk );
	}

	public function test_batch_retries_truncated_items_then_gives_up(): void {
		$client = new FakeClient( array( new WP_Error( 'seoc_ai_truncated', 'cut' ) ) );
		$out    = $this->suggester( $client, array( 'max_attempts' => 3 ) )->suggest_batch(
			array(
				array(
					'object_type' => 'post',
					'object_id'   => $this->post(),
				),
			),
			60.0
		);
		$this->assertCount( 3, $client->calls );
		$this->assertSame( array(), $out['remaining'] );
		$this->assertNull( $out['stopped'] );
	}

	public function test_batch_skips_unsupported_items_and_unavailable_client(): void {
		$client = new FakeClient( array( self::answer() ) );
		$out    = $this->suggester( $client )->suggest_batch(
			array(
				array(
					'object_type' => 'term',
					'object_id'   => 3,
				),
			),
			10.0
		);
		$this->assertSame( 'skipped', $out['results'][0]['status'] );
		$this->assertSame( array(), $client->calls );

		$client->available = false;
		$items             = array(
			array(
				'object_type' => 'post',
				'object_id'   => 5,
			),
		);
		$out               = $this->suggester( $client )->suggest_batch( $items, 10.0 );
		$this->assertSame( $items, $out['remaining'] );
		$this->assertSame( 'seoc_ai_not_available', $out['stopped']['code'] );
	}

	public function test_alternatives(): void {
		$client  = new FakeClient(
			array(
				array(
					'options' => array(
						self::answer(),
						self::answer( array( 'title' => 'Water Filters Compared: RO vs UV vs Carbon for Homes' ) ),
						self::answer( array( 'title' => '<b>bad</b>' ) ),
						self::answer( array( 'title' => 'A fourth one is ignored' ) ),
					),
				),
			)
		);
		$options = $this->suggester( $client )->alternatives( $this->post(), array( 'focus_keyword' => 'water filter' ), 3 );
		$this->assertCount( 3, $options );
		$this->assertTrue( $options[0]['valid'] );
		$this->assertTrue( $options[1]['valid'] );
		$this->assertFalse( $options[2]['valid'] );
		$this->assertSame( Schemas::alternatives(), $client->calls[0]['schema'] );
		$this->assertStringContainsString( 'Write 3 different options', $client->last_user_text() );
		$this->assertSame( array(), $this->sunk );
	}
}
