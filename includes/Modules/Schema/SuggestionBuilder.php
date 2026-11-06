<?php
/**
 * Builds the approval-queue suggestion (docs/ARCHITECTURE.md §7.3) for one post: runs the
 * deterministic extractors and, optionally, the AI generator for types that are not already
 * output (by the SEO plugin, the content, or SEO Console itself), validates every node, and
 * proposes the post's full `_seoc_schema` value (approved nodes kept + new nodes).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Modules\Schema\Extractors\ArticleExtractor;
use SEOConsole\Modules\Schema\Extractors\BreadcrumbExtractor;
use SEOConsole\Modules\Schema\Extractors\ExtractorInterface;
use SEOConsole\Modules\Schema\Extractors\FaqExtractor;
use SEOConsole\Modules\Schema\Extractors\HowToExtractor;
use SEOConsole\Modules\Schema\Extractors\ProductExtractor;
use SEOConsole\Modules\Schema\Extractors\VideoExtractor;

defined( 'ABSPATH' ) || exit;

final class SuggestionBuilder {

	/** Applier id registered in Phase B. */
	const APPLIER = 'schema';

	/** @var Detector */
	private $detector;

	/** @var Validator */
	private $validator;

	/** @var ExtractorInterface[] */
	private $extractors;

	/** @var AiGenerator|null */
	private $ai;

	/**
	 * @param Detector                  $detector   Detector.
	 * @param Validator|null            $validator  Validator.
	 * @param ExtractorInterface[]|null $extractors Extractors (default: default_extractors() with default settings).
	 * @param AiGenerator|null          $ai         AI generator (null = rules only).
	 */
	public function __construct( Detector $detector, ?Validator $validator = null, ?array $extractors = null, ?AiGenerator $ai = null ) {
		$this->detector   = $detector;
		$this->validator  = $validator ? $validator : new Validator();
		$this->extractors = null !== $extractors ? $extractors : self::default_extractors( SettingsFields::defaults(), $detector->seo_plugin() );
		$this->ai         = $ai;
	}

	/**
	 * Extractors enabled by the module settings. Article and breadcrumbs only when no SEO plugin
	 * is active (Rank Math and Yoast own those).
	 *
	 * @param array  $settings   Module settings.
	 * @param string $seo_plugin Active SEO plugin.
	 * @return ExtractorInterface[]
	 */
	public static function default_extractors( array $settings, string $seo_plugin ): array {
		$on  = static function ( $key ) use ( $settings ) {
			return ! isset( $settings[ $key ] ) || (bool) $settings[ $key ];
		};
		$out = array();
		if ( $on( 'basic_without_seo_plugin' ) && 'none' === $seo_plugin ) {
			$out[] = new ArticleExtractor();
			$out[] = new BreadcrumbExtractor();
		}
		if ( $on( 'detect_faq' ) ) {
			$out[] = new FaqExtractor();
		}
		if ( $on( 'detect_howto' ) ) {
			$out[] = new HowToExtractor();
		}
		if ( $on( 'detect_video' ) ) {
			$out[] = new VideoExtractor();
		}
		if ( $on( 'detect_product' ) ) {
			$out[] = new ProductExtractor();
		}
		return $out;
	}

	/**
	 * Builds the suggestion for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $opts use_ai (bool, default true when an AiGenerator was given), ai_types (string[]),
	 *                       rendered_html (string, page HTML to scan for existing schema).
	 * @return array{suggestion: array|null, added: array[], skipped: array[], incomplete: array[], rejected: array[], ai_error: \WP_Error|null}
	 */
	public function for_post( \WP_Post $post, array $opts = array() ): array {
		$result = array(
			'suggestion' => null,
			'added'      => array(),
			'skipped'    => array(),
			'incomplete' => array(),
			'rejected'   => array(),
			'ai_error'   => null,
		);
		$content  = (string) $post->post_content;
		$found    = $this->detector->detect( $post->ID, isset( $opts['rendered_html'] ) ? (string) $opts['rendered_html'] : '' );
		$current  = Store::raw( $post->ID );
		$existing = null === $current ? array() : $current;

		// Groups already present: approved by SEO Console, or output by something else.
		$taken = array();
		foreach ( Store::types( $existing ) as $type ) {
			$taken[ Types::group( $type ) ] = __( 'already approved in SEO Console', 'seo-console' );
		}
		foreach ( $found as $finding ) {
			$group = Types::group( $finding['type'] );
			if ( Detector::SOURCE_SEOC !== $finding['source'] && ! isset( $taken[ $group ] ) ) {
				/* translators: %s: where the schema comes from, e.g. "Rank Math". */
				$taken[ $group ] = sprintf( __( 'already output by %s', 'seo-console' ), Detector::source_label( $finding['source'] ) );
			}
		}

		$context = array( 'content' => $content );
		foreach ( $this->extractors as $extractor ) {
			$group = Types::group( $extractor->type() );
			if ( isset( $taken[ $group ] ) ) {
				$result['skipped'][] = self::skip( $extractor->type(), $taken[ $group ] );
				continue;
			}
			foreach ( $extractor->extract( $post ) as $candidate ) {
				$this->consider( $candidate, $context, $taken, $result );
			}
		}

		$use_ai = null !== $this->ai && ( ! isset( $opts['use_ai'] ) || $opts['use_ai'] );
		if ( $use_ai ) {
			$ai_types = isset( $opts['ai_types'] ) ? (array) $opts['ai_types'] : Types::ai_types();
			$ask      = array();
			foreach ( array_intersect( Types::ai_types(), $ai_types ) as $type ) {
				if ( isset( $taken[ Types::group( $type ) ] ) ) {
					$result['skipped'][] = self::skip( $type, $taken[ Types::group( $type ) ] );
				} else {
					$ask[] = $type;
				}
			}
			if ( array() !== $ask ) {
				$skip      = array_values( array_unique( array_merge( Store::types( $existing ), wp_list_pluck( $found, 'type' ) ) ) );
				$generated = $this->ai->generate( $post, $ask, array( 'skip' => $skip ) );
				if ( is_wp_error( $generated ) ) {
					$result['ai_error'] = $generated;
				} else {
					$result['rejected'] = $generated['rejected'];
					foreach ( $generated['accepted'] as $candidate ) {
						$this->consider( $candidate, $context, $taken, $result );
					}
				}
			}
		}

		if ( array() === $result['added'] ) {
			return $result;
		}

		$proposed = array_merge( $existing, wp_list_pluck( $result['added'], 'node' ) );
		$check    = $this->validator->validate_graph( $proposed, $context );
		if ( ! $check['valid'] ) {
			foreach ( $result['added'] as $candidate ) {
				$candidate['errors']    = $check['errors'];
				$result['incomplete'][] = $candidate;
			}
			$result['added'] = array();
			return $result;
		}

		$sources              = array_unique( wp_list_pluck( $result['added'], 'source' ) );
		$is_ai                = in_array( 'ai', $sources, true );
		$result['suggestion'] = array(
			'module'         => 'schema',
			'type'           => self::APPLIER,
			'object_type'    => 'post',
			'object_id'      => (int) $post->ID,
			'field'          => 'schema',
			'current_value'  => $current,
			'proposed_value' => $proposed,
			'rationale'      => self::rationale( $result['added'], $result['skipped'] ),
			'confidence'     => $is_ai ? 0.6 : 0.9,
			'source'         => $is_ai ? 'ai' : 'rule',
		);
		return $result;
	}

	/**
	 * Validates a candidate and files it under added / incomplete / skipped.
	 *
	 * @param array $candidate Candidate.
	 * @param array $context   Validator context.
	 * @param array $taken     Groups already present (updated).
	 * @param array $result    Result (updated).
	 */
	private function consider( array $candidate, array $context, array &$taken, array &$result ): void {
		$type  = $candidate['type'];
		$group = Types::group( $type );
		if ( isset( $taken[ $group ] ) ) {
			$result['skipped'][] = self::skip( $type, $taken[ $group ] );
			return;
		}
		$check = $this->validator->validate_node( $candidate['node'], $context );
		if ( ! $check['valid'] ) {
			$candidate['errors']    = $check['errors'];
			$result['incomplete'][] = $candidate;
			return;
		}
		$candidate['warnings'] = $check['warnings'];
		$result['added'][]     = $candidate;
		if ( Types::get( $type )['single'] ) {
			$taken[ $group ] = __( 'already proposed', 'seo-console' );
		}
	}

	/**
	 * Plain-English rationale: what is added and why, the honest rich-result note per type, and
	 * what was skipped.
	 *
	 * @param array[] $added   Added candidates.
	 * @param array[] $skipped Skipped entries.
	 */
	public static function rationale( array $added, array $skipped ): string {
		$lines = array();
		$noted = array();
		foreach ( $added as $candidate ) {
			$type = $candidate['type'];
			$line = Types::label( $type ) . ': ' . ( '' !== $candidate['evidence'] ? $candidate['evidence'] : __( 'Suggested from the page content.', 'seo-console' ) );
			if ( 'ai' === $candidate['source'] ) {
				$line .= ' ' . __( '(Suggested by AI; please check every value against the page.)', 'seo-console' );
			}
			if ( ! isset( $noted[ $type ] ) ) {
				$line         .= ' ' . Types::note( $type );
				$noted[ $type ] = true;
			}
			$lines[] = trim( $line );
		}
		$not = array();
		foreach ( $skipped as $skip ) {
			$not[ $skip['type'] ] = Types::label( $skip['type'] ) . ' (' . $skip['reason'] . ')';
		}
		if ( array() !== $not ) {
			/* translators: %s: list of skipped types with reasons. */
			$lines[] = sprintf( __( 'Not added: %s.', 'seo-console' ), implode( '; ', $not ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $type   Type.
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function skip( string $type, string $reason ): array {
		return array(
			'type'   => $type,
			'reason' => $reason,
		);
	}
}
