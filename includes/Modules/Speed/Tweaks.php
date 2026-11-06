<?php
/**
 * Safe front-end optimisations SEO Console applies itself.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Each tweak is independent and only adds/removes hooks. `apply()` never writes anything;
 * on/off state is stored elsewhere (core `module_setting` applier, so it is undoable).
 *
 * Every output hook is guarded: nothing is changed for admin, AJAX, cron, REST, XML-RPC, feeds,
 * embeds, robots/trackbacks, AMP or Customizer previews.
 *
 * Config keys used by apply():
 * - `cache_features`     — CacheConfigAudit::features() (feature => bool); tweaks the cache plugin already does are skipped.
 * - `lcp_images`         — list of [ 'page_url', 'image_url', 'srcset'?, 'sizes'? ] (from PSI results).
 * - `preconnect_origins` — list of https origins (from PSI `preconnect_candidates`).
 * - `revisions_to_keep`  — int 1–100 (default 10).
 * - `heartbeat_interval` — admin heartbeat interval in seconds, 15–120 (default 60).
 */
class Tweaks {

	const MAX_PRECONNECT = 4;

	/**
	 * Config passed to apply().
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * LCP entry for the current request (false = none), keyed by request URI.
	 *
	 * @var array
	 */
	private $lcp_cache = array();

