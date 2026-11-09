<?php
/**
 * @package SEOConsole
 */

namespace SEOConsole\Tests\Integration\Core;

use SEOConsole\AI\Tools\Registry;
use SEOConsole\AI\Tools\Tool;
use SEOConsole\Plugin;

require_once __DIR__ . '/CoreTestCase.php';

class AiToolsRegistryTest extends CoreTestCase {

	/** @var callable */
	private $filter;

	public function set_up(): void {
		parent::set_up();
		$this->filter = static function ( $tools ) {
			$tools[] = new Tool(
				array(
					'name'         => 'core_echo',
					'description'  => 'Echoes the text.',
					'module'       => 'core',
					'input_schema' => array(
						'type'                 => 'object',
						'properties'           => array( 'text' => array( 'type' => 'string' ) ),
						'required'             => array( 'text' ),
						'additionalProperties' => false,
					),
					'callback'     => static function ( array $input ) {
						return array( 'text' => $input['text'], 'keys' => array_keys( $input ) );
					},
				)
			);
			$tools[] = new Tool(
				array(
					'name'        => 'core_big',
					'description' => 'Returns too much.',
					'module'      => 'core',
					'callback'    => static function () {
						return array( 'blob' => str_repeat( 'x', Registry::MAX_RESULT_BYTES + 10 ) );
					},
				)
			);
			$tools[] = new Tool(
				array(
					'name'        => 'ghost_tool',
					'description' => 'Belongs to a module that is not enabled.',
					'module'      => 'no_such_module',
					'callback'    => '__return_empty_array',
				)
			);
			$tools[] = new Tool( array( 'name' => 'Bad Name', 'description' => 'x', 'module' => 'core', 'callback' => '__return_empty_array' ) );
			$tools[] = 'not a tool';
			return $tools;
		};
		add_filter( 'seoc_ai_tools', $this->filter );
		Plugin::instance()->ai_tools()->reset();
	}

	public function tear_down(): void {
		remove_filter( 'seoc_ai_tools', $this->filter );
		Plugin::instance()->ai_tools()->reset();
		parent::tear_down();
	}

	public function test_only_valid_tools_of_enabled_modules_are_listed(): void {
		$names = array_keys( Plugin::instance()->ai_tools()->all() );
		$this->assertContains( 'core_echo', $names );
		$this->assertContains( 'core_big', $names );
		$this->assertNotContains( 'ghost_tool', $names );
		$this->assertNotContains( 'Bad Name', $names );
	}

	public function test_run_requires_manage_capability(): void {
		$this->login( 'subscriber' );
		$result = Plugin::instance()->ai_tools()->run( 'core_echo', array( 'text' => 'hi' ) );
		$this->assertSame( 'seoc_forbidden', $result->get_error_code() );
	}

	public function test_run_validates_input_and_drops_unknown_keys(): void {
		$this->login( 'administrator' );
		$registry = Plugin::instance()->ai_tools();
		$this->assertSame( 'seoc_ai_tool_input', $registry->run( 'core_echo', array() )->get_error_code() );
		$this->assertSame( 'seoc_ai_tool_unknown', $registry->run( 'nope', array() )->get_error_code() );
		$result = $registry->run( 'core_echo', array( 'text' => 'hi', 'evil' => 1 ) );
		$this->assertSame( array( 'text' => 'hi', 'keys' => array( 'text' ) ), $result );
	}

	public function test_oversized_results_are_refused(): void {
		$this->login( 'administrator' );
		$this->assertSame( 'seoc_ai_tool_too_large', Plugin::instance()->ai_tools()->run( 'core_big', array() )->get_error_code() );
	}
}
