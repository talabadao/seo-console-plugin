<?php
/**
 * Proposed settings for the Schema module (returned by SchemaModule::settings_schema() in Phase B).
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class SettingsFields {

	/**
	 * Settings schema in the core format (docs/ARCHITECTURE.md §6).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return array(
			// What the module does.
			'output_enabled'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'detect_faq'               => array(
				'type'    => 'bool',
				'default' => true,
			),
			'detect_howto'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'detect_video'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'detect_product'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'basic_without_seo_plugin' => array(
				'type'    => 'bool',
				'default' => true,
			),
			'ai_suggestions'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Business / organization (site-wide, front page).
			'business_type'            => array(
				'type'    => 'enum',
				'default' => 'none',
				'enum'    => array_merge( array( 'none', 'Organization' ), Types::local_business_types() ),
			),
			'business_name'            => array(
				'type'    => 'string',
				'default' => '',
			),
			'business_description'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'business_url'             => array(
				'type'    => 'url',
				'default' => '',
			),
			'business_logo'            => array(
				'type'    => 'url',
				'default' => '',
			),
			'business_image'           => array(
				'type'    => 'url',
				'default' => '',
			),
			'business_phone'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'business_email'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'address_street'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'address_locality'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'address_region'           => array(
				'type'    => 'string',
				'default' => '',
			),
			'address_postal_code'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'address_country'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'geo_latitude'             => array(
				'type'    => 'string',
				'default' => '',
			),
			'geo_longitude'            => array(
				'type'    => 'string',
				'default' => '',
			),
			'opening_hours'            => array(
				'type'    => 'string_list',
				'default' => array(),
			),
			'same_as'                  => array(
				'type'    => 'url_list',
				'default' => array(),
			),
			'price_range'              => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Keys of the business / organization fields (site-wide node).
	 *
	 * @return string[]
	 */
	public static function business_keys(): array {
		return array_values(
			array_filter(
				array_keys( self::schema() ),
				static function ( $key ) {
					return 0 === strpos( $key, 'business_' ) || 0 === strpos( $key, 'address_' ) || 0 === strpos( $key, 'geo_' ) || in_array( $key, array( 'opening_hours', 'same_as', 'price_range' ), true );
				}
			)
		);
	}

	/**
	 * Defaults as a flat array (used when core Settings is not available, e.g. tests).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::schema() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}
		return $out;
	}
}
