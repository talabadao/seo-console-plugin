<?php
/**
 * FAQPage from content, without AI. Sources, in order of trust:
 *  1. Yoast FAQ block / Rank Math FAQ block (the author marked these as FAQ) — used alone when present;
 *  2. <details><summary>Question?</summary>answer</details> (core/details block or classic HTML);
 *  3. headings ending in "?" followed by paragraphs/lists (at least two such headings);
 *  4. <dl> with <dt>Question?</dt><dd>answer</dd>.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class FaqExtractor extends AbstractExtractor {

	/** Max questions in one FAQPage. */
	const MAX_QUESTIONS = 50;

	/** Max characters per answer. */
	const MAX_ANSWER = 2000;

	/** {@inheritDoc} */
	public function type(): string {
		return 'FAQPage';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return array();
		}

		$pairs   = array();
		$sources = array();
		$this->walk_blocks( parse_blocks( $content ), $pairs, $sources );

		if ( array() === $pairs ) {
			$body = Html::body( $content );
			if ( $body ) {
				$this->add_pairs( $pairs, $sources, $this->from_details( $body ), __( 'details (accordion) blocks', 'seo-console' ) );
				$this->add_pairs( $pairs, $sources, $this->from_headings( $body ), __( 'headings that ask a question', 'seo-console' ) );
				$this->add_pairs( $pairs, $sources, $this->from_definition_lists( $body ), __( 'definition lists', 'seo-console' ) );
			}
		}
		if ( array() === $pairs ) {
			return array();
		}

		$pairs     = array_slice( $pairs, 0, self::MAX_QUESTIONS );
		$questions = array();
		foreach ( $pairs as $pair ) {
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => $pair[0],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => Html::truncate( $pair[1], self::MAX_ANSWER ),
				),
			);
		}
		$evidence = sprintf(
			/* translators: 1: number of questions, 2: where they were found. */
			_n( 'Found %1$d question in %2$s.', 'Found %1$d questions in %2$s.', count( $questions ), 'seo-console' ),
			count( $questions ),
			implode( ', ', array_unique( $sources ) )
		);
		return array(
			$this->candidate(
				array(
					'@type'      => 'FAQPage',
					'mainEntity' => $questions,
				),
				$evidence
			),
		);
	}

	/**
	 * Collects Q&A from Yoast / Rank Math FAQ blocks (recursively through inner blocks).
	 *
	 * @param array[]  $blocks  Parsed blocks.
	 * @param array[]  $pairs   Collected [question, answer] pairs.
	 * @param string[] $sources Collected source labels.
	 */
	private function walk_blocks( array $blocks, array &$pairs, array &$sources ): void {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( 'yoast/faq-block' === $name ) {
				$this->add_pairs( $pairs, $sources, $this->from_yoast_block( $block ), __( 'a Yoast FAQ block', 'seo-console' ) );
			} elseif ( 'rank-math/faq-block' === $name ) {
				$this->add_pairs( $pairs, $sources, $this->from_rank_math_block( $block ), __( 'a Rank Math FAQ block', 'seo-console' ) );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $pairs, $sources );
			}
		}
	}

	/**
	 * @param array $block Parsed yoast/faq-block.
	 * @return array[] Pairs.
	 */
	private function from_yoast_block( array $block ): array {
		$pairs = array();
		$items = isset( $block['attrs']['questions'] ) && is_array( $block['attrs']['questions'] ) ? $block['attrs']['questions'] : array();
		foreach ( $items as $item ) {
			$question = isset( $item['jsonQuestion'] ) ? (string) $item['jsonQuestion'] : self::rich_text( isset( $item['question'] ) ? $item['question'] : '' );
			$answer   = isset( $item['jsonAnswer'] ) ? (string) $item['jsonAnswer'] : self::rich_text( isset( $item['answer'] ) ? $item['answer'] : '' );
			$pairs[]  = array( Html::to_text( $question ), Html::to_text( $answer ) );
		}
		if ( array() === $pairs ) {
			$pairs = $this->pairs_by_class( (string) $block['innerHTML'], 'schema-faq-section', 'schema-faq-question', 'schema-faq-answer' );
		}
		return $pairs;
	}

	/**
	 * @param array $block Parsed rank-math/faq-block.
	 * @return array[] Pairs.
	 */
	private function from_rank_math_block( array $block ): array {
		$pairs = array();
		$items = isset( $block['attrs']['questions'] ) && is_array( $block['attrs']['questions'] ) ? $block['attrs']['questions'] : array();
		foreach ( $items as $item ) {
			if ( isset( $item['visible'] ) && false === $item['visible'] ) {
				continue;
			}
			$pairs[] = array(
				Html::to_text( isset( $item['title'] ) ? (string) $item['title'] : '' ),
				Html::to_text( isset( $item['content'] ) ? (string) $item['content'] : '' ),
			);
		}
		if ( array() === $pairs ) {
			$pairs = $this->pairs_by_class( (string) $block['innerHTML'], 'rank-math-list-item', 'rank-math-question', 'rank-math-answer' );
		}
		return $pairs;
	}

	/**
	 * Fallback for FAQ blocks without attributes: reads the saved HTML by class names.
	 *
	 * @param string $html           Block HTML.
	 * @param string $item_class     Wrapper class of one Q&A.
	 * @param string $question_class Question class.
	 * @param string $answer_class   Answer class.
	 * @return array[] Pairs.
	 */
	private function pairs_by_class( string $html, string $item_class, string $question_class, string $answer_class ): array {
		$pairs = array();
		$body  = Html::body( $html );
		if ( ! $body ) {
			return $pairs;
		}
		$xpath = new \DOMXPath( $body->ownerDocument );
		$items = $xpath->query( sprintf( '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $item_class ) );
		foreach ( $items ? $items : array() as $item ) {
			$q = $xpath->query( sprintf( './/*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $question_class ), $item );
			$a = $xpath->query( sprintf( './/*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $answer_class ), $item );
			if ( $q && $a && $q->length && $a->length ) {
				$pairs[] = array( Html::text( $q->item( 0 ) ), Html::text( $a->item( 0 ) ) );
			}
		}
		return $pairs;
	}

	/**
	 * <details><summary>Question?</summary> answer </details>.
	 *
	 * @param \DOMElement $body Parsed content.
	 * @return array[] Pairs.
	 */
	private function from_details( \DOMElement $body ): array {
		$pairs = array();
		foreach ( $body->getElementsByTagName( 'details' ) as $details ) {
			$summary = null;
			$answer  = array();
			foreach ( $details->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'summary' === strtolower( $child->nodeName ) && null === $summary ) {
					$summary = Html::text( $child );
				} elseif ( $child instanceof \DOMElement ) {
					$answer[] = Html::text( $child );
				} elseif ( XML_TEXT_NODE === $child->nodeType ) {
					$answer[] = Html::clean_text( $child->textContent );
				}
			}
			if ( null !== $summary && self::is_question( $summary ) ) {
				$pairs[] = array( $summary, Html::clean_text( implode( ' ', $answer ) ) );
			}
		}
		return $pairs;
	}

	/**
	 * Headings ending with "?" and the content up to the next heading. Needs at least two.
	 *
	 * @param \DOMElement $body Parsed content.
	 * @return array[] Pairs.
	 */
	private function from_headings( \DOMElement $body ): array {
		$pairs   = array();
		$current = null;
		$answer  = array();
		foreach ( Html::flat_blocks( $body ) as $element ) {
			$level = Html::heading_level( $element );
			if ( $level > 0 ) {
				if ( null !== $current ) {
					$pairs[] = array( $current, Html::clean_text( implode( ' ', $answer ) ) );
				}
				$text    = Html::text( $element );
				$current = self::is_question( $text ) ? $text : null;
				$answer  = array();
				continue;
			}
			if ( null !== $current && in_array( strtolower( $element->nodeName ), array( 'p', 'ul', 'ol', 'blockquote', 'table', 'pre' ), true ) ) {
				$answer[] = Html::text( $element );
			}
		}
		if ( null !== $current ) {
			$pairs[] = array( $current, Html::clean_text( implode( ' ', $answer ) ) );
		}
		$pairs = array_values(
			array_filter(
				$pairs,
				static function ( $pair ) {
					return '' !== $pair[1];
				}
			)
		);
		return count( $pairs ) >= 2 ? $pairs : array();
	}

	/**
	 * <dl><dt>Question?</dt><dd>answer</dd></dl>.
	 *
	 * @param \DOMElement $body Parsed content.
	 * @return array[] Pairs.
	 */
	private function from_definition_lists( \DOMElement $body ): array {
		$pairs = array();
		foreach ( $body->getElementsByTagName( 'dl' ) as $dl ) {
			$question = null;
			$answer   = array();
			foreach ( Html::children( $dl ) as $child ) {
				$tag = strtolower( $child->nodeName );
				if ( 'dt' === $tag ) {
					if ( null !== $question && array() !== $answer ) {
						$pairs[] = array( $question, implode( ' ', $answer ) );
					}
					$text     = Html::text( $child );
					$question = self::is_question( $text ) ? $text : null;
					$answer   = array();
				} elseif ( 'dd' === $tag && null !== $question ) {
					$answer[] = Html::text( $child );
				}
			}
			if ( null !== $question && array() !== $answer ) {
				$pairs[] = array( $question, implode( ' ', $answer ) );
			}
		}
		return $pairs;
	}

	/**
	 * Adds pairs that have both parts and a question not seen yet.
	 *
	 * @param array[]  $pairs   Collected pairs.
	 * @param string[] $sources Collected sources.
	 * @param array[]  $found   New pairs.
	 * @param string   $source  Source label.
	 */
	private function add_pairs( array &$pairs, array &$sources, array $found, string $source ): void {
		$seen = array();
		foreach ( $pairs as $pair ) {
			$seen[ Html::normalize( $pair[0] ) ] = true;
		}
		foreach ( $found as $pair ) {
			$key = Html::normalize( $pair[0] );
			if ( '' === $key || '' === trim( $pair[1] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$pairs[]      = $pair;
			$sources[]    = $source;
		}
	}

	/**
	 * Whether text reads as a question (ends with a question mark, Latin or full-width).
	 *
	 * @param string $text Text.
	 */
	private static function is_question( string $text ): bool {
		return 1 === preg_match( '/[?？]\s*$/u', $text ) && Html::length( $text ) <= 300;
	}

	/**
	 * Yoast stores rich text as a string or a list of strings / element arrays.
	 *
	 * @param mixed $value Value.
	 */
	private static function rich_text( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$out = '';
		foreach ( $value as $piece ) {
			if ( is_string( $piece ) ) {
				$out .= $piece;
			} elseif ( is_array( $piece ) && isset( $piece['props']['children'] ) ) {
				$out .= self::rich_text( $piece['props']['children'] );
			}
		}
		return $out;
	}
}
