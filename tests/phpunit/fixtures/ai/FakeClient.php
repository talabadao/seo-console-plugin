<?php
/**
 * Fake Claude client for tests: returns queued canned answers and records every call.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Fixtures\AI;

use SEOConsole\AI\ClientInterface;

class FakeClient implements ClientInterface {

	/**
	 * Recorded calls: [ 'method', 'system', 'messages', 'schema', 'opts' ].
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Queued answers (array|WP_Error|callable(array $call)), consumed in order. The last one repeats.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Value of is_available().
	 *
	 * @var bool
	 */
	public $available = true;

	/**
	 * Constructor.
	 *
	 * @param array $answers Queued answers.
	 */
	public function __construct( array $answers = array() ) {
		$this->answers = $answers;
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function structured( string $system, array $messages, array $json_schema, array $opts = array() ) {
		return $this->answer( 'structured', $system, $messages, $json_schema, $opts );
	}

	public function chat( string $system, array $messages, array $opts = array() ) {
		return $this->answer( 'chat', $system, $messages, array(), $opts );
	}

	/** Text of the last user message sent. */
	public function last_user_text(): string {
		$call = end( $this->calls );
		$last = end( $call['messages'] );
		return is_string( $last['content'] ) ? $last['content'] : wp_json_encode( $last['content'] );
	}

	private function answer( string $method, string $system, array $messages, array $schema, array $opts ) {
		$call          = compact( 'method', 'system', 'messages', 'schema', 'opts' );
		$this->calls[] = $call;
		$answer        = count( $this->answers ) > 1 ? array_shift( $this->answers ) : reset( $this->answers );
		if ( is_callable( $answer ) ) {
			return $answer( $call );
		}
		return false === $answer ? array() : $answer;
	}
}
