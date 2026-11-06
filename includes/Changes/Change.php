<?php
/**
 * One row of the change log (see docs/ARCHITECTURE.md §7.2).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a `seoc_changes` row. Values are decoded from JSON.
 */
final class Change {

	/** @var int */
	public $id = 0;

	/** @var string UTC 'Y-m-d H:i:s'. */
	public $created_at = '';

	/** @var int */
	public $user_id = 0;

	/** @var string 'user' | 'ai' | 'rule' | 'mcp' | 'system'. */
	public $source = 'user';

	/** @var string */
	public $module = '';

	/** @var string Applier id. */
	public $type = '';

	/** @var string */
	public $object_type = '';

	/** @var int|null */
	public $object_id;

	/** @var string */
	public $field = '';

	/** @var mixed */
	public $before_value;

	/** @var mixed */
	public $after_value;

	/** @var string 'applied' | 'reverted' | 'failed'. */
	public $status = 'applied';

	/** @var string|null */
	public $error;

	/** @var int|null */
	public $suggestion_id;

	/** @var string|null */
	public $batch_id;

	/** @var string */
	public $note = '';

	/** @var string|null UTC 'Y-m-d H:i:s'. */
	public $reverted_at;

	/** @var int|null */
	public $reverted_by;

	/**
	 * Builds a Change from a database row.
	 *
	 * @param array|object $row Row.
	 */
	public static function from_row( $row ): self {
		$row    = (array) $row;
		$change = new self();

		$change->id            = (int) $row['id'];
		$change->created_at    = (string) $row['created_at'];
		$change->user_id       = (int) $row['user_id'];
		$change->source        = (string) $row['source'];
		$change->module        = (string) $row['module'];
		$change->type          = (string) $row['type'];
		$change->object_type   = (string) $row['object_type'];
		$change->object_id     = null === $row['object_id'] ? null : (int) $row['object_id'];
		$change->field         = (string) $row['field'];
		$change->before_value  = self::decode( $row['before_value'] );
		$change->after_value   = self::decode( $row['after_value'] );
		$change->status        = (string) $row['status'];
		$change->error         = null === $row['error'] ? null : (string) $row['error'];
		$change->suggestion_id = null === $row['suggestion_id'] ? null : (int) $row['suggestion_id'];
		$change->batch_id      = null === $row['batch_id'] ? null : (string) $row['batch_id'];
		$change->note          = (string) $row['note'];
		$change->reverted_at   = null === $row['reverted_at'] ? null : (string) $row['reverted_at'];
		$change->reverted_by   = null === $row['reverted_by'] ? null : (int) $row['reverted_by'];
		return $change;
	}

	/**
	 * Plain array of the stored fields (dates as stored, UTC).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * JSON-encodes a value for storage.
	 *
	 * @param mixed $value Value.
	 */
	public static function encode( $value ): string {
		$json = wp_json_encode( $value );
		return false === $json ? 'null' : $json;
	}

	/**
	 * Decodes a stored JSON value.
	 *
	 * @param string|null $json Stored value.
	 * @return mixed
	 */
	public static function decode( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}
		return json_decode( (string) $json, true );
	}

	/**
	 * Whether two values are the same for conflict/stale detection: null, '' and false are equal; scalars are
	 * compared as strings; arrays by their JSON with sorted keys.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 */
	public static function values_equal( $a, $b ): bool {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Normalises a value for comparison.
	 *
	 * @param mixed $value Value.
	 */
	private static function normalize( $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return '';
			}
			return (string) wp_json_encode( self::ksort_recursive( $value ) );
		}
		return '';
	}

	/**
	 * Sorts associative keys recursively.
	 *
	 * @param array $value Array.
	 */
	private static function ksort_recursive( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::ksort_recursive( $item );
			} elseif ( is_int( $item ) || is_float( $item ) ) {
				$value[ $key ] = (string) $item;
			}
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}
}
