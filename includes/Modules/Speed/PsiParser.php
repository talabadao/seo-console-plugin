<?php
/**
 * Turns a raw PageSpeed Insights v5 response into a compact, storable result.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Compact PSI result (schema 1):
 *
 *     [
 *       'schema'                => 1,
 *       'url'                   => requested URL,
 *       'final_url'             => URL after redirects,
 *       'strategy'              => 'mobile'|'desktop',
 *       'fetched_at'            => ISO 8601 UTC,
 *       'lighthouse_version'    => '12.6.1',
 *       'score'                 => 0–100|null,
 *       'lab'                   => [ lcp|fcp|tbt|cls|si|ttfb => [ 'value' => float (ms; CLS unitless), 'display' => string, 'score' => 0–1|null ] ],
 *       'field'                 => [ 'url' => FieldBlock|null, 'origin' => FieldBlock|null ],
 *       'lcp_element'           => [ 'type' => image|text|other, 'selector', 'snippet', 'label', 'image_url', 'srcset', 'sizes',
 *                                    'lazy_loaded' => bool, 'priority_hinted' => bool|null, 'phases' => [ ttfb|load_delay|load_time|render_delay => ms ] ]|null,
 *       'third_parties'         => [ [ 'entity', 'origins' => string[], 'transfer_bytes', 'blocking_ms', 'main_thread_ms' ] ] (max 10),
 *       'preconnect_candidates' => string[] origins (max 4),
 *       'audits'                => [ [ 'id', 'kind' => opportunity|diagnostic, 'title', 'display_value', 'score', 'savings_ms', 'savings_bytes',
 *                                      'metric_savings' => [ LCP|FCP|TBT|CLS|INP => number ], 'items' => [ ... max 5 ] ] ] (failing only, max 25),
 *       'warnings'              => string[],
 *     ]
 *
 * FieldBlock: [ 'id' => string, 'overall' => good|ni|poor|null, 'metrics' => [ lcp|inp|cls|fcp|ttfb => [ 'p75', 'category', 'good', 'ni', 'poor' ] ] ].
 */
class PsiParser {

	const SCHEMA = 1;

	/** Size budget for one compact result (JSON bytes). */
	const MAX_BYTES = 30000;

	const MAX_AUDITS = 25;

	const MAX_ITEMS = 5;

	const MAX_STRING = 300;

	const MAX_THIRD_PARTIES = 10;

	const MAX_PRECONNECT = 4;

	/** Lab metric key → audit id. */
	const LAB_METRICS = array(
		'lcp'  => 'largest-contentful-paint',
		'fcp'  => 'first-contentful-paint',
		'tbt'  => 'total-blocking-time',
		'cls'  => 'cumulative-layout-shift',
		'si'   => 'speed-index',
		'ttfb' => 'server-response-time',
	);

	/** PSI field metric name → our key. */
	const FIELD_METRICS = array(
		'LARGEST_CONTENTFUL_PAINT_MS'     => 'lcp',
		'INTERACTION_TO_NEXT_PAINT'       => 'inp',
		'CUMULATIVE_LAYOUT_SHIFT_SCORE'   => 'cls',
		'FIRST_CONTENTFUL_PAINT_MS'       => 'fcp',
		'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb',
		'TIME_TO_FIRST_BYTE'              => 'ttfb',
	);

	const CATEGORIES = array(
		'FAST'    => 'good',
		'AVERAGE' => 'ni',
		'SLOW'    => 'poor',
	);

	/** Audits never listed as failing opportunities/diagnostics (metrics, raw data, handled elsewhere). */
	const SKIP_AUDITS = array(
		'first-contentful-paint',
		'largest-contentful-paint',
		'total-blocking-time',
		'cumulative-layout-shift',
		'speed-index',
		'interactive',
		'max-potential-fid',
		'first-meaningful-paint',
		'largest-contentful-paint-element',
		'lcp-breakdown-insight',
		'lcp-phases-insight',
		'network-requests',
		'network-rtt',
		'network-server-latency',
		'main-thread-tasks',
		'diagnostics',
		'metrics',
		'screenshot-thumbnails',
		'final-screenshot',
		'full-page-screenshot',
		'script-treemap-data',
		'resource-summary',
		'performance-budget',
		'timing-budget',
	);

