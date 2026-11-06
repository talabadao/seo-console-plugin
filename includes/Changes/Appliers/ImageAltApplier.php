<?php
/**
 * Applier `image_alt`: alternative text of an image attachment.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes\Appliers;

use SEOConsole\Changes\ApplierInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Object type 'attachment', field 'alt' (meta `_wp_attachment_image_alt`).
 */
final class ImageAltApplier implements ApplierInterface {

	const META_KEY = '_wp_attachment_image_alt';

	const MAX_LENGTH = 250;

	/** {@inheritDoc} */
	public function type(): string {
		return 'image_alt';
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		if ( 'attachment' !== $object_type || ! $object_id ) {
			return null;
		}
		return (string) get_post_meta( $object_id, self::META_KEY, true );
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		if ( 'attachment' !== $object_type || 'alt' !== $field ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Alt text applies to the "alt" field of attachments.', 'seo-console' ) );
		}
		$post = $object_id ? get_post( $object_id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'seoc_not_found', __( 'Attachment not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( ! wp_attachment_is_image( $post ) ) {
			return new WP_Error( 'seoc_invalid_object', __( 'This attachment is not an image.', 'seo-console' ) );
		}
		if ( null !== $value && ! is_string( $value ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be text.', 'seo-console' ) );
		}
		$value = (string) $value;
		if ( wp_strip_all_tags( $value ) !== $value ) {
			return new WP_Error( 'seoc_invalid_value', __( 'HTML is not allowed here.', 'seo-console' ) );
		}
		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			/* translators: %d: maximum length */
			return new WP_Error( 'seoc_invalid_value', sprintf( __( 'The value is too long (maximum %d characters).', 'seo-console' ), self::MAX_LENGTH ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			delete_post_meta( (int) $object_id, self::META_KEY );
			return true;
		}
		update_post_meta( (int) $object_id, self::META_KEY, wp_slash( $value ) );
		return true;
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		return __( 'Image alt text', 'seo-console' );
	}
}
