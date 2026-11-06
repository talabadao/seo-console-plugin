<?php
/**
 * Chat REST routes: per-user isolation, errors, task export, user deletion.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Chat;

use SEOConsole\Tests\Fixtures\AI\ModuleTestCase;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/fixtures/ai/ModuleTestCase.php';

class ChatRestTest extends ModuleTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->client->answers = array(
			array(
				'text'  => "**LCP** is slow because of the hero image.\n\n- Preload it",
				'usage' => array(
					'input_tokens'  => 10,
					'output_tokens' => 5,
				),
				'model' => 'claude-opus-5-5',
			),
		);
	}

	private function send( string $text, string $id = '' ) {
		return $this->call(
			'POST',
			'/chat/messages',
			array(
				'conversation_id' => $id,
				'text'            => $text,
			)
		);
	}

	public function test_send_and_list_are_per_user(): void {
		$alice    = $this->login( 'administrator' );
		$response = $this->send( 'Why is my LCP slow?' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$id   = $data['conversation_id'];
		$this->assertSame( array( 'user', 'assistant' ), wp_list_pluck( $data['messages'], 'role' ) );
		$this->assertArrayHasKey( 'month_cost_usd', $data['ai_usage'] );
		$this->assertSame( 'low', $this->client->calls[0]['opts']['effort'], 'Chat runs at the module setting (default low).' );
		$this->assertSame( 8000, $this->client->calls[0]['opts']['max_tokens'] );
		$this->assertStringContainsString( 'SEO plugin:', $this->client->calls[0]['system'] );

		$list = $this->call( 'GET', '/chat/conversations' )->get_data();
		$this->assertSame( array( $id ), wp_list_pluck( $list['items'], 'id' ) );
		$this->assertCount( 2, $this->call( 'GET', '/chat/conversations/' . $id )->get_data()['messages'] );

		$this->login( 'administrator' ); // Bob.
		$this->assertSame( array(), $this->call( 'GET', '/chat/conversations' )->get_data()['items'] );
		$this->assertSame( 404, $this->call( 'GET', '/chat/conversations/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/chat/conversations/' . $id )->get_status() );
		$this->assertSame( 404, $this->send( 'Hijack', $id )->get_status() );

		wp_set_current_user( $alice );
		$deleted = $this->call( 'DELETE', '/chat/conversations/' . $id );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertSame( 2, $deleted->get_data()['messages'] );
		$this->assertSame( 404, $this->call( 'GET', '/chat/conversations/' . $id )->get_status() );
	}

	public function test_validation_and_ai_errors(): void {
		$this->login( 'administrator' );
		$this->assertSame( 400, $this->send( '' )->get_status() );
		$this->assertSame( 400, $this->send( 'Hi', 'bad id!' )->get_status() );
		$this->assertSame( 400, $this->send( "  \n " )->get_status() );

		$this->client->answers = array( new WP_Error( 'seoc_ai_budget', 'raw' ) );
		$response              = $this->send( 'Hello' );
		$this->assertSame( 402, $response->get_status() );
		$this->assertSame( 'seoc_ai_budget', $response->get_data()['code'] );

		$this->client->answers = array( new WP_Error( 'seoc_ai_no_key', 'raw' ) );
		$this->assertStringContainsString( 'Add your Claude API key', $this->send( 'Hello' )->get_data()['message'] );
		$this->assertSame( array(), $this->call( 'GET', '/chat/conversations' )->get_data()['items'], 'Failed sends store nothing.' );
	}

	public function test_task_from_conversation(): void {
		$this->login( 'administrator' );
		$id                    = $this->send( 'Why is my LCP slow?' )->get_data()['conversation_id'];
		$this->client->answers = array(
			array(
				'title'                 => 'Preload the hero image',
				'goal'                  => 'LCP under 2.5 s.',
				'context'               => 'Astra theme.',
				'steps'                 => array( 'Add a preload link.' ),
				'files_likely_involved' => array( 'wp-content/mu-plugins/site-optimizations/preload.php' ),
				'acceptance_criteria'   => array( 'LCP < 2.5 s' ),
				'risks'                 => array(),
				'rollback'              => 'Revert the PR.',
			),
		);
		$response = $this->call(
			'POST',
			'/chat/task',
			array(
				'goal'            => 'Make the home page faster',
				'conversation_id' => $id,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Preload the hero image', $data['task']['title'] );
		$this->assertStringStartsWith( '# Preload the hero image', $data['markdown'] );
		$call = end( $this->client->calls );
		$this->assertSame( 'low', $call['opts']['effort'] );
		$this->assertStringContainsString( 'User: Why is my LCP slow?', $this->client->last_user_text() );
		$this->assertStringContainsString( 'Assistant: **LCP**', $this->client->last_user_text() );
	}

	public function test_user_deletion_removes_conversations(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user = $this->login( 'administrator' );
		$this->send( 'Hello' );
		$this->login( 'administrator' );
		wp_delete_user( $user );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'seoc_chat_messages WHERE user_id = %d', $user ) ) ); // phpcs:ignore
	}
}
