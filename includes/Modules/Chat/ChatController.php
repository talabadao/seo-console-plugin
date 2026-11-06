<?php
/**
 * REST routes of the AI Chat module (seo-console/v1/chat/...). All require manage_options, and every
 * conversation belongs to the current user only.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Chat;

use SEOConsole\Plugin;
use SEOConsole\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ChatController extends Controller {

	const ID_PATTERN = '[A-Za-z0-9_-]{8,64}';

	/**
	 * Module.
	 *
	 * @var ChatModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param Plugin     $plugin Plugin.
	 * @param ChatModule $module Module.
	 */
	public function __construct( Plugin $plugin, ChatModule $module ) {
		parent::__construct( $plugin );
		$this->module = $module;
	}

	/** {@inheritDoc} */
	public function register_routes(): void {
		$ns         = Plugin::REST_NAMESPACE;
		$permission = array( $this, 'can_manage' );
		$id_arg     = array(
			'id' => array(
				'type'    => 'string',
				'pattern' => '^' . self::ID_PATTERN . '$',
			),
		);

		register_rest_route(
			$ns,
			'/chat/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_conversations' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			$ns,
			'/chat/conversations/(?P<id>' . self::ID_PATTERN . ')',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_conversation' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			$ns,
			'/chat/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $permission,
				'args'                => array(
					'conversation_id' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^(' . self::ID_PATTERN . ')?$',
					),
					'text'            => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => Conversation::MAX_MESSAGE_CHARS * 2,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/chat/task',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'task' ),
				'permission_callback' => $permission,
				'args'                => array(
					'goal'            => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => TaskWriter::MAX_GOAL_CHARS,
					),
					'notes'           => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => TaskWriter::MAX_NOTES_CHARS,
					),
					'conversation_id' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^(' . self::ID_PATTERN . ')?$',
					),
				),
			)
		);
	}

	/**
	 * GET chat/conversations.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_conversations() {
		return $this->respond(
			array(
				'items'         => $this->module->conversation()->conversations( get_current_user_id(), 100 ),
				'ai_configured' => $this->plugin->claude()->is_available(),
				'ai_usage'      => $this->usage(),
			)
		);
	}

	/**
	 * GET chat/conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_conversation( WP_REST_Request $request ) {
		$id       = (string) $request['id'];
		$messages = $this->module->conversation()->messages( get_current_user_id(), $id );
		if ( ! $messages ) {
			return $this->respond( new WP_Error( 'seoc_chat_not_found', __( 'Conversation not found.', 'seo-console' ), array( 'status' => 404 ) ) );
		}
		return $this->respond(
			array(
				'id'       => $id,
				'messages' => $messages,
			)
		);
	}

	/**
	 * DELETE chat/conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_conversation( WP_REST_Request $request ) {
		$deleted = $this->module->conversation()->delete( get_current_user_id(), (string) $request['id'] );
		if ( is_wp_error( $deleted ) ) {
			return $this->respond( $deleted );
		}
		return $this->respond(
			array(
				'deleted'  => true,
				'messages' => $deleted,
			)
		);
	}

	/**
	 * POST chat/messages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $request ) {
		$result = $this->module->conversation()->send( get_current_user_id(), (string) $request['conversation_id'], (string) $request['text'] );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$result['ai_usage'] = $this->usage();
		return $this->respond( $result );
	}

	/**
	 * POST chat/task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function task( WP_REST_Request $request ) {
		$notes = (string) $request['notes'];
		$id    = (string) $request['conversation_id'];
		if ( '' === trim( $notes ) && '' !== $id ) {
			$notes = self::excerpt( $this->module->conversation()->messages( get_current_user_id(), $id ) );
		}
		$task = $this->module->task_writer()->write(
			(string) $request['goal'],
			array(
				'notes'  => $notes,
				'effort' => 'low',
			)
		);
		if ( is_wp_error( $task ) ) {
			return $this->respond( $task );
		}
		return $this->respond(
			array(
				'task'     => $task,
				'markdown' => TaskWriter::to_markdown( $task ),
			)
		);
	}

	/**
	 * The last messages of a conversation as notes for the task writer.
	 *
	 * @param array $messages Messages (output shape).
	 * @return string
	 */
	private static function excerpt( array $messages ): string {
		$lines = array();
		foreach ( array_slice( $messages, -6 ) as $message ) {
			$lines[] = ( 'assistant' === $message['role'] ? 'Assistant: ' : 'User: ' ) . mb_substr( $message['content'], 0, 3000 );
		}
		return implode( "\n\n", $lines );
	}

	/**
	 * This month's AI cost and budget.
	 *
	 * @return array{month_cost_usd: float, budget_usd: int}
	 */
	private function usage(): array {
		$usage = $this->plugin->usage();
		return array(
			'month_cost_usd' => round( $usage->month_cost(), 4 ),
			'budget_usd'     => $usage->budget(),
		);
	}
}
