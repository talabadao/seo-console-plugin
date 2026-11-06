<?php
/**
 * What modules need from the Claude client (see docs/ARCHITECTURE.md §10). The concrete
 * implementation is AI\ClaudeClient; tests can pass a fake implementing this interface.
 *
 * @package SEOConsole
 */

namespace SEOConsole\AI;

defined( 'ABSPATH' ) || exit;

interface ClientInterface {

	/** Whether an API key is configured and the monthly budget is not exhausted. */
	public function is_available(): bool;

	/**
	 * One request whose answer must match a JSON schema.
	 *
	 * @param string $system      System prompt (stable; cacheable).
	 * @param array  $messages    Messages API messages: [ [ 'role' => 'user', 'content' => string|array ], ... ].
	 * @param array  $json_schema JSON schema of the expected object (additionalProperties false, required set).
	 * @param array  $opts        effort ('low'|'medium'|'high'), max_tokens, cache_system (bool), context (string, for usage logs).
	 * @return array|\WP_Error Decoded object.
	 */
	public function structured( string $system, array $messages, array $json_schema, array $opts = array() );

	/**
	 * A free-text answer.
	 *
	 * @param string $system   System prompt.
	 * @param array  $messages Messages API messages.
	 * @param array  $opts     Same options as structured().
	 * @return array|\WP_Error [ 'text' => string, 'usage' => array, 'model' => string ].
	 */
	public function chat( string $system, array $messages, array $opts = array() );
}
