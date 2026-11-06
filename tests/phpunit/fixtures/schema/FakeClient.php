<?php
/**
 * Fake AI client for Schema tests: returns a canned structured answer and records the request.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Fixtures\Schema;

use SEOConsole\AI\ClientInterface;

class FakeClient implements ClientInterface {

	/** @var array|\WP_Error */
	public $answer;

	/** @var bool */
	public $available = true;

	/** @var array[] Recorded calls: system, messages, schema, opts. */
	public $calls = array();

	/**
	 * @param array|\WP_Error $answer Answer returned by structured().
	 */
	public function __construct( $answer = array( 'candidates' => array() ) ) {
		$this->answer = $answer;
	}

	public function is_available(): bool {
		return $this->available;
	}

	public function structured( string $system, array $messages, array $json_schema, array $opts = array() ) {
		$this->calls[] = compact( 'system', 'messages', 'json_schema', 'opts' );
		return $this->answer;
	}

	public function chat( string $system, array $messages, array $opts = array() ) {
		return new \WP_Error( 'seoc_test', 'chat() is not used by the Schema module.' );
	}
}
