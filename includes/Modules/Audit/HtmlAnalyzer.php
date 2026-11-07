<?php
/**
 * Extracts SEO facts from one HTML document.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties.

/**
 * Stateless HTML analyzer built on DOMDocument.
 */
class HtmlAnalyzer {

	/** Max entries kept in list fields. */
	const LIST_CAP = 20;

	/** Elements ignored when counting words. */
	const NON_CONTENT = array( 'script', 'style', 'noscript', 'template', 'svg', 'nav', 'footer', 'header', 'aside', 'form', 'iframe', 'select', 'button', 'canvas', 'object' );

	/**
	 * Analyzes a document.
	 *
	 * @param string $html    Raw HTML (any charset).
	 * @param string $url     Absolute URL of the document (base for relative links).
	 * @param array  $headers Lower-case response headers (content-type, x-robots-tag).
	 * @return array Facts; see keys below.
	 */
	public function analyze( string $html, string $url, array $headers = array() ): array {
		$facts = self::empty_facts();

		$content_type      = isset( $headers['content-type'] ) ? (string) $headers['content-type'] : '';
		$facts['x_robots'] = isset( $headers['x-robots-tag'] ) ? self::squash( (string) $headers['x-robots-tag'] ) : '';
		$charset           = self::detect_charset( $html, $content_type );
		$facts['charset']  = $charset['declared'];
		$html              = self::to_utf8( $html, $charset['effective'] );

		$doc = self::load( $html );
		if ( ! $doc ) {
			$facts['parse_error'] = true;
			$facts               += self::robots_flags( '', $facts['x_robots'] );
			return $facts;
		}
		$xpath = new DOMXPath( $doc );

		// Base URL.
		$base = $url;
		$node = $xpath->query( '//base[@href]' )->item( 0 );
		if ( $node instanceof DOMElement ) {
			$resolved = Url::resolve( $node->getAttribute( 'href' ), $url );
			if ( '' !== $resolved ) {
				$base = $resolved;
			}
		}
		$facts['base'] = $base;

		// <html lang>.
		$html_el = $doc->getElementsByTagName( 'html' )->item( 0 );
		if ( $html_el instanceof DOMElement ) {
			$facts['lang'] = trim( $html_el->getAttribute( 'lang' ) );
			if ( '' === $facts['lang'] ) {
				$facts['lang'] = trim( $html_el->getAttribute( 'xml:lang' ) );
			}
		}

		// Title.
		$titles               = $doc->getElementsByTagName( 'title' );
		$facts['title_count'] = 0;
		foreach ( $titles as $title ) {
			if ( self::inside( $title, array( 'svg' ) ) ) {
				continue;
			}
			++$facts['title_count'];
			if ( null === $facts['title'] ) {
				$facts['title'] = self::squash( $title->textContent );
			}
		}

		$this->read_meta( $doc, $facts );
		$this->read_link_tags( $doc, $base, $facts );
		$this->read_headings( $xpath, $facts );
		$this->read_images( $doc, $base, $facts );
		$this->read_links( $doc, $base, $facts );
		$this->read_json_ld( $doc, $facts );
		if ( 0 === strpos( strtolower( $url ), 'https://' ) ) {
			$this->read_mixed_content( $doc, $base, $facts );
		}

		$facts += self::robots_flags( $facts['robots_meta'], $facts['x_robots'] );

		// Word count last: it removes non-content nodes.
		$facts['word_count'] = self::count_words( $this->main_text( $doc, $xpath ) );

		return $facts;
	}

	/**
	 * Default facts.
	 */
	public static function empty_facts(): array {
		return array(
			'parse_error'          => false,
			'base'                 => '',
			'title'                => null,
			'title_count'          => 0,
			'meta_description'     => null,
			'description_count'    => 0,
			'canonical'            => '',
			'canonical_raw'        => '',
			'canonical_relative'   => false,
			'canonical_count'      => 0,
			'robots_meta'          => '',
			'x_robots'             => '',
			'h1_count'             => 0,
			'h1'                   => '',
			'headings'             => array(),
			'word_count'           => 0,
			'images_total'         => 0,
			'images_missing_alt'   => array(),
			'images_missing_count' => 0,
			'images_empty_alt'     => 0,
			'images_no_dimensions' => array(),
			'images_no_dims_count' => 0,
			'images_lazy'          => 0,
			'links'                => array(),
			'hreflang'             => array(),
			'og'                   => array(),
			'schema_types'         => array(),
			'schema_errors'        => 0,
			'viewport'             => '',
			'lang'                 => '',
			'charset'              => '',
			'mixed_content'        => array(),
		);
	}

