<?php
/**
 * Applier `module_setting`: one setting `<module>.<key>` written through Settings.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Changes\Appliers;

use SEOConsole\Changes\ApplierInterface;
use SEOConsole\Core\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Object type 'site' (or 'option'), object id null, field '<module>.<key>' (e.g. 'speed.disable_emojis',
 * 'general.ai_model', 'speed.enabled').
 */
final class ModuleSettingApplier implements ApplierInterface {

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

	/** {@inheritDoc} */
	public function type(): string {
		return 'module_setting';
	}

	/** {@inheritDoc} */
	public function read( string $object_type, ?int $object_id, string $field ) {
		$parts = $this->split( $field );
		return null === $parts ? null : $this->settings->get( $parts[0], $parts[1] );
	}

	/** {@inheritDoc} */
	public function validate( $value, string $object_type, ?int $object_id, string $field ) {
		if ( ! in_array( $object_type, array( 'site', 'option' ), true ) ) {
			return new WP_Error( 'seoc_invalid_object', __( 'Settings changes use the object type "site".', 'seo-console' ) );
		}
		$parts = $this->split( $field );
		if ( null === $parts ) {
			return new WP_Error( 'seoc_invalid_field', __( 'The field must look like "module.key".', 'seo-console' ) );
		}
		$clean = $this->settings->validate( $parts[0], $parts[1], $value );
		return is_wp_error( $clean ) ? $clean : true;
	}

	/** {@inheritDoc} */
	public function write( string $object_type, ?int $object_id, string $field, $value ) {
		$parts = $this->split( $field );
		if ( null === $parts ) {
			return new WP_Error( 'seoc_invalid_field', __( 'The field must look like "module.key".', 'seo-console' ) );
		}
		return $this->settings->set( $parts[0], $parts[1], $value );
	}

	/** {@inheritDoc} */
	public function affects_frontend(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function label( string $field ): string {
		/* translators: %s: setting name such as speed.disable_emojis */
		return sprintf( __( 'Setting %s', 'seo-console' ), $field );
	}

	/**
	 * Splits 'module.key'.
	 *
	 * @param string $field Field.
	 * @return array{0: string, 1: string}|null
	 */
	private function split( string $field ): ?array {
		if ( ! preg_match( '/^([a-z0-9_\-]+)\.([a-z0-9_]+)$/', $field, $m ) ) {
			return null;
		}
		return array( $m[1], $m[2] );
	}
}