	/** Item keys kept (numbers or short strings). */
	const ITEM_KEYS = array(
		'url',
		'entity',
		'label',
		'groupLabel',
		'statistic',
		'value',
		'totalBytes',
		'wastedBytes',
		'wastedMs',
		'wastedPercent',
		'transferSize',
		'cacheLifetimeMs',
		'blockingTime',
		'mainThreadTime',
		'total',
		'scripting',
		'duration',
		'responseTime',
		'score',
	);

	/**
	 * Parses a raw PSI response.
	 *
	 * @param array  $raw      Decoded PSI JSON.
	 * @param string $strategy Strategy used for the request (falls back to Lighthouse's form factor).
	 * @return array Compact result (see class doc).
	 */
	public function parse( array $raw, string $strategy = '' ): array {
		$lh     = isset( $raw['lighthouseResult'] ) && is_array( $raw['lighthouseResult'] ) ? $raw['lighthouseResult'] : array();
		$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

		if ( '' === $strategy ) {
			$strategy = isset( $lh['configSettings']['formFactor'] ) ? (string) $lh['configSettings']['formFactor'] : 'mobile';
		}

		$final_url = self::str( $lh, 'finalDisplayedUrl' );
		if ( '' === $final_url ) {
			$final_url = self::str( $lh, 'finalUrl' );
		}
		$requested = self::str( $lh, 'requestedUrl' );
		if ( '' === $requested ) {
			$requested = isset( $raw['id'] ) ? (string) $raw['id'] : $final_url;
		}
		if ( '' === $final_url ) {
			$final_url = $requested;
		}

		$score = null;
		if ( isset( $lh['categories']['performance']['score'] ) && is_numeric( $lh['categories']['performance']['score'] ) ) {
			$score = (int) round( 100 * (float) $lh['categories']['performance']['score'] );
		}

		$third = $this->third_parties( $audits, $final_url );

		$result = array(
			'schema'                => self::SCHEMA,
			'url'                   => self::trim( $requested ),
			'final_url'             => self::trim( $final_url ),
			'strategy'              => 'desktop' === $strategy ? 'desktop' : 'mobile',
			'fetched_at'            => self::str( $lh, 'fetchTime' ),
			'lighthouse_version'    => self::str( $lh, 'lighthouseVersion' ),
			'score'                 => $score,
			'lab'                   => $this->lab( $audits ),
			'field'                 => array(
				'url'    => $this->field( isset( $raw['loadingExperience'] ) ? $raw['loadingExperience'] : null, true ),
				'origin' => $this->field( isset( $raw['originLoadingExperience'] ) ? $raw['originLoadingExperience'] : null, false ),
			),
			'lcp_element'           => $this->lcp_element( $audits, $final_url ),
			'third_parties'         => $third,
			'preconnect_candidates' => $this->preconnect_candidates( $audits, $third, $final_url ),
			'audits'                => $this->failing_audits( $audits ),
			'warnings'              => array(),
		);

		if ( ! empty( $lh['runWarnings'] ) && is_array( $lh['runWarnings'] ) ) {
			foreach ( array_slice( $lh['runWarnings'], 0, 5 ) as $warning ) {
				if ( is_string( $warning ) ) {
					$result['warnings'][] = self::trim( wp_strip_all_tags( $warning ) );
				}
			}
		}

		return $this->enforce_budget( $result );
	}

	/**
	 * Lab metrics.
	 *
	 * @param array $audits Audits.
	 * @return array
	 */
	private function lab( array $audits ): array {
		$lab = array();
		foreach ( self::LAB_METRICS as $key => $id ) {
			$audit = isset( $audits[ $id ] ) && is_array( $audits[ $id ] ) ? $audits[ $id ] : null;
			if ( null === $audit || ! isset( $audit['numericValue'] ) || ! is_numeric( $audit['numericValue'] ) ) {
				$lab[ $key ] = null;
				continue;
			}
			$value       = (float) $audit['numericValue'];
			$lab[ $key ] = array(
				'value'   => 'cls' === $key ? round( $value, 3 ) : round( $value ),
				'display' => self::trim( self::str( $audit, 'displayValue' ) ),
				'score'   => isset( $audit['score'] ) && is_numeric( $audit['score'] ) ? round( (float) $audit['score'], 2 ) : null,
			);
		}

		// Lighthouse 13 has no server-response-time audit: fall back to the LCP breakdown's TTFB.
		if ( null === $lab['ttfb'] ) {
			$phases = $this->lcp_phases( $audits );
			if ( isset( $phases['ttfb'] ) ) {
				$lab['ttfb'] = array(
					'value'   => $phases['ttfb'],
					'display' => sprintf( '%d ms', $phases['ttfb'] ),
					'score'   => null,
				);
			}
		}
		return $lab;
	}

