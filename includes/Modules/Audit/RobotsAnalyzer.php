<?php
/**
 * Fetches and checks the site's robots.txt.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * robots.txt checks.
 */
class RobotsAnalyzer {

	/** @var Url */
	private $scope;

	/** @var HttpFetcher */
	private $fetcher;

	/**
	 * Constructor.
	 *
	 * @param Url|null         $scope   Site scope.
	 * @param HttpFetcher|null $fetcher HTTP client.
	 */
	public function __construct( ?Url $scope = null, ?HttpFetcher $fetcher = null ) {
		$this->scope   = $scope ? $scope : new Url();
		$this->fetcher = $fetcher ? $fetcher : new HttpFetcher( $this->scope );
	}

	/**
	 * Fetches /robots.txt over HTTP; falls back to what WordPress would serve when loopback fails.
	 *
	 * @return array{url: string, source: string, status: int, content: string, error: string}
	 *     source: 'http' (fetched), 'virtual' (loopback failed, generated locally), 'file' (loopback failed, physical file read).
	 */
	public function fetch(): array {
		$url      = $this->scope->root_url( '/robots.txt' );
		$response = $this->fetcher->get( $url );
		if ( '' === $response['error'] ) {
			$status = (int) $response['final_status'];
			return array(
				'url'     => $url,
				'source'  => 'http',
				'status'  => $status,
				'content' => 200 === $status ? substr( (string) $response['body'], 0, 512000 ) : '',
				'error'   => '',
			);
		}
		$file = self::physical_file();
		if ( '' !== $file ) {
			return array(
				'url'     => $url,
				'source'  => 'file',
				'status'  => 200,
				'content' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				'error'   => $response['error'],
			);
		}
		return array(
			'url'     => $url,
			'source'  => 'virtual',
			'status'  => 200,
			'content' => self::virtual_content(),
			'error'   => $response['error'],
		);
	}

	/** Path of a physical robots.txt in the WordPress root, or ''. */
	public static function physical_file(): string {
		$file = trailingslashit( ABSPATH ) . 'robots.txt';
		return is_readable( $file ) ? $file : '';
	}

