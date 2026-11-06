<?php
/**
 * Applier `post_meta_json`: SEO Console's own `_seoc_*` post/term meta, stored as JSON.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes\Appliers;

use SEOConsole\Changes\ApplierInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Object type 'post' | 'attachment' | 'term', field = meta key matching `_seoc_[a-z0-9_]+`, value = any
 * JSON-serialisable value (null deletes the meta). Other meta keys are refused.
 */
final class PostMetaJsonApplier implements ApplierInterface {

	const MAX_BYTES = 65536;

	/** {@inheritDoc} */
	public function type(): string {
		return 'post_meta_json';
	}

	/**
	 * Whether a meta key is allowed.
	 *
	 * @param string $field Meta key.
	 */
	public static function allowed_key( string $field ): bool {
		return (bool) preg_match( '/^_seoc_[a-z0-9_]{1,60}$/', $field );
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		if ( ! $object_id || ! self::allowed_key( $field ) ) {
			return null;
		}
		$raw = 'term' === $object_type ? get_term_meta( $object_id, $field, true ) : get_post_meta( $object_id, $field, true );
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}
		if ( ! is_string( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		if ( ! self::allowed_key( $field ) ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Only SEO Console meta keys (_seoc_*) can be changed.', 'seo-console' ) );
		}
		if ( ! in_array( $object_type, array( 'post', 'attachment', 'term' ), true ) ) {
			return new WP_Error( 'seoc_invalid_object', __( 'Unsupported object type.', 'seo-console' ) );
		}
		$exists = 'term' === $object_type ? ( $object_id && get_term( $object_id ) instanceof \WP_Term ) : ( $object_id && get_post( $object_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'seoc_not_found', __( 'Object not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( null === $value ) {
			return true;
		}
		if ( is_object( $value ) || is_resource( $value ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be JSON data.', 'seo-console' ) );
		}
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be JSON data.', 'seo-console' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value is too large.', 'seo-console' ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		if ( ! self::allowed_key( $field ) || ! $object_id ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Only SEO Console meta keys (_seoc_*) can be changed.', 'seo-console' ) );
		}
		$is_term = 'term' === $object_type;
		if ( null === $value || '' === $value ) {
			if ( $is_term ) {
				delete_term_meta( $object_id, $field );
			} else {
				delete_post_meta( $object_id, $field );
			}
			return true;
		}
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be JSON data.', 'seo-console' ) );
		}
		if ( $is_term ) {
			update_term_meta( $object_id, $field, wp_slash( $json ) );
		} else {
			update_post_meta( $object_id, $field, wp_slash( $json ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		$labels = array(
			'_seoc_schema' => __( 'Structured data (schema)', 'seo-console' ),
		);
		if ( isset( $labels[ $field ] ) ) {
			return $labels[ $field ];
		}
		/* translators: %s: meta key */
		return sprintf( __( 'SEO Console data (%s)', 'seo-console' ), $field );
	}
}
