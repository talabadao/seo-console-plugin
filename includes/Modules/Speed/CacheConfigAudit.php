<?php
/**
 * Read-only audit of the active cache plugin's configuration.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Reads LiteSpeed Cache, WP Rocket or SiteGround Speed Optimizer settings (never writes them),
 * evaluates a speed checklist and reports which features the plugin already handles so
 * SEO Console's own tweaks can stay out of the way.
 *
 * Finding: [ 'id', 'status' => ok|recommend|warning|unknown, 'title', 'detail', 'how_to', 'settings_url', 'impact' => high|medium|low ].
 */
class CacheConfigAudit {

	const PLUGINS = array(
		'litespeed'    => 'LiteSpeed Cache',
		'wp-rocket'    => 'WP Rocket',
		'sg-optimizer' => 'SiteGround Speed Optimizer',
	);

	/** Features the overlap check knows. */
	const FEATURES = array(
		'page_cache',
		'browser_cache',
		'mobile_cache',
		'object_cache',
		'cdn',
		'preload',
		'gzip',
		'minify_css',
		'minify_js',
		'minify_html',
		'combine_css',
		'combine_js',
		'defer_js',
		'delay_js',
		'critical_css',
		'unused_css',
		'font_display',
		'google_fonts',
		'lazyload',
		'lazyload_lcp_exclusion',
		'image_formats',
		'image_dimensions',
		'disable_emojis',
		'disable_embeds',
		'heartbeat',
		'dns_prefetch',
		'preconnect',
		'remove_query_strings',
		'database_cleanup',
	);

	/**
	 * Plugin id.
	 *
	 * @var string
	 */
	private $plugin;

	/**
	 * Environment facts (overridable for tests).
	 *
	 * @var array
	 */
	private $env;

	/**
	 * Memoised findings by id.
	 *
	 * @var array|null
	 */
	private $findings;

	/**
	 * Memoised feature states.
	 *
	 * @var array|null
	 */
	private $states;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_id 'litespeed'|'wp-rocket'|'sg-optimizer' (from core CachePlugins::detect()).
	 * @param array  $env       Optional overrides: wp_cache (bool), ext_object_cache (bool), version (string),
	 *                          litespeed_server (bool|null), nginx (bool).
	 */
	public function __construct( string $plugin_id, array $env = array() ) {
		$this->plugin = $plugin_id;
		$this->env    = array_merge( $this->detect_env(), $env );
	}

	/**
	 * Whether this plugin id is supported by the audit.
	 *
	 * @param string $plugin_id Plugin id.
	 * @return bool
	 */
	public static function supports( string $plugin_id ): bool {
		return isset( self::PLUGINS[ $plugin_id ] );
	}

	/**
	 * Plugin id this audit reads.
	 *
	 * @return string
	 */
	public function plugin(): string {
		return $this->plugin;
	}

	/**
	 * Human name of the plugin.
	 *
	 * @return string
	 */
	public function plugin_name(): string {
		return isset( self::PLUGINS[ $this->plugin ] ) ? self::PLUGINS[ $this->plugin ] : $this->plugin;
	}

	/**
	 * One finding by check id (null when the plugin does not offer it).
	 *
	 * @param string $id Check id.
	 * @return array|null
	 */
	public function finding( string $id ) {
		if ( null === $this->findings ) {
			$this->findings = array_column( $this->audit(), null, 'id' );
		}
		return isset( $this->findings[ $id ] ) ? $this->findings[ $id ] : null;
	}

	/**
	 * True only when the cache plugin is known to perform this feature right now.
	 *
	 * @param string $feature One of FEATURES.
	 * @return bool
	 */
	public function does_feature( string $feature ): bool {
		$states = $this->features();
		return isset( $states[ $feature ] ) && true === $states[ $feature ];
	}

	/**
	 * Feature → true (on) | false (off) | null (unknown / not offered by this plugin).
	 *
	 * @return array
	 */
	public function features(): array {
		if ( null !== $this->states ) {
			return $this->states;
		}
		$states = array_fill_keys( self::FEATURES, null );
		switch ( $this->plugin ) {
			case 'litespeed':
				$states = array_merge( $states, $this->litespeed_features() );
				break;
			case 'wp-rocket':
				$states = array_merge( $states, $this->rocket_features() );
				break;
			case 'sg-optimizer':
				$states = array_merge( $states, $this->sg_features() );
				break;
		}
		if ( ! empty( $this->env['ext_object_cache'] ) ) {
			$states['object_cache'] = true;
		}
		$this->states = $states;
		return $states;
	}