	/**
	 * Field data block from loadingExperience / originLoadingExperience.
	 *
	 * @param mixed $block      PSI block.
	 * @param bool  $url_level  True for loadingExperience (URL-level).
	 * @return array|null
	 */
	private function field( $block, bool $url_level ) {
		if ( ! is_array( $block ) || empty( $block['metrics'] ) || ! is_array( $block['metrics'] ) ) {
			return null;
		}
		// PSI may answer URL-level requests with origin data; that is not URL data.
		if ( $url_level && ! empty( $block['origin_fallback'] ) ) {
			return null;
		}
		$metrics = array();
		foreach ( $block['metrics'] as $name => $metric ) {
			if ( ! isset( self::FIELD_METRICS[ $name ] ) || ! is_array( $metric ) || ! isset( $metric['percentile'] ) ) {
				continue;
			}
			$key = self::FIELD_METRICS[ $name ];
			$p75 = (float) $metric['percentile'];
			if ( 'cls' === $key ) {
				$p75 = round( $p75 / 100, 2 );
			}
			$dist = array( null, null, null );
			if ( isset( $metric['distributions'] ) && is_array( $metric['distributions'] ) ) {
				foreach ( array_slice( array_values( $metric['distributions'] ), 0, 3 ) as $i => $bucket ) {
					$dist[ $i ] = isset( $bucket['proportion'] ) ? round( (float) $bucket['proportion'], 4 ) : null;
				}
			}
			$category        = isset( $metric['category'] ) ? (string) $metric['category'] : '';
			$metrics[ $key ] = array(
				'p75'      => 'cls' === $key ? $p75 : (int) round( $p75 ),
				'category' => isset( self::CATEGORIES[ $category ] ) ? self::CATEGORIES[ $category ] : Thresholds::rate( $key, $p75 ),
				'good'     => $dist[0],
				'ni'       => $dist[1],
				'poor'     => $dist[2],
			);
		}
		if ( empty( $metrics ) ) {
			return null;
		}
		$overall = isset( $block['overall_category'] ) ? (string) $block['overall_category'] : '';
		return array(
			'id'      => self::trim( isset( $block['id'] ) ? (string) $block['id'] : '' ),
			'overall' => isset( self::CATEGORIES[ $overall ] ) ? self::CATEGORIES[ $overall ] : null,
			'metrics' => $metrics,
		);
	}

	/**
	 * LCP element details (Lighthouse 10–13).
	 *
	 * @param array  $audits    Audits.
	 * @param string $final_url Page URL (to resolve relative image URLs).
	 * @return array|null
	 */
	private function lcp_element( array $audits, string $final_url ) {
		$node = null;

		// Lighthouse ≤ 12: largest-contentful-paint-element (table, or list of tables).
		if ( isset( $audits['largest-contentful-paint-element']['details'] ) ) {
			$node = self::find_node( $audits['largest-contentful-paint-element']['details'] );
		}
		// Lighthouse 12.x insights / 13: lcp-breakdown-insight, lcp-phases-insight, lcp-discovery-insight.
		foreach ( array( 'lcp-breakdown-insight', 'lcp-phases-insight', 'lcp-discovery-insight' ) as $id ) {
			if ( null === $node && isset( $audits[ $id ]['details'] ) ) {
				$node = self::find_node( $audits[ $id ]['details'] );
			}
		}

		$image_url = '';
		foreach ( array( 'prioritize-lcp-image', 'lcp-discovery-insight' ) as $id ) {
			if ( '' === $image_url && ! empty( $audits[ $id ]['details']['items'] ) && is_array( $audits[ $id ]['details']['items'] ) ) {
				foreach ( $audits[ $id ]['details']['items'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['url'] ) && is_string( $item['url'] ) ) {
						$image_url = $item['url'];
						break;
					}
				}
			}
		}

		if ( null === $node && '' === $image_url ) {
			return null;
		}

		$snippet = $node && isset( $node['snippet'] ) ? (string) $node['snippet'] : '';
		$tag     = preg_match( '/^<\s*([a-z0-9-]+)/i', $snippet, $m ) ? strtolower( $m[1] ) : '';

		if ( '' === $image_url && 'img' === $tag ) {
			$image_url = self::attr( $snippet, 'src' );
		}
		if ( '' === $image_url && preg_match( '/url\(\s*[\'"]?([^\'")]+)/i', $snippet, $bg ) ) {
			$image_url = html_entity_decode( $bg[1], ENT_QUOTES );
		}
		if ( '' === $image_url && 'video' === $tag ) {
			$image_url = self::attr( $snippet, 'poster' );
		}
		$image_url = self::absolute_url( $image_url, $final_url );

