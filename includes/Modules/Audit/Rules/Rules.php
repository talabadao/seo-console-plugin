<?php
/**
 * Registry of audit rules (metadata + checks).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Rule registry. Rules are built lazily so their texts are translated in the current locale.
 */
final class Rules {

	/** @var Rule[]|null Keyed by id. */
	private static $rules;

	/**
	 * Every rule keyed by id.
	 *
	 * @return Rule[]
	 */
	public static function all(): array {
		if ( null === self::$rules ) {
			self::$rules = array();
			$definitions = array_merge(
				self::indexability(),
				self::run_level(),
				self::links(),
				self::content(),
				self::images(),
				self::performance(),
				self::international(),
				self::social(),
				self::structured_data()
			);
			/**
			 * Filters the audit rule definitions. Each item: id, severity, category, title, why, how_to_fix,
			 * fixable_by, scope (page|run), check (callable).
			 *
			 * @param array[] $definitions Definitions.
			 */
			$definitions = apply_filters( 'seoc_audit_rule_definitions', $definitions );
			foreach ( $definitions as $definition ) {
				if ( empty( $definition['id'] ) || ! isset( $definition['check'] ) || ! is_callable( $definition['check'] ) ) {
					continue;
				}
				self::$rules[ $definition['id'] ] = new Rule( $definition, $definition['check'] );
			}
		}
		return self::$rules;
	}

