<?php
/**
 * Reads, validates and writes one kind of site value so changes can be applied and undone
 * (see docs/ARCHITECTURE.md §7.1).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes;

defined( 'ABSPATH' ) || exit;

interface ApplierInterface {

	/** Applier id, e.g. 'seo_meta'. */
	public function type(): string;

	/**
	 * Current value.
	 *
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param string   $field       Field.
	 * @return mixed
	 */
	public function read( string $object_type, ?int $object_id, string $field );

	/**
	 * @param mixed    $value       Proposed value.
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param string   $field       Field.
	 * @return true|\WP_Error
	 */
	public function validate( $value, string $object_type, ?int $object_id, string $field );

	/**
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param string   $field       Field.
	 * @param mixed    $value       Value (already validated).
	 * @return true|\WP_Error
	 */
	public function write( string $object_type, ?int $object_id, string $field, $value );

	/** True when the change alters public pages (caches are purged and a health check runs). */
	public function affects_frontend(): bool;

	/**
	 * Human label for the change log, e.g. "SEO title".
	 *
	 * @param string $field Field.
	 */
	public function label( string $field ): string;
}
