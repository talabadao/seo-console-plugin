<?php
/**
 * Claude Messages API client over wp_remote_post (see docs/ARCHITECTURE.md §10).
 *
 * @package SEOConsole
 */

namespace SEOConsole\AI;

use SEOConsole\Core\Logger;
use SEOConsole\Core\Secrets;
use SEOConsole\Core\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * No SDK (keeps PHP 7.4 support and avoids bundling Guzzle/PSR packages). Never sends temperature/top_p/top_k or a
 * `thinking` parameter (thinking cannot be disabled on Claude Opus 5.5; depth is set with output_config.effort).
 */
class ClaudeClient implements ClientInterface {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	const API_VERSION = '2026-10-21';

	const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

	/** Models that get server-side refusal fallbacks. */
	const FALLBACK_MODELS = array( 'claude-opus-5-5', 'claude-sonnet-5-5' );

	const MODELS = array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5' );

	const EFFORTS = array( 'low', 'medium', 'high', 'xhigh', 'max' );

	const DEFAULT_MAX_TOKENS = 16000;

	const TIMEOUT = 120;

	const MAX_RETRIES = 2;

	const MAX_RETRY_WAIT = 10;

	/**
	 * Secrets.
	 *
	 * @var Secrets
	 */
	private $secrets;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Usage tracker.
	 *
	 * @var Usage
	 */
	private $usage;

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Sleeps between retries; replaceable in tests.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Secrets     $secrets  Secrets.
	 * @param Settings    $settings Settings.
	 * @param Usage       $usage    Usage tracker.
	 * @param Logger|null $logger   Logger.
	 */
	public function __construct( Secrets $secrets, Settings $settings, Usage $usage, ?Logger $logger = null ) {
		$this->secrets  = $secrets;
		$this->settings = $settings;
		$this->usage    = $usage;
		$this->logger   = $logger;
		$this->sleeper  = static function ( int $seconds ) {
			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		};
	}

	/**
	 * Replaces the retry sleeper (tests).
	 *
	 * @param callable $sleeper function( int $seconds ).
	 */
	public function set_sleeper( callable $sleeper ): void {
		$this->sleeper = $sleeper;
	}

	/**
	 * The usage tracker.
	 */
	public function usage(): Usage {
		return $this->usage;
	}

	/** {@inheritDoc} */
	public function is_available(): bool {
		return $this->secrets->has( 'claude_api_key' ) && $this->usage->within_budget();
	}

	/**
	 * Configured model.
	 */
	public function model(): string {
		$model = (string) $this->settings->get( Settings::GENERAL, 'ai_model' );
		return in_array( $model, self::MODELS, true ) ? $model : self::MODELS[0];
	}

