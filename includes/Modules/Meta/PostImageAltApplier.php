<?php
/**
 * Applier `post_image_alt`: the alt text of an image inside one post's content.
 *
 * WordPress stores an inserted image's alt text in the post content (<img alt="…"> in classic HTML and in the
 * core/image block, whose alt attribute is read from that tag). Changing the media-library alt does not change
 * images already placed in posts, so suggestions for those images target the post.
 *
 * Object type 'post', field 'image_alt:<attachment id>'.
 * - read: the alt of the first <img> of that attachment in the content (class wp-image-<id> or data-id="<id>").
 * - write: sets the alt on every occurrence of the attachment in the content (wp_update_post, so a revision is
 *   kept). When the media-library alt is empty it is filled too, so new insertions get it; reverting restores
 *   only the inline alt, the media-library alt is left as is.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Meta;

use SEOConsole\Changes\ApplierInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PostImageAltApplier implements ApplierInterface {

	const TYPE = 'post_image_alt';

	const FIELD_PREFIX = 'image_alt:';

	const MAX_LENGTH = 250;

	/** {@inheritDoc} */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * Field name for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function field( int $attachment_id ): string {
		return self::FIELD_PREFIX . $attachment_id;
	}

	/**
	 * Attachment ID from a field name, or 0.
	 *
	 * @param string $field Field.
	 * @return int
	 */
	public static function attachment_id( string $field ): int {
		return preg_match( '/^image_alt:([1-9]\d{0,19})$/', $field, $m ) ? (int) $m[1] : 0;
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		$id   = self::attachment_id( $field );
		$post = 'post' === $object_type && $object_id ? get_post( $object_id ) : null;
		if ( ! $id || ! $post ) {
			return null;
		}
		foreach ( self::image_tags( (string) $post->post_content, $id ) as $tag ) {
			return self::alt_of( $tag );
		}
		return null;
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		$id = self::attachment_id( $field );
		if ( 'post' !== $object_type || ! $id ) {
			return new WP_Error( 'seoc_invalid_field', __( 'In-post alt text applies to the "image_alt:<id>" field of posts.', 'seo-console' ) );
		}
		$post = $object_id ? get_post( $object_id ) : null;
		if ( ! $post || 'attachment' === $post->post_type ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( ! self::image_tags( (string) $post->post_content, $id ) ) {
			return new WP_Error( 'seoc_not_found', __( 'This image is no longer in the post.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( null !== $value && ! is_string( $value ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be text.', 'seo-console' ) );
		}
		$value = (string) $value;
		if ( wp_strip_all_tags( $value ) !== $value || false !== strpbrk( $value, "<>\n\r" ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'HTML and line breaks are not allowed here.', 'seo-console' ) );
		}
		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			/* translators: %d: maximum length */
			return new WP_Error( 'seoc_invalid_value', sprintf( __( 'The value is too long (maximum %d characters).', 'seo-console' ), self::MAX_LENGTH ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		$id    = self::attachment_id( $field );
		$post  = $object_id ? get_post( $object_id ) : null;
		$value = sanitize_text_field( (string) $value );
		if ( ! $id || ! $post ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}

		$content = (string) $post->post_content;
		$updated = self::replace_alt( $content, $id, $value );
		if ( $updated !== $content ) {
			// The content is ours to rewrite only in the alt attribute; kses would strip markup (iframes, scripts)
			// the author was allowed to add when the job runs as a user without unfiltered_html.
			$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses ) {
				kses_remove_filters();
			}
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $updated,
					)
				),
				true
			);
			if ( $kses ) {
				kses_init_filters();
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( '' !== $value && '' === (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) && wp_attachment_is_image( $id ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $value ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		return __( 'Image alt text (in post)', 'seo-console' );
	}

	/**
	 * Sets the alt attribute on every <img> of the attachment.
	 *
	 * @param string $content       Post content.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           New alt (plain text).
	 * @return string
	 */
	public static function replace_alt( string $content, int $attachment_id, string $alt ): string {
		$escaped = esc_attr( $alt );
		return (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) use ( $attachment_id, $escaped ) {
				$tag = $m[0];
				if ( ! self::is_attachment_tag( $tag, $attachment_id ) ) {
					return $tag;
				}
				if ( preg_match( '/\salt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $tag ) ) {
					return (string) preg_replace( '/(\s)alt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '$1alt="' . str_replace( array( '\\', '$' ), array( '\\\\', '\$' ), $escaped ) . '"', $tag, 1 );
				}
				return '<img alt="' . $escaped . '"' . substr( $tag, 4 );
			},
			$content
		);
	}

	/**
	 * <img> tags of the attachment in the content.
	 *
	 * @param string $content       Content.
	 * @param int    $attachment_id Attachment ID.
	 * @return string[]
	 */
	public static function image_tags( string $content, int $attachment_id ): array {
		$out = array();
		if ( preg_match_all( '#<img\b[^>]*>#i', $content, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( self::is_attachment_tag( $tag, $attachment_id ) ) {
					$out[] = $tag;
				}
			}
		}
		return $out;
	}

	/**
	 * Alt text of an <img> tag ('' when missing).
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	public static function alt_of( string $tag ): string {
		if ( preg_match( '/\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m ) ) {
			$raw = isset( $m[3] ) ? $m[3] : ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1] );
			return html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Whether an <img> tag shows the attachment (class wp-image-<id>, or data-id="<id>" in gallery markup).
	 *
	 * @param string $tag           Tag.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_attachment_tag( string $tag, int $attachment_id ): bool {
		if ( preg_match( '/\sclass\s*=\s*(["\'])([^"\']*)\1/i', $tag, $m ) && preg_match( '/(^|\s)wp-image-' . $attachment_id . '(\s|$)/', $m[2] ) ) {
			return true;
		}
		return (bool) preg_match( '/\sdata-id\s*=\s*(["\']?)' . $attachment_id . '\1(\s|\/|>)/i', $tag );
	}
}
