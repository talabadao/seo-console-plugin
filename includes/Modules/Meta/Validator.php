<?php
/**
 * Post-validation of Claude's proposals before they become suggestions.
 *
 * Structured output guarantees the JSON shape, not the content: this class normalises the text and rejects
 * anything that must never reach the approval queue (HTML, URLs, instruction-like text, empty or unchanged values,
 * hard length overflows). Softer problems become warnings shown next to the suggestion.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Validator {

	/** Hard limits (characters): above these a proposal is rejected. */
	const HARD_MAX = array(
		'title'       => 70,
		'description' => 170,
		'alt'         => 150,
	);

	/** Ideal ranges [ min, max ]: outside them a proposal gets a warning. */
	const IDEAL = array(
		'title'       => array( 50, 60 ),
		'description' => array( 120, 155 ),
		'alt'         => array( 5, 125 ),
	);

	/** Vietnamese letters with diacritics (lower case; matched case-insensitively). */
	const VI_CHARS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';

	/** Letters that only Vietnamese uses among common Latin-script languages. */
	const VI_ONLY_CHARS = 'ạảãầấậẩẫằắặẳẵẹẻẽềếệểễịỉĩọỏồốộổỗơờớợởỡụủũưừứựửữỳỵỷỹđ';

	/** Scripts expected for languages that do not use the Latin alphabet. */
	const SCRIPTS = array(
		'ru' => 'Cyrillic',
		'uk' => 'Cyrillic',
		'bg' => 'Cyrillic',
		'sr' => 'Cyrillic',
		'el' => 'Greek',
		'ar' => 'Arabic',
		'fa' => 'Arabic',
		'he' => 'Hebrew',
		'th' => 'Thai',
		'hi' => 'Devanagari',
		'ja' => 'Han|Hiragana|Katakana',
		'zh' => 'Han',
		'ko' => 'Hangul',
	);

	/**
	 * Validates and normalises one proposed value.
	 *
	 * @param string $field 'title' | 'description' | 'alt'.
	 * @param mixed  $value Proposed value (from the model).
	 * @param array  $args  current (string), language (code, e.g. 'vi'), focus_keyword (string), brand (string),
	 *                      brand_appended (bool), allow_empty (bool, alt only: decorative image).
	 * @return array{valid: true|WP_Error, value: string, warnings: array<int, array{code: string, message: string}>}
	 */
	public function validate( string $field, $value, array $args = array() ): array {
		$warnings = array();
		$result   = function ( $valid, $normalised ) use ( &$warnings ) {
			return array(
				'valid'    => $valid,
				'value'    => $normalised,
				'warnings' => $warnings,
			);
		};

		if ( ! isset( self::HARD_MAX[ $field ] ) ) {
			return $result( self::error( 'seoc_meta_unknown_field', __( 'Unknown field.', 'seo-console' ) ), '' );
		}
		if ( ! is_string( $value ) ) {
			return $result( self::error( 'seoc_meta_invalid', __( 'The AI answer was not text.', 'seo-console' ) ), '' );
		}

		// Checks on the raw value first: normalising would hide these.
		if ( preg_match( '#<\s*/?\s*[a-z!][^>]*>#i', $value ) ) {
			return $result( self::error( 'seoc_meta_html', __( 'The proposal contains HTML and was discarded.', 'seo-console' ) ), self::normalise( $value ) );
		}
		$value = self::normalise( $value );
		if ( preg_match( '#(https?://|ftp://|\bwww\.[a-z0-9-]+\.|mailto:)#i', $value ) ) {
			return $result( self::error( 'seoc_meta_url', __( 'The proposal contains a link and was discarded.', 'seo-console' ) ), $value );
		}
		if ( self::looks_like_instructions( $value ) ) {
			return $result( self::error( 'seoc_meta_suspicious', __( 'The proposal looks like instructions or a chat reply instead of page text and was discarded.', 'seo-console' ) ), $value );
		}

		$length = PostContext::len( $value );
		if ( 0 === $length ) {
			if ( 'alt' === $field && ! empty( $args['allow_empty'] ) ) {
				$warnings[] = self::warning( 'decorative', __( 'The AI considers this image decorative (empty alt text).', 'seo-console' ) );
				return $result( true, '' );
			}
			return $result( self::error( 'seoc_meta_empty', __( 'The AI returned an empty proposal.', 'seo-console' ) ), '' );
		}
		$current = isset( $args['current'] ) ? self::normalise( (string) $args['current'] ) : '';
		if ( '' !== $current && self::fold( $current ) === self::fold( $value ) ) {
			return $result( self::error( 'seoc_meta_unchanged', __( 'The proposal is the same as the current value.', 'seo-console' ) ), $value );
		}
		if ( $length > self::HARD_MAX[ $field ] ) {
			return $result(
				self::error(
					'seoc_meta_too_long',
					/* translators: 1: number of characters, 2: maximum */
					sprintf( __( 'The proposal is too long (%1$d characters, maximum %2$d).', 'seo-console' ), $length, self::HARD_MAX[ $field ] )
				),
				$value
			);
		}

		list( $min, $max ) = self::IDEAL[ $field ];
		if ( $length < $min ) {
			/* translators: 1: number of characters, 2: ideal minimum */
			$warnings[] = self::warning( 'too_short', sprintf( __( 'Shorter than ideal (%1$d characters; aim for at least %2$d).', 'seo-console' ), $length, $min ) );
		} elseif ( $length > $max ) {
			/* translators: 1: number of characters, 2: ideal maximum */
			$warnings[] = self::warning( 'too_long', sprintf( __( 'Longer than ideal (%1$d characters; aim for at most %2$d). Search engines may cut it off.', 'seo-console' ), $length, $max ) );
		}

		$language = isset( $args['language'] ) ? strtolower( (string) $args['language'] ) : '';
		if ( '' !== $language && ! self::language_plausible( $value, $language ) ) {
			$warnings[] = self::warning( 'language_mismatch', __( 'The text does not look like it is written in the post\'s language (for Vietnamese: check the diacritics).', 'seo-console' ) );
		}

		$keyword = isset( $args['focus_keyword'] ) ? self::fold( self::normalise( (string) $args['focus_keyword'] ) ) : '';
		if ( '' !== $keyword && 'alt' !== $field ) {
			$count = substr_count( self::fold( $value ), $keyword );
			if ( 0 === $count ) {
				$warnings[] = self::warning( 'keyword_missing', __( 'The focus keyword does not appear.', 'seo-console' ) );
			} elseif ( $count > ( 'title' === $field ? 1 : 2 ) ) {
				$warnings[] = self::warning( 'keyword_stuffing', __( 'The focus keyword is repeated too often.', 'seo-console' ) );
			}
		}

		$brand = isset( $args['brand'] ) ? self::fold( self::normalise( (string) $args['brand'] ) ) : '';
		if ( 'title' === $field && ! empty( $args['brand_appended'] ) && PostContext::len( $brand ) >= 3 && false !== strpos( self::fold( $value ), $brand ) ) {
			$warnings[] = self::warning( 'brand_duplicated', __( 'The SEO plugin already adds the brand to titles, so it would appear twice.', 'seo-console' ) );
		}

		if ( self::looks_like_clickbait( $value ) ) {
			$warnings[] = self::warning( 'clickbait', __( 'The text uses capitals or exclamation marks heavily.', 'seo-console' ) );
		}

		return $result( true, $value );
	}

	/**
	 * Plain single-line text: control characters and line breaks removed, entities decoded, tags stripped,
	 * whitespace collapsed, wrapping quotes removed.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function normalise( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = wp_strip_all_tags( $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = (string) preg_replace( '/[\x{200B}-\x{200D}\x{2028}\x{2029}\x{FEFF}]/u', '', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( preg_match( '/^(["\'“”«])(.*)(["\'“”»])$/u', $value, $m ) && false === strpbrk( $m[2], '"“”«»' ) ) {
			$value = trim( $m[2] );
		}
		return $value;
	}

	/**
	 * Whether the text plausibly is in the given language.
	 *
	 * @param string $value    Text.
	 * @param string $language Language code.
	 * @return bool
	 */
	public static function language_plausible( string $value, string $language ): bool {
		$letters = preg_replace( '/[^\p{L}]/u', '', $value );
		if ( PostContext::len( (string) $letters ) < 12 ) {
			return true; // Too short to judge (brand names, model numbers).
		}
		if ( 'vi' === $language ) {
			return (bool) preg_match( '/[' . self::VI_CHARS . ']/iu', $value );
		}
		if ( isset( self::SCRIPTS[ $language ] ) ) {
			return (bool) preg_match( '/\p{' . str_replace( '|', '}|\p{', self::SCRIPTS[ $language ] ) . '}/u', $value );
		}
		// Latin-script languages: Vietnamese-only letters or a non-Latin script suggest the wrong language.
		if ( preg_match( '/[' . self::VI_ONLY_CHARS . ']/iu', $value ) ) {
			return false;
		}
		$non_latin = preg_match_all( '/[\p{Cyrillic}\p{Arabic}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}\p{Hebrew}\p{Greek}]/u', $value );
		return $non_latin < 3;
	}

	/**
	 * Whether the text reads like instructions, a prompt leak or a chat reply rather than page text.
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	public static function looks_like_instructions( string $value ): bool {
		$patterns = array(
			'/\b(ignore|disregard|forget|override)\b.{0,30}\b(instructions?|prompts?|previous|prior)\b/iu',
			'/\b(system prompt|developer message|as an ai|language model|i am claude|i\'m claude)\b/iu',
			'/^(sure|certainly|of course)[,!.]/iu',
			'/^(here is|here are|here\'s) (the|your|a|an|my)\b/iu',
			'/^(i cannot|i can\'t|i\'m sorry|i am sorry|sorry,)/iu',
			'/^(assistant|system|user|human)\s*:/iu',
			'/(```|<\|)/u',
			'/\b(bỏ qua|phớt lờ)\b.{0,40}\b(hướng dẫn|chỉ dẫn|lệnh)\b/iu',
			'/^(dưới đây là|đây là (tiêu đề|mô tả))/iu',
			'/\{\{|\}\}|%%[a-z_]+%%/iu',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Heavy capitals or exclamation marks.
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	private static function looks_like_clickbait( string $value ): bool {
		if ( substr_count( $value, '!' ) >= 2 ) {
			return true;
		}
		$letters = (int) preg_match_all( '/\p{L}/u', $value );
		$upper   = (int) preg_match_all( '/\p{Lu}/u', $value );
		return $letters >= 15 && $upper / $letters > 0.5;
	}

	/**
	 * Case-folded text for comparisons.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function fold( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 422 ) );
	}

	/**
	 * Warning helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array{code: string, message: string}
	 */
	private static function warning( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
