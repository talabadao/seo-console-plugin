<?php
/**
 * Small HTML helpers on top of DOMDocument: parse a fragment, read plain text, normalise text for
 * "is this visible on the page" comparisons, and find JSON-LD / microdata.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Html {

	/**
	 * Parses an HTML fragment. Returns the <body> element that wraps it, or null when DOM is unavailable.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMElement|null
	 */
	public static function body( string $html ): ?\DOMElement {
		if ( ! class_exists( '\DOMDocument' ) || '' === trim( $html ) ) {
			return null;
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		return $body instanceof \DOMElement ? $body : null;
	}

	/**
	 * Plain text of a node with block-level boundaries turned into spaces and whitespace collapsed.
	 *
	 * @param \DOMNode $node Node.
	 */
	public static function text( \DOMNode $node ): string {
		$doc = $node->ownerDocument;
		if ( ! $doc ) {
			return self::clean_text( $node->textContent );
		}
		return self::clean_text( wp_strip_all_tags( str_replace( '<', ' <', (string) $doc->saveHTML( $node ) ) ) );
	}

	/**
	 * Plain text from an HTML string.
	 *
	 * @param string $html HTML.
	 */
	public static function to_text( string $html ): string {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		return self::clean_text( wp_strip_all_tags( str_replace( '<', ' <', (string) $html ) ) );
	}

	/**
	 * Decodes entities and collapses whitespace.
	 *
	 * @param string $text Text.
	 */
	public static function clean_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		// Punctuation left floating by the tag→space conversion ("word ." → "word.").
		return (string) preg_replace( '/\s+([.,;:!?)])/u', '$1', $text );
	}

	/**
	 * Normalises text for containment checks: lower case, unified quotes/dashes, no punctuation spacing.
	 *
	 * @param string $text Text (HTML allowed).
	 */
	public static function normalize( string $text ): string {
		$text = self::to_text( $text );
		$text = str_replace(
			array( "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}" ),
			array( "'", "'", '"', '"', '-', '-', '...' ),
			$text
		);
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}\'"?.,\-]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Whether $needle (normalised) appears in $haystack (normalised).
	 *
	 * @param string $needle   Text to find.
	 * @param string $haystack Page content (HTML or text).
	 */
	public static function contains( string $needle, string $haystack ): bool {
		$needle = self::normalize( $needle );
		return '' !== $needle && false !== strpos( self::normalize( $haystack ), $needle );
	}

	/**
	 * Whether a string contains HTML markup.
	 *
	 * @param string $text Text.
	 */
	public static function has_html( string $text ): bool {
		return 1 === preg_match( '#<\s*/?\s*[a-z][a-z0-9-]*(\s[^>]*)?/?\s*>|<!--#i', $text );
	}

	/**
	 * Decoded JSON-LD blocks in HTML. Scripts with class "seoc-schema" (our own output) are skipped.
	 *
	 * @param string $html HTML.
	 * @return array[] Decoded JSON values.
	 */
	public static function json_ld_blocks( string $html ): array {
		$out = array();
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $matches as $match ) {
			if ( ! preg_match( '#type\s*=\s*["\']?application/ld\+json#i', $match[1] ) ) {
				continue;
			}
			if ( preg_match( '#class\s*=\s*["\'][^"\']*\bseoc-schema\b#i', $match[1] ) ) {
				continue;
			}
			$decoded = json_decode( trim( html_entity_decode( $match[2], ENT_NOQUOTES, 'UTF-8' ) ), true );
			if ( is_array( $decoded ) ) {
				$out[] = $decoded;
			}
		}
		return $out;
	}

	/**
	 * schema.org types declared with microdata (itemtype) or RDFa (typeof with a schema.org vocab).
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function microdata_types( string $html ): array {
		$types = array();
		if ( preg_match_all( '#itemtype\s*=\s*["\']\s*https?://schema\.org/([A-Za-z]+)#i', $html, $m ) ) {
			$types = array_merge( $types, $m[1] );
		}
		if ( preg_match( '#vocab\s*=\s*["\']https?://schema\.org/?["\']#i', $html ) && preg_match_all( '#typeof\s*=\s*["\']([A-Za-z]+)["\']#', $html, $m ) ) {
			$types = array_merge( $types, $m[1] );
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Heading level of an element (1–6) or 0 when it is not a heading.
	 *
	 * @param \DOMNode $node Node.
	 */
	public static function heading_level( \DOMNode $node ): int {
		if ( XML_ELEMENT_NODE === $node->nodeType && preg_match( '/^h([1-6])$/i', $node->nodeName, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Element children (skips text and comment nodes).
	 *
	 * @param \DOMNode $node Parent.
	 * @param string   $tag  Optional tag filter (lower case).
	 * @return \DOMElement[]
	 */
	public static function children( \DOMNode $node, string $tag = '' ): array {
		$out = array();
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && ( '' === $tag || strtolower( $child->nodeName ) === $tag ) ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Flattens wrapper elements (div/section/article/figure without heading semantics) so heading
	 * siblings can be walked in document order. Returns block-level elements in order.
	 *
	 * @param \DOMNode $root Root.
	 * @return \DOMElement[]
	 */
	public static function flat_blocks( \DOMNode $root ): array {
		$out = array();
		foreach ( self::children( $root ) as $child ) {
			$tag = strtolower( $child->nodeName );
			if ( in_array( $tag, array( 'div', 'section', 'article', 'main', 'aside' ), true ) ) {
				$out = array_merge( $out, self::flat_blocks( $child ) );
			} else {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Shortens text to $max characters at a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 */
	public static function truncate( string $text, int $max ): string {
		if ( self::length( $text ) <= $max ) {
			return $text;
		}
		$cut   = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, ' ,;:-' ) . '…';
	}

	/**
	 * Character length (multibyte-aware).
	 *
	 * @param string $text Text.
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
