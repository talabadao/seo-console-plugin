<?php
/**
 * Frontend output. Approved schema goes INSIDE the SEO plugin's graph:
 *  - Rank Math: filter rank_math/json_ld (our pieces under "seoc_*" keys),
 *  - Yoast:     filter wpseo_schema_graph (pieces appended),
 *  - none:      one <script type="application/ld+json" class="seoc-schema"> in wp_head.
 * Types already in the graph (same de-duplication group) are never added again.
 *
 * Output happens on singular views of posts with approved `_seoc_schema`, plus the site-wide
 * Organization / LocalBusiness from settings on the front page.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Modules\Schema\Extractors\BusinessExtractor;

defined( 'ABSPATH' ) || exit;

final class Output {

	/** @var string 'rankmath'|'yoast'|'none' */
	private $seo_plugin;

	/** @var Composer */
	private $composer;

	/** @var callable */
	private $settings_provider;

	/** @var Validator */
	private $validator;

	/**
	 * @param string        $seo_plugin        Active SEO plugin ('rankmath'|'yoast'|'none').
	 * @param Composer|null $composer          Composer.
	 * @param callable|null $settings_provider fn(): array of module settings (SettingsFields keys).
	 * @param Validator|null $validator        Validator.
	 */
	public function __construct( string $seo_plugin, ?Composer $composer = null, ?callable $settings_provider = null, ?Validator $validator = null ) {
		$this->seo_plugin        = in_array( $seo_plugin, array( 'rankmath', 'yoast' ), true ) ? $seo_plugin : 'none';
		$this->composer          = $composer ? $composer : new Composer();
		$this->settings_provider = $settings_provider ? $settings_provider : array( SettingsFields::class, 'defaults' );
		$this->validator         = $validator ? $validator : new Validator();
	}

	/** Output mode. */
	public function mode(): string {
		return $this->seo_plugin;
	}

	/** Adds the frontend hook for the current mode. */
	public function register(): void {
		if ( 'rankmath' === $this->seo_plugin ) {
			add_filter( 'rank_math/json_ld', array( $this, 'filter_rank_math' ), 999, 2 );
		} elseif ( 'yoast' === $this->seo_plugin ) {
			add_filter( 'wpseo_schema_graph', array( $this, 'filter_yoast' ), 99, 2 );
		} else {
			add_action( 'wp_head', array( $this, 'print_script' ), 30 );
		}
	}

	/**
	 * Rank Math: $data is an array of nodes keyed by name ('WebPage', 'publisher', 'richSnippet', ...).
	 *
	 * @param mixed $data   Rank Math JSON-LD data.
	 * @param mixed $jsonld Rank Math JsonLD instance (unused).
	 * @return mixed
	 */
	public function filter_rank_math( $data, $jsonld = null ) {
		unset( $jsonld );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$existing = array_values( array_filter( $data, 'is_array' ) );
		foreach ( self::merge( $existing, $this->pieces( $existing ) ) as $node ) {
			$key = 'seoc_' . sanitize_key( (string) substr( (string) strrchr( $node['@id'], '#' ), 1 ) );
			while ( isset( $data[ $key ] ) ) {
				$key .= '_';
			}
			$data[ $key ] = $node;
		}
		return $data;
	}

	/**
	 * Yoast: $graph is a list of pieces.
	 *
	 * @param mixed $graph   Yoast graph.
	 * @param mixed $context Yoast Meta_Tags_Context (unused).
	 * @return mixed
	 */
	public function filter_yoast( $graph, $context = null ) {
		unset( $context );
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		$existing = array_values( array_filter( $graph, 'is_array' ) );
		foreach ( self::merge( $existing, $this->pieces( $existing ) ) as $node ) {
			$graph[] = $node;
		}
		return $graph;
	}

	/** No SEO plugin: prints our own graph. */
	public function print_script(): void {
		$graph = self::merge( array(), $this->pieces( array() ) );
		if ( array() === $graph ) {
			return;
		}
		$json = self::encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			)
		);
		if ( '' === $json ) {
			return;
		}
		echo "\n" . '<script type="application/ld+json" class="seoc-schema">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode with "</" and "<!--" escaped (see encode()).
	}

	/**
	 * JSON for a <script> element: unescaped slashes/unicode for readability, but "</" and "<!--"
	 * escaped so content can never close the script element.
	 *
	 * @param array $data Data.
	 */
	public static function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '';
		}
		return str_replace( array( '</', '<!--' ), array( '<\/', '<!--' ), $json );
	}

	/**
	 * Our composed pieces for the current request (before de-duplication against $existing).
	 *
	 * @param array[] $existing Nodes already in the SEO plugin's graph (used to find WebPage/Organization @ids).
	 * @return array[]
	 */
	public function pieces( array $existing ): array {
		$settings = (array) call_user_func( $this->settings_provider );
		if ( isset( $settings['output_enabled'] ) && ! $settings['output_enabled'] ) {
			return array();
		}
		$pieces  = array();
		$org_id  = self::find_id( $existing, 'Organization' ) ?? self::find_id( $existing, 'LocalBusiness' );
		$webpage = self::find_id( $existing, 'WebPage' );

		if ( is_front_page() ) {
			$site = $this->site_node( $settings, $org_id );
			if ( null !== $site ) {
				$pieces[] = $site;
				if ( null === $org_id ) {
					$org_id = $site['@id'];
				}
			}
		}

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$nodes   = array_values(
				array_filter(
					Store::get( $post_id ),
					static function ( $node ) {
						$type = isset( $node['@type'] ) ? Types::primary( $node['@type'] ) : null;
						return null !== $type && Types::get( $type )['top_level'];
					}
				)
			);
			if ( array() !== $nodes ) {
				$pieces = array_merge(
					$pieces,
					$this->composer->compose(
						$nodes,
						array(
							'url'             => (string) get_permalink( $post_id ),
							'webpage_id'      => $webpage,
							'organization_id' => $org_id,
							'date_modified'   => (string) get_post_modified_time( DATE_W3C, true, $post_id ),
						)
					)
				);
			}
		}

		/**
		 * Filters SEO Console's schema pieces for the current page before de-duplication.
		 *
		 * @param array[] $pieces     Pieces.
		 * @param string  $seo_plugin Output mode.
		 */
		return (array) apply_filters( 'seoc_schema_pieces', $pieces, $this->seo_plugin );
	}

	/**
	 * Site-wide Organization / LocalBusiness from settings, or null when not configured or invalid.
	 *
	 * @param array       $settings Module settings.
	 * @param string|null $org_id   SEO plugin's Organization @id.
	 * @return array|null
	 */
	private function site_node( array $settings, ?string $org_id ): ?array {
		$built = ( new BusinessExtractor() )->build( $settings );
		if ( null === $built['node'] || ! $this->validator->validate_node( $built['node'] )['valid'] ) {
			return null;
		}
		return $this->composer->compose_site( $built['node'], array( 'parent_organization_id' => (string) $org_id ) );
	}

	/**
	 * Keeps only pieces whose type group is not already in the graph (and one per group for
	 * "single" types such as FAQPage).
	 *
	 * @param array[] $existing Nodes already in the graph.
	 * @param array[] $ours     Our pieces.
	 * @return array[]
	 */
	public static function merge( array $existing, array $ours ): array {
		$taken = array();
		foreach ( $existing as $node ) {
			foreach ( isset( $node['@type'] ) ? (array) $node['@type'] : array() as $type ) {
				if ( is_string( $type ) ) {
					$taken[ Types::group( $type ) ] = true;
				}
			}
		}
		$out = array();
		foreach ( $ours as $node ) {
			$type = isset( $node['@type'] ) ? Types::primary( $node['@type'] ) : null;
			if ( null === $type || empty( $node['@id'] ) ) {
				continue;
			}
			$group = Types::group( $type );
			if ( isset( $taken[ $group ] ) ) {
				continue;
			}
			if ( Types::get( $type )['single'] ) {
				$taken[ $group ] = true;
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * The @id of the first node in $nodes of the given group ('WebPage' or 'Organization').
	 *
	 * @param array[] $nodes Nodes.
	 * @param string  $group Group.
	 * @return string|null
	 */
	private static function find_id( array $nodes, string $group ): ?string {
		foreach ( $nodes as $node ) {
			if ( empty( $node['@id'] ) || ! is_string( $node['@id'] ) || ! isset( $node['@type'] ) ) {
				continue;
			}
			foreach ( (array) $node['@type'] as $type ) {
				if ( is_string( $type ) && Types::group( $type ) === $group ) {
					return $node['@id'];
				}
			}
		}
		return null;
	}
}
