<?php
/**
 * Wraps untrusted data (site facts, reports, pasted content) in tagged blocks for prompts.
 *
 * Same rule as Meta\Prompts::data_block() (kept separate because modules do not call each other's classes):
 * any of our tag names inside the data are neutralised, so data cannot close its block or open a fake one.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Chat;

defined( 'ABSPATH' ) || exit;

final class DataBlock {

	/** Reserved tag names. */
	const TAGS = array( 'site_facts', 'site_context', 'user_goal', 'notes', 'task', 'page_data', 'post_content' );

	/**
	 * Wraps data in a tag.
	 *
	 * @param string $tag  Tag name.
	 * @param string $data Untrusted data.
	 * @param array  $attr Attributes (values reduced to [a-z0-9_.-]).
	 * @return string
	 */
	public static function wrap( string $tag, string $data, array $attr = array() ): string {
		$attrs = '';
		foreach ( $attr as $name => $value ) {
			$attrs .= ' ' . preg_replace( '/[^a-z_]/', '', strtolower( (string) $name ) ) . '="' . preg_replace( '/[^a-z0-9_.-]/', '', strtolower( (string) $value ) ) . '"';
		}
		return '<' . $tag . $attrs . ">\n" . self::escape( $data ) . "\n</" . $tag . '>';
	}

	/**
	 * Neutralises reserved tag names inside data (< and > around them become ‹ and ›).
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public static function escape( string $data ): string {
		$names = implode( '|', array_map( 'preg_quote', self::TAGS ) );
		return (string) preg_replace_callback(
			'#<(\s*/?\s*)(' . $names . ')\b([^>]*)>?#i',
			static function ( $m ) {
				$close = '>' === substr( $m[0], -1 ) ? '›' : '';
				return '‹' . trim( $m[1] ) . $m[2] . $m[3] . $close;
			},
			$data
		);
	}
}
