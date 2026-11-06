<?php
/**
 * A requested change to the site (see docs/ARCHITECTURE.md §7.1).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes;

defined( 'ABSPATH' ) || exit;

final class ChangeRequest {

	/** @var string Applier id, e.g. 'seo_meta'. */
	public $type;

	/** @var string 'post' | 'term' | 'attachment' | 'option' | 'site'. */
	public $object_type;

	/** @var int|null */
	public $object_id;

	/** @var string e.g. 'title', 'description', 'alt', 'schema', 'speed.disable_emojis'. */
	public $field;

	/** @var mixed New value (JSON-serialisable). */
	public $value;

	/** @var string 'user' | 'ai' | 'rule' | 'mcp' | 'system'. */
	public $source = 'user';

	/** @var string Module id. */
	public $module = '';

	/** @var int|null */
	public $suggestion_id;

	/** @var string */
	public $note = '';

	/**
	 * @param string   $type        Applier id.
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param string   $field       Field.
	 * @param mixed    $value       New value.
	 * @param array    $extra       Optional: source, module, suggestion_id, note.
	 */
	public function __construct( string $type, string $object_type, ?int $object_id, string $field, $value, array $extra = array() ) {
		$this->type        = $type;
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->field       = $field;
		$this->value       = $value;
		foreach ( array( 'source', 'module', 'suggestion_id', 'note' ) as $key ) {
			if ( array_key_exists( $key, $extra ) ) {
				$this->$key = $extra[ $key ];
			}
		}
	}
}
