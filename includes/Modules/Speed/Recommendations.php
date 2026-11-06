<?php
/**
 * Plain-English "to-do" actions for PageSpeed Insights findings.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Lighthouse audit ids (classic audits and Lighthouse 13 insights) and weak metrics
 * to actions a non-technical user can understand, and says who can fix each one:
 *
 * - `seo-console`  — a SEO Console tweak (see Tweaks) that can be switched on;
 * - `cache-plugin` — a setting in the detected cache plugin (CacheConfigAudit check id, with plugin-specific steps);
 * - `developer`    — a code task (written so it can be handed to Claude Code as-is);
 * - `hosting`      — something only the host / server can change.
 *
 * Catalogue entry: [ 'id', 'aliases' => string[], 'title', 'what', 'why', 'how', 'impact' => high|medium|low,
 * 'effort' => low|medium|high, 'metrics' => string[], 'fixers' => [ [ 'type', 'tweak'|'check'|'task' ] ] ].
 *
 * Action (for_result()): [ 'id', 'audit_id', 'title', 'what', 'why', 'how', 'impact', 'effort', 'metrics',
 * 'display_value', 'savings_ms', 'savings_bytes', 'fixer' => Fixer, 'alternatives' => Fixer[], 'already_done' => string[] ].
 * Fixer: [ 'type', 'label', 'tweak'?, 'check'?, 'how_to'?, 'settings_url'?, 'task'? ].
 */
class Recommendations {

	const IMPACT_RANK = array(
		'high'   => 3,
		'medium' => 2,
		'low'    => 1,
	);

	/**
	 * Catalogue cache.
	 *
	 * @var array|null
	 */
	private $catalog;

	/**
	 * Alias → canonical id.
	 *
	 * @var array|null
	 */
	private $aliases;

	/**
	 * Every catalogue entry keyed by canonical id.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->catalog ) {
			$this->catalog = array();
			$this->aliases = array();
			foreach ( $this->definitions() as $id => $entry ) {
				$entry['id']          = $id;
				$entry['aliases']     = isset( $entry['aliases'] ) ? $entry['aliases'] : array();
				$this->catalog[ $id ] = $entry;
				$this->aliases[ $id ] = $id;
				foreach ( $entry['aliases'] as $alias ) {
					$this->aliases[ $alias ] = $id;
				}
			}
		}
		return $this->catalog;
	}

	/**
	 * Every audit id (canonical and aliases) that has a recommendation.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		$this->all();
		return array_keys( $this->aliases );
	}

	/**
	 * Catalogue entry for an audit id or alias.
	 *
	 * @param string $audit_id Lighthouse audit id.
	 * @return array|null
	 */
	public function get( string $audit_id ) {
		$all = $this->all();
		return isset( $this->aliases[ $audit_id ] ) ? $all[ $this->aliases[ $audit_id ] ] : null;
	}

	/**
	 * Builds the to-do list for one compact PSI result (PsiParser output).
	 *
	 * @param array $result  Compact PSI result.
	 * @param array $context Optional: 'cache_audit' => CacheConfigAudit|null, 'enabled_tweaks' => string[].
	 * @return array[] Actions, most important first. One action per catalogue entry.
	 */
	public function for_result( array $result, array $context = array() ): array {
		$actions = array();
		$audits  = isset( $result['audits'] ) && is_array( $result['audits'] ) ? $result['audits'] : array();

		foreach ( $audits as $audit ) {
			$entry = isset( $audit['id'] ) ? $this->get( (string) $audit['id'] ) : null;
			if ( null === $entry || isset( $actions[ $entry['id'] ] ) ) {
				continue;
			}
			$actions[ $entry['id'] ] = $this->action( $entry, $audit, $result, $context );
		}

		// LCP image that PSI did not flag but is lazy-loaded or not prioritised.
		$lcp = isset( $result['lcp_element'] ) && is_array( $result['lcp_element'] ) ? $result['lcp_element'] : null;
		if ( $lcp && 'image' === $lcp['type'] && ! isset( $actions['prioritize-lcp-image'] ) && ! isset( $actions['lcp-lazy-loaded'] )
			&& ( ! empty( $lcp['lazy_loaded'] ) || false === $lcp['priority_hinted'] ) ) {
			$entry                             = $this->get( ! empty( $lcp['lazy_loaded'] ) ? 'lcp-lazy-loaded' : 'prioritize-lcp-image' );
			$actions[ $entry['id'] ]           = $this->action( $entry, array( 'id' => $entry['id'] ), $result, $context );
			$actions[ $entry['id'] ]['impact'] = 'high';
		}

		// Weak metrics with no matching audit.
		foreach ( $this->metric_entries( $result ) as $id ) {
			if ( ! isset( $actions[ $id ] ) ) {
				$actions[ $id ] = $this->action( $this->get( $id ), array( 'id' => $id ), $result, $context );
			}
		}

		$actions = array_values( $actions );
		usort(
			$actions,
			static function ( $a, $b ) {
				$ra = self::IMPACT_RANK[ $a['impact'] ];
				$rb = self::IMPACT_RANK[ $b['impact'] ];
				if ( $ra !== $rb ) {
					return $rb <=> $ra;
				}
				return (int) $b['savings_ms'] <=> (int) $a['savings_ms'];
			}
		);
		return $actions;
	}

