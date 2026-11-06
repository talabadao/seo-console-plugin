<?php
/**
 * Applier `schema`: a post's approved structured data (`_seoc_schema`), validated against the
 * Types registry and the post content before it is written. Null or an empty list removes it.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

use SEOConsole\Changes\ApplierInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SchemaApplier implements ApplierInterface {

	const TYPE = 'schema';

	const FIELD = 'schema';

	/** @var Validator */
	private $validator;

	/**
	 * @param Validator|null $validator Validator.
	 */
	public function __construct( ?Validator $validator = null ) {
		$this->validator = $validator ? $validator : new Validator();
	}

	/** {@inheritDoc} */
	public function type(): string {
		return self::TYPE;
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		if ( 'post' !== $object_type || self::FIELD !== $field || ! $object_id ) {
			return null;
		}
		return Store::raw( $object_id );
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		if ( 'post' !== $object_type || self::FIELD !== $field ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Structured data can only be set on posts and pages.', 'seo-console' ) );
		}
		$post = $object_id ? get_post( $object_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( null === $value || array() === $value ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'seoc_invalid_schema', __( 'Structured data must be a list of schema items.', 'seo-console' ) );
		}
		$nodes  = Validator::is_list( $value ) ? $value : array( $value );
		$result = $this->validator->validate_graph( $nodes, array( 'content' => (string) $post->post_content ) );
		if ( ! $result['valid'] ) {
			return new WP_Error(
				'seoc_invalid_schema',
				/* translators: %s: list of problems. */
				sprintf( __( 'The structured data has problems: %s', 'seo-console' ), implode( ' ', $result['errors'] ) ),
				array(
					'errors'   => $result['errors'],
					'warnings' => $result['warnings'],
				)
			);
		}
		return true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		if ( 'post' !== $object_type || self::FIELD !== $field || ! $object_id ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Structured data can only be set on posts and pages.', 'seo-console' ) );
		}
		if ( null === $value || array() === $value || '' === $value ) {
			Store::delete( $object_id );
			return true;
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'seoc_invalid_schema', __( 'Structured data must be a list of schema items.', 'seo-console' ) );
		}
		$nodes = Validator::is_list( $value ) ? $value : array( $value );
		if ( ! Store::set( $object_id, $nodes ) ) {
			return new WP_Error( 'seoc_write_failed', __( 'The structured data could not be saved.', 'seo-console' ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		return __( 'Structured data', 'seo-console' );
	}
}