	/** {@inheritDoc} */
	public function structured( string $system, array $messages, array $json_schema, array $opts = array() ) {
		$opts   = array_merge( array( 'effort' => 'low' ), $opts );
		$result = $this->request( $system, $messages, $opts, $json_schema );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$decoded = json_decode( $result['text'], true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'seoc_ai_invalid_json', __( 'The AI answer was not valid JSON.', 'seo-console' ), array( 'status' => 502 ) );
		}
		return $decoded;
	}

	/** {@inheritDoc} */
	public function chat( string $system, array $messages, array $opts = array() ) {
		$opts   = array_merge( array( 'effort' => 'medium' ), $opts );
		$result = $this->request( $system, $messages, $opts, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'text'  => $result['text'],
			'usage' => $result['usage'],
			'model' => $result['model'],
		);
	}

	/**
	 * One tiny request to verify the key and model (ignores the monthly budget).
	 *
	 * @param string|null $model Model to test (default: configured model).
	 * @return array{ok: bool, model: string, latency_ms: int}|WP_Error
	 */
	public function test_connection( ?string $model = null ) {
		$start  = microtime( true );
		$result = $this->request(
			'You check that an API connection works.',
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word OK.',
				),
			),
			array(
				'effort'       => 'low',
				'max_tokens'   => 1024,
				'model'        => $model,
				'context'      => 'test_connection',
				'skip_budget'  => true,
				'allow_cutoff' => true,
			),
			null
		);
		if ( is_wp_error( $result ) && 'seoc_ai_refusal' !== $result->get_error_code() ) {
			return $result;
		}
		return array(
			'ok'         => true,
			'model'      => is_wp_error( $result ) ? (string) $model : $result['model'],
			'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * Builds the request body (exposed for tests and debugging; contains no secret).
	 *
	 * @param string     $system      System prompt.
	 * @param array      $messages    Messages.
	 * @param array      $opts        Options.
	 * @param array|null $json_schema Schema for structured output.
	 * @return array
	 */
	public function build_body( string $system, array $messages, array $opts, ?array $json_schema ): array {
		$model  = isset( $opts['model'] ) && in_array( $opts['model'], self::MODELS, true ) ? $opts['model'] : $this->model();
		$effort = isset( $opts['effort'] ) && in_array( $opts['effort'], self::EFFORTS, true ) ? $opts['effort'] : 'low';

		$system_block = array(
			'type' => 'text',
			'text' => $system,
		);
		if ( ! empty( $opts['cache_system'] ) ) {
			$system_block['cache_control'] = array( 'type' => 'ephemeral' );
		}

		$output_config = array( 'effort' => $effort );
		if ( null !== $json_schema ) {
			$output_config['format'] = array(
				'type'   => 'json_schema',
				'schema' => $json_schema,
			);
		}

		$body = array(
			'model'         => $model,
			'max_tokens'    => isset( $opts['max_tokens'] ) ? max( 1, (int) $opts['max_tokens'] ) : self::DEFAULT_MAX_TOKENS,
			'system'        => '' === $system ? array() : array( $system_block ),
			'messages'      => array_values( $messages ),
			'output_config' => $output_config,
		);
		if ( '' === $system ) {
			unset( $body['system'] );
		}
		if ( in_array( $model, self::FALLBACK_MODELS, true ) ) {
			$body['fallbacks'] = 'default';
		}
		return $body;
	}

	/**
	 * Sends a request with retries and budget/usage handling.
	 *
	 * @param string     $system      System prompt.
	 * @param array      $messages    Messages.
	 * @param array      $opts        Options.
	 * @param array|null $json_schema Schema for structured output.
	 * @return array{text: string, usage: array, model: string, stop_reason: string}|WP_Error
	 */
	private function request( string $system, array $messages, array $opts, ?array $json_schema ) {
		$key = $this->secrets->get( 'claude_api_key' );
		if ( null === $key ) {
			return new WP_Error( 'seoc_ai_no_key', __( 'Add your Claude API key in SEO Console → Settings first.', 'seo-console' ), array( 'status' => 400 ) );
		}
		if ( empty( $opts['skip_budget'] ) ) {
			$budget = $this->usage->budget();
			if ( $budget <= 0 ) {
				return new WP_Error(
					'seoc_ai_budget',
					__( 'AI features are turned off (the monthly AI budget is 0).', 'seo-console' ),
					array(
						'status' => 402,
						'reason' => 'disabled',
					)
				);
			}
			if ( $this->usage->month_cost() >= $budget ) {
				return new WP_Error(
					'seoc_ai_budget',
					__( 'This month\'s AI budget is used up. Raise it in Settings or wait until next month.', 'seo-console' ),
					array(
						'status' => 402,
						'reason' => 'exhausted',
					)
				);
			}
		}
		if ( empty( $messages ) ) {
			return new WP_Error( 'seoc_ai_request', __( 'No messages to send.', 'seo-console' ), array( 'status' => 400 ) );
		}

		$body    = $this->build_body( $system, $messages, $opts, $json_schema );
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);
		if ( isset( $body['fallbacks'] ) ) {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
		}
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		);
		$meta = array(
			'module'  => isset( $opts['module'] ) ? (string) $opts['module'] : '',
			'context' => isset( $opts['context'] ) ? (string) $opts['context'] : '',
		);

		$attempt = 0;
		while ( true ) {
			$response = wp_remote_post( self::ENDPOINT, $args );
			if ( is_wp_error( $response ) ) {
				$this->log( 'warning', 'Claude request failed (network).', array( 'error' => $response->get_error_message() ) );
				return new WP_Error(
					'seoc_ai_network',
					/* translators: %s: error message */
					sprintf( __( 'Could not reach the Claude API: %s', 'seo-console' ), $response->get_error_message() ),
					array( 'status' => 502 )
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( ( 429 === $code || $code >= 500 ) && $attempt < self::MAX_RETRIES ) {
				++$attempt;
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait        = is_numeric( $retry_after ) ? (int) ceil( (float) $retry_after ) : $attempt;
				call_user_func( $this->sleeper, max( 0, min( self::MAX_RETRY_WAIT, $wait ) ) );
				continue;
			}
			break;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( 200 !== $code ) {
			return $this->http_error( $code, is_array( $data ) ? $data : array() );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'seoc_ai_invalid_response', __( 'The Claude API returned an unreadable answer.', 'seo-console' ), array( 'status' => 502 ) );
		}

		$model              = isset( $data['model'] ) ? (string) $data['model'] : $body['model'];
		$usage              = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$stop_reason        = isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '';
		$meta['request_id'] = isset( $data['id'] ) ? (string) $data['id'] : '';

		if ( 'refusal' === $stop_reason ) {
			$this->usage->record( $model, $usage, array_merge( $meta, array( 'status' => 'refusal' ) ) );
			$category = isset( $data['stop_details']['category'] ) ? (string) $data['stop_details']['category'] : '';
			return new WP_Error(
				'seoc_ai_refusal',
				__( 'Claude declined this request.', 'seo-console' ),
				array(
					'status'   => 422,
					'category' => $category,
				)
			);
		}
		if ( 'max_tokens' === $stop_reason && empty( $opts['allow_cutoff'] ) ) {
			$this->usage->record( $model, $usage, array_merge( $meta, array( 'status' => 'truncated' ) ) );
			return new WP_Error( 'seoc_ai_truncated', __( 'The AI answer was cut off (too long).', 'seo-console' ), array( 'status' => 502 ) );
		}
		$this->usage->record( $model, $usage, array_merge( $meta, array( 'status' => 'ok' ) ) );

		$text = null;
		foreach ( isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array() as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text = (string) $block['text'];
				break;
			}
		}
		if ( null === $text ) {
			if ( ! empty( $opts['allow_cutoff'] ) ) {
				$text = '';
			} else {
				return new WP_Error( 'seoc_ai_invalid_response', __( 'The Claude API answer had no text.', 'seo-console' ), array( 'status' => 502 ) );
			}
		}
		return array(
			'text'        => $text,
			'usage'       => $usage,
			'model'       => $model,
			'stop_reason' => $stop_reason,
		);
	}

	/**
	 * Maps a non-200 response to a WP_Error.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded error body.
	 */
	private function http_error( int $code, array $data ): WP_Error {
		$api_message = isset( $data['error']['message'] ) ? sanitize_text_field( (string) $data['error']['message'] ) : '';
		$api_type    = isset( $data['error']['type'] ) ? sanitize_key( (string) $data['error']['type'] ) : '';
		$this->log(
			'warning',
			'Claude request failed.',
			array(
				'http_status' => $code,
				'type'        => $api_type,
				'message'     => $api_message,
			)
		);
		$extra = array(
			'status'      => 401 === $code || 403 === $code ? 400 : 502,
			'http_status' => $code,
			'api_type'    => $api_type,
		);
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'seoc_ai_auth', __( 'The Claude API key was rejected. Check it in Settings.', 'seo-console' ), $extra );
		}
		if ( 429 === $code || $code >= 500 ) {
			$extra['status'] = 503;
			return new WP_Error( 'seoc_ai_unavailable', __( 'The Claude API is busy or unavailable. Try again in a few minutes.', 'seo-console' ), $extra );
		}
		$extra['status'] = 400;
		return new WP_Error(
			'seoc_ai_request',
			/* translators: %s: API error message */
			'' !== $api_message ? sprintf( __( 'The Claude API rejected the request: %s', 'seo-console' ), $api_message ) : __( 'The Claude API rejected the request.', 'seo-console' ),
			$extra
		);
	}

	/**
	 * Logs (never the key: Logger redacts key-like values too).
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger ) {
			$this->logger->log( $level, $message, $context, 'ai' );
		}
	}
}