	/**
	 * Metric-only entries triggered by weak field/lab values.
	 *
	 * @param array $result Compact result.
	 * @return string[]
	 */
	private function metric_entries( array $result ): array {
		$ids   = array();
		$field = array();
		foreach ( array( 'url', 'origin' ) as $scope ) {
			if ( ! empty( $result['field'][ $scope ]['metrics'] ) ) {
				$field = $result['field'][ $scope ]['metrics'];
				break;
			}
		}
		if ( isset( $field['inp']['category'] ) && in_array( $field['inp']['category'], array( 'ni', 'poor' ), true ) ) {
			$ids[] = 'inp';
		}
		$lab_ttfb = isset( $result['lab']['ttfb']['value'] ) ? (float) $result['lab']['ttfb']['value'] : 0.0;
		if ( $lab_ttfb > 600 || ( isset( $field['ttfb']['category'] ) && 'poor' === $field['ttfb']['category'] ) ) {
			$ids[] = 'server-response-time';
		}
		return $ids;
	}

	/**
	 * Builds one action.
	 *
	 * @param array $entry   Catalogue entry.
	 * @param array $audit   Compact audit (or [ 'id' ] for metric-only).
	 * @param array $result  Compact result.
	 * @param array $context Context.
	 * @return array
	 */
	private function action( array $entry, array $audit, array $result, array $context ): array {
		$cache   = isset( $context['cache_audit'] ) && $context['cache_audit'] instanceof CacheConfigAudit ? $context['cache_audit'] : null;
		$enabled = isset( $context['enabled_tweaks'] ) ? (array) $context['enabled_tweaks'] : array();
		$tweaks  = Tweaks::definitions();

		$candidates = array();
		$done       = array();
		foreach ( $entry['fixers'] as $fixer ) {
			switch ( $fixer['type'] ) {
				case 'seo-console':
					if ( ! isset( $tweaks[ $fixer['tweak'] ] ) ) {
						break;
					}
					if ( in_array( $fixer['tweak'], $enabled, true ) ) {
						/* translators: %s: tweak title */
						$done[] = sprintf( __( 'SEO Console "%s" is already on.', 'seo-console' ), $tweaks[ $fixer['tweak'] ]['title'] );
						break;
					}
					$candidates[] = array(
						'type'  => 'seo-console',
						/* translators: %s: tweak title */
						'label' => sprintf( __( 'SEO Console can fix this: turn on "%s".', 'seo-console' ), $tweaks[ $fixer['tweak'] ]['title'] ),
						'tweak' => $fixer['tweak'],
					);
					break;
				case 'cache-plugin':
					$finding = $cache ? $cache->finding( $fixer['check'] ) : null;
					if ( null === $finding ) {
						break;
					}
					if ( 'ok' === $finding['status'] && ! in_array( $fixer['check'], array( 'lazyload', 'combine_css', 'combine_js', 'mobile_cache' ), true ) ) {
						/* translators: 1: setting, 2: cache plugin name */
						$done[] = sprintf( __( '"%1$s" is already on in %2$s.', 'seo-console' ), $finding['title'], $cache->plugin_name() );
						break;
					}
					if ( 'ok' === $finding['status'] ) {
						break;
					}
					$candidates[] = array(
						'type'         => 'cache-plugin',
						/* translators: 1: setting, 2: cache plugin name */
						'label'        => sprintf( __( 'Change "%1$s" in %2$s.', 'seo-console' ), $finding['title'], $cache->plugin_name() ),
						'check'        => $fixer['check'],
						'how_to'       => $finding['how_to'],
						'settings_url' => $finding['settings_url'],
					);
					break;
				case 'developer':
					$candidates[] = array(
						'type'  => 'developer',
						'label' => __( 'Developer task (can be handed to Claude Code).', 'seo-console' ),
						'task'  => $this->task( $fixer['task'], $audit, $result ),
					);
					break;
				case 'hosting':
					$candidates[] = array(
						'type'  => 'hosting',
						'label' => __( 'Ask your hosting provider.', 'seo-console' ),
						'task'  => $fixer['task'],
					);
					break;
			}
		}

		$weight = isset( $audit['metric_savings'] ) ? PsiParser::weight( $audit ) : 0.0;
		$impact = $entry['impact'];
		if ( $weight > 0 ) {
			$impact = $weight >= 1000 ? 'high' : ( $weight >= 300 ? 'medium' : 'low' );
		}

		return array(
			'id'            => $entry['id'],
			'audit_id'      => isset( $audit['id'] ) ? (string) $audit['id'] : $entry['id'],
			'title'         => $entry['title'],
			'what'          => $entry['what'],
			'why'           => $entry['why'],
			'how'           => $entry['how'],
			'impact'        => $impact,
			'effort'        => $entry['effort'],
			'metrics'       => $entry['metrics'],
			'display_value' => isset( $audit['display_value'] ) ? (string) $audit['display_value'] : '',
			'savings_ms'    => isset( $audit['savings_ms'] ) ? $audit['savings_ms'] : null,
			'savings_bytes' => isset( $audit['savings_bytes'] ) ? $audit['savings_bytes'] : null,
			'fixer'         => $candidates ? $candidates[0] : null,
			'alternatives'  => array_slice( $candidates, 1 ),
			'already_done'  => $done,
		);
	}