	/**
	 * Noindex/nofollow from meta robots + X-Robots-Tag (directives for other named bots are ignored).
	 *
	 * @param string $meta     Combined meta robots/googlebot content.
	 * @param string $x_robots X-Robots-Tag header value.
	 * @return array{noindex: bool, nofollow: bool}
	 */
	public static function robots_flags( string $meta, string $x_robots ): array {
		$tokens = array();
		foreach ( explode( ',', strtolower( $meta ) ) as $token ) {
			$tokens[] = trim( $token );
		}
		foreach ( explode( ',', strtolower( $x_robots ) ) as $token ) {
			$token = trim( $token );
			if ( preg_match( '/^([a-z0-9_\-]+)\s*:\s*(.+)$/', $token, $m ) && ! in_array( $m[1], array( 'unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview' ), true ) ) {
				if ( 'googlebot' !== $m[1] && 'robots' !== $m[1] ) {
					continue;
				}
				$token = trim( $m[2] );
			}
			$tokens[] = $token;
		}
		return array(
			'noindex'  => in_array( 'noindex', $tokens, true ) || in_array( 'none', $tokens, true ),
			'nofollow' => in_array( 'nofollow', $tokens, true ) || in_array( 'none', $tokens, true ),
		);
	}

	/**
	 * Words in a text (letters/digits runs; Vietnamese syllables count as words).
	 *
	 * @param string $text Text.
	 */
	public static function count_words( string $text ): int {
		$count = preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’\-]*/u', $text );
		return false === $count ? 0 : (int) $count;
	}

	/**
	 * Charset from header/meta.
	 *
	 * @param string $html         Raw HTML.
	 * @param string $content_type Content-Type header.
	 * @return array{declared: string, effective: string}
	 */
	public static function detect_charset( string $html, string $content_type ): array {
		$declared = '';
		if ( preg_match( '/charset\s*=\s*["\']?([a-z0-9_\-:.]+)/i', $content_type, $m ) ) {
			$declared = strtolower( $m[1] );
		}
		$head = substr( $html, 0, 4096 );
		if ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-:.]+)/i', $head, $m ) ) {
			$meta = strtolower( $m[1] );
			if ( '' === $declared ) {
				$declared = $meta;
			}
		}
		$effective = '' === $declared ? 'utf-8' : $declared;
		if ( in_array( $effective, array( 'utf8', 'utf-8' ), true ) && ! self::is_utf8( $html ) ) {
			$effective = 'windows-1252';
		}
		return array(
			'declared'  => $declared,
			'effective' => 'utf8' === $effective ? 'utf-8' : $effective,
		);
	}

	/**
	 * Valid UTF-8?
	 *
	 * @param string $text Bytes.
	 */
	private static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * Converts to UTF-8.
	 *
	 * @param string $html    Bytes.
	 * @param string $charset Source charset.
	 */
	private static function to_utf8( string $html, string $charset ): string {
		if ( 'utf-8' === $charset ) {
			return $html;
		}
		$aliases = array(
			'latin1'     => 'ISO-8859-1',
			'iso-8859-1' => 'Windows-1252',
			'us-ascii'   => 'ASCII',
		);
		$from    = isset( $aliases[ $charset ] ) ? $aliases[ $charset ] : $charset;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$encodings = array_map( 'strtolower', mb_list_encodings() );
			if ( in_array( strtolower( $from ), $encodings, true ) ) {
				$converted = mb_convert_encoding( $html, 'UTF-8', $from );
				if ( is_string( $converted ) ) {
					return $converted;
				}
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $from, 'UTF-8//IGNORE', $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown charsets return false.
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}
		return wp_check_invalid_utf8( $html, true );
	}

	/**
	 * Parses UTF-8 HTML with libxml errors suppressed.
	 *
	 * @param string $html UTF-8 HTML.
	 */
	private static function load( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}
		if ( ! self::is_utf8( $html ) ) {
			$html = wp_check_invalid_utf8( $html, true );
		}
		// Encode non-ASCII as entities so libxml cannot misread the charset (a <meta charset> may disagree).
		if ( function_exists( 'mb_encode_numericentity' ) ) {
			$html = mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
		} else {
			$html = '<?xml encoding="UTF-8">' . $html;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadHTML( $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $ok ? $doc : null;
	}

	/**
	 * Meta tags: description, robots, viewport, Open Graph / Twitter, http-equiv charset.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_meta( DOMDocument $doc, array &$facts ): void {
		$robots = array();
		foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
			$name     = strtolower( trim( $meta->getAttribute( 'name' ) ) );
			$property = strtolower( trim( $meta->getAttribute( 'property' ) ) );
			$content  = self::squash( $meta->getAttribute( 'content' ) );

			if ( 'description' === $name ) {
				++$facts['description_count'];
				if ( null === $facts['meta_description'] ) {
					$facts['meta_description'] = $content;
				}
			} elseif ( 'robots' === $name || 'googlebot' === $name ) {
				$robots[] = strtolower( $content );
			} elseif ( 'viewport' === $name ) {
				$facts['viewport'] = $content;
			}

			$key = '';
			if ( 0 === strpos( $property, 'og:' ) || 0 === strpos( $property, 'twitter:' ) ) {
				$key = $property;
			} elseif ( 0 === strpos( $name, 'twitter:' ) || 0 === strpos( $name, 'og:' ) ) {
				$key = $name;
			}
			if ( '' !== $key && ! isset( $facts['og'][ $key ] ) && count( $facts['og'] ) < 30 ) {
				$facts['og'][ $key ] = AuditStore::truncate( $content, 500 );
			}
		}
		$facts['robots_meta'] = implode(
			', ',
			array_filter(
				$robots,
				static function ( $token ) {
					return '' !== (string) $token;
				}
			)
		);
	}

	/**
	 * <link> tags: canonical and hreflang alternates.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param string      $base  Base URL.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_link_tags( DOMDocument $doc, string $base, array &$facts ): void {
		foreach ( $doc->getElementsByTagName( 'link' ) as $link ) {
			$rels = self::rel_tokens( $link->getAttribute( 'rel' ) );
			$href = trim( $link->getAttribute( 'href' ) );
			if ( in_array( 'canonical', $rels, true ) ) {
				++$facts['canonical_count'];
				if ( 1 === $facts['canonical_count'] ) {
					$facts['canonical_raw']      = $href;
					$facts['canonical_relative'] = '' !== $href && ! preg_match( '#^https?://#i', $href );
					$facts['canonical']          = self::strip_fragment( Url::resolve( $href, $base ) );
				}
			}
			if ( in_array( 'alternate', $rels, true ) && $link->hasAttribute( 'hreflang' ) && count( $facts['hreflang'] ) < 100 ) {
				$facts['hreflang'][] = array(
					'lang' => trim( $link->getAttribute( 'hreflang' ) ),
					'url'  => self::strip_fragment( Url::resolve( $href, $base ) ),
				);
			}
		}
	}

	/**
	 * h1–h6 outline.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param array    $facts Facts (by reference).
	 */
	private function read_headings( DOMXPath $xpath, array &$facts ): void {
		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		foreach ( $nodes as $node ) {
			$level = (int) substr( $node->nodeName, 1 );
			$text  = self::squash( $node->textContent );
			if ( 1 === $level ) {
				++$facts['h1_count'];
				if ( '' === $facts['h1'] ) {
					$facts['h1'] = AuditStore::truncate( $text, 300 );
				}
			}
			if ( count( $facts['headings'] ) < 100 ) {
				$facts['headings'][] = array( $level, AuditStore::truncate( $text, 200 ) );
			}
		}
	}

	/**
	 * Images: alt, dimensions, lazy loading.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param string      $base  Base URL.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_images( DOMDocument $doc, string $base, array &$facts ): void {
		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			if ( self::inside( $img, array( 'noscript' ) ) ) {
				continue;
			}
			$src = '';
			foreach ( array( 'data-src', 'data-lazy-src', 'src' ) as $attr ) {
				$value = trim( $img->getAttribute( $attr ) );
				if ( '' !== $value && 0 !== strpos( $value, 'data:' ) ) {
					$src = $value;
					break;
				}
			}
			$src = '' === $src ? '' : Url::resolve( $src, $base );
			++$facts['images_total'];

			if ( ! $img->hasAttribute( 'alt' ) ) {
				++$facts['images_missing_count'];
				if ( count( $facts['images_missing_alt'] ) < self::LIST_CAP ) {
					$facts['images_missing_alt'][] = $src;
				}
			} elseif ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
				++$facts['images_empty_alt'];
			}
			if ( '' === trim( $img->getAttribute( 'width' ) ) || '' === trim( $img->getAttribute( 'height' ) ) ) {
				++$facts['images_no_dims_count'];
				if ( count( $facts['images_no_dimensions'] ) < self::LIST_CAP ) {
					$facts['images_no_dimensions'][] = $src;
				}
			}
			if ( 'lazy' === strtolower( $img->getAttribute( 'loading' ) ) ) {
				++$facts['images_lazy'];
			}
		}
	}

	/**
	 * Anchor links.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param string      $base  Base URL.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_links( DOMDocument $doc, string $base, array &$facts ): void {
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			if ( ! $a->hasAttribute( 'href' ) ) {
				continue;
			}
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] ) {
				continue;
			}
			$url = self::strip_fragment( Url::resolve( $href, $base ) );
			if ( '' === $url ) {
				continue;
			}
			$rels   = self::rel_tokens( $a->getAttribute( 'rel' ) );
			$anchor = self::squash( $a->textContent );
			if ( '' === $anchor ) {
				foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
					$anchor = self::squash( $img->getAttribute( 'alt' ) );
					break;
				}
			}
			if ( '' === $anchor ) {
				$anchor = self::squash( $a->getAttribute( 'aria-label' ) );
			}
			$facts['links'][] = array(
				'url'      => $url,
				'anchor'   => AuditStore::truncate( $anchor, 191 ),
				'nofollow' => (bool) array_intersect( $rels, array( 'nofollow', 'sponsored', 'ugc' ) ),
				'rel'      => implode( ' ', $rels ),
				'in_nav'   => self::in_navigation( $a ),
			);
		}
	}

	/**
	 * JSON-LD @types (top level and @graph).
	 *
	 * @param DOMDocument $doc   Document.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_json_ld( DOMDocument $doc, array &$facts ): void {
		$types = array();
		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( $script->getAttribute( 'type' ) ) ) ) {
				continue;
			}
			$json = trim( $script->textContent );
			$json = preg_replace( '/^\s*<!--|-->\s*$/', '', $json );
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				++$facts['schema_errors'];
				continue;
			}
			$nodes = self::is_list( $data ) ? $data : array( $data );
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				self::collect_types( $node, $types );
				if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
					foreach ( $node['@graph'] as $item ) {
						if ( is_array( $item ) ) {
							self::collect_types( $item, $types );
						}
					}
				}
			}
		}
		$facts['schema_types'] = array_values( array_unique( $types ) );
	}

	/**
	 * Adds a node's @type(s).
	 *
	 * @param array    $node  JSON-LD node.
	 * @param string[] $types Collected (by reference).
	 */
	private static function collect_types( array $node, array &$types ): void {
		if ( ! isset( $node['@type'] ) ) {
			return;
		}
		foreach ( (array) $node['@type'] as $type ) {
			if ( is_string( $type ) && '' !== $type ) {
				$types[] = AuditStore::truncate( $type, 80 );
			}
		}
	}

	/**
	 * http:// subresources on an https page.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param string      $base  Base URL.
	 * @param array       $facts Facts (by reference).
	 */
	private function read_mixed_content( DOMDocument $doc, string $base, array &$facts ): void {
		$found  = array();
		$checks = array(
			'img'    => array( 'src', 'srcset', 'data-src' ),
			'source' => array( 'src', 'srcset' ),
			'script' => array( 'src' ),
			'iframe' => array( 'src' ),
			'video'  => array( 'src', 'poster' ),
			'audio'  => array( 'src' ),
			'embed'  => array( 'src' ),
			'object' => array( 'data' ),
			'link'   => array( 'href' ),
		);
		foreach ( $checks as $tag => $attrs ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
				if ( 'link' === $tag && ! array_intersect( self::rel_tokens( $el->getAttribute( 'rel' ) ), array( 'stylesheet', 'icon', 'preload', 'modulepreload', 'manifest', 'apple-touch-icon' ) ) ) {
					continue;
				}
				foreach ( $attrs as $attr ) {
					$value = trim( $el->getAttribute( $attr ) );
					if ( '' === $value ) {
						continue;
					}
					$candidates = 'srcset' === $attr ? array_map(
						static function ( $part ) {
							return (string) strtok( trim( $part ), ' ' );
						},
						explode( ',', $value )
					) : array( $value );
					foreach ( $candidates as $candidate ) {
						$resolved = Url::resolve( $candidate, $base );
						if ( 0 === stripos( $resolved, 'http://' ) ) {
							$found[ $resolved ] = true;
						}
					}
				}
			}
		}
		$facts['mixed_content'] = array_slice( array_keys( $found ), 0, self::LIST_CAP );
	}

	/**
	 * Visible main text: <main>/[role=main], else a single <article>, else <body>, minus non-content elements.
	 *
	 * @param DOMDocument $doc   Document.
	 * @param DOMXPath    $xpath XPath.
	 */
	private function main_text( DOMDocument $doc, DOMXPath $xpath ): string {
		$root = $xpath->query( '//main|//*[@role="main"]' )->item( 0 );
		if ( ! $root ) {
			$articles = $doc->getElementsByTagName( 'article' );
			$root     = 1 === $articles->length ? $articles->item( 0 ) : $doc->getElementsByTagName( 'body' )->item( 0 );
		}
		if ( ! $root ) {
			return '';
		}
		$remove = array();
		foreach ( self::NON_CONTENT as $tag ) {
			foreach ( $root instanceof DOMElement ? $root->getElementsByTagName( $tag ) : array() as $node ) {
				$remove[] = $node;
			}
		}
		foreach ( $xpath->query( './/*[@role="navigation" or @aria-hidden="true"]', $root ) as $node ) {
			$remove[] = $node;
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		$parts = array();
		foreach ( $xpath->query( './/text()', $root ) as $text ) {
			$parts[] = $text->nodeValue;
		}
		return self::squash( implode( ' ', $parts ) );
	}

	/**
	 * Inside <nav>, <header> or role=navigation?
	 *
	 * @param DOMNode $node Node.
	 */
	private static function in_navigation( DOMNode $node ): bool {
		for ( $parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
			$name = strtolower( $parent->nodeName );
			if ( 'nav' === $name || 'header' === $name || 'navigation' === strtolower( $parent->getAttribute( 'role' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Has an ancestor among tag names?
	 *
	 * @param DOMNode  $node Node.
	 * @param string[] $tags Lower-case tags.
	 */
	private static function inside( DOMNode $node, array $tags ): bool {
		for ( $parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
			if ( in_array( strtolower( $parent->nodeName ), $tags, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lower-case rel tokens.
	 *
	 * @param string $rel Attribute value.
	 * @return string[]
	 */
	private static function rel_tokens( string $rel ): array {
		return array_values(
			array_filter(
				(array) preg_split( '/\s+/', strtolower( trim( $rel ) ) ),
				static function ( $token ) {
					return '' !== (string) $token;
				}
			)
		);
	}

	/**
	 * Collapses whitespace.
	 *
	 * @param string $text Text.
	 */
	private static function squash( string $text ): string {
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );
		return trim( null === $text ? '' : $text );
	}

	/**
	 * Removes a #fragment.
	 *
	 * @param string $url URL.
	 */
	private static function strip_fragment( string $url ): string {
		$pos = strpos( $url, '#' );
		return false === $pos ? $url : substr( $url, 0, $pos );
	}

	/**
	 * Sequential array?
	 *
	 * @param array $value Value.
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