	/**
	 * Evaluates the checklist.
	 *
	 * @return array[] Findings.
	 */
	public function audit(): array {
		if ( ! self::supports( $this->plugin ) ) {
			return array();
		}
		$f        = $this->features();
		$name     = self::PLUGINS[ $this->plugin ];
		$findings = array();

		foreach ( $this->checks() as $id => $check ) {
			$spec = isset( $check['plugins'][ $this->plugin ] ) ? $check['plugins'][ $this->plugin ] : null;
			if ( null === $spec ) {
				continue; // Not offered by this plugin.
			}
			$state = isset( $f[ $check['feature'] ] ) ? $f[ $check['feature'] ] : null;
			if ( isset( $check['evaluate'] ) ) {
				list( $status, $detail ) = call_user_func( $check['evaluate'], $state, $f, $spec );
			} elseif ( null === $state ) {
				$status = 'unknown';
				$detail = __( 'SEO Console could not read this setting (the plugin version may store it differently). Check it in the plugin settings.', 'seo-console' );
			} elseif ( $state ) {
				$status = 'ok';
				$detail = $check['on'];
			} else {
				$status = isset( $check['off_status'] ) ? $check['off_status'] : 'recommend';
				$detail = $check['off'];
			}
			$findings[] = array(
				'id'           => $id,
				'status'       => $status,
				'title'        => $check['title'],
				'detail'       => $detail,
				'how_to'       => sprintf( '%s: %s', $name, $spec['how_to'] ),
				'settings_url' => admin_url( $spec['url'] ),
				'impact'       => $check['impact'],
			);
		}
		return $findings;
	}

