<?php
/**
 * Builds the data Claude needs to write SEO meta for one post.
 *
 * The result is a plain array (easy to test, easy to serialise). Prompts::meta_user_message() renders it into
 * tagged data blocks. This class has no dependency on core services: current SEO values come in through $seo.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class PostContext {

	/** Default cap for the main text, in characters. */
	const MAX_TEXT_CHARS = 12000;

	/** Maximum number of images listed. */
	const MAX_IMAGES = 20;

	/** Intro (text before the first heading) never takes more than this share of the budget. */
	const MAX_INTRO_SHARE = 0.4;

	/**
	 * Main-text cap in characters.
	 *
	 * @var int
	 */
	private $max_chars;

	/**
	 * Constructor.
	 *
	 * @param int $max_chars Main-text cap in characters (minimum 1000).
	 */
	public function __construct( int $max_chars = self::MAX_TEXT_CHARS ) {
		$this->max_chars = max( 1000, $max_chars );
	}

	/**
	 * Builds the context for one post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     Current SEO values from the SEO plugin: focus_keyword, title, description,
	 *                       resolved_title (what the plugin outputs), brand_appended (bool: the title template
	 *                       already appends the site name), brand (string, defaults to the site name).
	 * @return array|WP_Error
	 */
	public function build( int $post_id, array $seo = array() ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seoc_meta_no_post', __( 'The post does not exist.', 'seo-console' ), array( 'status' => 404 ) );
		}

		$content = (string) $post->post_content;
		$text    = self::html_to_text( $content );
		$short   = self::shorten( $text, $this->max_chars );
		$locale  = self::post_locale( $post );
		$type    = get_post_type_object( $post->post_type );

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$context = array(
			'post_id'         => (int) $post->ID,
			'post_type'       => (string) $post->post_type,
			'post_type_label' => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
			'title'           => self::plain( get_the_title( $post ) ),
			'slug'            => (string) $post->post_name,
			'permalink'       => (string) get_permalink( $post ),
			'excerpt'         => self::plain( has_excerpt( $post ) ? $post->post_excerpt : '' ),
			'text'            => $short['text'],
			'text_truncated'  => $short['truncated'],
			'text_length'     => self::len( $text ),
			'headings'        => self::headings( $content ),
			'categories'      => self::term_names( $post, 'product' === $post->post_type ? 'product_cat' : 'category' ),
			'tags'            => self::term_names( $post, 'product' === $post->post_type ? 'product_tag' : 'post_tag' ),
			'seo'             => array(
				'focus_keyword'  => self::plain( isset( $seo['focus_keyword'] ) ? (string) $seo['focus_keyword'] : '' ),
				'title'          => self::plain( isset( $seo['title'] ) ? (string) $seo['title'] : '' ),
				'resolved_title' => self::plain( isset( $seo['resolved_title'] ) ? (string) $seo['resolved_title'] : '' ),
				'description'    => self::plain( isset( $seo['description'] ) ? (string) $seo['description'] : '' ),
				'brand_appended' => ! empty( $seo['brand_appended'] ),
				'brand'          => self::plain( isset( $seo['brand'] ) && '' !== $seo['brand'] ? (string) $seo['brand'] : $site_name ),
			),
			'site'            => array(
				'name'    => self::plain( $site_name ),
				'tagline' => self::plain( (string) get_bloginfo( 'description' ) ),
				'url'     => (string) home_url( '/' ),
			),
			'locale'          => $locale,
			'language'        => self::language_code( $locale ),
			'language_name'   => self::language_name( $locale ),
			'images'          => $this->images( $post ),
			'product'         => self::product_data( $post ),
		);

		/**
		 * Filters the data sent to Claude for one post's SEO meta. Values are treated as untrusted data.
		 *
		 * @param array   $context Context array.
		 * @param WP_Post $post    The post.
		 */
		$filtered = apply_filters( 'seoc_meta_post_context', $context, $post );
		return is_array( $filtered ) ? $filtered : $context;
	}

	/**
	 * Converts post HTML (blocks, shortcodes, page-builder markup) to markdown-ish plain text: headings become
	 * "## Heading", list items "- item", paragraphs are separated by a blank line.
	 *
	 * @param string $html Post content.
	 * @return string
	 */
	public static function html_to_text( string $html ): string {
		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );
		// Comments, including block delimiters <!-- wp:... -->.
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
		// Elements whose content is never prose.
		$html = preg_replace( '#<(script|style|noscript|template|svg|iframe|object|canvas|form|select|button)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$html = preg_replace( '#<(script|style|noscript|template|svg|iframe)\b[^>]*/?>#is', ' ', $html );
		$html = self::strip_shortcode_tags( $html );

		// Headings → markdown, on their own lines.
		$html = preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is',
			static function ( $m ) {
				$text = self::plain( $m[2] );
				return '' === $text ? "\n" : "\n\n" . str_repeat( '#', (int) $m[1] ) . ' ' . $text . "\n\n";
			},
			$html
		);
		$html = preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#<(td|th)\b[^>]*>#i', ' | ', $html );
		$html = preg_replace( '#</?(p|div|section|article|header|footer|aside|blockquote|figure|figcaption|ul|ol|table|tr|pre|dl|dt|dd|main|nav)\b[^>]*>#i', "\n\n", $html );

		$text  = wp_strip_all_tags( $html );
		$text  = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text  = str_replace( "\xc2\xa0", ' ', $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line    = trim( preg_replace( '/[ \t\x0B\f]+/u', ' ', $line ) );
			$lines[] = ( '-' === $line || '|' === $line ) ? '' : $line;
		}
		$text = implode( "\n", $lines );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Shortens markdown-ish text to about $max characters while always keeping the intro (text before the first
	 * heading, capped) and every heading line. Each section keeps its opening, cut at a word boundary with "[…]".
	 *
	 * @param string $text Text from html_to_text().
	 * @param int    $max  Character budget.
	 * @return array{text: string, truncated: bool}
	 */
	public static function shorten( string $text, int $max ): array {
		if ( self::len( $text ) <= $max ) {
			return array(
				'text'      => $text,
				'truncated' => false,
			);
		}

		// Split into intro + sections (heading line + body).
		$parts    = preg_split( '/^(#{1,6} .+)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$intro    = trim( (string) array_shift( $parts ) );
		$sections = array();
		$count    = count( $parts );
		for ( $i = 0; $i < $count; $i += 2 ) {
			$sections[] = array(
				'heading' => trim( $parts[ $i ] ),
				'body'    => isset( $parts[ $i + 1 ] ) ? trim( $parts[ $i + 1 ] ) : '',
			);
		}

		$heading_chars = 0;
		foreach ( $sections as $section ) {
			$heading_chars += self::len( $section['heading'] ) + 2;
		}

		// Intro: kept whole when it fits its share, otherwise cut.
		$intro_cap = empty( $sections ) ? $max : (int) max( 500, floor( $max * self::MAX_INTRO_SHARE ) );
		$intro     = self::cut( $intro, $intro_cap );

		// Water-filling: short sections keep everything, long ones share the rest equally.
		$remaining = max( 0, $max - self::len( $intro ) - $heading_chars );
		$order     = array_keys( $sections );
		usort(
			$order,
			static function ( $a, $b ) use ( $sections ) {
				return self::len( $sections[ $a ]['body'] ) <=> self::len( $sections[ $b ]['body'] );
			}
		);
		$left = count( $order );
		foreach ( $order as $index ) {
			$share                      = $left > 0 ? (int) floor( $remaining / $left ) : 0;
			$sections[ $index ]['body'] = self::cut( $sections[ $index ]['body'], $share );
			$remaining                 -= self::len( $sections[ $index ]['body'] );
			$remaining                  = max( 0, $remaining );
			--$left;
		}

		$out = '' === $intro ? array() : array( $intro );
		foreach ( $sections as $section ) {
			$out[] = '' === $section['body'] ? $section['heading'] : $section['heading'] . "\n\n" . $section['body'];
		}
		return array(
			'text'      => implode( "\n\n", $out ),
			'truncated' => true,
		);
	}

	/**
	 * Images in the content (plus the featured image and, for products, the gallery), in document order, with the
	 * nearest heading before them and their caption.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array<string, mixed>> Each: attachment_id (int, 0 when unknown), src, filename, alt (as in
	 *         the content), attachment_alt (media library), caption, heading, featured (bool).
	 */
	public function images( WP_Post $post ): array {
		$html = (string) $post->post_content;
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
		// [caption]<img …> Caption text[/caption] → <figure><img …><figcaption>Caption text</figcaption></figure>.
		$html = preg_replace_callback(
			'#\[caption[^\]]*\](.*?)\[/caption\]#is',
			static function ( $m ) {
				if ( ! preg_match( '#^(.*<img\b[^>]*>(?:\s*</a>)?)(.*)$#is', $m[1], $p ) ) {
					return $m[1];
				}
				return '<figure>' . $p[1] . '<figcaption>' . $p[2] . '</figcaption></figure>';
			},
			$html
		);

		$images  = array();
		$seen    = array();
		$heading = '';
		$last    = null;
		preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1\s*>|<img\b[^>]*>|<figcaption\b[^>]*>(.*?)</figcaption\s*>#is', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$tag = strtolower( substr( $m[0], 0, 4 ) );
			if ( '<img' === $tag ) {
				$image = $this->image_from_tag( $m[0], $heading );
				if ( null === $image ) {
					$last = null;
					continue;
				}
				$key = $image['attachment_id'] ? 'id:' . $image['attachment_id'] : 'src:' . $image['src'];
				if ( isset( $seen[ $key ] ) ) {
					$last = null;
					continue;
				}
				$seen[ $key ] = true;
				$images[]     = $image;
				$last         = count( $images ) - 1;
			} elseif ( '<fig' === $tag ) {
				if ( null !== $last && '' === $images[ $last ]['caption'] ) {
					$images[ $last ]['caption'] = self::cut( self::plain( $m[3] ), 300 );
				}
				$last = null;
			} else {
				$heading = self::cut( self::plain( $m[2] ), 200 );
				$last    = null;
			}
		}

		$extra = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$extra[] = array( $thumb, true );
		}
		if ( 'product' === $post->post_type ) {
			$gallery = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post->ID, '_product_image_gallery', true ) ) ) );
			foreach ( $gallery as $id ) {
				$extra[] = array( $id, false );
			}
		}
		foreach ( $extra as $pair ) {
			list( $id, $featured ) = $pair;
			if ( isset( $seen[ 'id:' . $id ] ) ) {
				if ( $featured ) {
					foreach ( $images as $i => $image ) {
						if ( $image['attachment_id'] === $id ) {
							$images[ $i ]['featured'] = true;
						}
					}
				}
				continue;
			}
			$src = wp_get_attachment_url( $id );
			if ( ! $src ) {
				continue;
			}
			$seen[ 'id:' . $id ] = true;
			$images[]            = array(
				'attachment_id'  => $id,
				'src'            => (string) $src,
				'filename'       => self::filename( (string) $src ),
				'alt'            => '',
				'attachment_alt' => self::attachment_alt( $id ),
				'caption'        => self::cut( self::plain( (string) wp_get_attachment_caption( $id ) ), 300 ),
				'heading'        => '',
				'featured'       => $featured,
			);
		}

		return array_slice( $images, 0, self::MAX_IMAGES );
	}

	/**
	 * The post's language as a WordPress locale (e.g. vi or vi_VN): Polylang, then WPML, then the site locale.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function post_locale( WP_Post $post ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$locale = pll_get_post_language( $post->ID, 'locale' );
			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}
		// WPML (and plugins imitating it) answer this filter; null means "not handled".
		$details = apply_filters( 'wpml_post_language_details', null, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
		if ( is_array( $details ) ) {
			if ( ! empty( $details['locale'] ) && is_string( $details['locale'] ) ) {
				return $details['locale'];
			}
			if ( ! empty( $details['language_code'] ) && is_string( $details['language_code'] ) ) {
				return $details['language_code'];
			}
		}
		$locale = get_locale();
		return '' === $locale ? 'en_US' : $locale;
	}

	/**
	 * Two-letter (or three-letter) language code of a locale: vi_VN → vi.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_code( string $locale ): string {
		$parts = preg_split( '/[_-]/', strtolower( $locale ) );
		return '' === $parts[0] ? 'en' : $parts[0];
	}

	/**
	 * English name of the language (for the prompt), falling back to the locale itself.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_name( string $locale ): string {
		$names = array(
			'vi' => 'Vietnamese',
			'en' => 'English',
			'fr' => 'French',
			'de' => 'German',
			'es' => 'Spanish',
			'pt' => 'Portuguese',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'ru' => 'Russian',
			'uk' => 'Ukrainian',
			'tr' => 'Turkish',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'th' => 'Thai',
			'id' => 'Indonesian',
			'ms' => 'Malay',
			'ar' => 'Arabic',
			'he' => 'Hebrew',
			'hi' => 'Hindi',
			'el' => 'Greek',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'nb' => 'Norwegian',
			'fi' => 'Finnish',
			'cs' => 'Czech',
		);
		$code  = self::language_code( $locale );
		return isset( $names[ $code ] ) ? $names[ $code ] : $locale;
	}

	/**
	 * WooCommerce product facts, or null when the post is not a product (or WooCommerce is not active).
	 *
	 * @param WP_Post $post Post.
	 * @return array|null price, regular_price, sale_price, currency, sku, stock_status, short_description, attributes.
	 */
	public static function product_data( WP_Post $post ): ?array {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! is_object( $product ) ) {
			return null;
		}
		$call = static function ( $method ) use ( $product ) {
			return is_callable( array( $product, $method ) ) ? $product->$method() : '';
		};

		$attributes = array();
		$raw        = method_exists( $product, 'get_attributes' ) ? $product->get_attributes() : array();
		foreach ( (array) $raw as $key => $attribute ) {
			$name = is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : (string) $key;
			if ( '' === $name ) {
				continue;
			}
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name, $product ) : $name;
			$value = method_exists( $product, 'get_attribute' ) ? $product->get_attribute( $name ) : '';
			if ( '' !== (string) $value ) {
				$attributes[ self::plain( (string) $label ) ] = self::cut( self::plain( (string) $value ), 300 );
			}
		}

		return array(
			'price'             => (string) $call( 'get_price' ),
			'regular_price'     => (string) $call( 'get_regular_price' ),
			'sale_price'        => (string) $call( 'get_sale_price' ),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'sku'               => self::plain( (string) $call( 'get_sku' ) ),
			'stock_status'      => (string) $call( 'get_stock_status' ),
			'short_description' => self::cut( self::html_to_text( (string) $call( 'get_short_description' ) ), 1500 ),
			'attributes'        => array_slice( $attributes, 0, 30, true ),
		);
	}

	/**
	 * Heading outline of the content (all of it, independent of shortening).
	 *
	 * @param string $html Content.
	 * @return array<int, array{level: int, text: string}>
	 */
	public static function headings( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$text = self::plain( $h[2] );
				if ( '' !== $text ) {
					$out[] = array(
						'level' => (int) $h[1],
						'text'  => self::cut( $text, 200 ),
					);
				}
			}
		}
		return array_slice( $out, 0, 100 );
	}

	/**
	 * Whether an alt text is missing or useless (empty, the file name, "IMG_1234", very short).
	 *
	 * @param string $alt      Alt text.
	 * @param string $filename File name.
	 * @return bool
	 */
	public static function is_weak_alt( string $alt, string $filename = '' ): bool {
		$alt = trim( $alt );
		if ( self::len( $alt ) < 5 ) {
			return true;
		}
		$stem = strtolower( (string) preg_replace( '/[\s_-]+/', '-', (string) preg_replace( '/\.[a-z0-9]+$/i', '', $filename ) ) );
		$norm = strtolower( (string) preg_replace( '/[\s_-]+/', '-', $alt ) );
		if ( '' !== $stem && $stem === $norm ) {
			return true;
		}
		return (bool) preg_match( '/^(img|image|dsc|dscn|photo|pic|screenshot|untitled|picture)[\s_-]*\d*(\.[a-z]{3,4})?$/i', $alt );
	}

	/**
	 * Plain single-line text: no tags, decoded entities, collapsed whitespace.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function plain( string $value ): string {
		$value = wp_strip_all_tags( self::strip_shortcode_tags( $value ) );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( "\xc2\xa0", ' ', $value );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Multibyte length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public static function len( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Cuts text to at most $max characters at a word boundary, marking the cut with " […]".
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters (0 → empty string).
	 * @return string
	 */
	public static function cut( string $text, int $max ): string {
		if ( self::len( $text ) <= $max ) {
			return $text;
		}
		if ( $max < 20 ) {
			return '';
		}
		$cut   = mb_substr( $text, 0, $max - 4, 'UTF-8' );
		$space = max( (int) mb_strrpos( $cut, ' ', 0, 'UTF-8' ), (int) mb_strrpos( $cut, "\n", 0, 'UTF-8' ) );
		if ( $space > ( $max / 2 ) ) {
			$cut = mb_substr( $cut, 0, $space, 'UTF-8' );
		}
		return rtrim( $cut ) . ' […]';
	}

	/**
	 * Removes shortcode tags ([name attr], [/name]) but keeps the text they enclose. Registered or not, so
	 * page-builder markup ([vc_row], [et_pb_section]) disappears too.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	private static function strip_shortcode_tags( string $html ): string {
		return (string) preg_replace( '#\[/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?/?\]#', ' ', $html );
	}

	/**
	 * One image from an <img> tag.
	 *
	 * @param string $tag     The tag.
	 * @param string $heading Nearest heading before it.
	 * @return array|null
	 */
	private function image_from_tag( string $tag, string $heading ): ?array {
		$attr = static function ( $name ) use ( $tag ) {
			if ( preg_match( '/\s' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m ) ) {
				$value = isset( $m[4] ) && '' !== $m[4] ? $m[4] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2] );
				return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			return null;
		};
		$src  = (string) $attr( 'src' );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			$src = (string) $attr( 'data-src' );
		}
		if ( '' === $src || 0 === strpos( $src, 'data:' ) || false !== strpos( $src, ';base64' ) || '' === esc_url_raw( $src ) ) {
			return null; // Inline/placeholder images cannot be managed from the media library.
		}

		$id    = 0;
		$class = (string) $attr( 'class' );
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			$id = (int) $m[1];
		} elseif ( null !== $attr( 'data-id' ) ) {
			$id = absint( $attr( 'data-id' ) );
		}
		if ( $id && 'attachment' !== get_post_type( $id ) ) {
			$id = 0;
		}
		if ( ! $id ) {
			$id = (int) attachment_url_to_postid( $src );
		}
		$alt = $attr( 'alt' );

		return array(
			'attachment_id'  => $id,
			'src'            => esc_url_raw( $src ),
			'filename'       => self::filename( $src ),
			'alt'            => null === $alt ? '' : self::plain( $alt ),
			'attachment_alt' => $id ? self::attachment_alt( $id ) : '',
			'caption'        => '',
			'heading'        => $heading,
			'featured'       => false,
		);
	}

	/**
	 * Media-library alt text of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function attachment_alt( int $id ): string {
		return self::plain( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * File name without query string or WordPress size suffix (photo-300x200.jpg → photo.jpg).
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function filename( string $src ): string {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$base = wp_basename( '' === $path ? $src : $path );
		return (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $base );
	}

	/**
	 * Term names of a taxonomy for the post.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy.
	 * @return string[]
	 */
	private static function term_names( WP_Post $post, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				$names[] = self::plain( $term->name );
			}
		}
		return array_slice( $names, 0, 20 );
	}
}
