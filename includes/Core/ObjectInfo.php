<?php
/**
 * Titles and links of the objects that changes and suggestions refer to.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a post, attachment, term, option or the site for list screens.
 */
final class ObjectInfo {

	/**
	 * Title and links of an object.
	 *
	 * @param string   $object_type 'post' | 'attachment' | 'term' | 'option' | 'site'.
	 * @param int|null $object_id   Object id.
	 * @return array{object_title: string, edit_link: string, view_link: string}
	 */
	public static function describe( string $object_type, ?int $object_id ): array {
		$info = array(
			'object_title' => '',
			'edit_link'    => '',
			'view_link'    => '',
		);
		switch ( $object_type ) {
			case 'post':
			case 'attachment':
				$post = $object_id ? get_post( $object_id ) : null;
				if ( $post ) {
					$title                = wp_strip_all_tags( get_the_title( $post ) );
					$info['object_title'] = '' !== $title ? $title : sprintf( '#%d', $post->ID );
					$info['edit_link']    = (string) get_edit_post_link( $post->ID, 'raw' );
					$view                 = 'attachment' === $post->post_type ? wp_get_attachment_url( $post->ID ) : get_permalink( $post );
					$info['view_link']    = $view ? (string) $view : '';
				}
				break;
			case 'term':
				$term = $object_id ? get_term( $object_id ) : null;
				if ( $term instanceof \WP_Term ) {
					$info['object_title'] = $term->name;
					$info['edit_link']    = (string) get_edit_term_link( $term->term_id, $term->taxonomy );
					$link                 = get_term_link( $term );
					$info['view_link']    = is_wp_error( $link ) ? '' : (string) $link;
				}
				break;
			default:
				$info['object_title'] = wp_strip_all_tags( get_bloginfo( 'name' ) );
				$info['view_link']    = home_url( '/' );
				break;
		}
		return $info;
	}

	/**
	 * Public URL of an object for a health check (empty when not public).
	 *
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 */
	public static function public_url( string $object_type, ?int $object_id ): string {
		if ( 'post' === $object_type && $object_id ) {
			$post = get_post( $object_id );
			if ( $post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
				return (string) get_permalink( $post );
			}
		}
		if ( 'term' === $object_type && $object_id ) {
			$link = get_term_link( (int) $object_id );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		return '';
	}

	/**
	 * ISO 8601 UTC date for REST output, or null.
	 *
	 * @param string|null $mysql_utc 'Y-m-d H:i:s' in UTC.
	 */
	public static function iso_date( ?string $mysql_utc ): ?string {
		if ( null === $mysql_utc || '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}
}