	/**
	 * The robots.txt WordPress serves when there is no physical file (same as do_robots(), including the
	 * robots_txt filter that Rank Math, Yoast and core sitemaps use).
	 */
	public static function virtual_content(): string {
		$public = (string) get_option( 'blog_public' );
		$output = "User-agent: *\n";
		if ( '0' === $public ) {
			$output .= "Disallow: /\n";
		} else {
			$site_url = wp_parse_url( site_url() );
			$path     = ! empty( $site_url['path'] ) ? untrailingslashit( $site_url['path'] ) : '';
			$output  .= "Disallow: {$path}/wp-admin/\n";
			$output  .= "Allow: {$path}/wp-admin/admin-ajax.php\n";
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	/**
	 * Who manages robots.txt: 'file' (physical file), 'rankmath', 'yoast' or 'WordPress' (virtual default).
	 */
	public static function manager(): string {
		if ( '' !== self::physical_file() ) {
			return 'file';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		return 'WordPress';
	}

	/**
	 * Plain-English "where to change it" advice for the manager.
	 *
	 * @param string $manager Manager id.
	 */
	public static function advice( string $manager ): string {
		switch ( $manager ) {
			case 'file':
				return __( 'Your robots.txt is a real file in the WordPress folder on your hosting. Edit it with your hosting File Manager (or FTP), or ask your developer.', 'seo-console' );
			case 'rankmath':
				return __( 'Rank Math manages your robots.txt: go to Rank Math → General Settings → Edit robots.txt.', 'seo-console' );
			case 'yoast':
				return __( 'Yoast SEO can edit your robots.txt: go to Yoast SEO → Tools → File editor.', 'seo-console' );
			default:
				return __( 'WordPress generates your robots.txt automatically. If you see "Disallow: /", check Settings → Reading → "Discourage search engines from indexing this site" and untick it.', 'seo-console' );
		}
	}

	/**
	 * URLs that must stay crawlable: home, recent posts and pages, top categories, WooCommerce products.
	 *
	 * @return string[]
	 */
	public function important_urls(): array {
		$urls  = array( $this->scope->home() );
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => 6,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( post_type_exists( 'product' ) ) {
			$posts = array_merge(
				$posts,
				get_posts(
					array(
						'post_type'        => 'product',
						'post_status'      => 'publish',
						'numberposts'      => 3,
						'fields'           => 'ids',
						'suppress_filters' => true,
					)
				)
			);
		}
		foreach ( $posts as $post_id ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		foreach ( array( 'category', 'product_cat' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'number'     => 3,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'hide_empty' => true,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		return array_values( array_unique( array_map( array( $this->scope, 'normalize' ), $urls ) ) );
	}

	/**
	 * Theme CSS, core JS, an upload and a plugin script: Google needs them to render pages.
	 *
	 * @return array<string, string> label => URL.
	 */
	public function asset_urls(): array {
		$uploads = wp_upload_dir( null, false );
		return array(
			'theme_css'  => get_stylesheet_uri(),
			'core_js'    => includes_url( 'js/jquery/jquery.min.js' ),
			'uploads'    => trailingslashit( (string) $uploads['baseurl'] ) . '2024/01/image.jpg',
			'plugin_js'  => content_url( '/plugins/example/script.js' ),
			'plugin_css' => content_url( '/plugins/example/style.css' ),
		);
	}

	/**
	 * Runs every robots.txt check.
	 *
	 * @param array|null    $fetched        Result of fetch() (fetched when null).
	 * @param string[]|null $important_urls URLs that must be crawlable (computed when null).
	 * @param string[]|null $asset_urls     Asset URLs (computed when null).
	 * @return array {
	 *     @type string    $manager  file|rankmath|yoast|WordPress.
	 *     @type string    $source   http|virtual|file.
	 *     @type int       $status   HTTP status.
	 *     @type bool      $exists   A robots.txt was served.
	 *     @type string    $content  Content (≤ 100 KB).
	 *     @type string[]  $sitemaps Sitemap: URLs.
	 *     @type array[]   $groups   Parsed groups.
	 *     @type array[]   $checks   [ [ 'rule' => rule id, 'data' => array ] ].
	 * }
	 */
	public function analyze( ?array $fetched = null, ?array $important_urls = null, ?array $asset_urls = null ): array {
		$fetched = null === $fetched ? $this->fetch() : $fetched;
		$manager = self::manager();
		$advice  = self::advice( $manager );
		$exists  = 200 === (int) $fetched['status'] && '' !== trim( (string) $fetched['content'] );
		$robots  = RobotsTxt::parse( $exists ? (string) $fetched['content'] : '' );
		$checks  = array();

		$status = (int) $fetched['status'];
		if ( $status >= 500 ) {
			$checks[] = array(
				'rule' => 'robots_unreachable',
				'data' => array(
					'status' => $status,
					'advice' => $advice,
				),
			);
		} elseif ( ! $exists ) {
			$checks[] = array(
				'rule' => 'robots_missing',
				'data' => array(
					'status' => $status,
					'advice' => $advice,
				),
			);
		}

		if ( $exists ) {
			$home         = $this->scope->home();
			$disallow_all = array();
			foreach ( array( 'Googlebot', '*' ) as $agent ) {
				if ( ! $robots->is_allowed( $home, $agent ) ) {
					$disallow_all[] = $agent;
				}
			}
			if ( $disallow_all ) {
				$checks[] = array(
					'rule' => 'robots_disallow_all',
					'data' => array(
						'user_agents'     => $disallow_all,
						'rule'            => $robots->matching_rule( $home, $disallow_all[0] ),
						'discourage_flag' => '0' === (string) get_option( 'blog_public' ),
						'advice'          => $advice,
					),
				);
			} else {
				$blocked = array();
				foreach ( null === $important_urls ? $this->important_urls() : $important_urls as $url ) {
					if ( ! $robots->is_allowed( $url, 'Googlebot' ) ) {
						$blocked[] = array(
							'url'  => $url,
							'rule' => $robots->matching_rule( $url, 'Googlebot' ),
						);
					}
				}
				if ( $blocked ) {
					$checks[] = array(
						'rule' => 'robots_blocks_important',
						'data' => array(
							'urls'   => array_slice( $blocked, 0, 20 ),
							'advice' => $advice,
						),
					);
				}
			}

			$assets = array();
			foreach ( null === $asset_urls ? $this->asset_urls() : $asset_urls as $label => $url ) {
				if ( ! $robots->is_allowed( $url, 'Googlebot' ) ) {
					$assets[] = array(
						'type' => (string) $label,
						'url'  => $url,
						'rule' => $robots->matching_rule( $url, 'Googlebot' ),
					);
				}
			}
			if ( $assets && ! $disallow_all ) {
				$checks[] = array(
					'rule' => 'robots_blocks_assets',
					'data' => array(
						'assets' => $assets,
						'advice' => $advice,
					),
				);
			}

			if ( ! $robots->sitemaps() ) {
				$checks[] = array(
					'rule' => 'robots_no_sitemap',
					'data' => array( 'advice' => $advice ),
				);
			}
			if ( $robots->errors() ) {
				$checks[] = array(
					'rule' => 'robots_syntax',
					'data' => array(
						'errors' => array_slice( $robots->errors(), 0, 20 ),
						'count'  => count( $robots->errors() ),
						'advice' => $advice,
					),
				);
			}
		}

		return array(
			'manager'  => $manager,
			'source'   => (string) $fetched['source'],
			'status'   => $status,
			'exists'   => $exists,
			'content'  => substr( (string) $fetched['content'], 0, 102400 ),
			'sitemaps' => $robots->sitemaps(),
			'groups'   => $robots->groups(),
			'checks'   => $checks,
		);
	}
}
