<?php
/**
 * Shared helpers for extractors.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Types;
use SEOConsole\Modules\Schema\Validator;

defined( 'ABSPATH' ) || exit;

abstract class AbstractExtractor implements ExtractorInterface {

	/**
	 * Builds a candidate; required properties that are empty are listed in 'missing'.
	 *
	 * @param array    $node     Node.
	 * @param string   $evidence What was found.
	 * @param string[] $missing  Extra properties known to be missing.
	 * @return array
	 */
	protected function candidate( array $node, string $evidence, array $missing = array() ): array {
		$type = (string) Types::primary( $node['@type'] );
		foreach ( Types::required( $type ) as $prop ) {
			if ( ! Validator::filled( $node, $prop ) ) {
				$missing[] = $prop;
			}
		}
		return array(
			'type'     => $type,
			'node'     => self::prune( $node ),
			'source'   => 'rule',
			'evidence' => $evidence,
			'missing'  => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * Removes null / empty-string / empty-array values recursively.
	 *
	 * @param array $node Node.
	 * @return array
	 */
	public static function prune( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$value        = self::prune( $value );
				$node[ $key ] = $value;
			}
			if ( null === $value || '' === $value || array() === $value ) {
				unset( $node[ $key ] );
			}
		}
		return self::was_list( $node ) ? array_values( $node ) : $node;
	}

	/**
	 * Whether all keys are integers (a list that lost items while pruning).
	 *
	 * @param array $value Array.
	 */
	private static function was_list( array $value ): bool {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_int( $key ) ) {
				return false;
			}
		}
		return true;
	}
}
