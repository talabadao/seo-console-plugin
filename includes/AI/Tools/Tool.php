<?php
/**
 * One tool the AI can call (see docs/ARCHITECTURE.md §17.1). Used by the Chat module and by the
 * Abilities/MCP bridge, so a tool is written once per module.
 *
 * @package SEOConsole
 */

namespace SEOConsole\AI\Tools;

defined( 'ABSPATH' ) || exit;

final class Tool {

	const KIND_READ    = 'read';
	const KIND_PROPOSE = 'propose';

	/** @var string Unique name, lowercase a-z, 0-9 and underscores, at most 64 chars, prefixed with the module id. */
	public $name;

	/** @var string What the tool returns and when to use it (written for the model, English). */
	public $description;

	/** @var array JSON schema (type object) of the input. */
	public $input_schema;

	/**
	 * @var string 'read' returns data only. 'propose' may create Suggestions (through SuggestionService) that a
	 *             person approves; a tool never changes the site itself.
	 */
	public $kind;

	/** @var string Module id that owns the tool. */
	public $module;

	/** @var string Short human label (translated) for the UI, e.g. "Read audit issues". */
	public $label;

	/** @var callable function ( array $input ): array|\WP_Error — JSON-serialisable result. */
	public $callback;

	/**
	 * @param array $args name, description, input_schema, kind, module, label, callback.
	 */
	public function __construct( array $args ) {
		$this->name         = isset( $args['name'] ) ? (string) $args['name'] : '';
		$this->description  = isset( $args['description'] ) ? (string) $args['description'] : '';
		$this->input_schema = isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ? $args['input_schema'] : array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
		$this->kind         = isset( $args['kind'] ) && self::KIND_PROPOSE === $args['kind'] ? self::KIND_PROPOSE : self::KIND_READ;
		$this->module       = isset( $args['module'] ) ? (string) $args['module'] : '';
		$this->label        = isset( $args['label'] ) ? (string) $args['label'] : $this->name;
		$this->callback     = isset( $args['callback'] ) ? $args['callback'] : null;
	}

	/** Whether the definition is usable. */
	public function is_valid(): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $this->name )
			&& '' !== $this->description
			&& '' !== $this->module
			&& is_callable( $this->callback );
	}
}