	/**
	 * Developer task text with the affected resources appended.
	 *
	 * @param string $task   Base task.
	 * @param array  $audit  Compact audit.
	 * @param array  $result Compact result.
	 * @return string
	 */
	private function task( string $task, array $audit, array $result ): string {
		$lines = array();
		if ( ! empty( $audit['items'] ) && is_array( $audit['items'] ) ) {
			foreach ( $audit['items'] as $item ) {
				$label = '';
				if ( ! empty( $item['url'] ) ) {
					$label = (string) $item['url'];
				} elseif ( ! empty( $item['selector'] ) ) {
					$label = (string) $item['selector'];
				} elseif ( ! empty( $item['entity'] ) ) {
					$label = (string) $item['entity'];
				}
				if ( '' !== $label ) {
					$lines[] = '- ' . $label;
				}
			}
		}
		$page = isset( $result['final_url'] ) ? (string) $result['final_url'] : '';
		$text = $task;
		if ( '' !== $page ) {
			/* translators: 1: page URL, 2: mobile|desktop */
			$text .= "\n\n" . sprintf( __( 'Page tested: %1$s (%2$s).', 'seo-console' ), $page, isset( $result['strategy'] ) ? $result['strategy'] : 'mobile' );
		}
		if ( $lines ) {
			$text .= "\n" . __( 'Affected resources (from PageSpeed Insights):', 'seo-console' ) . "\n" . implode( "\n", $lines );
		}
		if ( isset( $audit['id'] ) ) {
			/* translators: %s: Lighthouse audit id */
			$text .= "\n" . sprintf( __( 'Lighthouse audit: %s. Re-run the SEO Console speed test after the change to confirm.', 'seo-console' ), $audit['id'] );
		}
		return $text;
	}

	/**
	 * Fixer helpers.
	 *
	 * @param string $tweak Tweak id.
	 * @return array
	 */
	private static function tweak( string $tweak ): array {
		return array(
			'type'  => 'seo-console',
			'tweak' => $tweak,
		);
	}

	/**
	 * Cache-plugin fixer.
	 *
	 * @param string $check CacheConfigAudit check id.
	 * @return array
	 */
	private static function cache( string $check ): array {
		return array(
			'type'  => 'cache-plugin',
			'check' => $check,
		);
	}

	/**
	 * Developer fixer.
	 *
	 * @param string $task Task description.
	 * @return array
	 */
	private static function dev( string $task ): array {
		return array(
			'type' => 'developer',
			'task' => $task,
		);
	}

	/**
	 * Hosting fixer.
	 *
	 * @param string $task What to ask the host.
	 * @return array
	 */
	private static function host( string $task ): array {
		return array(
			'type' => 'hosting',
			'task' => $task,
		);
	}

