<?php
/**
 * Chat Conversation: append-only storage, trimming window, ownership.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Chat;

use SEOConsole\Modules\Chat\ContextBuilder;
use SEOConsole\Modules\Chat\Conversation;
use SEOConsole\Tests\Fixtures\AI\FakeClient;
use WP_Error;
use WP_UnitTestCase;

class ConversationTest extends WP_UnitTestCase {

	/** @var FakeClient */
	private $client;

	/** @var Conversation */
	private $chat;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/fixtures/ai/FakeClient.php';
	}

	public function set_up() {
		parent::set_up();
		Conversation::install();
		$counter      = 0;
		$this->client = new FakeClient(
			array(
				static function () use ( &$counter ) {
					++$counter;
					return array(
						'text'  => 'Answer ' . $counter,
						'usage' => array(
							'input_tokens'            => 100,
							'cache_read_input_tokens' => 900,
							'output_tokens'           => 50,
						),
						'model' => 'claude-opus-5-5',
					);
				},
			)
		);
		$this->chat   = new Conversation( $this->client, new ContextBuilder() );
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Conversation::table() ); // phpcs:ignore
		parent::tear_down();
	}

	public function test_send_stores_both_turns_and_calls_chat_with_medium_effort(): void {
		$user = self::factory()->user->create();
		$out  = $this->chat->send( $user, '', 'Why is my home page LCP 4 s?' );

		$this->assertIsArray( $out );
		$this->assertTrue( Conversation::valid_id( $out['conversation_id'] ) );
		$this->assertCount( 2, $out['messages'] );
		list( $q, $a ) = $out['messages'];
		$this->assertSame( 'user', $q['role'] );
		$this->assertSame( 'Why is my home page LCP 4 s?', $q['content'] );
		$this->assertSame( 'assistant', $a['role'] );
		$this->assertSame( 'Answer 1', $a['content'] );
		$this->assertSame( 1000, $a['tokens_in'] );
		$this->assertSame( 50, $a['tokens_out'] );
		$this->assertSame( 'claude-opus-5-5', $a['model'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $a['created_at'] );

		$call = $this->client->calls[0];
		$this->assertSame( 'chat', $call['method'] );
		$this->assertSame( 'medium', $call['opts']['effort'] );
		$this->assertTrue( $call['opts']['cache_system'] );
		$this->assertSame( ( new ContextBuilder() )->system_prompt(), $call['system'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => 'Why is my home page LCP 4 s?',
				),
			),
			$call['messages']
		);

		$this->assertSame( $out['messages'], $this->chat->messages( $user, $out['conversation_id'] ) );
	}

	public function test_history_is_append_only_and_sent_unchanged(): void {
		$user = self::factory()->user->create();
		$id   = Conversation::new_id();
		$this->chat->send( $user, $id, 'First' );
		$first_rows = $this->chat->messages( $user, $id );
		$this->chat->send( $user, $id, 'Second' );

		$rows = $this->chat->messages( $user, $id );
		$this->assertCount( 4, $rows );
		$this->assertSame( $first_rows, array_slice( $rows, 0, 2 ), 'Earlier turns are never modified.' );
		$this->assertSame( array( 'First', 'Answer 1', 'Second', 'Answer 2' ), wp_list_pluck( $rows, 'content' ) );
		$this->assertSame(
			array( 'user', 'assistant', 'user' ),
			wp_list_pluck( $this->client->calls[1]['messages'], 'role' )
		);
		$this->assertSame( $this->client->calls[0]['messages'][0], $this->client->calls[1]['messages'][0], 'Stable prefix.' );
	}

	public function test_failed_call_stores_nothing(): void {
		$user                  = self::factory()->user->create();
		$id                    = Conversation::new_id();
		$this->client->answers = array( new WP_Error( 'seoc_ai_budget', 'raw' ) );
		$result                = $this->chat->send( $user, $id, 'Hello' );
		$this->assertWPError( $result );
		$this->assertSame( 'seoc_ai_budget', $result->get_error_code() );
		$this->assertStringContainsString( 'budget', $result->get_error_message() );
		$this->assertSame( 402, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->chat->messages( $user, $id ) );

		$this->client->answers = array( array( 'text' => '   ' ) );
		$this->assertWPError( $this->chat->send( $user, $id, 'Hello' ) );
		$this->assertSame( array(), $this->chat->messages( $user, $id ) );

		$this->client->available = false;
		$this->assertSame( 'seoc_ai_not_available', $this->chat->send( $user, $id, 'Hello' )->get_error_code() );
	}

	public function test_input_validation(): void {
		$user = self::factory()->user->create();
		$this->assertSame( 'seoc_chat_empty', $this->chat->send( $user, '', " \n\x00 " )->get_error_code() );
		$this->assertSame( 'seoc_chat_too_long', $this->chat->send( $user, '', str_repeat( 'a', Conversation::MAX_MESSAGE_CHARS + 1 ) )->get_error_code() );
		$this->assertSame( 'seoc_chat_invalid_id', $this->chat->send( $user, "x' OR 1=1 --", 'Hi' )->get_error_code() );
		$this->assertSame( 'seoc_chat_forbidden', $this->chat->send( 0, '', 'Hi' )->get_error_code() );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_conversations_are_per_user(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();
		$a1    = $this->chat->send( $alice, '', 'Alice first question about sitemaps' )['conversation_id'];
		$a2    = $this->chat->send( $alice, '', str_repeat( 'Long question ', 20 ) )['conversation_id'];
		$this->chat->send( $alice, $a1, 'Follow-up' );

		$list = $this->chat->conversations( $alice );
		$this->assertSame( array( $a1, $a2 ), wp_list_pluck( $list, 'id' ), 'Most recently active first.' );
		$this->assertSame( 'Alice first question about sitemaps', $list[0]['title'] );
		$this->assertSame( 4, $list[0]['message_count'] );
		$this->assertLessThanOrEqual( 80, mb_strlen( $list[1]['title'] ) );

		$this->assertSame( array(), $this->chat->conversations( $bob ) );
		$this->assertSame( array(), $this->chat->messages( $bob, $a1 ) );
		$this->assertSame( 'seoc_chat_not_found', $this->chat->send( $bob, $a1, 'Hijack' )->get_error_code() );
		$this->assertSame( 'seoc_chat_not_found', $this->chat->delete( $bob, $a1 )->get_error_code() );

		$this->assertSame( 4, $this->chat->delete( $alice, $a1 ) );
		$this->assertSame( array( $a2 ), wp_list_pluck( $this->chat->conversations( $alice ), 'id' ) );
		$this->assertSame( 2, Conversation::delete_user_data( $alice ) );
	}

	public function test_window_keeps_recent_turns_in_stable_steps(): void {
		$rows = function ( int $turns ): array {
			$out = array();
			for ( $i = 1; $i <= $turns; $i++ ) {
				$out[] = array(
					'role'    => 'user',
					'content' => 'Q' . $i,
				);
				$out[] = array(
					'role'    => 'assistant',
					'content' => 'A' . $i,
				);
			}
			return $out;
		};

		$this->assertCount( 60, Conversation::window( $rows( 30 ) ) );

		$w31 = Conversation::window( $rows( 31 ) );
		$this->assertCount( 42, $w31, '31 turns → window moves by one step (10 turns).' );
		$this->assertSame( Conversation::TRIMMED_NOTE . "\n\nQ11", $w31[0]['content'] );
		$this->assertSame( 'A31', end( $w31 )['content'] );

		$w40 = Conversation::window( $rows( 40 ) );
		$this->assertCount( 60, $w40 );
		$this->assertSame( $w31[0], $w40[0], 'Same window start (cache-friendly) until the next step.' );

		$w41 = Conversation::window( $rows( 41 ) );
		$this->assertSame( Conversation::TRIMMED_NOTE . "\n\nQ21", $w41[0]['content'] );

		// A dangling user message (no answer) is left out; the API sees alternating roles only.
		$broken = array_merge( $rows( 2 ), array( array( 'role' => 'user', 'content' => 'lost' ) ), $rows( 1 ) );
		$this->assertSame( array( 'Q1', 'A1', 'Q2', 'A2', 'Q1', 'A1' ), wp_list_pluck( Conversation::window( $broken ), 'content' ) );
	}

	public function test_long_conversation_sends_trimmed_window(): void {
		$user = self::factory()->user->create();
		$id   = Conversation::new_id();
		for ( $i = 1; $i <= 32; $i++ ) {
			$this->chat->send( $user, $id, 'Question ' . $i );
		}
		$last = end( $this->client->calls )['messages'];
		$this->assertCount( 21 * 2 + 1, $last ); // 31 stored turns → 21 kept + the new question.
		$this->assertStringContainsString( 'Question 11', $last[0]['content'] );
		$this->assertSame( 'Question 32', end( $last )['content'] );
		$this->assertCount( 64, $this->chat->messages( $user, $id ), 'Storage keeps everything.' );
	}

	public function test_table_sql_uses_placeholders(): void {
		$sql = Conversation::table_sql();
		$this->assertStringStartsWith( 'CREATE TABLE {prefix}seoc_chat_messages (', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringEndsWith( '{charset};', $sql );
	}
}
