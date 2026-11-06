<?php
/**
 * SEO meta status per post (for the SEO Meta page, the dashboard card and the AI site context).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use SEOConsole\Core\Database\Schema;
use SEOConsole\Integrations\SeoPlugins;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class PostsReport {

	/** Recommended lengths, same as the admin UI (src/admin/shared/utils/text.js RANGES). */
	const TITLE_RANGE       = array( 30, 60 );
	const DESCRIPTION_RANGE = array( 120, 160 );

	/** Issue filters. */
	const ISSUES = array( 'missing_title', 'missing_description', 'too_long', 'missing_alt' );

	/** Posts scanned at most when filtering by issue or computing coverage (shared hosting). */
	const SCAN_LIMIT = 1000;

	/** Transient for the coverage summary. */
	const COVERAGE_TRANSIENT = 'seoc_meta_coverage';

	/**
	 * SEO plugin adapter.
	 *
	 * @var SeoPlugins
	 */
	private $seo;

	/**
	 * Constructor.
	 *
	 * @param SeoPlugins $seo SEO plugin adapter.
	 */
	public function __construct( SeoPlugins $seo ) {
		$this->seo = $seo;
	}

	/**
	 * Public post types that have an editor: name => label.
	 *
	 * @return array<string, string>
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' === $name || ( ! post_type_supports( $name, 'editor' ) && 'product' !== $name ) ) {
				continue;
			}
			$out[ $name ] = (string) $type->labels->singular_name;
		}
		return $out;
	}

	/**
	 * Paged list of published posts with their meta status.
	 *
	 * @param array $args post_type ('' = all), issue (one of ISSUES or ''), search, page, per_page (≤ 100).
	 * @return array{items: array, total: int, page: int, per_page: int, scan_limited: bool}
	 */
	public function query( array $args ): array {
		$types    = self::post_types();
		$type     = isset( $args['post_type'] ) && isset( $types[ $args['post_type'] ] ) ? array( $args['post_type'] ) : array_keys( $types );
		$issue    = isset( $args['issue'] ) && in_array( $args['issue'], self::ISSUES, true ) ? $args['issue'] : '';
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$search   = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$base = array(
			'post_type'              => $type ? $type : array( 'post' ),
			'post_status'            => 'publish',
			's'                      => $search,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
		);

		if ( '' === $issue ) {
			$query = new WP_Query(
				$base + array(
					'posts_per_page' => $per_page,
					'paged'          => $page,
				)
			);
			$posts = $query->posts;
			return array(
				'items'        => $this->rows( $posts ),
				'total'        => (int) $query->found_posts,
				'page'         => $page,
				'per_page'     => $per_page,
				'scan_limited' => false,
			);
		}

		// Issue filters need per-post checks: scan the most recently modified posts.
		$query    = new WP_Query(
			$base + array(
				'posts_per_page' => self::SCAN_LIMIT,
				'fields'         => 'ids',
			)
		);
		$matching = array();
		foreach ( array_chunk( array_map( 'intval', $query->posts ), 100 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $id ) {
				$post = get_post( $id );
				if ( $post && in_array( $issue, $this->issues( $post ), true ) ) {
					$matching[] = $id;
				}
			}
		}
		$slice = array_slice( $matching, ( $page - 1 ) * $per_page, $per_page );
		return array(
			'items'        => $this->rows( array_filter( array_map( 'get_post', $slice ) ) ),
			'total'        => count( $matching ),
			'page'         => $page,
			'per_page'     => $per_page,
			'scan_limited' => (int) $query->found_posts > self::SCAN_LIMIT,
		);
	}

	/**
	 * One row.
	 *
	 * @param WP_Post $post    Post.
	 * @param int     $pending Pending meta suggestions for the post.
	 * @return array
	 */
	public function row( WP_Post $post, int $pending = 0 ): array {
		$types    = self::post_types();
		$title    = $this->seo->get( $post->ID, 'title' );
		$resolved = $this->seo->resolved_title( $post->ID );
		$desc     = $this->seo->get( $post->ID, 'description' );
		$images   = self::image_alt_counts( $post );
		return array(
			'id'                  => (int) $post->ID,
			'title'               => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'post_type'           => (string) $post->post_type,
			'post_type_label'     => isset( $types[ $post->post_type ] ) ? $types[ $post->post_type ] : (string) $post->post_type,
			'modified_at'         => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post->post_modified_gmt . ' UTC' ) ),
			'edit_link'           => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view_link'           => (string) get_permalink( $post ),
			'focus_keyword'       => $this->seo->get( $post->ID, 'focus_keyword' ),
			'seo_title'           => array(
				'value'    => $title,
				'resolved' => $resolved,
				'length'   => mb_strlen( $resolved ),
				'status'   => '' === trim( $title ) ? 'missing' : self::length_status( mb_strlen( $resolved ), self::TITLE_RANGE ),
			),
			'description'         => array(
				'value'  => $desc,
				'length' => mb_strlen( $desc ),
				'status' => '' === trim( $desc ) ? 'missing' : self::length_status( mb_strlen( $desc ), self::DESCRIPTION_RANGE ),
			),
			'images'              => $images,
			'issues'              => $this->issues( $post, $title, $resolved, $desc, $images ),
			'pending_suggestions' => $pending,
		);
	}

	/**
	 * Issues of one post.
	 *
	 * @param WP_Post     $post     Post.
	 * @param string|null $title    Stored SEO title (computed when null).
	 * @param string|null $resolved Resolved title.
	 * @param string|null $desc     Stored description.
	 * @param array|null  $images   image_alt_counts().
	 * @return string[]
	 */
	public function issues( WP_Post $post, ?string $title = null, ?string $resolved = null, ?string $desc = null, ?array $images = null ): array {
		$title    = null === $title ? $this->seo->get( $post->ID, 'title' ) : $title;
		$desc     = null === $desc ? $this->seo->get( $post->ID, 'description' ) : $desc;
		$images   = null === $images ? self::image_alt_counts( $post ) : $images;
		$issues   = array();
		$too_long = mb_strlen( $desc ) > self::DESCRIPTION_RANGE[1];
		if ( '' === trim( $title ) ) {
			$issues[] = 'missing_title';
		} else {
			$resolved = null === $resolved ? $this->seo->resolved_title( $post->ID ) : $resolved;
			$too_long = $too_long || mb_strlen( $resolved ) > self::TITLE_RANGE[1];
		}
		if ( '' === trim( $desc ) ) {
			$issues[] = 'missing_description';
		}
		if ( $too_long ) {
			$issues[] = 'too_long';
		}
		if ( $images['missing_alt'] > 0 ) {
			$issues[] = 'missing_alt';
		}
		return $issues;
	}

	/**
	 * Images in the content plus the featured image, and how many have no usable alt text. Cheap: no queries
	 * except the featured image's alt.
	 *
	 * @param WP_Post $post Post.
	 * @return array{total: int, missing_alt: int}
	 */
	public static function image_alt_counts( WP_Post $post ): array {
		$total   = 0;
		$missing = 0;
		if ( preg_match_all( '#<img\b[^>]*>#i', (string) $post->post_content, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/\ssrc\s*=\s*["\']?data:/i', $tag ) ) {
					continue;
				}
				++$total;
				$src = preg_match( '/\ssrc\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $s ) ? $s[1] : '';
				if ( PostContext::is_weak_alt( PostImageAltApplier::alt_of( $tag ), wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) ) {
					++$missing;
				}
			}
		}
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb && false === strpos( (string) $post->post_content, 'wp-image-' . $thumb ) ) {
			++$total;
			if ( '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
				++$missing;
			}
		}
		return array(
			'total'       => $total,
			'missing_alt' => $missing,
		);
	}

	/**
	 * Site-wide counts (cached for an hour; flushed when posts or meta change).
	 *
	 * @param bool $refresh Recompute.
	 * @return array{posts: int, scanned: int, missing_title: int, missing_description: int, too_long: int, missing_alt: int, scan_limited: bool, seo_plugin: string}
	 */
	public function coverage( bool $refresh = false ): array {
		$cached = $refresh ? false : get_transient( self::COVERAGE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['seo_plugin'] ) && $cached['seo_plugin'] === $this->seo->active() ) {
			return $cached;
		}
		$query  = new WP_Query(
			array(
				'post_type'           => array_keys( self::post_types() ),
				'post_status'         => 'publish',
				'posts_per_page'      => self::SCAN_LIMIT,
				'fields'              => 'ids',
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
			)
		);
		$counts = array_fill_keys( self::ISSUES, 0 );
		foreach ( array_chunk( array_map( 'intval', $query->posts ), 100 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				foreach ( $this->issues( $post ) as $issue ) {
					++$counts[ $issue ];
				}
			}
		}
		$out = array_merge(
			array(
				'posts'   => (int) $query->found_posts,
				'scanned' => count( $query->posts ),
			),
			$counts,
			array(
				'scan_limited' => (int) $query->found_posts > self::SCAN_LIMIT,
				'seo_plugin'   => $this->seo->active(),
			)
		);
		set_transient( self::COVERAGE_TRANSIENT, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Drops the cached coverage.
	 */
	public static function flush(): void {
		delete_transient( self::COVERAGE_TRANSIENT );
	}

	/**
	 * Pending meta suggestions per post (one query).
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, int>
	 */
	public static function pending_counts( array $post_ids ): array {
		global $wpdb;
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $post_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above; core table from the whitelist.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, COUNT(*) AS n FROM %i WHERE module = 'meta' AND status = 'pending' AND object_type = 'post' AND object_id IN ($placeholders) GROUP BY object_id",
				array_merge( array( Schema::table( 'suggestions' ) ), $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * "missing" | "short" | "good" | "long".
	 *
	 * @param int   $length Length.
	 * @param array $range  [ min, max ].
	 * @return string
	 */
	public static function length_status( int $length, array $range ): string {
		if ( 0 === $length ) {
			return 'missing';
		}
		if ( $length < $range[0] ) {
			return 'short';
		}
		return $length > $range[1] ? 'long' : 'good';
	}

	/**
	 * Rows for a list of posts.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @return array[]
	 */
	private function rows( array $posts ): array {
		$pending = self::pending_counts( wp_list_pluck( $posts, 'ID' ) );
		$out     = array();
		foreach ( $posts as $post ) {
			$out[] = $this->row( $post, isset( $pending[ $post->ID ] ) ? $pending[ $post->ID ] : 0 );
		}
		return $out;
	}
}
