<?php
/**
 * HowTo from content, without AI: the first ordered list (at least two items) under a heading that
 * reads like instructions ("How to …", "Step-by-step …", Vietnamese "Cách …").
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API properties.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Html;

defined( 'ABSPATH' ) || exit;

final class HowToExtractor extends AbstractExtractor {

	/** Max steps. */
	const MAX_STEPS = 50;

	/** {@inheritDoc} */
	public function type(): string {
		return 'HowTo';
	}

	/** {@inheritDoc} */
	public function extract( \WP_Post $post ): array {
		$body = Html::body( (string) $post->post_content );
		if ( ! $body ) {
			return array();
		}
		$heading = null;
		$level   = 0;
		foreach ( Html::flat_blocks( $body ) as $element ) {
			$this_level = Html::heading_level( $element );
			if ( $this_level > 0 ) {
				if ( null !== $heading && $this_level > $level ) {
					continue; // A sub-heading inside the how-to section.
				}
				$text    = Html::text( $element );
				$heading = self::is_howto_heading( $text ) ? $text : null;
				$level   = $this_level;
				continue;
			}
			if ( null === $heading || 'ol' !== strtolower( $element->nodeName ) ) {
				continue;
			}
			$steps = $this->steps( $element );
			if ( count( $steps ) < 2 ) {
				continue;
			}
			$evidence = sprintf(
				/* translators: 1: number of steps, 2: heading text. */
				__( 'Found a numbered list of %1$d steps under the heading "%2$s".', 'seo-console' ),
				count( $steps ),
				$heading
			);
			return array(
				$this->candidate(
					array(
						'@type' => 'HowTo',
						'name'  => $heading,
						'step'  => $steps,
					),
					$evidence
				),
			);
		}
		return array();
	}

	/**
	 * HowToStep nodes from the direct <li> children of an <ol>. A leading <strong>/<b> becomes the step name.
	 *
	 * @param \DOMElement $ol Ordered list.
	 * @return array[]
	 */
	private function steps( \DOMElement $ol ): array {
		$steps = array();
		foreach ( Html::children( $ol, 'li' ) as $li ) {
			$text = Html::text( $li );
			if ( '' === $text ) {
				continue;
			}
			$step  = array(
				'@type' => 'HowToStep',
				'text'  => $text,
			);
			$first = null;
			foreach ( $li->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->textContent ) ) {
					continue;
				}
				$first = $child;
				break;
			}
			if ( $first instanceof \DOMElement && in_array( strtolower( $first->nodeName ), array( 'strong', 'b' ), true ) ) {
				$name = rtrim( Html::text( $first ), ' :.-' );
				if ( '' !== $name && $name !== $text ) {
					$step['name'] = $name;
				}
			}
			$steps[] = $step;
			if ( count( $steps ) >= self::MAX_STEPS ) {
				break;
			}
		}
		return $steps;
	}

	/**
	 * @param string $text Heading text.
	 */
	private static function is_howto_heading( string $text ): bool {
		/**
		 * Filters regular expressions that mark a heading as the start of instructions.
		 *
		 * @param string[] $patterns PCRE patterns (case-insensitive, unicode).
		 */
		$patterns = (array) apply_filters(
			'seoc_schema_howto_heading_patterns',
			array( '/\bhow to\b/iu', '/\bstep[- ]by[- ]step\b/iu', '/\bsteps to\b/iu', '/^cách\s/iu', '/hướng dẫn/iu' )
		);
		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && @preg_match( $pattern, $text ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filtered patterns may be invalid.
				return true;
			}
		}
		return false;
	}
}