	/**
	 * Tweak catalogue.
	 *
	 * `scope`: 'frontend' (call apply() from register_frontend) or 'everywhere' (from register(): also affects admin).
	 * `cache_feature`: CacheConfigAudit feature that makes this tweak redundant (skipped when the cache plugin does it).
	 *
	 * @return array id => [ 'id', 'title', 'description', 'risk' => low|medium, 'scope', 'cache_feature', 'skips_when', 'undoable' => true ]
	 */
	public static function definitions(): array {
		$defs = array(
			'disable_emojis'           => array(
				'title'         => __( 'Remove the emoji script', 'seo-console' ),
				'description'   => __( 'WordPress loads a small script and styles on every page to draw emoji in old browsers. Modern browsers show emoji by themselves, so this is safe to remove.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => 'disable_emojis',
			),
			'disable_embeds'           => array(
				'title'         => __( 'Remove the WordPress embed script', 'seo-console' ),
				'description'   => __( 'Removes oEmbed discovery links and the script that resizes embedded WordPress posts. YouTube and other embeds keep working; embeds of other WordPress posts will not auto-resize.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => 'disable_embeds',
			),
			'disable_dashicons_guests' => array(
				'title'         => __( 'Do not load admin icons for visitors', 'seo-console' ),
				'description'   => __( 'The Dashicons font is only needed for the admin bar. It is skipped for logged-out visitors unless another stylesheet on the page needs it.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => null,
			),
			'clean_head'               => array(
				'title'         => __( 'Clean up the page head', 'seo-console' ),
				'description'   => __( 'Removes links nobody uses from every page: RSD, Windows Live Writer manifest, shortlink and the WordPress version number.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => null,
			),
			'disable_xmlrpc'           => array(
				'title'         => __( 'Turn off XML-RPC', 'seo-console' ),
				'description'   => __( 'XML-RPC is an old remote-publishing interface often abused by bots. Turning it off saves server work. Do not use this if you publish with the old WordPress mobile app or Jetpack (it is skipped automatically when Jetpack is active).', 'seo-console' ),
				'risk'          => 'medium',
				'scope'         => 'frontend',
				'cache_feature' => null,
			),
			'heartbeat'                => array(
				'title'         => __( 'Calm down the Heartbeat', 'seo-console' ),
				'description'   => __( 'WordPress pings the server every 15–60 seconds while pages are open. This stops it for visitors and slows it to once a minute in the admin (autosave and post locking keep working).', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'everywhere',
				'cache_feature' => 'heartbeat',
			),
			'lcp_boost'                => array(
				'title'         => __( 'Speed up the main image', 'seo-console' ),
				'description'   => __( 'For the pages SEO Console tested, the main above-the-fold image (the LCP image) is preloaded, given high priority and excluded from lazy-loading, including the cache plugin\'s lazy-load.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => null,
			),
			'preconnect'               => array(
				'title'         => __( 'Connect early to external sites', 'seo-console' ),
				'description'   => __( 'Adds preconnect hints for up to 4 external origins the page needs early (for example Google Fonts), found by the speed test.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'frontend',
				'cache_feature' => null,
			),
			'limit_revisions'          => array(
				'title'         => __( 'Limit post revisions', 'seo-console' ),
				'description'   => __( 'Keeps only the last 10 revisions of each post so the database stays small. Older revisions are removed the next time a post is saved.', 'seo-console' ),
				'risk'          => 'low',
				'scope'         => 'everywhere',
				'cache_feature' => null,
			),
		);
		foreach ( $defs as $id => $def ) {
			$defs[ $id ]['id']         = $id;
			$defs[ $id ]['undoable']   = true;
			$defs[ $id ]['skips_when'] = $def['cache_feature']
				? __( 'Skipped automatically when your cache plugin already does the same thing.', 'seo-console' )
				: '';
		}
		return $defs;
	}

	/**
	 * Hooks the enabled tweaks.
	 *
	 * @param string[] $enabled_tweaks Tweak ids.
	 * @param array    $config         See class doc.
	 * @param string   $scope          'all', 'frontend' or 'everywhere' (only tweaks with that scope).
	 * @return array [ 'applied' => string[], 'skipped' => [ id => reason ] ] reason: unknown|scope|cache_plugin|missing_config|conflict.
	 */
	public function apply( array $enabled_tweaks, array $config = array(), string $scope = 'all' ): array {
		$this->config = $config;
		$defs         = self::definitions();
		$cache        = self::cache_features( isset( $config['cache_features'] ) ? $config['cache_features'] : array() );
		$applied      = array();
		$skipped      = array();

		foreach ( array_unique( array_map( 'strval', $enabled_tweaks ) ) as $id ) {
			if ( ! isset( $defs[ $id ] ) ) {
				$skipped[ $id ] = 'unknown';
				continue;
			}
			if ( 'all' !== $scope && $defs[ $id ]['scope'] !== $scope ) {
				$skipped[ $id ] = 'scope';
				continue;
			}
			if ( $defs[ $id ]['cache_feature'] && ! empty( $cache[ $defs[ $id ]['cache_feature'] ] ) ) {
				$skipped[ $id ] = 'cache_plugin';
				continue;
			}
			$result = call_user_func( array( $this, 'hook_' . $id ) );
			if ( true === $result ) {
				$applied[] = $id;
			} else {
				$skipped[ $id ] = (string) $result;
			}
		}
		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}

	/**
	 * Whether public-page output may be changed in this request.
	 *
	 * @return bool
	 */
	public static function is_public_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			return false;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_customize_preview() ) {
			return false;
		}
		if ( isset( $GLOBALS['wp_query'] ) && did_action( 'parse_query' ) ) {
			if ( is_feed() || is_embed() || is_robots() || is_trackback() ) {
				return false;
			}
		}
		if ( did_action( 'wp' ) ) {
			if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
				return false;
			}
			if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Runs a callback once the main query is known (on `wp`), only for public requests.
	 *
	 * @param callable $callback Callback.
	 */
	private function on_public_request( callable $callback ): void {
		$run = static function () use ( $callback ) {
			if ( self::is_public_request() ) {
				$callback();
			}
		};
		if ( did_action( 'wp' ) ) {
			$run();
			return;
		}
		add_action( 'wp', $run, 0 );
	}

	/** Emoji script/styles. */
	private function hook_disable_emojis() {
		$this->on_public_request(
			static function () {
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'wp_print_styles', 'print_emoji_styles' );
				remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
				add_filter( 'emoji_svg_url', '__return_false' );
			}
		);
		return true;
	}

	/** OEmbed discovery + host script. */
	private function hook_disable_embeds() {
		$this->on_public_request(
			static function () {
				remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
				remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
				remove_action( 'wp_head', 'wp_oembed_add_host_js' );
				remove_filter( 'embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js' );
				add_filter( 'embed_oembed_discover', '__return_false' );
				add_action(
					'wp_footer',
					static function () {
						wp_dequeue_script( 'wp-embed' );
					},
					0
				);
			}
		);
		return true;
	}

	/** Dashicons for logged-out visitors. */
	private function hook_disable_dashicons_guests() {
		add_action(
			'wp_enqueue_scripts',
			static function () {
				if ( ! self::is_public_request() || is_user_logged_in() || is_admin_bar_showing() ) {
					return;
				}
				if ( ! self::style_needed_by_others( 'dashicons' ) ) {
					wp_dequeue_style( 'dashicons' );
				}
			},
			1000
		);
		return true;
	}

	/** RSD, wlwmanifest, shortlink, generator. */
	private function hook_clean_head() {
		$this->on_public_request(
			static function () {
				remove_action( 'wp_head', 'rsd_link' );
				remove_action( 'wp_head', 'wlwmanifest_link' );
				remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
				remove_action( 'wp_head', 'wp_generator' );
				remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
			}
		);
		return true;
	}

	/** XML-RPC off. */
	private function hook_disable_xmlrpc() {
		if ( class_exists( 'Jetpack' ) ) {
			return 'conflict';
		}
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array', 1000 );
		add_filter(
			'wp_headers',
			static function ( $headers ) {
				if ( is_array( $headers ) ) {
					unset( $headers['X-Pingback'] );
				}
				return $headers;
			},
			1000
		);
		return true;
	}

	/** Heartbeat: off for visitors, 60 s in admin. */
	private function hook_heartbeat() {
		$interval = isset( $this->config['heartbeat_interval'] ) ? (int) $this->config['heartbeat_interval'] : 60;
		$interval = max( 15, min( 120, $interval ) );
		add_filter(
			'heartbeat_settings',
			static function ( $settings ) use ( $interval ) {
				if ( is_admin() && is_array( $settings ) ) {
					$settings['interval'] = $interval;
				}
				return $settings;
			},
			1000
		);
		add_action(
			'wp_enqueue_scripts',
			static function () {
				// Dequeue only: if another script depends on it, WordPress still loads it, so nothing breaks.
				if ( self::is_public_request() ) {
					wp_dequeue_script( 'heartbeat' );
				}
			},
			1000
		);
		return true;
	}

	/** Revisions cap. */
	private function hook_limit_revisions() {
		$keep = isset( $this->config['revisions_to_keep'] ) ? (int) $this->config['revisions_to_keep'] : 10;
		$keep = max( 1, min( 100, $keep ) );
		add_filter(
			'wp_revisions_to_keep',
			static function ( $num ) use ( $keep ) {
				$num = (int) $num;
				if ( 0 === $num ) {
					return 0; // Revisions disabled elsewhere: keep it that way.
				}
				return ( $num < 0 || $num > $keep ) ? $keep : $num;
			},
			1000
		);
		return true;
	}

	/** Preconnect hints. */
	private function hook_preconnect() {
		$origins = self::clean_origins( isset( $this->config['preconnect_origins'] ) ? (array) $this->config['preconnect_origins'] : array() );
		if ( empty( $origins ) ) {
			return 'missing_config';
		}
		add_filter(
			'wp_resource_hints',
			static function ( $urls, $relation_type ) use ( $origins ) {
				if ( 'preconnect' !== $relation_type || ! is_array( $urls ) || ! self::is_public_request() ) {
					return $urls;
				}
				$existing = array();
				foreach ( $urls as $url ) {
					$href       = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;
					$existing[] = untrailingslashit( strtolower( (string) $href ) );
				}
				foreach ( $origins as $origin ) {
					if ( in_array( $origin, $existing, true ) ) {
						continue;
					}
					$urls[] = in_array( $origin, array( 'https://fonts.gstatic.com', 'https://use.typekit.net', 'https://p.typekit.net' ), true )
						? array(
							'href'        => $origin,
							'crossorigin' => 'anonymous',
						)
						: $origin;
				}
				return $urls;
			},
			20,
			2
		);
		return true;
	}

	/** LCP image: preload + fetchpriority + no lazy-load. */
	private function hook_lcp_boost() {
		$images = isset( $this->config['lcp_images'] ) ? (array) $this->config['lcp_images'] : array();
		if ( empty( $images ) ) {
			return 'missing_config';
		}
		add_action( 'wp_head', array( $this, 'print_lcp_preload' ), 1 );
		add_filter( 'wp_get_loading_optimization_attributes', array( $this, 'filter_loading_attributes' ), 20, 4 );
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attachment_attributes' ), 20, 3 );
		return true;
	}

	/**
	 * Prints the LCP preload link (wp_head, priority 1).
	 */
	public function print_lcp_preload(): void {
		$lcp = $this->current_lcp();
		if ( null === $lcp ) {
			return;
		}
		$attrs = sprintf( 'rel="preload" as="image" href="%s" fetchpriority="high"', esc_url( $lcp['image_url'] ) );
		if ( '' !== $lcp['srcset'] ) {
			$attrs .= sprintf( ' imagesrcset="%s"', esc_attr( $lcp['srcset'] ) );
			if ( '' !== $lcp['sizes'] ) {
				$attrs .= sprintf( ' imagesizes="%s"', esc_attr( $lcp['sizes'] ) );
			}
		}
		echo '<link ' . $attrs . " />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute escaped above.
	}

	/**
	 * `wp_get_loading_optimization_attributes`: no lazy-load + high priority for the LCP image.
	 *
	 * @param array  $loading_attrs Attributes WordPress wants to add.
	 * @param string $tag_name      Tag.
	 * @param array  $attr          Tag attributes.
	 * @param string $context       Context.
	 * @return array
	 */
	public function filter_loading_attributes( $loading_attrs, $tag_name, $attr, $context ) {
		unset( $context );
		if ( 'img' !== $tag_name || ! is_array( $loading_attrs ) || ! is_array( $attr ) ) {
			return $loading_attrs;
		}
		$lcp = $this->current_lcp();
		if ( null === $lcp || ! self::image_matches( isset( $attr['src'] ) ? (string) $attr['src'] : '', isset( $attr['srcset'] ) ? (string) $attr['srcset'] : '', $lcp['image_url'] ) ) {
			return $loading_attrs;
		}
		unset( $loading_attrs['loading'] );
		$loading_attrs['fetchpriority'] = 'high';
		if ( function_exists( 'wp_high_priority_element_flag' ) ) {
			wp_high_priority_element_flag( false );
		}
		return $loading_attrs;
	}

	/**
	 * `wp_content_img_tag`: fix the LCP <img> in post content (also when lazy-loading was hard-coded).
	 *
	 * @param string $image   Tag HTML.
	 * @param string $context Context.
	 * @param int    $attachment_id Attachment id.
	 * @return string
	 */
	public function filter_content_img_tag( $image, $context = '', $attachment_id = 0 ) {
		unset( $context, $attachment_id );
		if ( ! is_string( $image ) ) {
			return $image;
		}
		$lcp = $this->current_lcp();
		if ( null === $lcp ) {
			return $image;
		}
		$src    = self::tag_attr( $image, 'src' );
		$srcset = self::tag_attr( $image, 'srcset' );
		if ( ! self::image_matches( $src, $srcset, $lcp['image_url'] ) ) {
			return $image;
		}
		return self::boost_img_tag( $image );
	}

	/**
	 * `wp_get_attachment_image_attributes`: featured/hero images output by the theme.
	 *
	 * @param array $attr       Attributes.
	 * @param mixed $attachment Attachment post.
	 * @param mixed $size       Size.
	 * @return array
	 */
	public function filter_attachment_attributes( $attr, $attachment = null, $size = null ) {
		unset( $attachment, $size );
		if ( ! is_array( $attr ) ) {
			return $attr;
		}
		$lcp = $this->current_lcp();
		if ( null === $lcp || ! self::image_matches( isset( $attr['src'] ) ? (string) $attr['src'] : '', isset( $attr['srcset'] ) ? (string) $attr['srcset'] : '', $lcp['image_url'] ) ) {
			return $attr;
		}
		unset( $attr['loading'] );
		$attr['fetchpriority'] = 'high';
		$attr['data-no-lazy']  = '1';
		$classes               = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		if ( ! preg_match( '/(^|\s)skip-lazy(\s|$)/', $classes ) ) {
			$attr['class'] = trim( $classes . ' skip-lazy' );
		}
		if ( function_exists( 'wp_high_priority_element_flag' ) ) {
			wp_high_priority_element_flag( false );
		}
		return $attr;
	}

	/**
	 * Adds fetchpriority/no-lazy markers to an <img> tag and removes loading="lazy".
	 * `data-no-lazy` and the `skip-lazy` class are honoured by LiteSpeed Cache, WP Rocket and most lazy-load plugins.
	 *
	 * @param string $image Tag.
	 * @return string
	 */
	public static function boost_img_tag( string $image ): string {
		$image = preg_replace( '/\sloading\s*=\s*(["\']?)lazy\1/i', '', $image );
		if ( preg_match( '/\sfetchpriority\s*=/i', (string) $image ) ) {
			$image = preg_replace( '/\sfetchpriority\s*=\s*(["\']?)[a-z]*\1/i', ' fetchpriority="high"', (string) $image );
		} else {
			$image = preg_replace( '/^<img\b/i', '<img fetchpriority="high"', (string) $image );
		}
		if ( ! preg_match( '/\sdata-no-lazy\s*=/i', (string) $image ) ) {
			$image = preg_replace( '/^<img\b/i', '<img data-no-lazy="1"', (string) $image );
		}
		if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', (string) $image, $m ) ) {
			if ( ! preg_match( '/(^|\s)skip-lazy(\s|$)/', $m[2] ) ) {
				$image = str_replace( $m[0], ' class=' . $m[1] . trim( $m[2] . ' skip-lazy' ) . $m[1], (string) $image );
			}
		} else {
			$image = preg_replace( '/^<img\b/i', '<img class="skip-lazy"', (string) $image );
		}
		if ( function_exists( 'wp_high_priority_element_flag' ) ) {
			wp_high_priority_element_flag( false );
		}
		return (string) $image;
	}

	/**
	 * Finds the <img> for an LCP image URL in page HTML and returns its src/srcset/sizes
	 * (Phase B uses this after a loopback fetch to store exact preload attributes).
	 *
	 * @param string $html      Page HTML.
	 * @param string $image_url LCP image URL from PSI.
	 * @return array|null [ 'src', 'srcset', 'sizes' ]
	 */
	public static function find_image_attributes( string $html, string $image_url ) {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			return null;
		}
		foreach ( $tags[0] as $tag ) {
			$src    = self::tag_attr( $tag, 'src' );
			$srcset = self::tag_attr( $tag, 'srcset' );
			if ( self::image_matches( $src, $srcset, $image_url ) ) {
				return array(
					'src'    => $src,
					'srcset' => $srcset,
					'sizes'  => self::tag_attr( $tag, 'sizes' ),
				);
			}
		}
		return null;
	}

	/**
	 * The configured LCP image for the current request, or null.
	 *
	 * @return array|null [ 'image_url', 'srcset', 'sizes' ]
	 */
	private function current_lcp() {
		if ( ! self::is_public_request() ) {
			return null;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( ! array_key_exists( $uri, $this->lcp_cache ) ) {
			$this->lcp_cache[ $uri ] = false;
			$current                 = self::page_key( $uri );
			foreach ( (array) ( isset( $this->config['lcp_images'] ) ? $this->config['lcp_images'] : array() ) as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['page_url'] ) || empty( $entry['image_url'] ) ) {
					continue;
				}
				$image_url = esc_url_raw( (string) $entry['image_url'], array( 'http', 'https' ) );
				if ( '' === $image_url || self::page_key( (string) $entry['page_url'] ) !== $current ) {
					continue;
				}
				$this->lcp_cache[ $uri ] = array(
					'image_url' => $image_url,
					'srcset'    => isset( $entry['srcset'] ) ? (string) $entry['srcset'] : '',
					'sizes'     => isset( $entry['sizes'] ) ? (string) $entry['sizes'] : '',
				);
				break;
			}
		}
		return false === $this->lcp_cache[ $uri ] ? null : $this->lcp_cache[ $uri ];
	}

	/**
	 * Normalised key of a page URL or request URI: lowercase path without trailing slash ('/' for home)
	 * plus only the query vars that identify content with plain permalinks (tracking parameters are ignored).
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function page_key( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '/';
		$key  = strtolower( '/' . trim( $path, '/' ) );

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $args );
			$keep = array_intersect_key( $args, array_flip( array( 'p', 'page_id', 'cat', 'tag', 'post_type', 'name', 'pagename', 'product', 'product_cat', 'attachment_id', 'author', 'm' ) ) );
			$keep = array_filter( $keep, 'is_scalar' );
			if ( $keep ) {
				ksort( $keep );
				$key .= '?' . strtolower( http_build_query( $keep ) );
			}
		}
		return $key;
	}

	/**
	 * Comparable key of an image URL: path without host, size suffix (-768x410, -scaled),
	 * double next-gen extension (.jpg.webp) and extension.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function image_key( string $url ): string {
		$path = wp_parse_url( html_entity_decode( trim( $url ), ENT_QUOTES ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$path = strtolower( rawurldecode( $path ) );
		$path = (string) preg_replace( '/\.(jpe?g|png|gif)\.(webp|avif)$/', '.$1', $path );
		$path = (string) preg_replace( '/-(\d+x\d+|scaled)(?=\.[a-z0-9]+$)/', '', $path );
		return (string) preg_replace( '/\.[a-z0-9]+$/', '', $path );
	}

	/**
	 * Whether an <img> (src or any srcset candidate) is the LCP image.
	 *
	 * @param string $src       Src.
	 * @param string $srcset    Srcset.
	 * @param string $image_url LCP image URL.
	 * @return bool
	 */
	public static function image_matches( string $src, string $srcset, string $image_url ): bool {
		$target = self::image_key( $image_url );
		if ( '' === $target ) {
			return false;
		}
		if ( '' !== $src && self::image_key( $src ) === $target ) {
			return true;
		}
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( ! empty( $parts[0] ) && self::image_key( $parts[0] ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validated https origins (max 4, not this site).
	 *
	 * @param array $origins Origins/URLs.
	 * @return string[]
	 */
	public static function clean_origins( array $origins ): array {
		$site  = PsiParser::host( home_url( '/' ) );
		$clean = array();
		foreach ( $origins as $origin ) {
			if ( ! is_string( $origin ) ) {
				continue;
			}
			$normalised = PsiParser::origin( trim( $origin ) );
			if ( '' === $normalised || 0 !== strpos( $normalised, 'https://' ) || PsiParser::same_site( PsiParser::host( $normalised ), $site ) ) {
				continue;
			}
			$clean[ $normalised ] = true;
		}
		return array_slice( array_keys( $clean ), 0, self::MAX_PRECONNECT );
	}

	/**
	 * Feature map from CacheConfigAudit::features() or a plain list of feature ids.
	 *
	 * @param mixed $features Features.
	 * @return array feature => bool
	 */
	private static function cache_features( $features ): array {
		if ( $features instanceof CacheConfigAudit ) {
			$features = $features->features();
		}
		$out = array();
		foreach ( (array) $features as $key => $value ) {
			if ( is_int( $key ) && is_string( $value ) ) {
				$out[ $value ] = true;
			} elseif ( is_string( $key ) ) {
				$out[ $key ] = true === $value;
			}
		}
		return $out;
	}

	/**
	 * Whether any other enqueued style depends (directly or not) on a handle.
	 *
	 * @param string $handle Style handle.
	 * @return bool
	 */
	private static function style_needed_by_others( string $handle ): bool {
		$styles = wp_styles();
		foreach ( $styles->queue as $queued ) {
			if ( $queued !== $handle && self::depends_on( $styles, $queued, $handle, 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursive dependency check.
	 *
	 * @param \WP_Dependencies $deps   Registry.
	 * @param string           $from   Handle.
	 * @param string           $target Target handle.
	 * @param int              $depth  Depth.
	 * @return bool
	 */
	private static function depends_on( $deps, string $from, string $target, int $depth ): bool {
		if ( $depth > 10 || ! isset( $deps->registered[ $from ] ) ) {
			return false;
		}
		foreach ( (array) $deps->registered[ $from ]->deps as $dep ) {
			if ( $dep === $target || self::depends_on( $deps, (string) $dep, $target, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads an attribute from a tag.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute.
	 * @return string
	 */
	private static function tag_attr( string $tag, string $name ): string {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
			return html_entity_decode( isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2], ENT_QUOTES );
		}
		return '';
	}
}
