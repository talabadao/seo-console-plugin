<?php
/**
 * URL resolution, normalisation and crawl scope for the site being audited.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the site's home URL and decides which URLs are internal and crawlable.
 */
class Url {

	/** File extensions that are never HTML pages. */
	const SKIP_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic', 'pdf', 'zip', 'gz', 'tgz', 'rar', '7z', 'tar', 'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi', 'wmv', 'webm', 'ogg', 'ogv', 'wav', 'flac', 'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'exe', 'dmg', 'apk', 'msi', 'rss', 'atom', 'ics', 'epub' );

	/** Query keys whose URLs are never crawled (actions, faceted navigation, previews, search). */
	const SKIP_QUERY_KEYS = array( 'replytocom', 'add-to-cart', 'add_to_wishlist', 'remove_item', 'removed_item', 'undo_item', 's', 'share', 'preview', 'preview_id', 'preview_nonce', 'orderby', 'min_price', 'max_price', 'rating_filter', 'wc-ajax', 'action', 'ical', 'download', 'doing_wp_cron', 'feed', 'rest_route', 'customize_changeset_uuid', '_wpnonce', 'redirect_to' );

	/** Tracking parameters removed during normalisation. */
	const STRIP_QUERY_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'fbclid', 'gclid', 'msclkid', 'dclid', 'yclid', '_ga', '_gl', 'mc_cid', 'mc_eid' );

	/** Path segments that are never crawled. */
	const SKIP_SEGMENTS = array( 'wp-admin', 'wp-json', 'wp-login.php', 'wp-content', 'wp-includes', 'xmlrpc.php', 'wp-cron.php', 'wp-signup.php', 'wp-activate.php', 'wp-trackback.php', 'wp-comments-post.php', 'feed', 'trackback', 'cgi-bin' );

	/** First path segments (after the home path) that are never crawled. */
	const SKIP_FIRST_SEGMENTS = array( 'search', 'cart', 'checkout', 'my-account', 'gio-hang', 'thanh-toan', 'tai-khoan' );

	/** @var string Normalised home URL. */
	private $home;

	/** @var string Home scheme. */
	private $scheme;

	/** @var string Home host (lower case). */
	private $host;

	/** @var string Home host without www. */
	private $bare_host;

	/** @var int|null Home port. */
	private $port;

	/** @var string Home path with trailing slash, e.g. '/' or '/blog/'. */
	private $base_path;

	/** @var bool|null True: add trailing slash to internal paths, false: remove, null: leave. */
	private $trailing_slash;

	/**
	 * Constructor.
	 *
	 * @param string    $home_url       Home URL (default home_url( '/' )).
	 * @param bool|null $trailing_slash Trailing slash policy; default follows the permalink structure.
	 */
	public function __construct( string $home_url = '', ?bool $trailing_slash = null ) {
		$home_url = '' === $home_url ? home_url( '/' ) : $home_url;
		$parts    = wp_parse_url( $home_url );

		$this->scheme    = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$this->host      = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : 'localhost';
		$this->bare_host = self::strip_www( $this->host );
		$this->port      = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path            = isset( $parts['path'] ) ? $parts['path'] : '/';
		$this->base_path = rtrim( $path, '/' ) . '/';

		if ( null === $trailing_slash ) {
			$structure      = (string) get_option( 'permalink_structure' );
			$trailing_slash = '' === $structure ? null : ( '/' === substr( $structure, -1 ) );
		}
		$this->trailing_slash = $trailing_slash;

		$this->home = $this->scheme . '://' . $this->host . $this->port_suffix( $this->scheme, $this->port ) . $this->base_path;
	}

	/** Normalised home URL with trailing slash. */
	public function home(): string {
		return $this->home;
	}

	/** Home host (lower case). */
	public function host(): string {
		return $this->host;
	}

	/** Home scheme. */
	public function scheme(): string {
		return $this->scheme;
	}

	/**
	 * Absolute URL for a path relative to the site root (e.g. '/robots.txt').
	 *
	 * @param string $path Path starting with '/'.
	 */
	public function root_url( string $path ): string {
		return $this->scheme . '://' . $this->host . $this->port_suffix( $this->scheme, $this->port ) . '/' . ltrim( $path, '/' );
	}

	/** Stable hash used to index URLs. */
	public static function hash( string $url ): string {
		return md5( $url );
	}

	/**
	 * Resolves a reference against a base URL (RFC 3986 §5.2). Returns '' for non-HTTP references.
	 *
	 * @param string $ref  Reference (href/src).
	 * @param string $base Absolute base URL.
	 */
	public static function resolve( string $ref, string $base ): string {
		$ref = trim( str_replace( array( "\n", "\r", "\t" ), '', $ref ) );
		if ( '' === $ref ) {
			return '';
		}
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $ref, $m ) ) {
			$scheme = strtolower( $m[1] );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return '';
			}
			return $ref;
		}
		$b = wp_parse_url( $base );
		if ( ! $b || empty( $b['host'] ) ) {
			return '';
		}
		$scheme    = isset( $b['scheme'] ) ? $b['scheme'] : 'http';
		$authority = $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $ref, '//' ) ) {
			return $scheme . ':' . $ref;
		}

		$fragment = '';
		$hash_pos = strpos( $ref, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $ref, $hash_pos );
			$ref      = substr( $ref, 0, $hash_pos );
		}
		$query     = null;
		$query_pos = strpos( $ref, '?' );
		if ( false !== $query_pos ) {
			$query = substr( $ref, $query_pos + 1 );
			$ref   = substr( $ref, 0, $query_pos );
		}
		$base_path = isset( $b['path'] ) ? $b['path'] : '/';

		if ( '' === $ref ) {
			$path = $base_path;
			if ( null === $query ) {
				$query = isset( $b['query'] ) ? $b['query'] : null;
			}
		} elseif ( '/' === $ref[0] ) {
			$path = self::remove_dot_segments( $ref );
		} else {
			$dir  = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
			$path = self::remove_dot_segments( ( '' === $dir ? '/' : $dir ) . $ref );
		}
		return $scheme . '://' . $authority . $path . ( null === $query ? '' : '?' . $query ) . $fragment;
	}

	/**
	 * RFC 3986 remove_dot_segments.
	 *
	 * @param string $path Path.
	 */
	public static function remove_dot_segments( string $path ): string {
		if ( false === strpos( $path, '.' ) ) {
			return $path;
		}
		$output   = array();
		$segments = explode( '/', $path );
		$last     = count( $segments ) - 1;
		foreach ( $segments as $i => $segment ) {
			if ( '.' === $segment ) {
				if ( $i === $last ) {
					$output[] = '';
				}
				continue;
			}
			if ( '..' === $segment ) {
				if ( count( $output ) > 1 ) {
					array_pop( $output );
				}
				if ( $i === $last ) {
					$output[] = '';
				}
				continue;
			}
			$output[] = $segment;
		}
		$result = implode( '/', $output );
		return '' === $result || '/' !== $result[0] ? '/' . $result : $result;
	}

	/**
	 * Normalises an absolute URL: lower-case scheme/host, default port removed, fragment removed, tracking
	 * parameters removed, query sorted, trailing slash per permalink structure (internal URLs only).
	 *
	 * @param string $url             Absolute URL.
	 * @param bool   $trailing_policy Apply the trailing-slash policy (false: keep the path's slash as written).
	 * @return string '' when not an http(s) URL.
	 */
	public function normalize( string $url, bool $trailing_policy = true ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$path = self::remove_dot_segments( preg_replace( '#/{2,}#', '/', $path ) );
		$path = preg_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			$path
		);

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$pairs = array();
			foreach ( explode( '&', $parts['query'] ) as $index => $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$key = strtolower( urldecode( (string) strtok( $pair, '=' ) ) );
				if ( in_array( $key, self::STRIP_QUERY_KEYS, true ) || 0 === strpos( $key, 'utm_' ) ) {
					continue;
				}
				$pairs[] = array( $key, $index, $pair );
			}
			usort(
				$pairs,
				static function ( $a, $b ) {
					return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
				}
			);
			$query = implode( '&', array_column( $pairs, 2 ) );
		}

		$is_internal = $this->host_matches( $host ) && 0 === strpos( rtrim( $path, '/' ) . '/', $this->base_path );
		if ( $trailing_policy && $is_internal && null !== $this->trailing_slash && '/' !== $path ) {
			$last_segment = substr( $path, (int) strrpos( $path, '/' ) + 1 );
			$has_ext      = false !== strpos( $last_segment, '.' );
			if ( $this->trailing_slash && '/' !== substr( $path, -1 ) && ! $has_ext ) {
				$path .= '/';
			} elseif ( ! $this->trailing_slash && '/' === substr( $path, -1 ) ) {
				$path = rtrim( $path, '/' );
				$path = '' === $path ? '/' : $path;
			}
		}

		return $scheme . '://' . $host . $this->port_suffix( $scheme, $port ) . $path . ( '' === $query ? '' : '?' . $query );
	}

	/**
	 * Resolve + normalise in one call.
	 *
	 * @param string $ref  Reference.
	 * @param string $base Base URL.
	 */
	public function absolute( string $ref, string $base ): string {
		$resolved = self::resolve( $ref, $base );
		return '' === $resolved ? '' : $this->normalize( $resolved );
	}

	/**
	 * Same site: host equal to the home host ignoring a leading "www." and the scheme, and inside the home path.
	 *
	 * @param string $url Absolute URL.
	 */
	public function is_internal( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! $this->host_matches( strtolower( $parts['host'] ) ) ) {
			return false;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		return 0 === strpos( rtrim( $path, '/' ) . '/', $this->base_path );
	}

	/**
	 * True when the URL uses exactly the home scheme + host (not a www/http variant).
	 *
	 * @param string $url Absolute URL.
	 */
	public function is_canonical_host( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return false;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		return strtolower( $parts['scheme'] ?? '' ) === $this->scheme
			&& strtolower( $parts['host'] ) === $this->host
			&& $this->port_suffix( $this->scheme, $port ) === $this->port_suffix( $this->scheme, $this->port );
	}

	/**
	 * Why an internal URL should not be crawled.
	 *
	 * @param string $url Normalised absolute URL.
	 * @return string '' when crawlable, otherwise 'external'|'extension'|'excluded_path'|'excluded_query'.
	 */
	public function skip_reason( string $url ): string {
		if ( ! $this->is_internal( $url ) ) {
			return 'external';
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? strtolower( rawurldecode( $parts['path'] ) ) : '/';

		$last = substr( rtrim( $path, '/' ), (int) strrpos( rtrim( $path, '/' ), '/' ) + 1 );
		$dot  = strrpos( $last, '.' );
		if ( false !== $dot ) {
			$ext = substr( $last, $dot + 1 );
			if ( in_array( $ext, self::SKIP_EXTENSIONS, true ) ) {
				return 'extension';
			}
		}

		$relative = substr( $path, strlen( $this->base_path ) );
		$segments = array_values( array_filter( explode( '/', (string) $relative ), 'strlen' ) );
		foreach ( $segments as $segment ) {
			if ( in_array( $segment, self::SKIP_SEGMENTS, true ) ) {
				return 'excluded_path';
			}
		}
		if ( $segments && in_array( $segments[0], self::SKIP_FIRST_SEGMENTS, true ) ) {
			return 'excluded_path';
		}
		$last_segment = $segments ? end( $segments ) : '';
		if ( 'embed' === $last_segment || preg_match( '/^comment-page-\d+$/', $last_segment ) ) {
			return 'excluded_path';
		}

		if ( ! empty( $parts['query'] ) ) {
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				$key = strtolower( urldecode( (string) strtok( $pair, '=' ) ) );
				if ( in_array( $key, self::SKIP_QUERY_KEYS, true ) || 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
					return 'excluded_query';
				}
			}
		}
		return '';
	}

	/**
	 * Path + query of a URL (what robots.txt rules match against).
	 *
	 * @param string $url Absolute URL or path.
	 */
	public static function path_and_query( string $url ): string {
		if ( '' === $url ) {
			return '/';
		}
		if ( '/' === $url[0] || '*' === $url[0] ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Host comparison ignoring "www.".
	 *
	 * @param string $host Lower-case host.
	 */
	private function host_matches( string $host ): bool {
		return self::strip_www( rtrim( $host, '.' ) ) === $this->bare_host;
	}

	/**
	 * Removes a leading "www.".
	 *
	 * @param string $host Host.
	 */
	private static function strip_www( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * ":port" unless default for the scheme.
	 *
	 * @param string   $scheme Scheme.
	 * @param int|null $port   Port.
	 */
	private function port_suffix( string $scheme, ?int $port ): string {
		if ( null === $port || ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			return '';
		}
		return ':' . $port;
	}
}
