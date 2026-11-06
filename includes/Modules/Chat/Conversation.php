<?php
/**
 * Chat conversations: per-user, append-only storage and the call to Claude.
 *
 * Stored messages are never edited (append-only): the history sent to Claude is a stable prefix, which keeps prompt
 * caching effective and matches what the user saw. Only the most recent turns are sent (see window()).
 * Content is stored as plain text (Markdown from Claude); escape it when rendering.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Chat;

use SEOConsole\AI\ClientInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Conversation {

	/** Table name without prefix. */
	const TABLE = 'seoc_chat_messages';

	/** Maximum turns (user + assistant pairs) sent to Claude. */
	const MAX_TURNS = 30;

	/** Turns dropped at once when the window moves, so the sent prefix stays stable for this many turns. */
	const TRIM_STEP = 10;

	/** Maximum characters in one user message. */
	const MAX_MESSAGE_CHARS = 8000;

	/** Maximum messages returned by messages(). */
	const MAX_LIST = 500;

	/** Note prepended to the first sent message when earlier turns are left out. */
	const TRIMMED_NOTE = '[Earlier messages in this conversation are not included.]';

	/**
	 * Claude client.
	 *
	 * @var ClientInterface
	 */
	private $client;

	/**
	 * Context builder.
	 *
	 * @var ContextBuilder
	 */
	private $context;

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface $client  Claude client.
	 * @param ContextBuilder  $context Context builder.
	 * @param array           $options model (string, stored when the client does not report one), max_tokens (int).
	 */
	public function __construct( ClientInterface $client, ContextBuilder $context, array $options = array() ) {
		$this->client  = $client;
		$this->context = $context;
		$this->options = wp_parse_args(
			$options,
			array(
				'model'      => '',
				'max_tokens' => 8000,
			)
		);
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * CREATE TABLE statement for dbDelta, with {prefix} and {charset} placeholders (ModuleInterface::tables()).
	 *
	 * @return string
	 */
	public static function table_sql(): string {
		return 'CREATE TABLE {prefix}' . self::TABLE . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id varchar(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role varchar(16) NOT NULL,
  content longtext NOT NULL,
  tokens_in int(10) unsigned NOT NULL DEFAULT 0,
  tokens_out int(10) unsigned NOT NULL DEFAULT 0,
  model varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_conversation (user_id,conversation_id,id),
  KEY conversation_id (conversation_id)
) {charset};";
	}

	/**
	 * Creates or updates the table (tests; Phase B moves this to the module's tables()).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = str_replace( array( '{prefix}', '{charset}' ), array( $wpdb->prefix, $wpdb->get_charset_collate() ), self::table_sql() );
		dbDelta( $sql );
	}

	/**
	 * A new conversation ID.
	 *
	 * @return string
	 */
	public static function new_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Whether a conversation ID has an acceptable shape.
	 *
	 * @param string $id ID.
	 * @return bool
	 */
	public static function valid_id( string $id ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $id );
	}

	/**
	 * Sends a user message, stores it with Claude's answer, and returns both.
	 *
	 * Nothing is stored when Claude fails, so a retry does not leave half a turn behind.
	 *
	 * @param int    $user_id         User ID (the conversation owner).
	 * @param string $conversation_id Conversation ID ('' starts a new conversation).
	 * @param string $text            User message.
	 * @return array|WP_Error [ 'conversation_id' => string, 'messages' => [ user row, assistant row ] ].
	 */
	public function send( int $user_id, string $conversation_id, string $text ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'seoc_chat_forbidden', __( 'You must be logged in to chat.', 'seo-console' ), array( 'status' => 403 ) );
		}
		if ( '' === $conversation_id ) {
			$conversation_id = self::new_id();
		}
		if ( ! self::valid_id( $conversation_id ) ) {
			return new WP_Error( 'seoc_chat_invalid_id', __( 'Invalid conversation.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$text = self::clean( $text );
		if ( '' === $text ) {
			return new WP_Error( 'seoc_chat_empty', __( 'The message is empty.', 'seo-console' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_MESSAGE_CHARS ) {
			return new WP_Error(
				'seoc_chat_too_long',
				/* translators: %d: maximum number of characters */
				sprintf( __( 'The message is too long (maximum %d characters).', 'seo-console' ), self::MAX_MESSAGE_CHARS ),
				array( 'status' => 400 )
			);
		}
		if ( $this->owned_by_other( $user_id, $conversation_id ) ) {
			return new WP_Error( 'seoc_chat_not_found', __( 'Conversation not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( ! $this->client->is_available() ) {
			return Errors::from_client( new WP_Error( 'seoc_ai_not_available', '' ) );
		}

		$history    = $this->rows( $user_id, $conversation_id );
		$messages   = self::window( $history );
		$messages[] = array(
			'role'    => 'user',
			'content' => $text,
		);

		$response = $this->client->chat(
			$this->context->system_prompt(),
			$messages,
			array(
				'effort'       => 'medium',
				'cache_system' => true,
				'max_tokens'   => (int) $this->options['max_tokens'],
				'context'      => 'chat',
			)
		);
		if ( is_wp_error( $response ) ) {
			return Errors::from_client( $response );
		}
		$answer = isset( $response['text'] ) && is_string( $response['text'] ) ? self::clean( $response['text'] ) : '';
		if ( '' === $answer ) {
			return Errors::from_client( new WP_Error( 'seoc_ai_empty', __( 'Claude returned an empty answer.', 'seo-console' ) ) );
		}
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$model = isset( $response['model'] ) && is_string( $response['model'] ) && '' !== $response['model'] ? $response['model'] : (string) $this->options['model'];

		$first_id = $this->insert_turn( $user_id, $conversation_id, $text, $answer, $usage, $model );
		if ( is_wp_error( $first_id ) ) {
			return $first_id;
		}
		return array(
			'conversation_id' => $conversation_id,
			'messages'        => array_map( array( __CLASS__, 'output' ), $this->rows_from( $user_id, $conversation_id, $first_id ) ),
		);
	}

	/**
	 * Messages of one conversation, oldest first, ready for output.
	 *
	 * @param int    $user_id         Owner.
	 * @param string $conversation_id Conversation ID.
	 * @return array[]
	 */
	public function messages( int $user_id, string $conversation_id ): array {
		if ( ! self::valid_id( $conversation_id ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'output' ), $this->rows( $user_id, $conversation_id ) );
	}

	/**
	 * The user's conversations, most recent first.
	 *
	 * @param int $user_id Owner.
	 * @param int $limit   Maximum (1–100).
	 * @return array[] [ id, title, message_count, created_at, updated_at ].
	 */
	public function conversations( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, per-user data.
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT conversation_id, MIN(id) AS first_id, MAX(id) AS last_id, COUNT(*) AS message_count, MIN(created_at) AS created_at, MAX(created_at) AS updated_at
				FROM %i WHERE user_id = %d GROUP BY conversation_id ORDER BY last_id DESC LIMIT %d',
				self::table(),
				$user_id,
				$limit
			),
			ARRAY_A
		);
		if ( ! $groups ) {
			return array();
		}
		$first_ids    = array_map( 'intval', wp_list_pluck( $groups, 'first_id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $first_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
		$firsts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, content FROM %i WHERE user_id = %d AND id IN ($placeholders)",
				array_merge( array( self::table(), $user_id ), $first_ids )
			),
			OBJECT_K
		);
		// phpcs:enable

		$out = array();
		foreach ( $groups as $group ) {
			$first = isset( $firsts[ $group['first_id'] ] ) ? (string) $firsts[ $group['first_id'] ]->content : '';
			$title = trim( (string) preg_replace( '/\s+/u', ' ', $first ) );
			if ( mb_strlen( $title, 'UTF-8' ) > 80 ) {
				$title = rtrim( mb_substr( $title, 0, 79, 'UTF-8' ) ) . '…';
			}
			$out[] = array(
				'id'            => (string) $group['conversation_id'],
				'title'         => $title,
				'message_count' => (int) $group['message_count'],
				'created_at'    => self::iso( (string) $group['created_at'] ),
				'updated_at'    => self::iso( (string) $group['updated_at'] ),
			);
		}
		return $out;
	}

	/**
	 * Deletes a conversation of the user.
	 *
	 * @param int    $user_id         Owner.
	 * @param string $conversation_id Conversation ID.
	 * @return int|WP_Error Number of deleted messages.
	 */
	public function delete( int $user_id, string $conversation_id ) {
		global $wpdb;
		if ( ! self::valid_id( $conversation_id ) ) {
			return new WP_Error( 'seoc_chat_invalid_id', __( 'Invalid conversation.', 'seo-console' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$deleted = $wpdb->delete(
			self::table(),
			array(
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
			),
			array( '%d', '%s' )
		);
		if ( false === $deleted ) {
			return new WP_Error( 'seoc_chat_db', __( 'The conversation could not be deleted.', 'seo-console' ), array( 'status' => 500 ) );
		}
		if ( 0 === $deleted ) {
			return new WP_Error( 'seoc_chat_not_found', __( 'Conversation not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		return (int) $deleted;
	}

	/**
	 * Deletes every conversation of a user (for the delete_user hook / privacy erasure).
	 *
	 * @param int $user_id User ID.
	 * @return int Number of deleted messages.
	 */
	public static function delete_user_data( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Messages to send to Claude from stored rows: complete user→assistant turns only, the most recent ones.
	 *
	 * When there are more than MAX_TURNS turns, the window start moves forward in steps of TRIM_STEP turns, so the
	 * same prefix is sent for several consecutive messages (cache-friendly). Earlier turns are never modified in
	 * storage; when some are left out, a short note is prepended to the first sent message.
	 *
	 * @param array $rows Stored rows (role, content), oldest first.
	 * @return array Messages API messages.
	 */
	public static function window( array $rows ): array {
		$turns = array();
		$count = count( $rows );
		for ( $i = 0; $i < $count; $i++ ) {
			$row  = (array) $rows[ $i ];
			$next = $i + 1 < $count ? (array) $rows[ $i + 1 ] : null;
			if ( 'user' === $row['role'] && $next && 'assistant' === $next['role'] ) {
				$turns[] = array( (string) $row['content'], (string) $next['content'] );
				++$i;
			}
		}

		$total = count( $turns );
		$start = 0;
		if ( $total > self::MAX_TURNS ) {
			$start = (int) ( ceil( ( $total - self::MAX_TURNS ) / self::TRIM_STEP ) * self::TRIM_STEP );
		}
		$messages = array();
		foreach ( array_slice( $turns, $start ) as $index => $turn ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => ( 0 === $index && $start > 0 ) ? self::TRIMMED_NOTE . "\n\n" . $turn[0] : $turn[0],
			);
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $turn[1],
			);
		}
		return $messages;
	}

	/**
	 * A stored row prepared for output (REST): typed fields, ISO 8601 UTC time, cleaned plain-text content.
	 * The client must still render content as escaped Markdown, never as raw HTML.
	 *
	 * @param array|object $row Row.
	 * @return array
	 */
	public static function output( $row ): array {
		$row = (array) $row;
		return array(
			'id'              => (int) $row['id'],
			'conversation_id' => (string) $row['conversation_id'],
			'role'            => 'assistant' === $row['role'] ? 'assistant' : 'user',
			'content'         => self::clean( (string) $row['content'] ),
			'tokens_in'       => (int) $row['tokens_in'],
			'tokens_out'      => (int) $row['tokens_out'],
			'model'           => (string) $row['model'],
			'created_at'      => self::iso( (string) $row['created_at'] ),
		);
	}

	/**
	 * Plain text: valid UTF-8, no control characters except tab and newline, normalised newlines, trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		return trim( $text );
	}

	/**
	 * Whether the conversation ID already belongs to another user.
	 *
	 * @param int    $user_id         User.
	 * @param string $conversation_id Conversation ID.
	 * @return bool
	 */
	private function owned_by_other( int $user_id, string $conversation_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$other = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE conversation_id = %s AND user_id <> %d LIMIT 1', self::table(), $conversation_id, $user_id )
		);
		return null !== $other;
	}

	/**
	 * Stored rows of a conversation, oldest first.
	 *
	 * @param int    $user_id         Owner.
	 * @param string $conversation_id Conversation ID.
	 * @return array[]
	 */
	private function rows( int $user_id, string $conversation_id ): array {
		global $wpdb;
		// The newest MAX_LIST rows, returned oldest first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND conversation_id = %s ORDER BY id DESC LIMIT %d',
				self::table(),
				$user_id,
				$conversation_id,
				self::MAX_LIST
			),
			ARRAY_A
		);
		return array_reverse( (array) $rows );
	}

	/**
	 * The two rows of a turn just inserted (IDs are not assumed consecutive: auto_increment_increment may differ).
	 *
	 * @param int    $user_id         User.
	 * @param string $conversation_id Conversation.
	 * @param int    $first_id        ID of the user row.
	 * @return array[]
	 */
	private function rows_from( int $user_id, string $conversation_id, int $first_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND conversation_id = %s AND id >= %d ORDER BY id ASC LIMIT 2',
				self::table(),
				$user_id,
				$conversation_id,
				$first_id
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/**
	 * Inserts the user message and the answer in one statement, so the pair stays adjacent.
	 *
	 * @param int    $user_id         User.
	 * @param string $conversation_id Conversation.
	 * @param string $question        User text.
	 * @param string $answer          Assistant text.
	 * @param array  $usage           Usage from the client (input_tokens, output_tokens, cache_* tokens).
	 * @param string $model           Model.
	 * @return int|WP_Error ID of the user row.
	 */
	private function insert_turn( int $user_id, string $conversation_id, string $question, string $answer, array $usage, string $model ) {
		global $wpdb;
		$tokens_in  = 0;
		$tokens_out = isset( $usage['output_tokens'] ) ? absint( $usage['output_tokens'] ) : 0;
		foreach ( array( 'input_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens' ) as $key ) {
			$tokens_in += isset( $usage[ $key ] ) ? absint( $usage[ $key ] ) : 0;
		}
		$now   = current_time( 'mysql', true );
		$model = substr( sanitize_text_field( $model ), 0, 64 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (conversation_id, user_id, role, content, tokens_in, tokens_out, model, created_at)
				VALUES (%s, %d, %s, %s, %d, %d, %s, %s), (%s, %d, %s, %s, %d, %d, %s, %s)',
				self::table(),
				$conversation_id,
				$user_id,
				'user',
				$question,
				0,
				0,
				'',
				$now,
				$conversation_id,
				$user_id,
				'assistant',
				$answer,
				$tokens_in,
				$tokens_out,
				$model,
				$now
			)
		);
		if ( 2 !== $ok ) {
			return new WP_Error( 'seoc_chat_db', __( 'The message could not be saved.', 'seo-console' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id; // MySQL returns the ID of the first row of a multi-row insert.
	}

	/**
	 * MySQL UTC datetime → ISO 8601.
	 *
	 * @param string $datetime Datetime.
	 * @return string
	 */
	private static function iso( string $datetime ): string {
		$time = strtotime( $datetime . ' UTC' );
		return false === $time ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
