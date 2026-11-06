<?php
/**
 * robots.txt parser and matcher following Google's rules (RFC 9309 + Google's documentation).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Parsed robots.txt.
 */
class RobotsTxt {

	/** Directives Google or other major engines understand. */
	const KNOWN = array( 'user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host', 'clean-param' );

	/** Common misspellings Google still accepts. */
	const ALIASES = array(
		'useragent'  => 'user-agent',
		'user agent' => 'user-agent',
		'dissallow'  => 'disallow',
		'dissalow'   => 'disallow',
		'disalow'    => 'disallow',
		'diasllow'   => 'disallow',
		'disallaw'   => 'disallow',
		'site-map'   => 'sitemap',
	);

	/** @var array[] Groups: [ 'agents' => string[], 'rules' => [ [ type, pattern, line ] ], 'crawl_delay' => float|null ]. */
	private $groups = array();

	/** @var string[] */
	private $sitemaps = array();

	/** @var array[] [ 'line' => int, 'code' => string, 'text' => string ]. */
	private $errors = array();

	/** @var string */
	private $content = '';

	/**
	 * Parses robots.txt content.
	 *
	 * @param string $content File content.
	 */
	public static function parse( string $content ): self {
		$robots          = new self();
		$robots->content = $content;
		// Strip a UTF-8 BOM.
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		$lines       = preg_split( '/\r\n|\r|\n/', $content );
		$current     = null;
		$last_was_ua = false;
		$line_number = 0;
		foreach ( $lines as $raw ) {
			++$line_number;
			$line = $raw;
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				$robots->errors[] = array(
					'line' => $line_number,
					'code' => 'no_colon',
					'text' => AuditStore::truncate( trim( $raw ), 200 ),
				);
				continue;
			}
			$directive = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value     = trim( substr( $line, $colon + 1 ) );
			if ( isset( self::ALIASES[ $directive ] ) ) {
				$directive = self::ALIASES[ $directive ];
			}

			if ( 'user-agent' === $directive ) {
				if ( ! $last_was_ua || null === $current ) {
					$robots->groups[] = array(
						'agents'      => array(),
						'rules'       => array(),
						'crawl_delay' => null,
						'line'        => $line_number,
					);
					$current          = count( $robots->groups ) - 1;
				}
				$robots->groups[ $current ]['agents'][] = strtolower( $value );
				$last_was_ua                            = true;
				continue;
			}
			$last_was_ua = false;

			if ( 'sitemap' === $directive ) {
				if ( '' !== $value ) {
					$robots->sitemaps[] = $value;
				}
				continue;
			}
			if ( ! in_array( $directive, self::KNOWN, true ) ) {
				$robots->errors[] = array(
					'line' => $line_number,
					'code' => 'noindex' === $directive ? 'unsupported_noindex' : 'unknown_directive',
					'text' => AuditStore::truncate( trim( $raw ), 200 ),
				);
				continue;
			}
			if ( null === $current ) {
				$robots->errors[] = array(
					'line' => $line_number,
					'code' => 'rule_before_user_agent',
					'text' => AuditStore::truncate( trim( $raw ), 200 ),
				);
				continue;
			}
			if ( 'allow' === $directive || 'disallow' === $directive ) {
				if ( '' === $value ) {
					continue; // An empty rule matches nothing.
				}
				$robots->groups[ $current ]['rules'][] = array(
					'type'    => $directive,
					'pattern' => $value,
					'line'    => $line_number,
				);
			} elseif ( 'crawl-delay' === $directive ) {
				if ( is_numeric( $value ) ) {
					$robots->groups[ $current ]['crawl_delay'] = (float) $value;
				} else {
					$robots->errors[] = array(
						'line' => $line_number,
						'code' => 'invalid_crawl_delay',
						'text' => AuditStore::truncate( trim( $raw ), 200 ),
					);
				}
			}
		}
		return $robots;
	}

	/** Raw content. */
	public function content(): string {
		return $this->content;
	}

	/** @return array[] Groups. */
	public function groups(): array {
		return $this->groups;
	}

	/** @return string[] Sitemap: URLs. */
	public function sitemaps(): array {
		return array_values( array_unique( $this->sitemaps ) );
	}

	/** @return array[] Syntax problems. */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Product token of a user agent: "Googlebot/2.1 (+…)" → "googlebot".
	 *
	 * @param string $user_agent User agent.
	 */
	public static function product_token( string $user_agent ): string {
		$token = strtolower( trim( $user_agent ) );
		$token = preg_replace( '#[/\s(].*$#', '', $token );
		return null === $token ? '' : $token;
	}

	/**
	 * Rules that apply to a user agent: all groups naming its product token, else groups naming the token's
	 * prefix before "-" (googlebot-image → googlebot), else the "*" groups.
	 *
	 * @param string $user_agent User agent.
	 * @return array[] Rules.
	 */
	public function rules_for( string $user_agent ): array {
		$groups = $this->groups_for( $user_agent );
		$rules  = array();
		foreach ( $groups as $group ) {
			$rules = array_merge( $rules, $group['rules'] );
		}
		return $rules;
	}

	/**
	 * Crawl-delay for a user agent (seconds) or null.
	 *
	 * @param string $user_agent User agent.
	 */
	public function crawl_delay( string $user_agent ): ?float {
		foreach ( $this->groups_for( $user_agent ) as $group ) {
			if ( null !== $group['crawl_delay'] ) {
				return $group['crawl_delay'];
			}
		}
		return null;
	}

	/**
	 * Groups that apply to a user agent.
	 *
	 * @param string $user_agent User agent.
	 * @return array[]
	 */
	public function groups_for( string $user_agent ): array {
		$token      = self::product_token( $user_agent );
		$candidates = array( $token );
		if ( false !== strpos( $token, '-' ) ) {
			$candidates[] = substr( $token, 0, (int) strpos( $token, '-' ) );
		}
		$candidates[] = '*';
		foreach ( $candidates as $candidate ) {
			$matched = array();
			foreach ( $this->groups as $group ) {
				if ( in_array( $candidate, $group['agents'], true ) ) {
					$matched[] = $group;
				}
			}
			if ( $matched ) {
				return $matched;
			}
		}
		return array();
	}

	/**
	 * Google's decision: the most specific (longest) matching rule wins; on a tie Allow wins.
	 *
	 * @param string $url        Absolute URL or path (with query).
	 * @param string $user_agent User agent.
	 * @return array|null Winning rule or null when nothing matches.
	 */
	public function matching_rule( string $url, string $user_agent = 'Googlebot' ): ?array {
		$path = self::encode( Url::path_and_query( $url ) );
		$best = null;
		foreach ( $this->rules_for( $user_agent ) as $rule ) {
			if ( ! self::pattern_matches( $rule['pattern'], $path ) ) {
				continue;
			}
			$length = strlen( self::encode( $rule['pattern'] ) );
			if ( null === $best || $length > $best['length'] || ( $length === $best['length'] && 'allow' === $rule['type'] && 'disallow' === $best['type'] ) ) {
				$best           = $rule;
				$best['length'] = $length;
			}
		}
		return $best;
	}

	/**
	 * Whether a URL may be crawled by a user agent. /robots.txt itself is always allowed.
	 *
	 * @param string $url        Absolute URL or path.
	 * @param string $user_agent User agent.
	 */
	public function is_allowed( string $url, string $user_agent = 'Googlebot' ): bool {
		if ( '/robots.txt' === Url::path_and_query( $url ) ) {
			return true;
		}
		$rule = $this->matching_rule( $url, $user_agent );
		return null === $rule || 'allow' === $rule['type'];
	}

	/**
	 * Matches a rule pattern (with * and a trailing $) against an encoded path.
	 *
	 * @param string $pattern Rule value.
	 * @param string $path    Encoded path + query.
	 */
	public static function pattern_matches( string $pattern, string $path ): bool {
		$pattern = self::encode( $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		if ( '/' !== $pattern[0] && '*' !== $pattern[0] ) {
			$pattern = '/' . $pattern;
		}
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $piece ) {
			$regex .= ( $i > 0 ? '.*' : '' ) . preg_quote( $piece, '#' );
		}
		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#s', $path );
	}

	/**
	 * Percent-encodes non-ASCII bytes and normalises the case of existing escapes.
	 *
	 * @param string $value Path or pattern.
	 */
	private static function encode( string $value ): string {
		$value = preg_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			$value
		);
		return (string) preg_replace_callback(
			'/%[0-9a-f]{2}/i',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			(string) $value
		);
	}
}
