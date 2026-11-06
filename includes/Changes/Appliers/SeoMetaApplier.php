<?php
/**
 * Applier `seo_meta`: SEO title, meta description and focus keyword through Rank Math / Yoast.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes\Appliers;

use SEOConsole\Changes\ApplierInterface;
use SEOConsole\Integrations\SeoPlugins;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Object type 'post', fields title|description|focus_keyword.
 */
final class SeoMetaApplier implements ApplierInterface {

	/** Maximum lengths (characters) accepted per field. */
	const MAX_LENGTH = array(
		'title'         => 200,
		'description'   => 400,
		'focus_keyword' => 100,
	);

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

	/** {@inheritDoc} */
	public function type(): string {
		return 'seo_meta';
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		if ( 'post' !== $object_type || ! $object_id || ! in_array( $field, SeoPlugins::FIELDS, true ) ) {
			return null;
		}
		return $this->seo->get( $object_id, $field );
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		if ( 'post' !== $object_type ) {
			return new WP_Error( 'seoc_invalid_object', __( 'SEO meta can only be changed on posts.', 'seo-console' ) );
		}
		if ( ! in_array( $field, SeoPlugins::FIELDS, true ) ) {
			return new WP_Error( 'seoc_invalid_field', __( 'Unknown SEO field.', 'seo-console' ) );
		}
		if ( ! $object_id || ! get_post( $object_id ) ) {
			return new WP_Error( 'seoc_not_found', __( 'Post not found.', 'seo-console' ), array( 'status' => 404 ) );
		}
		if ( 'none' === $this->seo->active() ) {
			return new WP_Error( 'seoc_no_seo_plugin', __( 'No supported SEO plugin (Rank Math or Yoast SEO) is active.', 'seo-console' ), array( 'status' => 409 ) );
		}
		if ( null === $value ) {
			$value = '';
		}
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'The value must be text.', 'seo-console' ) );
		}
		if ( wp_strip_all_tags( $value ) !== $value ) {
			return new WP_Error( 'seoc_invalid_value', __( 'HTML is not allowed here.', 'seo-console' ) );
		}
		if ( mb_strlen( $value ) > self::MAX_LENGTH[ $field ] ) {
			return new WP_Error(
				'seoc_invalid_value',
				/* translators: %d: maximum length */
				sprintf( __( 'The value is too long (maximum %d characters).', 'seo-console' ), self::MAX_LENGTH[ $field ] )
			);
		}
		if ( 'focus_keyword' === $field && false !== strpos( $value, ',' ) ) {
			return new WP_Error( 'seoc_invalid_value', __( 'Enter a single focus keyword (no commas).', 'seo-console' ) );
		}
		return true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		return $this->seo->set( (int) $object_id, $field, null === $value ? '' : (string) $value );
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		switch ( $field ) {
			case 'title':
				return __( 'SEO title', 'seo-console' );
			case 'description':
				return __( 'Meta description', 'seo-console' );
			case 'focus_keyword':
				return __( 'Focus keyword', 'seo-console' );
		}
		return $field;
	}
}