		$type = 'other';
		if ( '' !== $image_url || in_array( $tag, array( 'img', 'picture', 'svg', 'video' ), true ) ) {
			$type = 'image';
		} elseif ( null !== $node ) {
			$type = 'text';
		}

		// Lazy-loaded / priority hints.
		$lazy = false;
		if ( isset( $audits['lcp-lazy-loaded']['score'] ) && is_numeric( $audits['lcp-lazy-loaded']['score'] ) && (float) $audits['lcp-lazy-loaded']['score'] < 1 ) {
			$lazy = true;
		}
		if ( preg_match( '/\sloading=["\']?lazy/i', $snippet ) ) {
			$lazy = true;
		}
		$hinted = null;
		if ( preg_match( '/\sfetchpriority=["\']?high/i', $snippet ) ) {
			$hinted = true;
		}
		$checklist = $this->discovery_checklist( $audits );
		if ( isset( $checklist['eagerlyLoaded'] ) && false === $checklist['eagerlyLoaded'] ) {
			$lazy = true;
		}
		if ( isset( $checklist['priorityHinted'] ) ) {
			$hinted = (bool) $checklist['priorityHinted'];
		}
		if ( 'image' === $type && null === $hinted && isset( $audits['prioritize-lcp-image']['score'] ) && is_numeric( $audits['prioritize-lcp-image']['score'] ) ) {
			$hinted = (float) $audits['prioritize-lcp-image']['score'] >= 1;
		}

		$srcset = self::attr( $snippet, 'srcset' );
		$sizes  = self::attr( $snippet, 'sizes' );
		// Lighthouse truncates long attribute values with an ellipsis: unusable then.
		if ( false !== strpos( $srcset, '…' ) ) {
			$srcset = '';
		}
		if ( false !== strpos( $sizes, '…' ) ) {
			$sizes = '';
		}