	/**
	 * The catalogue.
	 *
	 * @return array
	 */
	private function definitions(): array {
		return array(
			'render-blocking-resources'  => array(
				'aliases' => array( 'render-blocking-insight' ),
				'title'   => __( 'Stop CSS and JavaScript from holding up the first paint', 'seo-console' ),
				'what'    => __( 'The browser must download some CSS and JavaScript files before it can show anything.', 'seo-console' ),
				'why'     => __( 'Visitors stare at a blank screen longer, which hurts First Contentful Paint and LCP.', 'seo-console' ),
				'how'     => __( 'Defer non-critical JavaScript and load CSS asynchronously with critical CSS inlined.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'medium',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::cache( 'defer_js' ),
					self::cache( 'critical_css' ),
					self::dev( __( 'Remove render-blocking resources: add `defer` to non-critical scripts (wp_script_add_data / the "strategy" argument of wp_register_script), load non-critical CSS with media="print" onload swap or inline critical CSS, and stop enqueuing assets on pages that do not use them.', 'seo-console' ) ),
				),
			),
			'unused-css-rules'           => array(
				'title'   => __( 'Remove CSS the page does not use', 'seo-console' ),
				'what'    => __( 'The page downloads stylesheets with many rules it never uses (common with page builders and big themes).', 'seo-console' ),
				'why'     => __( 'Extra CSS delays the first paint, especially on phones.', 'seo-console' ),
				'how'     => __( 'Turn on "Remove unused CSS" in your cache plugin, or stop loading plugin styles on pages that do not need them.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'medium',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::cache( 'unused_css' ),
					self::dev( __( 'Reduce unused CSS: dequeue plugin stylesheets (wp_dequeue_style) on templates that do not use them, drop the block library CSS if the site does not use blocks, and remove unused theme CSS.', 'seo-console' ) ),
				),
			),
			'unused-javascript'          => array(
				'title'   => __( 'Remove JavaScript the page does not use', 'seo-console' ),
				'what'    => __( 'The page downloads JavaScript that is never run (often analytics, chat widgets, sliders on pages without sliders).', 'seo-console' ),
				'why'     => __( 'Unused JavaScript wastes data and keeps the phone\'s processor busy.', 'seo-console' ),
				'how'     => __( 'Delay non-essential scripts until the visitor interacts, and remove scripts from pages that do not need them.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'medium',
				'metrics' => array( 'LCP', 'TBT' ),
				'fixers'  => array(
					self::cache( 'delay_js' ),
					self::dev( __( 'Reduce unused JavaScript: dequeue scripts (wp_dequeue_script) on templates that do not use them, load third-party tags only after consent/interaction, and replace heavy libraries where a lighter option exists.', 'seo-console' ) ),
				),
			),
			'unminified-css'             => array(
				'title'   => __( 'Minify CSS', 'seo-console' ),
				'what'    => __( 'Stylesheets contain spaces and comments that browsers do not need.', 'seo-console' ),
				'why'     => __( 'Smaller files download faster.', 'seo-console' ),
				'how'     => __( 'Turn on CSS minification in your cache plugin.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'low',
				'metrics' => array( 'FCP' ),
				'fixers'  => array(
					self::cache( 'minify_css' ),
					self::dev( __( 'Serve minified CSS (build step or a minification plugin) for the listed stylesheets.', 'seo-console' ) ),
				),
			),
			'unminified-javascript'      => array(
				'title'   => __( 'Minify JavaScript', 'seo-console' ),
				'what'    => __( 'JavaScript files contain spaces and comments that browsers do not need.', 'seo-console' ),
				'why'     => __( 'Smaller files download and parse faster.', 'seo-console' ),
				'how'     => __( 'Turn on JavaScript minification in your cache plugin.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'low',
				'metrics' => array( 'FCP', 'TBT' ),
				'fixers'  => array(
					self::cache( 'minify_js' ),
					self::dev( __( 'Serve minified JavaScript (build step or a minification plugin) for the listed scripts.', 'seo-console' ) ),
				),
			),
			'uses-optimized-images'      => array(
				'title'   => __( 'Compress images', 'seo-console' ),
				'what'    => __( 'Some images are saved at higher quality than anyone can see.', 'seo-console' ),
				'why'     => __( 'Heavy images are the most common reason pages load slowly on phones.', 'seo-console' ),
				'how'     => __( 'Compress the images listed (most image optimizer plugins do this in bulk) and re-upload or regenerate them.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::cache( 'image_formats' ),
					self::dev( __( 'Compress the listed images (quality ~75–82 for JPEG/WebP) and regenerate thumbnails; set up automatic compression on upload.', 'seo-console' ) ),
				),
			),
			'modern-image-formats'       => array(
				'aliases' => array( 'image-delivery-insight' ),
				'title'   => __( 'Serve images as WebP or AVIF', 'seo-console' ),
				'what'    => __( 'Images are sent as JPEG/PNG instead of modern formats.', 'seo-console' ),
				'why'     => __( 'WebP and AVIF are 25–50% smaller at the same quality, so images (often the main image) load faster.', 'seo-console' ),
				'how'     => __( 'Turn on WebP/AVIF conversion in your cache or image plugin.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::cache( 'image_formats' ),
					self::dev( __( 'Serve the listed images as WebP/AVIF (WordPress 6.5+ can generate them via the image_editor_output_format filter, or use an image optimization plugin) with JPEG/PNG fallback.', 'seo-console' ) ),
				),
			),
			'uses-responsive-images'     => array(
				'title'   => __( 'Use correctly sized images', 'seo-console' ),
				'what'    => __( 'Some images are much larger than the space they are shown in.', 'seo-console' ),
				'why'     => __( 'Phones download desktop-sized images for no benefit.', 'seo-console' ),
				'how'     => __( 'Insert images at a suitable size (not "Full size") and let WordPress add responsive srcset.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'medium',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::dev( __( 'Serve properly sized images: output images with wp_get_attachment_image() (srcset + sizes) instead of hard-coded full-size URLs, register image sizes matching the layout, and set an accurate sizes attribute for hero/grid images.', 'seo-console' ) ),
				),
			),
			'offscreen-images'           => array(
				'title'   => __( 'Load below-the-fold images later', 'seo-console' ),
				'what'    => __( 'Images the visitor cannot see yet are downloaded straight away.', 'seo-console' ),
				'why'     => __( 'They compete for bandwidth with the content at the top of the page.', 'seo-console' ),
				'how'     => __( 'Turn on lazy-loading for images (while keeping the main top image excluded).', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::cache( 'lazyload' ),
					self::dev( __( 'Add loading="lazy" to below-the-fold images that bypass WordPress\'s automatic lazy-loading (sliders, page-builder widgets, hard-coded <img> tags), keeping the first visible image eager.', 'seo-console' ) ),
				),
			),
			'prioritize-lcp-image'       => array(
				'aliases' => array( 'lcp-discovery-insight' ),
				'title'   => __( 'Load the main image first', 'seo-console' ),
				'what'    => __( 'The biggest image at the top of the page (the LCP image) is discovered late or downloaded with low priority.', 'seo-console' ),
				'why'     => __( 'Largest Contentful Paint measures when this image appears; it is a Core Web Vital Google uses for ranking.', 'seo-console' ),
				'how'     => __( 'Preload the image and mark it fetchpriority="high". SEO Console can do this automatically for the tested pages.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::tweak( 'lcp_boost' ),
					self::cache( 'lazyload' ),
					self::dev( __( 'Prioritise the LCP image: output it as a real <img> (not a CSS background) early in the HTML with fetchpriority="high", no loading="lazy", and a <link rel="preload" as="image"> with imagesrcset/imagesizes in the head of that template.', 'seo-console' ) ),
				),
			),
			'lcp-lazy-loaded'            => array(
				'title'   => __( 'Do not lazy-load the main image', 'seo-console' ),
				'what'    => __( 'The main image at the top of the page is lazy-loaded, so the browser waits before downloading it.', 'seo-console' ),
				'why'     => __( 'This directly delays Largest Contentful Paint, often by a second or more on phones.', 'seo-console' ),
				'how'     => __( 'Exclude that image from lazy-loading. SEO Console\'s "Speed up the main image" tweak does this and also marks it so cache plugins skip it.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::tweak( 'lcp_boost' ),
					self::cache( 'lazyload' ),
					self::dev( __( 'Remove loading="lazy" (and lazy-load plugin markers such as data-src) from the hero/LCP image in the template; add fetchpriority="high".', 'seo-console' ) ),
				),
			),
			'server-response-time'       => array(
				'aliases' => array( 'document-latency-insight' ),
				'title'   => __( 'Make the server respond faster', 'seo-console' ),
				'what'    => __( 'The server takes a long time to send the first byte of the page (TTFB).', 'seo-console' ),
				'why'     => __( 'Everything else waits for this, so a slow server slows every metric.', 'seo-console' ),
				'how'     => __( 'Make sure page caching works, use PHP 8.1+, add an object cache, and clean up the database. If it stays slow, the hosting plan may be too small.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'medium',
				'metrics' => array( 'TTFB', 'FCP', 'LCP' ),
				'fixers'  => array(
					self::cache( 'page_cache' ),
					self::cache( 'object_cache' ),
					self::host( __( 'Ask your host to: upgrade PHP to 8.1 or newer, enable OPcache and Redis/Memcached object caching, and check whether the server or plan is overloaded (high TTFB even for cached pages).', 'seo-console' ) ),
					self::dev( __( 'Profile slow uncached requests (Query Monitor): find slow database queries, large autoloaded options and plugins doing remote calls on every page load.', 'seo-console' ) ),
				),
			),
			'uses-text-compression'      => array(
				'title'   => __( 'Turn on text compression (Gzip/Brotli)', 'seo-console' ),
				'what'    => __( 'HTML, CSS and JavaScript are sent uncompressed.', 'seo-console' ),
				'why'     => __( 'Compression usually makes these files 70–80% smaller.', 'seo-console' ),
				'how'     => __( 'Compression is a server setting; most cache plugins or hosts can switch it on.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::host( __( 'Ask your host to enable Brotli or Gzip compression for HTML, CSS, JS, JSON and SVG.', 'seo-console' ) ),
				),
			),
			'uses-long-cache-ttl'        => array(
				'aliases' => array( 'cache-insight' ),
				'title'   => __( 'Let browsers keep static files longer', 'seo-console' ),
				'what'    => __( 'Images, CSS and JavaScript are sent with a short (or no) browser cache time.', 'seo-console' ),
				'why'     => __( 'Returning visitors download the same files again.', 'seo-console' ),
				'how'     => __( 'Turn on browser caching (about one year for versioned static files). Third-party files cannot be changed.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array(),
				'fixers'  => array(
					self::cache( 'browser_cache' ),
					self::host( __( 'Ask your host to set "Cache-Control: max-age=31536000" (or Expires rules) for images, fonts, CSS and JS files.', 'seo-console' ) ),
				),
			),
			'font-display'               => array(
				'aliases' => array( 'font-display-insight' ),
				'title'   => __( 'Show text while fonts are loading', 'seo-console' ),
				'what'    => __( 'Text stays invisible until the web fonts download.', 'seo-console' ),
				'why'     => __( 'Visitors see a blank area instead of text, which delays the first paint.', 'seo-console' ),
				'how'     => __( 'Use font-display: swap for web fonts.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'low',
				'metrics' => array( 'FCP' ),
				'fixers'  => array(
					self::cache( 'font_display' ),
					self::dev( __( 'Add font-display: swap to every @font-face rule (theme and icon fonts) and add &display=swap to Google Fonts URLs; host critical fonts locally and preload the main one.', 'seo-console' ) ),
				),
			),
			'third-party-summary'        => array(
				'aliases' => array( 'third-parties-insight' ),
				'title'   => __( 'Reduce the cost of third-party scripts', 'seo-console' ),
				'what'    => __( 'Scripts from other companies (analytics, ads, chat, social widgets) take time to download and run.', 'seo-console' ),
				'why'     => __( 'They block the main thread, which hurts interactivity (TBT/INP).', 'seo-console' ),
				'how'     => __( 'Remove tags nobody uses, delay the rest until the visitor interacts, and preconnect to the important ones.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'medium',
				'metrics' => array( 'TBT', 'INP' ),
				'fixers'  => array(
					self::cache( 'delay_js' ),
					self::tweak( 'preconnect' ),
					self::dev( __( 'Audit third-party tags: remove unused ones (check Google Tag Manager), load chat/social widgets on interaction, use lightweight facades for YouTube/maps embeds.', 'seo-console' ) ),
				),
			),
			'dom-size'                   => array(
				'aliases' => array( 'dom-size-insight' ),
				'title'   => __( 'Simplify the page structure', 'seo-console' ),
				'what'    => __( 'The page is built from a very large number of HTML elements.', 'seo-console' ),
				'why'     => __( 'Big pages use more memory and make every interaction slower.', 'seo-console' ),
				'how'     => __( 'Reduce nested page-builder sections, huge menus and hidden duplicate mobile/desktop blocks.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'high',
				'metrics' => array( 'TBT', 'INP' ),
				'fixers'  => array(
					self::dev( __( 'Reduce DOM size on the listed page: flatten nested page-builder containers, avoid duplicating content for mobile/desktop, paginate or lazy-render long lists and mega menus.', 'seo-console' ) ),
				),
			),
			'bootup-time'                => array(
				'title'   => __( 'Reduce JavaScript execution time', 'seo-console' ),
				'what'    => __( 'Scripts take a long time to run on a phone.', 'seo-console' ),
				'why'     => __( 'While scripts run, the page cannot respond to taps (TBT/INP).', 'seo-console' ),
				'how'     => __( 'Delay non-essential scripts and remove heavy ones that are not needed.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'medium',
				'metrics' => array( 'TBT', 'INP' ),
				'fixers'  => array(
					self::cache( 'delay_js' ),
					self::dev( __( 'Reduce JavaScript execution: remove or replace the heaviest scripts listed, split long tasks, avoid running sliders/animations libraries on pages that do not use them.', 'seo-console' ) ),
				),
			),
			'mainthread-work-breakdown'  => array(
				'title'   => __( 'Reduce work in the browser', 'seo-console' ),
				'what'    => __( 'The browser spends a lot of time running scripts and calculating the layout.', 'seo-console' ),
				'why'     => __( 'A busy browser responds slowly to taps and scrolls.', 'seo-console' ),
				'how'     => __( 'Delay non-essential scripts and simplify heavy page sections.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'high',
				'metrics' => array( 'TBT', 'INP' ),
				'fixers'  => array(
					self::cache( 'delay_js' ),
					self::dev( __( 'Reduce main-thread work: cut script evaluation (see bootup-time), reduce style/layout cost (fewer DOM nodes, avoid layout thrashing and large CSS selectors).', 'seo-console' ) ),
				),
			),
			'layout-shift-elements'      => array(
				'aliases' => array( 'cls-culprits-insight' ),
				'title'   => __( 'Stop the page from jumping while it loads', 'seo-console' ),
				'what'    => __( 'Parts of the page move after they first appear (Cumulative Layout Shift).', 'seo-console' ),
				'why'     => __( 'Visitors tap the wrong thing; CLS is a Core Web Vital Google uses for ranking.', 'seo-console' ),
				'how'     => __( 'Reserve space for images, ads, embeds and banners, and avoid inserting content above what is already shown.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'medium',
				'metrics' => array( 'CLS' ),
				'fixers'  => array(
					self::dev( __( 'Fix layout shifts for the listed elements: set width/height or aspect-ratio on media, reserve min-height for ads/embeds/cookie bars, use font-display: optional or size-adjusted fallback fonts, avoid injecting content above existing content.', 'seo-console' ) ),
				),
			),
			'unsized-images'             => array(
				'title'   => __( 'Give images a width and height', 'seo-console' ),
				'what'    => __( 'Some images have no size set in the HTML.', 'seo-console' ),
				'why'     => __( 'The page jumps when they load (layout shift).', 'seo-console' ),
				'how'     => __( 'Add width and height attributes to the listed images.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'CLS' ),
				'fixers'  => array(
					self::dev( __( 'Add explicit width and height attributes (or CSS aspect-ratio) to the listed <img> elements, e.g. the logo in the header template.', 'seo-console' ) ),
				),
			),
			'uses-rel-preconnect'        => array(
				'aliases' => array( 'network-dependency-tree-insight' ),
				'title'   => __( 'Connect early to important external sites', 'seo-console' ),
				'what'    => __( 'The browser connects to font or script servers only when it finds them, which costs time.', 'seo-console' ),
				'why'     => __( 'Opening the connection early can save a few hundred milliseconds.', 'seo-console' ),
				'how'     => __( 'Add preconnect hints for the 2–4 most important external origins. SEO Console can add them.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'low',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::tweak( 'preconnect' ),
				),
			),
			'redirects'                  => array(
				'title'   => __( 'Avoid redirect chains', 'seo-console' ),
				'what'    => __( 'The address redirects one or more times before the page loads (for example http → https → www).', 'seo-console' ),
				'why'     => __( 'Every redirect adds a full round trip before anything can load.', 'seo-console' ),
				'how'     => __( 'Link to the final address directly and make the server redirect in a single step.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::host( __( 'Ask your host to redirect http and non-www (or www) to the final https address in one single 301 step.', 'seo-console' ) ),
					self::dev( __( 'Update internal links, menus and canonical settings to the final URL so no redirect is needed.', 'seo-console' ) ),
				),
			),
			'total-byte-weight'          => array(
				'title'   => __( 'Make the page lighter', 'seo-console' ),
				'what'    => __( 'The page downloads a lot of data in total.', 'seo-console' ),
				'why'     => __( 'Heavy pages are slow on mobile data and cost visitors money.', 'seo-console' ),
				'how'     => __( 'Start with the largest files listed: usually images, videos and fonts.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'medium',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::cache( 'image_formats' ),
					self::dev( __( 'Reduce total page weight: compress/resize the largest files listed, replace autoplay videos and GIFs, subset fonts, remove unused libraries.', 'seo-console' ) ),
				),
			),
			'legacy-javascript'          => array(
				'aliases' => array( 'legacy-javascript-insight' ),
				'title'   => __( 'Stop sending old-browser JavaScript to modern browsers', 'seo-console' ),
				'what'    => __( 'Some scripts include extra code only needed by very old browsers.', 'seo-console' ),
				'why'     => __( 'Modern phones download and run code they do not need.', 'seo-console' ),
				'how'     => __( 'Update the plugin or theme that ships these files; newer versions often fix it.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'medium',
				'metrics' => array( 'TBT' ),
				'fixers'  => array(
					self::dev( __( 'Rebuild or replace the listed scripts without legacy polyfills/transpilation (target modern browsers), or update the plugin that ships them.', 'seo-console' ) ),
				),
			),
			'duplicated-javascript'      => array(
				'aliases' => array( 'duplicated-javascript-insight' ),
				'title'   => __( 'Remove duplicate JavaScript', 'seo-console' ),
				'what'    => __( 'The same library is loaded more than once (often by different plugins).', 'seo-console' ),
				'why'     => __( 'The browser downloads and runs the same code twice.', 'seo-console' ),
				'how'     => __( 'Keep one copy and remove the others.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'medium',
				'metrics' => array( 'TBT' ),
				'fixers'  => array(
					self::dev( __( 'Find which plugins/theme bundle the duplicated modules listed and load the library once (shared registered handle).', 'seo-console' ) ),
				),
			),
			'uses-http2'                 => array(
				'aliases' => array( 'modern-http-insight' ),
				'title'   => __( 'Use HTTP/2 or HTTP/3', 'seo-console' ),
				'what'    => __( 'Files are served over the old HTTP/1.1 protocol.', 'seo-console' ),
				'why'     => __( 'HTTP/2 and HTTP/3 load many files in parallel over one connection.', 'seo-console' ),
				'how'     => __( 'This is a server setting; most hosts and CDNs support it.', 'seo-console' ),
				'impact'  => 'medium',
				'effort'  => 'low',
				'metrics' => array( 'FCP', 'LCP' ),
				'fixers'  => array(
					self::host( __( 'Ask your host to enable HTTP/2 (and HTTP/3 if available), or put the site behind a CDN such as Cloudflare.', 'seo-console' ) ),
				),
			),
			'efficient-animated-content' => array(
				'title'   => __( 'Use video instead of animated GIFs', 'seo-console' ),
				'what'    => __( 'Animated GIFs are very large files.', 'seo-console' ),
				'why'     => __( 'A short MP4/WebM video is usually 80–90% smaller.', 'seo-console' ),
				'how'     => __( 'Convert the GIFs listed to video.', 'seo-console' ),
				'impact'  => 'low',
				'effort'  => 'low',
				'metrics' => array( 'LCP' ),
				'fixers'  => array(
					self::dev( __( 'Replace the listed animated GIFs with <video autoplay muted loop playsinline> using MP4/WebM sources.', 'seo-console' ) ),
				),
			),
			'inp'                        => array(
				'aliases' => array( 'interaction-to-next-paint-insight', 'inp-breakdown-insight' ),
				'title'   => __( 'Make the page respond faster to taps and clicks', 'seo-console' ),
				'what'    => __( 'Real visitors wait noticeably after tapping or clicking (Interaction to Next Paint).', 'seo-console' ),
				'why'     => __( 'INP is a Core Web Vital Google uses for ranking; slow responses feel broken.', 'seo-console' ),
				'how'     => __( 'Reduce and delay JavaScript, especially third-party scripts, and simplify heavy pages.', 'seo-console' ),
				'impact'  => 'high',
				'effort'  => 'high',
				'metrics' => array( 'INP' ),
				'fixers'  => array(
					self::cache( 'delay_js' ),
					self::dev( __( 'Improve INP: find slow interactions (Chrome DevTools Performance panel / web-vitals attribution), break up long tasks, debounce input handlers, delay third-party scripts and reduce DOM size.', 'seo-console' ) ),
				),
			),
		);
	}
}