	/**
	 * One rule.
	 *
	 * @param string $id Rule id.
	 */
	public static function get( string $id ): ?Rule {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Rules of a scope.
	 *
	 * @param string $scope page|run.
	 * @return Rule[]
	 */
	public static function by_scope( string $scope ): array {
		return array_filter(
			self::all(),
			static function ( Rule $rule ) use ( $scope ) {
				return $scope === $rule->scope;
			}
		);
	}

	/**
	 * Metadata of every rule (for REST/UI).
	 *
	 * @return array<string, array>
	 */
	public static function describe(): array {
		return array_map(
			static function ( Rule $rule ) {
				return $rule->to_array();
			},
			self::all()
		);
	}

	/** Forgets built rules (locale switch, tests). */
	public static function reset(): void {
		self::$rules = null;
	}

	/* --------------------------------------------------------------- Helpers */

	/**
	 * A crawled 200 HTML page on the canonical host.
	 *
	 * @param array $p Page.
	 */
	public static function is_ok_html( array $p ): bool {
		return 'crawled' === ( $p['crawl_state'] ?? '' )
			&& 200 === (int) ( $p['status_code'] ?? 0 )
			&& ! empty( $p['is_html'] )
			&& empty( $p['extra']['host_variant'] );
	}

	/**
	 * A page whose content matters for search (200 HTML, not noindex).
	 *
	 * @param array $p Page.
	 */
	public static function is_content_page( array $p ): bool {
		return self::is_ok_html( $p ) && 'noindex' !== ( $p['indexability'] ?? '' );
	}

	/**
	 * Character length.
	 *
	 * @param string $text Text.
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Value from the page's extra data.
	 *
	 * @param array  $p        Page.
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private static function extra( array $p, string $key, $fallback = null ) {
		return isset( $p['extra'] ) && is_array( $p['extra'] ) && array_key_exists( $key, $p['extra'] ) ? $p['extra'][ $key ] : $fallback;
	}

	/**
	 * Valid hreflang value: x-default or language[-Script][-REGION] (ISO 639-1 / 15924 / 3166-1 or UN M.49).
	 *
	 * @param string $code Value.
	 */
	public static function valid_hreflang( string $code ): bool {
		$code = strtolower( trim( $code ) );
		if ( 'x-default' === $code ) {
			return true;
		}
		if ( ! preg_match( '/^([a-z]{2,3})(-[a-z]{4})?(-([a-z]{2}|\d{3}))?$/', $code, $m ) ) {
			return false;
		}
		// Frequent mistakes: "uk" is a language (Ukrainian), the United Kingdom is "gb"; "eu"/"int" are not regions.
		$region = isset( $m[4] ) ? $m[4] : '';
		return ! in_array( $region, array( 'uk', 'eu', 'un' ), true );
	}

	/* ----------------------------------------------------------- Definitions */

	/**
	 * Run rule definition that reads pre-computed checks from the context.
	 *
	 * @param array $definition Definition without check.
	 */
	private static function run_rule( array $definition ): array {
		$id                  = $definition['id'];
		$definition['scope'] = 'run';
		$definition['check'] = static function ( RuleContext $ctx ) use ( $id ) {
			return $ctx->run_checks( $id );
		};
		return $definition;
	}

	/** Indexability (page scope). */
	private static function indexability(): array {
		return array(
			array(
				'id'         => 'http_4xx',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Page not found or not accessible (4xx)', 'seo-console' ),
				'why'        => __( 'Visitors and Google get an error instead of the page. Links pointing here are wasted.', 'seo-console' ),
				'how_to_fix' => __( 'Restore the page, or add a 301 redirect to the most relevant live page, and update the links that point here.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$status = (int) ( $p['status_code'] ?? 0 );
					return 'crawled' === ( $p['crawl_state'] ?? '' ) && $status >= 400 && $status < 500 ? array( 'status' => $status ) : null;
				},
			),
			array(
				'id'         => 'http_5xx',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Server error (5xx)', 'seo-console' ),
				'why'        => __( 'The server failed to show the page. If this lasts, Google drops the page from its results.', 'seo-console' ),
				'how_to_fix' => __( 'Open the page yourself. If it fails, check the PHP error log or ask your developer or hosting provider.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					$status = (int) ( $p['status_code'] ?? 0 );
					return 'crawled' === ( $p['crawl_state'] ?? '' ) && $status >= 500 ? array( 'status' => $status ) : null;
				},
			),
			array(
				'id'         => 'fetch_failed',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Page could not be loaded', 'seo-console' ),
				'why'        => __( 'The request timed out or the connection failed. Slow or failing pages are crawled less by Google.', 'seo-console' ),
				'how_to_fix' => __( 'Open the page in your browser. If it is very slow, check caching and your hosting resources.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					return 'error' === ( $p['crawl_state'] ?? '' ) ? array( 'error' => (string) ( $p['error'] ?? '' ) ) : null;
				},
			),
			array(
				'id'         => 'redirect_chain',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Redirect chain (more than one hop)', 'seo-console' ),
				'why'        => __( 'Each extra redirect slows visitors down and can make Google stop following the chain.', 'seo-console' ),
				'how_to_fix' => __( 'Point the first redirect straight at the final URL, and update links to use the final URL.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$chain = isset( $p['redirect_chain'] ) && is_array( $p['redirect_chain'] ) ? $p['redirect_chain'] : array();
					if ( count( $chain ) < 2 || self::extra( $p, 'redirect_loop' ) ) {
						return null;
					}
					return array(
						'hops'      => count( $chain ),
						'chain'     => $chain,
						'final_url' => (string) ( $p['final_url'] ?? '' ),
					);
				},
			),
			array(
				'id'         => 'redirect_loop',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Redirect loop', 'seo-console' ),
				'why'        => __( 'The URL redirects back to itself (or more than 5 times), so nobody can reach the page.', 'seo-console' ),
				'how_to_fix' => __( 'Check your redirect plugin and .htaccess rules for redirects that point at each other, and remove one of them.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					if ( ! self::extra( $p, 'redirect_loop' ) && ! self::extra( $p, 'too_many_redirects' ) ) {
						return null;
					}
					return array(
						'chain'    => isset( $p['redirect_chain'] ) ? $p['redirect_chain'] : array(),
						'too_many' => (bool) self::extra( $p, 'too_many_redirects' ),
					);
				},
			),
			array(
				'id'         => 'blocked_by_robots',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Blocked by robots.txt', 'seo-console' ),
				'why'        => __( 'Google cannot read this page, so it cannot rank it well (it may still show the bare URL).', 'seo-console' ),
				'how_to_fix' => __( 'If the page should appear in Google, remove the robots.txt rule that blocks it. If not, ignore this notice.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					if ( empty( $p['robots_blocked'] ) && 'blocked' !== ( $p['crawl_state'] ?? '' ) ) {
						return null;
					}
					return ! empty( $p['in_sitemap'] ) ? null : array( 'rule' => self::extra( $p, 'robots_rule' ) );
				},
			),
			array(
				'id'         => 'canonical_missing',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Missing canonical tag', 'seo-console' ),
				'why'        => __( 'Without a canonical tag, Google has to guess which URL is the main version when the same content is reachable in several ways.', 'seo-console' ),
				'how_to_fix' => __( 'Rank Math and Yoast add a canonical tag automatically. Make sure one of them is active and the page is not excluded; otherwise ask your developer.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && '' === (string) ( $p['canonical'] ?? '' ) ? array() : null;
				},
			),
			array(
				'id'         => 'canonical_relative',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Canonical URL is not absolute', 'seo-console' ),
				'why'        => __( 'A relative canonical (without https:// and the domain) is easy to misread and can point to the wrong URL.', 'seo-console' ),
				'how_to_fix' => __( 'Use the full URL, including https:// and the domain, in the canonical field of your SEO plugin.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return self::is_ok_html( $p ) && self::extra( $p, 'canonical_relative' ) ? array( 'canonical' => (string) self::extra( $p, 'canonical_raw', '' ) ) : null;
				},
			),
			array(
				'id'         => 'canonical_other',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Canonical points to another page', 'seo-console' ),
				'why'        => __( 'This page tells Google that another URL is the main version, so this one will not be shown in search results.', 'seo-console' ),
				'how_to_fix' => __( 'This is fine for duplicates (filters, tracking URLs). If this page should rank on its own, set its canonical to its own URL.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$canonical = (string) ( $p['canonical'] ?? '' );
					return self::is_ok_html( $p ) && '' !== $canonical && $canonical !== $p['url'] ? array( 'canonical' => $canonical ) : null;
				},
			),
			array(
				'id'         => 'canonical_non_200',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Canonical points to a broken or redirected URL', 'seo-console' ),
				'why'        => __( 'Google ignores a canonical that does not lead to a working page, and may index the wrong URL.', 'seo-console' ),
				'how_to_fix' => __( 'Change the canonical to the final, working URL of the page.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					$canonical = (string) ( $p['canonical'] ?? '' );
					if ( ! self::is_ok_html( $p ) || '' === $canonical || $canonical === $p['url'] ) {
						return null;
					}
					$target = $ctx->page( $canonical );
					if ( ! $target ) {
						return null;
					}
					$status = (int) ( $target['status_code'] ?? 0 );
					if ( 'blocked' === $target['crawl_state'] || ( 'crawled' === $target['crawl_state'] && 200 === $status ) ) {
						return null;
					}
					return array(
						'canonical' => $canonical,
						'status'    => $status,
						'final_url' => (string) ( $target['final_url'] ?? '' ),
					);
				},
			),
			array(
				'id'         => 'noindex_in_sitemap',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Noindex page listed in the sitemap', 'seo-console' ),
				'why'        => __( 'The sitemap asks Google to index the page while the page asks not to be indexed. Google sees this as a mixed signal.', 'seo-console' ),
				'how_to_fix' => __( 'Decide: if the page should rank, remove "noindex" in the SEO plugin box of the page; if not, exclude it from the sitemap in your SEO plugin.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return 'noindex' === \SEOConsole\Modules\Audit\SitemapAnalyzer::page_problem( $p ) ? array( 'robots' => trim( ( $p['robots_meta'] ?? '' ) . ' ' . ( $p['x_robots'] ?? '' ) ) ) : null;
				},
			),
			array(
				'id'         => 'sitemap_error_page',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap lists a broken URL', 'seo-console' ),
				'why'        => __( 'The sitemap should only list working pages. Broken URLs waste Google\'s crawl time and lower trust in the sitemap.', 'seo-console' ),
				'how_to_fix' => __( 'Restore or redirect the page. Sitemaps from Rank Math, Yoast and WordPress update themselves once the page is fixed or deleted.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return 'error' === \SEOConsole\Modules\Audit\SitemapAnalyzer::page_problem( $p ) ? array( 'status' => (int) ( $p['status_code'] ?? 0 ) ) : null;
				},
			),
			array(
				'id'         => 'sitemap_redirect',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap lists a redirected URL', 'seo-console' ),
				'why'        => __( 'Sitemaps should list final URLs. Redirected URLs are crawled twice and are not indexed themselves.', 'seo-console' ),
				'how_to_fix' => __( 'Update the link or permalink so the sitemap lists the final URL, or remove the old URL from the sitemap.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return 'redirect' === \SEOConsole\Modules\Audit\SitemapAnalyzer::page_problem( $p ) ? array( 'final_url' => (string) ( $p['final_url'] ?? '' ) ) : null;
				},
			),
			array(
				'id'         => 'sitemap_canonicalised',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap lists a URL whose canonical is another page', 'seo-console' ),
				'why'        => __( 'The sitemap says "index this URL" while the page says another URL is the main one.', 'seo-console' ),
				'how_to_fix' => __( 'List only canonical URLs in the sitemap, or make the page canonical to itself.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return 'canonicalised' === \SEOConsole\Modules\Audit\SitemapAnalyzer::page_problem( $p ) ? array( 'canonical' => (string) ( $p['canonical'] ?? '' ) ) : null;
				},
			),
			array(
				'id'         => 'sitemap_blocked',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap lists a URL blocked by robots.txt', 'seo-console' ),
				'why'        => __( 'Google is invited to crawl a URL that robots.txt forbids, so the page cannot be read or ranked.', 'seo-console' ),
				'how_to_fix' => __( 'Either remove the robots.txt rule that blocks the URL, or remove the URL from the sitemap.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return 'blocked' === \SEOConsole\Modules\Audit\SitemapAnalyzer::page_problem( $p ) ? array( 'rule' => self::extra( $p, 'robots_rule' ) ) : null;
				},
			),
			array(
				'id'         => 'missing_from_sitemap',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Indexable page missing from the sitemap', 'seo-console' ),
				'why'        => __( 'Pages in the sitemap are discovered and re-crawled faster.', 'seo-console' ),
				'how_to_fix' => __( 'Check that the post type or taxonomy is included in your SEO plugin\'s sitemap settings, and that the page is not excluded.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					return $ctx->have_sitemap() && \SEOConsole\Modules\Audit\SitemapAnalyzer::missing_from_sitemap( $p ) && empty( $p['extra']['host_variant'] ) ? array() : null;
				},
			),
			array(
				'id'         => 'noindex_in_nav',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Noindex page linked from the main navigation', 'seo-console' ),
				'why'        => __( 'Menus point visitors and Google to your most important pages. A noindex page there is often a mistake.', 'seo-console' ),
				'how_to_fix' => __( 'If the page should rank, remove "noindex" from it; otherwise this notice can be ignored (e.g. login or cart pages).', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					return 'noindex' === ( $p['indexability'] ?? '' ) && $ctx->in_nav( (string) $p['url_hash'] ) ? array() : null;
				},
			),
		);
	}

	/** Run-level (site-wide) rules: loopback, robots.txt, sitemap files. */
	private static function run_level(): array {
		$defs = array(
			array(
				'id'         => 'loopback_failed',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'The site could not crawl itself', 'seo-console' ),
				'why'        => __( 'Your server blocks requests from the website to itself (a "loopback" request), so the audit could not load your pages. Other plugins and WordPress Site Health also need this.', 'seo-console' ),
				'how_to_fix' => __( 'Ask your hosting provider to allow loopback HTTP requests to your own domain (often a firewall, security plugin or wrong DNS/SSL on the server). Then run the audit again.', 'seo-console' ),
				'fixable_by' => 'developer',
			),
			array(
				'id'         => 'robots_missing',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt is missing', 'seo-console' ),
				'why'        => __( 'Without robots.txt Google crawls everything, including low-value URLs, and does not learn where your sitemap is.', 'seo-console' ),
				'how_to_fix' => __( 'WordPress normally serves a robots.txt automatically. Check that permalinks are not "Plain" and that no file or server rule blocks /robots.txt.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'robots_unreachable',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt returns a server error', 'seo-console' ),
				'why'        => __( 'When robots.txt fails with a 5xx error, Google stops crawling the whole site until it works again.', 'seo-console' ),
				'how_to_fix' => __( 'Open /robots.txt in your browser. If it fails, ask your hosting provider or developer to check the server error log.', 'seo-console' ),
				'fixable_by' => 'developer',
			),
			array(
				'id'         => 'robots_disallow_all',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt blocks the whole site', 'seo-console' ),
				'why'        => __( '"Disallow: /" tells Google not to crawl any page. Your site will disappear from search results.', 'seo-console' ),
				'how_to_fix' => __( 'Remove the "Disallow: /" line. If WordPress generates robots.txt, untick Settings → Reading → "Discourage search engines from indexing this site".', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'robots_blocks_important',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt blocks important pages', 'seo-console' ),
				'why'        => __( 'Your home page, recent posts, products or categories cannot be crawled by Google.', 'seo-console' ),
				'how_to_fix' => __( 'Remove or narrow the Disallow rules listed here so these pages can be crawled.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'robots_blocks_assets',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt blocks CSS, JavaScript or images', 'seo-console' ),
				'why'        => __( 'Google needs your CSS, JavaScript and images to see the page like a visitor. Blocking them can hurt rankings and mobile-friendliness.', 'seo-console' ),
				'how_to_fix' => __( 'Remove rules such as "Disallow: /wp-content/" or "Disallow: /wp-includes/".', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'robots_no_sitemap',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap not declared in robots.txt', 'seo-console' ),
				'why'        => __( 'A "Sitemap:" line helps every search engine find your sitemap.', 'seo-console' ),
				'how_to_fix' => __( 'Add a line like "Sitemap: https://your-site/sitemap_index.xml" to robots.txt (Rank Math, Yoast and WordPress add it automatically when their sitemap is on).', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'robots_syntax',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'robots.txt has lines search engines ignore', 'seo-console' ),
				'why'        => __( 'Unknown directives, rules before any "User-agent" line or lines without ":" are ignored, so the file may not do what you expect.', 'seo-console' ),
				'how_to_fix' => __( 'Fix or remove the listed lines. Note that "Noindex:" in robots.txt is no longer supported by Google.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'sitemap_missing',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'No XML sitemap found', 'seo-console' ),
				'why'        => __( 'A sitemap helps Google discover and re-crawl all your pages.', 'seo-console' ),
				'how_to_fix' => __( 'Turn on the sitemap in Rank Math or Yoast (or keep the WordPress core sitemap enabled) and declare it in robots.txt.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'sitemap_invalid',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap cannot be read', 'seo-console' ),
				'why'        => __( 'The sitemap is unreachable or not valid XML, so search engines cannot use it.', 'seo-console' ),
				'how_to_fix' => __( 'Open the sitemap in your browser. Blank output or PHP warnings before the XML usually come from a plugin or theme; clear caches and ask your developer if it persists.', 'seo-console' ),
				'fixable_by' => 'developer',
			),
			array(
				'id'         => 'sitemap_too_large',
				'severity'   => 'error',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap file is too large', 'seo-console' ),
				'why'        => __( 'A sitemap file may hold at most 50,000 URLs and 50 MB. Larger files are rejected.', 'seo-console' ),
				'how_to_fix' => __( 'Lower the "links per sitemap" setting of your SEO plugin so it splits the sitemap into smaller files.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'sitemap_bad_loc',
				'severity'   => 'warning',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap has relative or other-domain URLs', 'seo-console' ),
				'why'        => __( 'Sitemap URLs must be full URLs on this domain; others are ignored.', 'seo-console' ),
				'how_to_fix' => __( 'Check Settings → General (WordPress and Site Address) and your SEO plugin sitemap settings, then clear caches.', 'seo-console' ),
				'fixable_by' => 'user',
			),
			array(
				'id'         => 'sitemap_bad_lastmod',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Sitemap has invalid last-modified dates', 'seo-console' ),
				'why'        => __( 'Google ignores dates that are not in W3C format (e.g. 2024-05-30 or 2024-05-30T10:00:00+07:00).', 'seo-console' ),
				'how_to_fix' => __( 'Usually caused by a custom sitemap plugin; switch to the Rank Math, Yoast or WordPress sitemap.', 'seo-console' ),
				'fixable_by' => 'developer',
			),
			array(
				'id'         => 'sitemap_gz_skipped',
				'severity'   => 'notice',
				'category'   => 'indexability',
				'title'      => __( 'Compressed sitemap not checked', 'seo-console' ),
				'why'        => __( 'Gzip sitemaps (.xml.gz) are valid but this audit does not open them, so their URLs were not cross-checked.', 'seo-console' ),
				'how_to_fix' => __( 'Nothing to fix. If you want them checked, also publish an uncompressed sitemap.', 'seo-console' ),
				'fixable_by' => null,
			),
		);
		return array_map( array( __CLASS__, 'run_rule' ), $defs );
	}

	/** Links. */
	private static function links(): array {
		return array(
			array(
				'id'         => 'broken_internal_links',
				'severity'   => 'error',
				'category'   => 'links',
				'title'      => __( 'Links to broken pages', 'seo-console' ),
				'why'        => __( 'Visitors hit an error page and Google wastes crawl time.', 'seo-console' ),
				'how_to_fix' => __( 'Edit the page and change or remove each listed link (or redirect the broken URL to a working page).', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					if ( ! self::is_ok_html( $p ) ) {
						return null;
					}
					$broken = array();
					foreach ( $ctx->links_from( (int) $p['id'] ) as $link ) {
						$target = $ctx->page_by_hash( (string) $link['to_url_hash'] );
						if ( ! $target || isset( $broken[ $link['to_url'] ] ) ) {
							continue;
						}
						$status = (int) ( $target['status_code'] ?? 0 );
						if ( 'error' === $target['crawl_state'] || ( 'crawled' === $target['crawl_state'] && $status >= 400 ) ) {
							$broken[ $link['to_url'] ] = array(
								'url'    => (string) $link['to_url'],
								'status' => $status,
								'anchor' => (string) ( $link['anchor'] ?? '' ),
							);
						}
					}
					return $broken ? array(
						'count' => count( $broken ),
						'links' => array_slice( array_values( $broken ), 0, 20 ),
					) : null;
				},
			),
			array(
				'id'         => 'links_to_redirect',
				'severity'   => 'warning',
				'category'   => 'links',
				'title'      => __( 'Links to redirected URLs', 'seo-console' ),
				'why'        => __( 'Every redirect adds delay for visitors and Google. Internal links should point at the final URL.', 'seo-console' ),
				'how_to_fix' => __( 'Edit the page and replace each listed link with its final URL.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					if ( ! self::is_ok_html( $p ) ) {
						return null;
					}
					$found = array();
					foreach ( $ctx->links_from( (int) $p['id'] ) as $link ) {
						$target = $ctx->page_by_hash( (string) $link['to_url_hash'] );
						if ( ! $target || isset( $found[ $link['to_url'] ] ) || 'crawled' !== $target['crawl_state'] ) {
							continue;
						}
						$status = (int) ( $target['status_code'] ?? 0 );
						if ( $status >= 300 && $status < 400 ) {
							$found[ $link['to_url'] ] = array(
								'url'       => (string) $link['to_url'],
								'final_url' => (string) ( $target['final_url'] ?? '' ),
								'status'    => $status,
								'anchor'    => (string) ( $link['anchor'] ?? '' ),
							);
						}
					}
					return $found ? array(
						'count' => count( $found ),
						'links' => array_slice( array_values( $found ), 0, 20 ),
					) : null;
				},
			),
			array(
				'id'         => 'orphan_page',
				'severity'   => 'warning',
				'category'   => 'links',
				'title'      => __( 'Orphan page (no internal links point to it)', 'seo-console' ),
				'why'        => __( 'The page is only in the sitemap. Without internal links Google considers it unimportant and visitors cannot find it.', 'seo-console' ),
				'how_to_fix' => __( 'Link to this page from related posts, a category page or the menu.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					return ! empty( $p['indexable'] ) && ! empty( $p['in_sitemap'] ) && 0 === (int) ( $p['inbound_internal'] ?? 0 ) && $p['url'] !== $ctx->home() ? array() : null;
				},
			),
			array(
				'id'         => 'too_many_links',
				'severity'   => 'notice',
				'category'   => 'links',
				'title'      => __( 'Too many links on the page', 'seo-console' ),
				'why'        => __( 'More than 300 links dilute the value passed to each one and can overwhelm visitors.', 'seo-console' ),
				'how_to_fix' => __( 'Trim mega-menus, tag clouds and footer link lists.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					$total = (int) ( $p['internal_links_out'] ?? 0 ) + (int) ( $p['external_links_out'] ?? 0 );
					return self::is_ok_html( $p ) && $total > 300 ? array( 'links' => $total ) : null;
				},
			),
			array(
				'id'         => 'deep_page',
				'severity'   => 'notice',
				'category'   => 'links',
				'title'      => __( 'Page is more than 4 clicks from the home page', 'seo-console' ),
				'why'        => __( 'Deep pages are crawled less often and look less important.', 'seo-console' ),
				'how_to_fix' => __( 'Link to the page from the home page, a category page or the menu.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && null !== ( $p['depth'] ?? null ) && (int) $p['depth'] > 4 ? array( 'depth' => (int) $p['depth'] ) : null;
				},
			),
		);
	}

	/** Content: titles, descriptions, headings, thin content. */
	private static function content(): array {
		return array(
			array(
				'id'         => 'title_missing',
				'severity'   => 'error',
				'category'   => 'content',
				'title'      => __( 'Missing or empty title', 'seo-console' ),
				'why'        => __( 'The title is the blue link in Google results and one of the strongest ranking signals.', 'seo-console' ),
				'how_to_fix' => __( 'Write a unique title of 30–60 characters that includes the main keyword, in the SEO box of the page.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && '' === trim( (string) ( $p['title'] ?? '' ) ) ? array() : null;
				},
			),
			array(
				'id'         => 'title_duplicate',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Duplicate title', 'seo-console' ),
				'why'        => __( 'Pages with the same title compete with each other and Google may pick the wrong one.', 'seo-console' ),
				'how_to_fix' => __( 'Give each page its own title that describes what makes it different.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					if ( empty( $p['indexable'] ) ) {
						return null;
					}
					$others = $ctx->duplicates( 'title', (string) ( $p['title'] ?? '' ), (string) $p['url'] );
					return $others ? array(
						'title'  => (string) $p['title'],
						'count'  => count( $others ),
						'others' => array_slice( $others, 0, 10 ),
					) : null;
				},
			),
			array(
				'id'         => 'title_too_long',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Title too long', 'seo-console' ),
				'why'        => __( 'Google shows about 60 characters (~580 pixels); the rest is cut off with "…".', 'seo-console' ),
				'how_to_fix' => __( 'Shorten the title to 60 characters or less, keeping the main keyword near the start.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					$length = self::length( (string) ( $p['title'] ?? '' ) );
					return self::is_content_page( $p ) && $length > 60 ? array( 'length' => $length ) : null;
				},
			),
			array(
				'id'         => 'title_too_short',
				'severity'   => 'notice',
				'category'   => 'content',
				'title'      => __( 'Title too short', 'seo-console' ),
				'why'        => __( 'A very short title misses the chance to describe the page and include keywords.', 'seo-console' ),
				'how_to_fix' => __( 'Expand the title to 30–60 characters.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					$length = self::length( trim( (string) ( $p['title'] ?? '' ) ) );
					return self::is_content_page( $p ) && $length > 0 && $length < 30 ? array( 'length' => $length ) : null;
				},
			),
			array(
				'id'         => 'meta_desc_missing',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Missing meta description', 'seo-console' ),
				'why'        => __( 'Google then picks a random snippet from the page, which often attracts fewer clicks.', 'seo-console' ),
				'how_to_fix' => __( 'Write a 70–160 character summary that makes people want to click, in the SEO box of the page.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && '' === trim( (string) ( $p['meta_description'] ?? '' ) ) ? array() : null;
				},
			),
			array(
				'id'         => 'meta_desc_duplicate',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Duplicate meta description', 'seo-console' ),
				'why'        => __( 'Identical descriptions make pages look the same in search results.', 'seo-console' ),
				'how_to_fix' => __( 'Write a unique description for each page.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					if ( empty( $p['indexable'] ) ) {
						return null;
					}
					$others = $ctx->duplicates( 'meta_description', (string) ( $p['meta_description'] ?? '' ), (string) $p['url'] );
					return $others ? array(
						'count'  => count( $others ),
						'others' => array_slice( $others, 0, 10 ),
					) : null;
				},
			),
			array(
				'id'         => 'meta_desc_too_long',
				'severity'   => 'notice',
				'category'   => 'content',
				'title'      => __( 'Meta description too long', 'seo-console' ),
				'why'        => __( 'Google cuts descriptions after about 160 characters.', 'seo-console' ),
				'how_to_fix' => __( 'Shorten the description to 160 characters or less.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					$length = self::length( (string) ( $p['meta_description'] ?? '' ) );
					return self::is_content_page( $p ) && $length > 160 ? array( 'length' => $length ) : null;
				},
			),
			array(
				'id'         => 'meta_desc_too_short',
				'severity'   => 'notice',
				'category'   => 'content',
				'title'      => __( 'Meta description too short', 'seo-console' ),
				'why'        => __( 'A short description wastes space in search results that could convince people to click.', 'seo-console' ),
				'how_to_fix' => __( 'Expand the description to 70–160 characters.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					$length = self::length( trim( (string) ( $p['meta_description'] ?? '' ) ) );
					return self::is_content_page( $p ) && $length > 0 && $length < 70 ? array( 'length' => $length ) : null;
				},
			),
			array(
				'id'         => 'h1_missing',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Missing H1 heading', 'seo-console' ),
				'why'        => __( 'The H1 tells visitors and Google what the page is about.', 'seo-console' ),
				'how_to_fix' => __( 'Make sure the page shows its main heading as H1 (usually the post title; some themes or page builders hide it).', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && 0 === (int) ( $p['h1_count'] ?? 0 ) ? array() : null;
				},
			),
			array(
				'id'         => 'h1_multiple',
				'severity'   => 'notice',
				'category'   => 'content',
				'title'      => __( 'More than one H1 heading', 'seo-console' ),
				'why'        => __( 'Several H1s make the main topic less clear. Often the logo or a widget title is wrongly marked as H1.', 'seo-console' ),
				'how_to_fix' => __( 'Keep one H1 for the main heading and change the others to H2 or H3.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$count = (int) ( $p['h1_count'] ?? 0 );
					return self::is_content_page( $p ) && $count > 1 ? array( 'count' => $count ) : null;
				},
			),
			array(
				'id'         => 'title_same_as_h1',
				'severity'   => 'notice',
				'category'   => 'content',
				'title'      => __( 'Title is identical to the H1', 'seo-console' ),
				'why'        => __( 'Not a problem in itself, but a different SEO title lets you target more search phrases.', 'seo-console' ),
				'how_to_fix' => __( 'Optionally write an SEO title that adds a benefit or keyword variation.', 'seo-console' ),
				'fixable_by' => null,
				'check'      => static function ( array $p ) {
					$title = RuleContext::normalize_text( (string) ( $p['title'] ?? '' ) );
					return self::is_content_page( $p ) && '' !== $title && RuleContext::normalize_text( (string) ( $p['h1'] ?? '' ) ) === $title ? array() : null;
				},
			),
			array(
				'id'         => 'thin_content',
				'severity'   => 'warning',
				'category'   => 'content',
				'title'      => __( 'Thin content', 'seo-console' ),
				'why'        => __( 'Pages with very little text rarely rank and can lower the perceived quality of the site.', 'seo-console' ),
				'how_to_fix' => __( 'Add useful content (answers, details, examples), merge it with a similar page, or set it to noindex.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					$min = (int) $ctx->setting( 'thin_content_words', 200 );
					if ( ! self::is_content_page( $p ) || empty( $p['post_id'] ) ) {
						return null; // Archives and the blog home are lists, not articles.
					}
					$words = (int) ( $p['word_count'] ?? 0 );
					return $words < $min ? array(
						'words'   => $words,
						'minimum' => $min,
					) : null;
				},
			),
		);
	}

	/** Images. */
	private static function images(): array {
		return array(
			array(
				'id'         => 'img_missing_alt',
				'severity'   => 'warning',
				'category'   => 'images',
				'title'      => __( 'Images without alt text', 'seo-console' ),
				'why'        => __( 'Alt text describes images to blind visitors and to Google Images.', 'seo-console' ),
				'how_to_fix' => __( 'Add a short description of each image in the Media Library (Alternative Text). Decorative images can use an empty alt.', 'seo-console' ),
				'fixable_by' => 'ai',
				'check'      => static function ( array $p ) {
					$list  = isset( $p['images_missing_alt'] ) && is_array( $p['images_missing_alt'] ) ? $p['images_missing_alt'] : array();
					$count = (int) self::extra( $p, 'images_missing_count', count( $list ) );
					return self::is_content_page( $p ) && $count > 0 ? array(
						'count'  => $count,
						'images' => $list,
					) : null;
				},
			),
			array(
				'id'         => 'img_no_dimensions',
				'severity'   => 'notice',
				'category'   => 'images',
				'title'      => __( 'Images without width and height', 'seo-console' ),
				'why'        => __( 'Without size attributes the layout jumps while images load (Cumulative Layout Shift).', 'seo-console' ),
				'how_to_fix' => __( 'Insert images through the WordPress editor (it adds width and height) or ask your developer to add them in the theme.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					$list  = (array) self::extra( $p, 'images_no_dimensions', array() );
					$count = (int) self::extra( $p, 'images_no_dims_count', count( $list ) );
					return self::is_content_page( $p ) && $count > 0 ? array(
						'count'  => $count,
						'images' => $list,
					) : null;
				},
			),
		);
	}

	/** Performance and mobile. */
	private static function performance(): array {
		return array(
			array(
				'id'         => 'slow_response',
				'severity'   => 'warning',
				'category'   => 'performance',
				'title'      => __( 'Slow server response', 'seo-console' ),
				'why'        => __( 'The server took more than 2 seconds to answer. Slow pages lose visitors and are crawled less.', 'seo-console' ),
				'how_to_fix' => __( 'Enable page caching (LiteSpeed Cache, WP Rocket, SG Optimizer), check heavy plugins, or upgrade hosting. See the Speed page.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					$ms    = (int) ( $p['response_ms'] ?? 0 );
					$limit = (int) $ctx->setting( 'slow_ms', 2000 );
					return self::is_ok_html( $p ) && $ms > $limit ? array(
						'ms'    => $ms,
						'limit' => $limit,
					) : null;
				},
			),
			array(
				'id'         => 'large_html',
				'severity'   => 'warning',
				'category'   => 'performance',
				'title'      => __( 'Very large HTML', 'seo-console' ),
				'why'        => __( 'Pages over 2 MB of HTML load slowly and Google may stop reading them before the end.', 'seo-console' ),
				'how_to_fix' => __( 'Look for inline images (base64), huge menus or page-builder bloat; ask your developer to slim the template.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					$bytes = (int) ( $p['bytes'] ?? 0 );
					return self::is_ok_html( $p ) && $bytes > 2097152 ? array( 'bytes' => $bytes ) : null;
				},
			),
			array(
				'id'         => 'mixed_content',
				'severity'   => 'warning',
				'category'   => 'performance',
				'title'      => __( 'Insecure (http://) files on a secure page', 'seo-console' ),
				'why'        => __( 'Browsers block or warn about http:// files on https:// pages, which can break images, styles or scripts.', 'seo-console' ),
				'how_to_fix' => __( 'Replace http:// with https:// in the listed URLs (a search-and-replace plugin can update the database).', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$list = (array) self::extra( $p, 'mixed_content', array() );
					return self::is_ok_html( $p ) && $list ? array(
						'count' => count( $list ),
						'urls'  => $list,
					) : null;
				},
			),
			array(
				'id'         => 'viewport_missing',
				'severity'   => 'warning',
				'category'   => 'performance',
				'title'      => __( 'Missing mobile viewport tag', 'seo-console' ),
				'why'        => __( 'Without it, phones show the desktop layout zoomed out. Google ranks with the mobile version.', 'seo-console' ),
				'how_to_fix' => __( 'Use a responsive theme; your developer can add <meta name="viewport" content="width=device-width, initial-scale=1"> to the theme header.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && '' === (string) self::extra( $p, 'viewport', '' ) ? array() : null;
				},
			),
		);
	}

	/** International. */
	private static function international(): array {
		return array(
			array(
				'id'         => 'html_lang_missing',
				'severity'   => 'notice',
				'category'   => 'international',
				'title'      => __( 'Missing language attribute', 'seo-console' ),
				'why'        => __( 'The lang attribute tells browsers, screen readers and translators which language the page is in.', 'seo-console' ),
				'how_to_fix' => __( 'Themes normally print it via language_attributes(). Check Settings → General → Site Language, or ask your developer.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					return self::is_content_page( $p ) && '' === (string) self::extra( $p, 'lang', '' ) ? array() : null;
				},
			),
			array(
				'id'         => 'hreflang_invalid',
				'severity'   => 'error',
				'category'   => 'international',
				'title'      => __( 'Invalid hreflang code', 'seo-console' ),
				'why'        => __( 'Google ignores hreflang tags with invalid language or region codes.', 'seo-console' ),
				'how_to_fix' => __( 'Use codes like "vi", "en", "en-gb" or "x-default" (the United Kingdom is "gb", not "uk"). Fix them in your multilingual plugin.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$bad = array();
					foreach ( isset( $p['hreflang'] ) && is_array( $p['hreflang'] ) ? $p['hreflang'] : array() as $alt ) {
						if ( ! self::valid_hreflang( (string) ( $alt['lang'] ?? '' ) ) ) {
							$bad[] = (string) ( $alt['lang'] ?? '' );
						}
					}
					return self::is_ok_html( $p ) && $bad ? array( 'codes' => array_values( array_unique( $bad ) ) ) : null;
				},
			),
			array(
				'id'         => 'hreflang_no_return',
				'severity'   => 'warning',
				'category'   => 'international',
				'title'      => __( 'hreflang without return link', 'seo-console' ),
				'why'        => __( 'Alternate language pages must link back to each other; otherwise Google ignores the hreflang tags.', 'seo-console' ),
				'how_to_fix' => __( 'Make sure every translation lists all other translations (including itself). Your multilingual plugin should do this when translations are linked.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p, RuleContext $ctx ) {
					if ( ! self::is_ok_html( $p ) ) {
						return null;
					}
					$missing = array();
					foreach ( isset( $p['hreflang'] ) && is_array( $p['hreflang'] ) ? $p['hreflang'] : array() as $alt ) {
						$url = (string) ( $alt['url'] ?? '' );
						if ( '' === $url || $url === $p['url'] ) {
							continue;
						}
						$target = $ctx->page( $url );
						if ( ! $target || ! self::is_ok_html( $target + array( 'is_html' => 1 ) ) ) {
							continue;
						}
						$back = false;
						foreach ( isset( $target['hreflang'] ) && is_array( $target['hreflang'] ) ? $target['hreflang'] : array() as $target_alt ) {
							if ( (string) ( $target_alt['url'] ?? '' ) === $p['url'] ) {
								$back = true;
								break;
							}
						}
						if ( ! $back ) {
							$missing[] = $url;
						}
					}
					return $missing ? array( 'urls' => array_slice( array_values( array_unique( $missing ) ), 0, 20 ) ) : null;
				},
			),
		);
	}

	/** Social. */
	private static function social(): array {
		return array(
			array(
				'id'         => 'og_title_missing',
				'severity'   => 'notice',
				'category'   => 'social',
				'title'      => __( 'Missing Open Graph title', 'seo-console' ),
				'why'        => __( 'Facebook, Zalo and LinkedIn use og:title for shared links.', 'seo-console' ),
				'how_to_fix' => __( 'Rank Math and Yoast add Open Graph tags automatically; check that their social/Open Graph option is on.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$og = isset( $p['og'] ) && is_array( $p['og'] ) ? $p['og'] : array();
					return self::is_content_page( $p ) && '' === (string) ( $og['og:title'] ?? '' ) ? array() : null;
				},
			),
			array(
				'id'         => 'og_image_missing',
				'severity'   => 'notice',
				'category'   => 'social',
				'title'      => __( 'Missing Open Graph image', 'seo-console' ),
				'why'        => __( 'Shared links without an image get far fewer clicks on social networks.', 'seo-console' ),
				'how_to_fix' => __( 'Set a featured image on the post, or a default social image in your SEO plugin.', 'seo-console' ),
				'fixable_by' => 'user',
				'check'      => static function ( array $p ) {
					$og = isset( $p['og'] ) && is_array( $p['og'] ) ? $p['og'] : array();
					return self::is_content_page( $p ) && '' === (string) ( $og['og:image'] ?? '' ) ? array() : null;
				},
			),
		);
	}

	/** Structured data. */
	private static function structured_data(): array {
		return array(
			array(
				'id'         => 'schema_invalid_json',
				'severity'   => 'warning',
				'category'   => 'structured-data',
				'title'      => __( 'Broken structured data (JSON-LD)', 'seo-console' ),
				'why'        => __( 'A JSON-LD block that is not valid JSON is ignored by Google, so rich results are lost.', 'seo-console' ),
				'how_to_fix' => __( 'Find the plugin or custom code that prints the broken block and fix or remove it.', 'seo-console' ),
				'fixable_by' => 'developer',
				'check'      => static function ( array $p ) {
					$errors = (int) self::extra( $p, 'schema_errors', 0 );
					return self::is_ok_html( $p ) && $errors > 0 ? array( 'blocks' => $errors ) : null;
				},
			),
		);
	}
}