		return array(
			'type'            => $type,
			'selector'        => self::trim( $node && isset( $node['selector'] ) ? (string) $node['selector'] : '' ),
			'snippet'         => self::trim( $snippet ),
			'label'           => self::trim( $node && isset( $node['nodeLabel'] ) ? (string) $node['nodeLabel'] : '' ),
			'image_url'       => self::trim( $image_url, 1000 ),
			'srcset'          => self::trim( $srcset, 2000 ),
			'sizes'           => self::trim( $sizes ),
			'lazy_loaded'     => $lazy,
			'priority_hinted' => 'image' === $type ? $hinted : null,
			'phases'          => $this->lcp_phases( $audits ),
		);
	}

	/**
	 * LCP phases in ms.
	 *
	 * @param array $audits Audits.
	 * @return array
	 */
	private function lcp_phases( array $audits ): array {
		$map    = array(
			'TTFB'                 => 'ttfb',
			'Load Delay'           => 'load_delay',
			'Load Time'            => 'load_time',
			'Render Delay'         => 'render_delay',
			'timeToFirstByte'      => 'ttfb',
			'resourceLoadDelay'    => 'load_delay',
			'resourceLoadDuration' => 'load_time',
			'elementRenderDelay'   => 'render_delay',
		);
		$phases = array();
		foreach ( array( 'largest-contentful-paint-element', 'lcp-breakdown-insight', 'lcp-phases-insight' ) as $id ) {
			if ( empty( $audits[ $id ]['details'] ) || ! is_array( $audits[ $id ]['details'] ) ) {
				continue;
			}
			foreach ( self::tables( $audits[ $id ]['details'] ) as $table ) {
				foreach ( $table as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$name  = isset( $row['phase'] ) ? $row['phase'] : ( isset( $row['subpart'] ) ? $row['subpart'] : null );
					$value = isset( $row['timing'] ) ? $row['timing'] : ( isset( $row['duration'] ) ? $row['duration'] : null );
					if ( is_string( $name ) && isset( $map[ $name ] ) && is_numeric( $value ) ) {
						$phases[ $map[ $name ] ] = (int) round( (float) $value );
					}
				}
			}
			if ( $phases ) {
				break;
			}
		}
		return $phases;
	}

	/**
	 * Lighthouse 13 lcp-discovery-insight checklist as name => bool.
	 *
	 * @param array $audits Audits.
	 * @return array
	 */
	private function discovery_checklist( array $audits ): array {
		$out = array();
		if ( empty( $audits['lcp-discovery-insight']['details'] ) ) {
			return $out;
		}
		$details = $audits['lcp-discovery-insight']['details'];
		$lists   = array( $details );
		if ( isset( $details['items'] ) && is_array( $details['items'] ) ) {
			$lists = array_merge( $lists, $details['items'] );
		}
		foreach ( $lists as $list ) {
			if ( is_array( $list ) && isset( $list['type'] ) && 'checklist' === $list['type'] && isset( $list['items'] ) && is_array( $list['items'] ) ) {
				foreach ( $list['items'] as $name => $entry ) {
					if ( is_array( $entry ) && array_key_exists( 'value', $entry ) ) {
						$out[ $name ] = (bool) $entry['value'];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Third-party entities (excluding the site's own host).
	 *
	 * @param array  $audits    Audits.
	 * @param string $final_url Page URL.
	 * @return array
	 */
	private function third_parties( array $audits, string $final_url ): array {
		$site_host = self::host( $final_url );
		$entities  = array();

		foreach ( array( 'third-party-summary', 'third-parties-insight' ) as $id ) {
			if ( empty( $audits[ $id ]['details']['items'] ) || ! is_array( $audits[ $id ]['details']['items'] ) ) {
				continue;
			}
			foreach ( $audits[ $id ]['details']['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name    = self::entity_name( isset( $item['entity'] ) ? $item['entity'] : '' );
				$origins = array();
				$urls    = array();
				if ( isset( $item['subItems']['items'] ) && is_array( $item['subItems']['items'] ) ) {
					foreach ( $item['subItems']['items'] as $sub ) {
						if ( is_array( $sub ) && isset( $sub['url'] ) && is_string( $sub['url'] ) ) {
							$urls[] = $sub['url'];
						}
					}
				}
				if ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
					$urls[] = $item['url'];
				}
				foreach ( $urls as $url ) {
					$origin = self::origin( $url );
					if ( '' !== $origin && ! self::same_site( self::host( $url ), $site_host ) ) {
						$origins[ $origin ] = true;
					}
				}
				if ( empty( $origins ) ) {
					continue; // First party (or unknown).
				}
				$entities[] = array(
					'entity'         => self::trim( '' !== $name ? $name : implode( ', ', array_keys( $origins ) ) ),
					'origins'        => array_slice( array_keys( $origins ), 0, 5 ),
					'transfer_bytes' => isset( $item['transferSize'] ) ? (int) $item['transferSize'] : 0,
					'blocking_ms'    => isset( $item['blockingTime'] ) ? (int) round( (float) $item['blockingTime'] ) : 0,
					'main_thread_ms' => isset( $item['mainThreadTime'] ) ? (int) round( (float) $item['mainThreadTime'] ) : 0,
				);
			}
			if ( $entities ) {
				break;
			}
		}

		// Fallback: group network requests by third-party origin.
		if ( empty( $entities ) && ! empty( $audits['network-requests']['details']['items'] ) && is_array( $audits['network-requests']['details']['items'] ) ) {
			$by_origin = array();
			foreach ( $audits['network-requests']['details']['items'] as $req ) {
				if ( ! is_array( $req ) || empty( $req['url'] ) || ! is_string( $req['url'] ) ) {
					continue;
				}
				$origin = self::origin( $req['url'] );
				if ( '' === $origin || self::same_site( self::host( $req['url'] ), $site_host ) ) {
					continue;
				}
				if ( ! isset( $by_origin[ $origin ] ) ) {
					$by_origin[ $origin ] = 0;
				}
				$by_origin[ $origin ] += isset( $req['transferSize'] ) ? (int) $req['transferSize'] : 0;
			}
			foreach ( $by_origin as $origin => $bytes ) {
				$entities[] = array(
					'entity'         => self::host( $origin ),
					'origins'        => array( $origin ),
					'transfer_bytes' => $bytes,
					'blocking_ms'    => 0,
					'main_thread_ms' => 0,
				);
			}
		}

		usort(
			$entities,
			static function ( $a, $b ) {
				if ( $a['blocking_ms'] !== $b['blocking_ms'] ) {
					return $b['blocking_ms'] <=> $a['blocking_ms'];
				}
				return $b['transfer_bytes'] <=> $a['transfer_bytes'];
			}
		);
		return array_slice( $entities, 0, self::MAX_THIRD_PARTIES );
	}

	/**
	 * Origins worth a preconnect hint: Lighthouse's own suggestions first, then
	 * render-critical third-party origins (fonts, render-blocking CSS/JS), then the heaviest.
	 *
	 * @param array  $audits    Audits.
	 * @param array  $third     Parsed third parties.
	 * @param string $final_url Page URL.
	 * @return string[]
	 */
	private function preconnect_candidates( array $audits, array $third, string $final_url ): array {
		$site_host  = self::host( $final_url );
		$candidates = array();
		$add        = static function ( $url ) use ( &$candidates, $site_host ) {
			$origin = PsiParser::origin( (string) $url );
			if ( '' !== $origin && 0 === strpos( $origin, 'https://' ) && ! PsiParser::same_site( PsiParser::host( $origin ), $site_host ) ) {
				$candidates[ $origin ] = true;
			}
		};

		if ( ! empty( $audits['uses-rel-preconnect']['details']['items'] ) && is_array( $audits['uses-rel-preconnect']['details']['items'] ) ) {
			foreach ( $audits['uses-rel-preconnect']['details']['items'] as $item ) {
				if ( is_array( $item ) && isset( $item['url'] ) ) {
					$add( $item['url'] );
				}
			}
		}
		foreach ( array( 'render-blocking-resources', 'render-blocking-insight' ) as $id ) {
			if ( ! empty( $audits[ $id ]['details']['items'] ) && is_array( $audits[ $id ]['details']['items'] ) ) {
				foreach ( $audits[ $id ]['details']['items'] as $item ) {
					if ( is_array( $item ) && isset( $item['url'] ) ) {
						$add( $item['url'] );
					}
				}
			}
		}
		// Google Fonts CSS always pulls the font files from fonts.gstatic.com.
		if ( isset( $candidates['https://fonts.googleapis.com'] ) ) {
			$candidates['https://fonts.gstatic.com'] = true;
		}
		foreach ( $third as $entity ) {
			foreach ( $entity['origins'] as $origin ) {
				if ( preg_match( '#^https://(fonts\.gstatic\.com|fonts\.googleapis\.com|use\.typekit\.net|p\.typekit\.net)$#', $origin ) ) {
					$add( $origin );
				}
			}
		}
		foreach ( $third as $entity ) {
			foreach ( $entity['origins'] as $origin ) {
				$add( $origin );
			}
		}
		return array_slice( array_keys( $candidates ), 0, self::MAX_PRECONNECT );
	}

	/**
	 * Failing opportunities and diagnostics, biggest first.
	 *
	 * @param array $audits Audits.
	 * @return array
	 */
	private function failing_audits( array $audits ): array {
		$out = array();
		foreach ( $audits as $id => $audit ) {
			if ( ! is_array( $audit ) || in_array( $id, self::SKIP_AUDITS, true ) ) {
				continue;
			}
			$mode = isset( $audit['scoreDisplayMode'] ) ? (string) $audit['scoreDisplayMode'] : '';
			if ( ! in_array( $mode, array( 'numeric', 'binary', 'metricSavings' ), true ) ) {
				continue;
			}
			if ( ! isset( $audit['score'] ) || ! is_numeric( $audit['score'] ) || (float) $audit['score'] >= 0.9 ) {
				continue;
			}
			$details = isset( $audit['details'] ) && is_array( $audit['details'] ) ? $audit['details'] : array();

			$metric_savings = array();
			if ( isset( $audit['metricSavings'] ) && is_array( $audit['metricSavings'] ) ) {
				foreach ( $audit['metricSavings'] as $metric => $value ) {
					if ( is_numeric( $value ) && (float) $value > 0 ) {
						$metric_savings[ (string) $metric ] = 'CLS' === $metric ? round( (float) $value, 3 ) : (int) round( (float) $value );
					}
				}
			}

			$savings_ms = null;
			if ( isset( $details['overallSavingsMs'] ) && is_numeric( $details['overallSavingsMs'] ) ) {
				$savings_ms = (int) round( (float) $details['overallSavingsMs'] );
			} elseif ( isset( $details['summary']['wastedMs'] ) && is_numeric( $details['summary']['wastedMs'] ) ) {
				$savings_ms = (int) round( (float) $details['summary']['wastedMs'] );
			}
			$savings_bytes = null;
			if ( isset( $details['overallSavingsBytes'] ) && is_numeric( $details['overallSavingsBytes'] ) ) {
				$savings_bytes = (int) $details['overallSavingsBytes'];
			} elseif ( isset( $details['summary']['wastedBytes'] ) && is_numeric( $details['summary']['wastedBytes'] ) ) {
				$savings_bytes = (int) $details['summary']['wastedBytes'];
			}

			$is_opportunity = ( isset( $details['type'] ) && 'opportunity' === $details['type'] )
				|| null !== $savings_bytes
				|| ( '-insight' === substr( (string) $id, -8 ) && ! empty( $metric_savings ) );

			$out[] = array(
				'id'             => (string) $id,
				'kind'           => $is_opportunity ? 'opportunity' : 'diagnostic',
				'title'          => self::trim( wp_strip_all_tags( self::str( $audit, 'title' ) ) ),
				'display_value'  => self::trim( wp_strip_all_tags( self::str( $audit, 'displayValue' ) ) ),
				'score'          => round( (float) $audit['score'], 2 ),
				'savings_ms'     => $savings_ms,
				'savings_bytes'  => $savings_bytes,
				'metric_savings' => $metric_savings,
				'items'          => $this->items( $details ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$wa = PsiParser::weight( $a );
				$wb = PsiParser::weight( $b );
				if ( $wa !== $wb ) {
					return $wb <=> $wa;
				}
				return $a['score'] <=> $b['score'];
			}
		);
		return array_slice( $out, 0, self::MAX_AUDITS );
	}

	/**
	 * Sort weight of a failing audit: estimated ms saved (CLS scaled so 0.1 ≈ 1 s).
	 *
	 * @param array $audit Compact audit.
	 * @return float
	 */
	public static function weight( array $audit ): float {
		$weight = isset( $audit['savings_ms'] ) ? (float) $audit['savings_ms'] : 0.0;
		foreach ( (array) $audit['metric_savings'] as $metric => $value ) {
			$value  = 'CLS' === $metric ? (float) $value * 10000 : (float) $value;
			$weight = max( $weight, $value );
		}
		if ( 0.0 === $weight && ! empty( $audit['savings_bytes'] ) ) {
			$weight = (float) $audit['savings_bytes'] / 1000; // ~1 ms per KB as a tie-breaker.
		}
		return $weight;
	}

	/**
	 * Top items of an audit's details, trimmed to known keys.
	 *
	 * @param array $details Details.
	 * @param int   $max     Max items.
	 * @return array
	 */
	private function items( array $details, int $max = self::MAX_ITEMS ): array {
		$rows = array();
		if ( isset( $details['items'] ) && is_array( $details['items'] ) ) {
			// Lists hold tables; take rows of the first table that has any.
			if ( isset( $details['type'] ) && 'list' === $details['type'] ) {
				foreach ( self::tables( $details ) as $table ) {
					if ( $table ) {
						$rows = $table;
						break;
					}
				}
			} elseif ( array_values( $details['items'] ) === $details['items'] ) {
				$rows = $details['items'];
			}
		}
		$out = array();
		foreach ( array_slice( $rows, 0, $max ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = array();
			foreach ( self::ITEM_KEYS as $key ) {
				if ( ! array_key_exists( $key, $row ) ) {
					continue;
				}
				$value = $row[ $key ];
				if ( 'entity' === $key ) {
					$value = self::entity_name( $value );
				} elseif ( is_array( $value ) && isset( $value['value'] ) && is_scalar( $value['value'] ) ) {
					$value = $value['value']; // { type: numeric, value: n }.
				} elseif ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
					$value = $value['url']; // { type: url|source-location, url }.
				}
				if ( is_float( $value ) ) {
					$value = abs( $value ) < 10 ? round( $value, 3 ) : round( $value );
				} elseif ( is_string( $value ) ) {
					$value = self::trim( $value );
				} elseif ( ! is_int( $value ) && ! is_bool( $value ) ) {
					continue;
				}
				$item[ $key ] = $value;
			}
			if ( isset( $row['node'] ) && is_array( $row['node'] ) ) {
				$item['selector'] = self::trim( self::str( $row['node'], 'selector' ), 200 );
				$item['snippet']  = self::trim( self::str( $row['node'], 'snippet' ), 200 );
			}
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Keeps the result under the size budget by dropping detail, never metrics.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private function enforce_budget( array $result ): array {
		$steps = array( 3, 1, 0 );
		foreach ( $steps as $keep ) {
			if ( strlen( (string) wp_json_encode( $result ) ) <= self::MAX_BYTES ) {
				return $result;
			}
			foreach ( $result['audits'] as $i => $audit ) {
				$result['audits'][ $i ]['items'] = array_slice( $audit['items'], 0, $keep );
			}
		}
		$too_big = true;
		while ( $too_big ) {
			$size    = strlen( (string) wp_json_encode( $result ) );
			$audits  = count( $result['audits'] );
			$too_big = $size > self::MAX_BYTES && $audits > 5;
			if ( $too_big ) {
				array_pop( $result['audits'] );
			}
		}
		return $result;
	}

	/**
	 * Finds the first DOM node object anywhere in a details structure.
	 *
	 * @param mixed $details Details.
	 * @param int   $depth   Recursion depth.
	 * @return array|null
	 */
	private static function find_node( $details, int $depth = 0 ) {
		if ( ! is_array( $details ) || $depth > 4 ) {
			return null;
		}
		if ( isset( $details['type'] ) && 'node' === $details['type'] && ( isset( $details['snippet'] ) || isset( $details['selector'] ) ) ) {
			return $details;
		}
		if ( isset( $details['node'] ) && is_array( $details['node'] ) ) {
			return $details['node'];
		}
		if ( isset( $details['items'] ) && is_array( $details['items'] ) ) {
			foreach ( $details['items'] as $item ) {
				$found = self::find_node( $item, $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Row lists of every table inside details (a table itself or a list of tables).
	 *
	 * @param array $details Details.
	 * @return array[]
	 */
	private static function tables( array $details ): array {
		$tables = array();
		$type   = isset( $details['type'] ) ? $details['type'] : '';
		if ( 'list' === $type && isset( $details['items'] ) && is_array( $details['items'] ) ) {
			foreach ( $details['items'] as $sub ) {
				if ( is_array( $sub ) && isset( $sub['type'] ) && in_array( $sub['type'], array( 'table', 'opportunity' ), true ) && isset( $sub['items'] ) && is_array( $sub['items'] ) ) {
					$tables[] = $sub['items'];
				}
			}
		} elseif ( isset( $details['items'] ) && is_array( $details['items'] ) ) {
			$tables[] = $details['items'];
		}
		return $tables;
	}

	/**
	 * Entity name from a string or a { type: link, text } object.
	 *
	 * @param mixed $entity Entity.
	 * @return string
	 */
	private static function entity_name( $entity ): string {
		if ( is_string( $entity ) ) {
			return $entity;
		}
		if ( is_array( $entity ) && isset( $entity['text'] ) && is_string( $entity['text'] ) ) {
			return $entity['text'];
		}
		return '';
	}

	/**
	 * Reads one HTML attribute from a snippet.
	 *
	 * @param string $html Snippet.
	 * @param string $name Attribute.
	 * @return string
	 */
	private static function attr( string $html, string $name ): string {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m ) ) {
			$value = isset( $m[4] ) && '' !== $m[4] ? $m[4] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2] );
			return html_entity_decode( $value, ENT_QUOTES );
		}
		return '';
	}

	/**
	 * Resolves a possibly relative URL against the page URL. Data URIs are dropped.
	 *
	 * @param string $url  URL.
	 * @param string $base Page URL.
	 * @return string
	 */
	private static function absolute_url( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$parts = wp_parse_url( $base );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$root = $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $root . $dir . $url;
	}

	/**
	 * scheme://host[:port] of a URL ('' when invalid).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Lowercase host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Whether two hosts belong to the same site (equal, www-variant or subdomain of each other).
	 *
	 * @param string $host      Host.
	 * @param string $site_host Site host.
	 * @return bool
	 */
	public static function same_site( string $host, string $site_host ): bool {
		if ( '' === $host || '' === $site_host ) {
			return false;
		}
		$host      = preg_replace( '/^www\./', '', $host );
		$site_host = preg_replace( '/^www\./', '', $site_host );
		if ( $host === $site_host ) {
			return true;
		}
		$len_h = strlen( $host );
		$len_s = strlen( $site_host );
		return ( $len_h > $len_s && substr( $host, -( $len_s + 1 ) ) === '.' . $site_host )
			|| ( $len_s > $len_h && substr( $site_host, -( $len_h + 1 ) ) === '.' . $host );
	}

	/**
	 * String value of an array key.
	 *
	 * @param array  $data Data.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function str( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Trims a string to a byte budget (UTF-8 safe).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	private static function trim( string $text, int $max = self::MAX_STRING ): string {
		$text = trim( $text );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $text, 0, $max - 3, 'UTF-8' ) . '…';
		}
		return substr( $text, 0, $max - 3 ) . '…';
	}
}