	/**
	 * The checklist with plugin-specific instructions.
	 *
	 * @return array
	 */
	private function checks(): array {
		$plugin    = $this->plugin;
		$env       = $this->env;
		$caution   = __( 'Test the site after turning it on (menus, sliders, forms, cookie banner). If something breaks, exclude that file or turn the option off again.', 'seo-console' );
		$combine_e = static function ( $state ) {
			if ( null === $state ) {
				return array( 'unknown', __( 'Could not read this setting.', 'seo-console' ) );
			}
			if ( $state ) {
				return array( 'ok', __( 'Combining files is on. On modern HTTP/2 or HTTP/3 hosting it gains little and is a common cause of broken layouts after theme or plugin updates. If you see problems, turn this off first.', 'seo-console' ) );
			}
			return array( 'ok', __( 'Combining files is off. That is fine on HTTP/2 or HTTP/3 hosting and avoids breakage.', 'seo-console' ) );
		};

		return array(
			'page_cache'    => array(
				'feature'  => 'page_cache',
				'title'    => __( 'Page cache', 'seo-console' ),
				'impact'   => 'high',
				'evaluate' => static function ( $state ) use ( $plugin, $env ) {
					if ( 'litespeed' === $plugin && $state && false === $env['litespeed_server'] ) {
						return array( 'warning', __( 'LiteSpeed page cache is switched on, but this server is not a LiteSpeed server, so pages are not actually cached. Use QUIC.cloud CDN or a cache plugin that works on this server.', 'seo-console' ) );
					}
					if ( 'wp-rocket' === $plugin && ! $env['wp_cache'] ) {
						return array( 'warning', __( 'WP Rocket is active but WordPress caching is not switched on (WP_CACHE is missing from wp-config.php), so pages are not cached. Deactivate and re-activate WP Rocket, or ask your host to make wp-config.php writable.', 'seo-console' ) );
					}
					if ( null === $state ) {
						return array( 'unknown', __( 'Could not read whether page caching is on.', 'seo-console' ) );
					}
					return $state
						? array( 'ok', __( 'Pages are served from cache, so most visitors get a fast response without running PHP.', 'seo-console' ) )
						: array( 'warning', __( 'Page caching is off. This is the single biggest speed setting: every visit rebuilds the page from scratch and the server responds slowly (high TTFB).', 'seo-console' ) );
				},
				'plugins'  => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Cache → Cache tab → Enable Cache = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-cache',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Caching is automatic once WP Rocket is active; check Settings → WP Rocket → Dashboard for warnings.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#dashboard',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Caching → Dynamic Caching = ON (or File-Based Caching on non-SiteGround hosting).', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_caching',
					),
				),
			),
			'browser_cache' => array(
				'feature' => 'browser_cache',
				'title'   => __( 'Browser cache', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'Returning visitors reuse images, CSS and JavaScript they already downloaded.', 'seo-console' ),
				'off'     => __( 'Browsers download the same images, CSS and JavaScript again on every visit. Turn browser caching on.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Cache → Browser tab → Browser Cache = ON (TTL 31557600).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-cache#browser',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'WP Rocket adds browser-cache rules automatically on Apache/LiteSpeed servers. On Nginx, ask your host to add "expires" rules for static files.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#dashboard',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Environment → Browser Caching = ON (automatic on SiteGround hosting).', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_environment',
					),
				),
			),
			'mobile_cache'  => array(
				'feature'  => 'mobile_cache',
				'title'    => __( 'Cache for mobile visitors', 'seo-console' ),
				'impact'   => 'high',
				'evaluate' => static function ( $state ) use ( $plugin ) {
					if ( 'litespeed' === $plugin ) {
						return $state
							? array( 'ok', __( 'Mobile visitors get their own cached copy. Only needed if your theme outputs different HTML on mobile; otherwise it just doubles the cache size.', 'seo-console' ) )
							: array( 'ok', __( 'Mobile and desktop visitors share the same cached page, which is right for responsive themes.', 'seo-console' ) );
					}
					if ( null === $state ) {
						return array( 'unknown', __( 'Could not read the mobile cache setting.', 'seo-console' ) );
					}
					return $state
						? array( 'ok', __( 'Mobile visitors are served cached pages.', 'seo-console' ) )
						: array( 'warning', __( 'Mobile visitors are NOT served cached pages. Most visitors and Google\'s mobile test are on phones, so turn mobile caching on.', 'seo-console' ) );
				},
				'plugins'  => array(
					'litespeed' => array(
						'how_to' => __( 'LiteSpeed Cache → Cache → Cache tab → Cache Mobile (only if the theme serves different HTML on phones).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-cache',
					),
					'wp-rocket' => array(
						'how_to' => __( 'Settings → WP Rocket → Cache → Mobile Cache → Enable caching for mobile devices = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#cache',
					),
				),
			),
			'minify_css'    => array(
				'feature' => 'minify_css',
				'title'   => __( 'Minify CSS', 'seo-console' ),
				'impact'  => 'low',
				'on'      => __( 'Stylesheets are minified (spaces and comments removed).', 'seo-console' ),
				'off'     => __( 'Stylesheets are sent with spaces and comments. Minifying them is a safe, small win.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → CSS Settings → CSS Minify = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_css',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → CSS Files → Minify CSS files = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → CSS → Minify CSS Files = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'minify_js'     => array(
				'feature' => 'minify_js',
				'title'   => __( 'Minify JavaScript', 'seo-console' ),
				'impact'  => 'low',
				'on'      => __( 'JavaScript files are minified.', 'seo-console' ),
				'off'     => __( 'JavaScript files are sent unminified. Minifying them is usually safe and saves bytes.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → JS Settings → JS Minify = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_js',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → JavaScript Files → Minify JavaScript files = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → JavaScript → Minify JavaScript Files = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'combine_css'   => array(
				'feature'  => 'combine_css',
				'title'    => __( 'Combine CSS files', 'seo-console' ),
				'impact'   => 'low',
				'evaluate' => $combine_e,
				'plugins'  => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → CSS Settings → CSS Combine.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_css',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Combine CSS files (hidden when Remove Unused CSS is on).', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → CSS → Combine CSS Files.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'combine_js'    => array(
				'feature'  => 'combine_js',
				'title'    => __( 'Combine JavaScript files', 'seo-console' ),
				'impact'   => 'low',
				'evaluate' => $combine_e,
				'plugins'  => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → JS Settings → JS Combine.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_js',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Combine JavaScript files (only offered on older versions).', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → JavaScript → Combine JavaScript Files.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'defer_js'      => array(
				'feature' => 'defer_js',
				'title'   => __( 'Defer JavaScript', 'seo-console' ),
				'impact'  => 'high',
				'on'      => __( 'Scripts load without blocking the first paint.', 'seo-console' ) . ' ' . $caution,
				'off'     => __( 'Scripts block the page from showing until they download. Deferring them is one of the biggest wins for First Contentful Paint and LCP.', 'seo-console' ) . ' ' . $caution,
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → JS Settings → Load JS Deferred = Deferred.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_js',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Load JavaScript deferred = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → JavaScript → Defer Render-blocking JavaScript = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'delay_js'      => array(
				'feature' => 'delay_js',
				'title'   => __( 'Delay JavaScript until interaction', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'Non-essential scripts (analytics, chat, ads) wait until the visitor interacts. Big win for Total Blocking Time and INP.', 'seo-console' ) . ' ' . $caution,
				'off'     => __( 'Analytics, chat and ad scripts run during page load and block the main thread. Delaying them until the visitor interacts usually improves TBT and INP a lot.', 'seo-console' ) . ' ' . $caution,
				'plugins' => array(
					'litespeed' => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → JS Settings → Load JS Deferred = Delayed.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_js',
					),
					'wp-rocket' => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Delay JavaScript execution = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
				),
			),
			'lazyload'      => array(
				'feature'  => 'lazyload',
				'title'    => __( 'Lazy-load images (main image excluded)', 'seo-console' ),
				'impact'   => 'high',
				'evaluate' => static function ( $state, $f ) {
					if ( null === $state ) {
						return array( 'unknown', __( 'Could not read the lazy-load setting.', 'seo-console' ) );
					}
					if ( ! $state ) {
						return array( 'ok', __( 'The cache plugin does not lazy-load images. WordPress itself already lazy-loads images below the fold, so this is fine.', 'seo-console' ) );
					}
					if ( true === $f['lazyload_lcp_exclusion'] ) {
						return array( 'ok', __( 'Images below the fold load lazily and the above-the-fold images are excluded.', 'seo-console' ) );
					}
					return array( 'warning', __( 'Images are lazy-loaded, but nothing keeps the main above-the-fold image (the LCP image) from being lazy-loaded too, which delays it. Exclude it in the plugin, or turn on SEO Console\'s "Speed up the main image" tweak, which marks it so cache plugins skip it.', 'seo-console' ) );
				},
				'plugins'  => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → Media Settings → Lazy Load Images = ON, and Page Optimization → VPI → Viewport Images = ON (or add the hero image to Lazy Load Image Excludes).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_media',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → Media → LazyLoad → Enable for images; WP Rocket 3.16+ excludes above-the-fold images automatically, older versions need the hero image in "Excluded images or iframes".', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#media',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Media → Lazy Load Media; add the hero image\'s CSS class to the exclusions.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_media',
					),
				),
			),
			'image_formats' => array(
				'feature' => 'image_formats',
				'title'   => __( 'WebP / AVIF images', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'Browsers get modern WebP/AVIF images, usually 25–50% smaller than JPEG/PNG.', 'seo-console' ),
				'off'     => __( 'Images are served as JPEG/PNG. Serving WebP or AVIF makes them 25–50% smaller.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Image Optimization → Image Optimization Settings → Next-Gen Image Format (WebP/AVIF) and Image WebP Replacement = ON; then send images for optimization.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-img_optm#settings',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'WP Rocket does not convert images. Use Imagify (or another converter), then Settings → WP Rocket → Media → WebP compatibility.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#media',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Media → Use WebP Images = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_media',
					),
				),
			),
			'critical_css'  => array(
				'feature' => 'critical_css',
				'title'   => __( 'Critical CSS / load CSS asynchronously', 'seo-console' ),
				'impact'  => 'high',
				'on'      => __( 'The CSS needed for the first screen is inlined and the rest loads without blocking.', 'seo-console' ) . ' ' . $caution,
				'off'     => __( 'All CSS must download before anything shows. Generating critical CSS lets the first screen paint much sooner.', 'seo-console' ) . ' ' . $caution,
				'plugins' => array(
					'litespeed' => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → CSS Settings → Load CSS Asynchronously = ON (generates critical CSS through QUIC.cloud).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_css',
					),
					'wp-rocket' => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Optimize CSS delivery → Load CSS asynchronously.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
				),
			),
			'unused_css'    => array(
				'feature' => 'unused_css',
				'title'   => __( 'Remove unused CSS', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'Each page only loads the CSS it uses.', 'seo-console' ) . ' ' . $caution,
				'off'     => __( 'Pages load CSS for features they do not use (page builders often ship hundreds of KB). Removing unused CSS cuts it down.', 'seo-console' ) . ' ' . $caution,
				'plugins' => array(
					'litespeed' => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → CSS Settings → Generate UCSS = ON (needs QUIC.cloud).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_css',
					),
					'wp-rocket' => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Optimize CSS delivery → Remove Unused CSS.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
				),
			),
			'font_display'  => array(
				'feature' => 'font_display',
				'title'   => __( 'Show text while web fonts load', 'seo-console' ),
				'impact'  => 'low',
				'on'      => __( 'Text appears immediately in a fallback font while web fonts download.', 'seo-console' ),
				'off'     => __( 'Text may stay invisible until web fonts download. Turning on font-display: swap fixes it.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Page Optimization → CSS Settings → Font Display Optimization = Swap.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-page_optm#settings_css',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → File Optimization → Optimize Google Fonts (other fonts need font-display: swap in the theme CSS).', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#file_optimization',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Frontend → Web Fonts Optimization = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_frontend',
					),
				),
			),
			'preload'       => array(
				'feature' => 'preload',
				'title'   => __( 'Cache preloading', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'The plugin refills the cache after it is cleared, so visitors (and Google) rarely hit an uncached page.', 'seo-console' ),
				'off'     => __( 'After the cache is cleared, the first visitor of each page gets a slow, uncached page. Turn on preloading so the cache refills itself.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Crawler → General Settings → Crawler = ON (some hosts disable the crawler; ask yours).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-crawler',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'Settings → WP Rocket → Preload → Activate Preloading = ON.', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#preload',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Caching → Preheat Cache = ON.', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_caching',
					),
				),
			),
			'object_cache'  => array(
				'feature' => 'object_cache',
				'title'   => __( 'Object cache (Redis / Memcached)', 'seo-console' ),
				'impact'  => 'medium',
				'on'      => __( 'Database query results are kept in memory, which speeds up uncached pages, the admin and WooCommerce.', 'seo-console' ),
				'off'     => __( 'There is no persistent object cache, so every uncached page re-runs the same database queries. If your host offers Redis or Memcached, turn it on.', 'seo-console' ),
				'plugins' => array(
					'litespeed'    => array(
						'how_to' => __( 'LiteSpeed Cache → Cache → Object tab → Object Cache = ON (Redis or Memcached must be available on the server; ask your host).', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-cache#object',
					),
					'wp-rocket'    => array(
						'how_to' => __( 'WP Rocket has no object cache. Ask your host for Redis and install their recommended object-cache plugin (e.g. Redis Object Cache).', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#dashboard',
					),
					'sg-optimizer' => array(
						'how_to' => __( 'Speed Optimizer → Caching → Memcached = ON (SiteGround hosting).', 'seo-console' ),
						'url'    => 'admin.php?page=sgo_caching',
					),
				),
			),
			'cdn'           => array(
				'feature' => 'cdn',
				'title'   => __( 'CDN', 'seo-console' ),
				'impact'  => 'low',
				'on'      => __( 'Static files are served from a CDN close to your visitors.', 'seo-console' ),
				'off'     => __( 'No CDN is configured in the plugin. For visitors far from your server a CDN (for example Cloudflare or QUIC.cloud) cuts loading times. Skip this if your host already puts a CDN in front of the site.', 'seo-console' ),
				'plugins' => array(
					'litespeed' => array(
						'how_to' => __( 'LiteSpeed Cache → CDN → QUIC.cloud CDN or CDN Mapping.', 'seo-console' ),
						'url'    => 'admin.php?page=litespeed-cdn',
					),
					'wp-rocket' => array(
						'how_to' => __( 'Settings → WP Rocket → CDN → Enable Content Delivery Network (or use RocketCDN / Cloudflare).', 'seo-console' ),
						'url'    => 'options-general.php?page=wprocket#page_cdn',
					),
				),
			),
		);
	}

	/**
	 * LiteSpeed Cache 3.0+ stores each setting in its own option `litespeed.conf.<key>`.
	 *
	 * @return array
	 */
	private function litespeed_features(): array {
		$get = static function ( $key ) {
			return get_option( 'litespeed.conf.' . $key, null );
		};
		// Nothing stored at all (pre-3.0 or never saved): everything unknown.
		if ( null === $get( 'cache' ) && null === $get( 'optm-css_min' ) ) {
			return array();
		}
		$on    = static function ( $key ) use ( $get ) {
			$value = $get( $key );
			return null === $value ? null : self::truthy( $value );
		};
		$defer = $get( 'optm-js_defer' );
		$vpi   = $on( 'media-vpi' );
		$exc   = self::non_empty_list( $get( 'media-lazy_exc' ) );
		$cdn   = $on( 'cdn' );
		if ( ! $cdn && $on( 'cdn-quic' ) ) {
			$cdn = true;
		}
		$fonts = $on( 'optm-ggfonts_async' );
		if ( ! $fonts && $on( 'optm-ggfonts_rm' ) ) {
			$fonts = true;
		}

		return array(
			'page_cache'             => $on( 'cache' ),
			'browser_cache'          => $on( 'cache-browser' ),
			'mobile_cache'           => $on( 'cache-mobile' ),
			'object_cache'           => $on( 'object' ),
			'cdn'                    => $cdn,
			'preload'                => $on( 'crawler' ),
			'minify_css'             => $on( 'optm-css_min' ),
			'minify_js'              => $on( 'optm-js_min' ),
			'minify_html'            => $on( 'optm-html_min' ),
			'combine_css'            => $on( 'optm-css_comb' ),
			'combine_js'             => $on( 'optm-js_comb' ),
			'defer_js'               => null === $defer ? null : (int) $defer >= 1,
			'delay_js'               => null === $defer ? null : 2 === (int) $defer,
			'critical_css'           => $on( 'optm-css_async' ),
			'unused_css'             => $on( 'optm-ucss' ),
			'font_display'           => $on( 'optm-css_font_display' ),
			'google_fonts'           => $fonts,
			'lazyload'               => $on( 'media-lazy' ),
			'lazyload_lcp_exclusion' => true === $vpi || $exc,
			'image_formats'          => $on( 'img_optm-webp' ),
			'image_dimensions'       => $on( 'media-add_missing_sizes' ),
			'disable_emojis'         => $on( 'optm-emoji_rm' ),
			'heartbeat'              => $on( 'misc-heartbeat_front' ),
			'dns_prefetch'           => self::non_empty_list( $get( 'optm-dns_prefetch' ) ),
			'preconnect'             => self::non_empty_list( $get( 'optm-dns_preconnect' ) ),
			'remove_query_strings'   => $on( 'optm-qs_rm' ),
		);
	}

	/**
	 * WP Rocket stores everything in the `wp_rocket_settings` array.
	 *
	 * @return array
	 */
	private function rocket_features(): array {
		$s = get_option( 'wp_rocket_settings', null );
		if ( ! is_array( $s ) ) {
			return array( 'page_cache' => $this->env['wp_cache'] ? true : null );
		}
		$on       = static function ( $key ) use ( $s ) {
			return array_key_exists( $key, $s ) ? self::truthy( $s[ $key ] ) : null;
		};
		$version  = (string) $this->env['version'];
		$auto_atf = '' !== $version && version_compare( $version, '3.16', '>=' );

		return array(
			'page_cache'             => (bool) $this->env['wp_cache'],
			'browser_cache'          => $this->env['nginx'] ? null : true,
			'mobile_cache'           => $on( 'cache_mobile' ),
			'cdn'                    => $on( 'cdn' ),
			'preload'                => $on( 'manual_preload' ),
			'minify_css'             => $on( 'minify_css' ),
			'minify_js'              => $on( 'minify_js' ),
			'combine_css'            => $on( 'minify_concatenate_css' ),
			'combine_js'             => $on( 'minify_concatenate_js' ),
			'defer_js'               => $on( 'defer_all_js' ),
			'delay_js'               => $on( 'delay_js' ),
			'critical_css'           => $on( 'async_css' ),
			'unused_css'             => $on( 'remove_unused_css' ),
			'font_display'           => $on( 'minify_google_fonts' ),
			'google_fonts'           => $on( 'minify_google_fonts' ),
			'lazyload'               => $on( 'lazyload' ),
			'lazyload_lcp_exclusion' => $auto_atf || self::non_empty_list( isset( $s['exclude_lazyload'] ) ? $s['exclude_lazyload'] : null ),
			'image_formats'          => $on( 'cache_webp' ),
			'image_dimensions'       => $on( 'image_dimensions' ),
			'disable_emojis'         => $on( 'emoji' ),
			'disable_embeds'         => $on( 'embeds' ),
			'heartbeat'              => $on( 'control_heartbeat' ),
			'dns_prefetch'           => self::non_empty_list( isset( $s['dns_prefetch'] ) ? $s['dns_prefetch'] : null ),
			'remove_query_strings'   => $on( 'remove_query_strings' ),
			'database_cleanup'       => $on( 'schedule_automatic_cleanup' ),
		);
	}

	/**
	 * SiteGround Speed Optimizer stores one option per setting: `siteground_optimizer_<key>`.
	 *
	 * @return array
	 */
	private function sg_features(): array {
		$on       = static function ( $key ) {
			$value = get_option( 'siteground_optimizer_' . $key, null );
			return null === $value ? null : self::truthy( $value );
		};
		$any      = static function ( array $keys ) use ( $on ) {
			$result = null;
			foreach ( $keys as $key ) {
				$value = $on( $key );
				if ( true === $value ) {
					return true;
				}
				if ( false === $value ) {
					$result = false;
				}
			}
			return $result;
		};
		$prefetch = get_option( 'siteground_optimizer_dns_prefetch_urls', null );

		return array(
			'page_cache'           => $any( array( 'enable_cache', 'file_caching' ) ),
			'browser_cache'        => $on( 'enable_browser_caching' ),
			'gzip'                 => $on( 'enable_gzip_compression' ),
			'object_cache'         => $on( 'enable_memcached' ),
			'preload'              => $on( 'preheat_cache' ),
			'minify_css'           => $on( 'optimize_css' ),
			'minify_js'            => $on( 'optimize_javascript' ),
			'minify_html'          => $on( 'optimize_html' ),
			'combine_css'          => $on( 'combine_css' ),
			'combine_js'           => $on( 'combine_javascript' ),
			'defer_js'             => $on( 'optimize_javascript_async' ),
			'font_display'         => $any( array( 'optimize_web_fonts', 'combine_google_fonts' ) ),
			'google_fonts'         => $any( array( 'optimize_web_fonts', 'combine_google_fonts' ) ),
			'lazyload'             => $on( 'lazyload_images' ),
			'image_formats'        => $on( 'webp_support' ),
			'disable_emojis'       => $on( 'disable_emojis' ),
			'heartbeat'            => $on( 'heartbeat_control' ),
			'dns_prefetch'         => true === $on( 'dns_prefetch' ) && self::non_empty_list( $prefetch ) ? true : $on( 'dns_prefetch' ),
			'remove_query_strings' => $on( 'remove_query_strings' ),
			'database_cleanup'     => self::non_empty_list( get_option( 'siteground_optimizer_database_optimization', null ) ),
		);
	}

	/**
	 * Environment detection.
	 *
	 * @return array
	 */
	private function detect_env(): array {
		global $is_nginx;
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$ls       = null;
		if ( isset( $_SERVER['X-LSCACHE'] ) || ( '' !== $software && false !== stripos( $software, 'litespeed' ) ) ) {
			$ls = true;
		} elseif ( '' !== $software ) {
			$ls = false;
		}
		$version = '';
		if ( 'litespeed' === $this->plugin && defined( 'LSCWP_V' ) ) {
			$version = (string) constant( 'LSCWP_V' );
		} elseif ( 'wp-rocket' === $this->plugin && defined( 'WP_ROCKET_VERSION' ) ) {
			$version = (string) constant( 'WP_ROCKET_VERSION' );
		} elseif ( 'sg-optimizer' === $this->plugin && defined( '\SiteGround_Optimizer\VERSION' ) ) {
			$version = (string) constant( '\SiteGround_Optimizer\VERSION' );
		}
		return array(
			'wp_cache'         => defined( 'WP_CACHE' ) && WP_CACHE,
			'ext_object_cache' => (bool) wp_using_ext_object_cache(),
			'version'          => $version,
			'litespeed_server' => $ls,
			'nginx'            => ! empty( $is_nginx ),
		);
	}

	/**
	 * Truthiness of a stored setting ('1', 1, true, 'on', 'yes').
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'yes', 'true' ), true ) || ( is_numeric( $value ) && (int) $value > 0 );
		}
		return (bool) $value;
	}

	/**
	 * Whether a stored list (array, JSON array or newline string) has entries.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function non_empty_list( $value ): bool {
		if ( is_array( $value ) ) {
			return count( array_filter( $value ) ) > 0;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return count( array_filter( $decoded ) ) > 0;
			}
			return '' !== trim( $value );
		}
		return false;
	}
}
