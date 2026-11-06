<?php
/**
 * Rank Math / Yoast SEO adapter (see docs/ARCHITECTURE.md §9).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Integrations;

use SEOConsole\Core\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes SEO title, meta description and focus keyword through the active SEO plugin's post meta.
 * The meta keys are shared with the SEO Console web app and must not change.
 */
final class SeoPlugins {

	const FIELDS = array( 'title', 'description', 'focus_keyword' );

	const KEYS = array(
		'yoast'    => array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		),
		'rankmath' => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		),
	);

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The SEO plugin used for reads and writes: 'rankmath', 'yoast' or 'none'.
	 * The general setting `seo_plugin` can force one; 'auto' detects.
	 */
	public function active(): string {
		$forced = (string) $this->settings->get( Settings::GENERAL, 'seo_plugin' );
		if ( in_array( $forced, array( 'rankmath', 'yoast', 'none' ), true ) ) {
			return $forced;
		}
		$detected = $this->detected();
		return empty( $detected ) ? 'none' : $detected[0];
	}

	/**
	 * Installed and active SEO plugins, in order of preference.
	 *
	 * @return string[] Subset of [ 'rankmath', 'yoast' ].
	 */
	public function detected(): array {
		$found = array();
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'yoast';
		}
		return $found;
	}

	/**
	 * Human name of a plugin id.
	 *
	 * @param string $id Plugin id.
	 */
	public static function name( string $id ): string {
		$names = array(
			'rankmath' => 'Rank Math SEO',
			'yoast'    => 'Yoast SEO',
			'none'     => __( 'None', 'seo-console' ),
		);
		return isset( $names[ $id ] ) ? $names[ $id ] : $id;
	}

	/**
	 * Version of the detected plugin, if it exposes one.
	 *
	 * @param string $id Plugin id.
	 */
	public static function version( string $id ): string {
		if ( 'rankmath' === $id && defined( 'RANK_MATH_VERSION' ) ) {
			return (string) RANK_MATH_VERSION;
		}
		if ( 'yoast' === $id && defined( 'WPSEO_VERSION' ) ) {
			return (string) WPSEO_VERSION;
		}
		return '';
	}

	/**
	 * Every meta key used by either plugin (for REST registration).
	 *
	 * @return string[]
	 */
	public static function all_meta_keys(): array {
		return array_merge( array_values( self::KEYS['yoast'] ), array_values( self::KEYS['rankmath'] ) );
	}

	/**
	 * Meta key of a field for a plugin.
	 *
	 * @param string      $field  title|description|focus_keyword.
	 * @param string|null $plugin Plugin id; null for the active one.
	 */
	public function meta_key( string $field, ?string $plugin = null ): ?string {
		$plugin = null === $plugin ? $this->active() : $plugin;
		return isset( self::KEYS[ $plugin ][ $field ] ) ? self::KEYS[ $plugin ][ $field ] : null;
	}

	/**
	 * Stored (raw) value. Rank Math's focus keyword returns the first keyword of the comma list.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   title|description|focus_keyword.
	 */
	public function get( int $post_id, string $field ): string {
		$key = $this->meta_key( $field );
		if ( null === $key ) {
			return '';
		}
		$value = (string) get_post_meta( $post_id, $key, true );
		if ( 'focus_keyword' === $field && 'rankmath' === $this->active() ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
			return (string) $parts[0];
		}
		return $value;
	}

	/**
	 * Writes a value. An empty string deletes the meta. Rank Math's focus keyword replaces only the first keyword.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   title|description|focus_keyword.
	 * @param string $value   Plain text.
	 * @return true|WP_Error
	 */
	public function set( int $post_id, string $field, string $value ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Unknown SEO field.', 'seo-console' ), array( 'status' => 400 ) );
		}
		$key = $this->meta_key( $field );
		if ( null === $key ) {
			return new WP_Error(
				'seoc_no_seo_plugin',
				__( 'No supported SEO plugin (Rank Math or Yoast SEO) is active.', 'seo-console' ),
				array( 'status' => 409 )
			);
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		$value = sanitize_text_field( $value );

		if ( 'focus_keyword' === $field && 'rankmath' === $this->active() ) {
			$existing = (string) get_post_meta( $post_id, $key, true );
			$parts    = '' === trim( $existing ) ? array() : array_map( 'trim', explode( ',', $existing ) );
			if ( '' === $value ) {
				array_shift( $parts );
			} elseif ( empty( $parts ) ) {
				$parts = array( $value );
			} else {
				$parts[0] = $value;
			}
			$value = implode( ',', array_filter( $parts, 'strlen' ) );
		}

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return true;
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		return true;
	}

	/**
	 * Title the SEO plugin would output (template variables replaced when the plugin offers a function for it;
	 * otherwise the raw value). Falls back to the post title.
	 *
	 * @param int $post_id Post id.
	 */
	public function resolved_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$plugin = $this->active();
		$raw    = $this->get( $post_id, 'title' );

		if ( 'yoast' === $plugin ) {
			if ( '' === $raw ) {
				$titles = get_option( 'wpseo_titles', array() );
				$raw    = is_array( $titles ) && ! empty( $titles[ 'title-' . $post->post_type ] ) ? (string) $titles[ 'title-' . $post->post_type ] : '';
			}
			if ( '' !== $raw && function_exists( 'wpseo_replace_vars' ) ) {
				$raw = (string) wpseo_replace_vars( $raw, $post );
			}
		} elseif ( 'rankmath' === $plugin ) {
			if ( '' === $raw ) {
				$titles = get_option( 'rank-math-options-titles', array() );
				$raw    = is_array( $titles ) && ! empty( $titles[ 'pt_' . $post->post_type . '_title' ] ) ? (string) $titles[ 'pt_' . $post->post_type . '_title' ] : '';
			}
			if ( '' !== $raw && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
				$raw = (string) \RankMath\Helper::replace_vars( $raw, $post );
			}
		}

		$raw = trim( wp_strip_all_tags( $raw ) );
		return '' !== $raw ? $raw : wp_strip_all_tags( get_the_title( $post ) );
	}
}
