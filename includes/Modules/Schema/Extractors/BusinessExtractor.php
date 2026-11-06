<?php
/**
 * Organization / LocalBusiness node from the module settings (see SettingsFields). Site-wide, shown
 * on the front page. Not post-based, so it does not implement ExtractorInterface.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema\Extractors;

use SEOConsole\Modules\Schema\Types;

defined( 'ABSPATH' ) || exit;

final class BusinessExtractor {

	/** Day tokens accepted in opening-hours lines. */
	const DAYS = array(
		'mo'        => 'Monday',
		'mon'       => 'Monday',
		'monday'    => 'Monday',
		'tu'        => 'Tuesday',
		'tue'       => 'Tuesday',
		'tuesday'   => 'Tuesday',
		'we'        => 'Wednesday',
		'wed'       => 'Wednesday',
		'wednesday' => 'Wednesday',
		'th'        => 'Thursday',
		'thu'       => 'Thursday',
		'thursday'  => 'Thursday',
		'fr'        => 'Friday',
		'fri'       => 'Friday',
		'friday'    => 'Friday',
		'sa'        => 'Saturday',
		'sat'       => 'Saturday',
		'saturday'  => 'Saturday',
		'su'        => 'Sunday',
		'sun'       => 'Sunday',
		'sunday'    => 'Sunday',
	);

	/**
	 * Builds the node. Returns node null when business_type is 'none' or no name is set.
	 *
	 * @param array $settings Module settings (keys from SettingsFields::schema()).
	 * @return array{node: array|null, errors: string[]}
	 */
	public function build( array $settings ): array {
		$get  = static function ( $key ) use ( $settings ) {
			return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
		};
		$type = $get( 'business_type' );
		if ( '' === $type || 'none' === $type || ! Types::is_a( $type, 'Organization' ) || '' === $get( 'business_name' ) ) {
			return array(
				'node'   => null,
				'errors' => array(),
			);
		}
		$is_local = Types::is_a( $type, 'LocalBusiness' );
		$node     = array(
			'@type'       => $type,
			'name'        => $get( 'business_name' ),
			'description' => $get( 'business_description' ),
			'url'         => '' !== $get( 'business_url' ) ? $get( 'business_url' ) : home_url( '/' ),
			'logo'        => $get( 'business_logo' ),
			'image'       => '' !== $get( 'business_image' ) ? $get( 'business_image' ) : $get( 'business_logo' ),
			'telephone'   => $get( 'business_phone' ),
			'email'       => $get( 'business_email' ),
		);
		$address  = array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $get( 'address_street' ),
			'addressLocality' => $get( 'address_locality' ),
			'addressRegion'   => $get( 'address_region' ),
			'postalCode'      => $get( 'address_postal_code' ),
			'addressCountry'  => strtoupper( $get( 'address_country' ) ),
		);
		if ( count( array_filter( $address ) ) > 1 ) {
			$node['address'] = $address;
		}
		$same_as = isset( $settings['same_as'] ) ? array_values( array_filter( array_map( 'strval', (array) $settings['same_as'] ) ) ) : array();
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}
		$errors = array();
		if ( $is_local ) {
			$node['priceRange'] = $get( 'price_range' );
			$lat                = $get( 'geo_latitude' );
			$lng                = $get( 'geo_longitude' );
			if ( '' !== $lat && '' !== $lng ) {
				if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
					$node['geo'] = array(
						'@type'     => 'GeoCoordinates',
						'latitude'  => (float) $lat,
						'longitude' => (float) $lng,
					);
				} else {
					$errors[] = __( 'Latitude and longitude must be numbers, e.g. 21.0285 and 105.8542.', 'seo-console' );
				}
			}
			$hours = self::parse_opening_hours( isset( $settings['opening_hours'] ) ? (array) $settings['opening_hours'] : array() );
			if ( $hours['specs'] ) {
				$node['openingHoursSpecification'] = $hours['specs'];
			}
			$errors = array_merge( $errors, $hours['errors'] );
		}
		return array(
			'node'   => AbstractExtractor::prune( $node ),
			'errors' => $errors,
		);
	}

	/**
	 * Parses lines like "Mo-Fr 08:00-17:30", "Sa,Su 09:00-12:00", "Monday 10:00-14:00".
	 *
	 * @param string[] $lines Lines.
	 * @return array{specs: array[], errors: string[]}
	 */
	public static function parse_opening_hours( array $lines ): array {
		$specs  = array();
		$errors = array();
		$order  = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z,\-\s]+?)\s+(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})$/u', $line, $m ) ) {
				/* translators: %s: opening hours line as typed. */
				$errors[] = sprintf( __( 'Could not read the opening hours "%s". Use a format like "Mo-Fr 08:00-17:30".', 'seo-console' ), $line );
				continue;
			}
			$days = array();
			$ok   = true;
			foreach ( preg_split( '/\s*,\s*/', strtolower( trim( $m[1] ) ) ) as $part ) {
				$range = array_map( 'trim', explode( '-', $part ) );
				if ( ! isset( self::DAYS[ $range[0] ] ) || ( isset( $range[1] ) && ! isset( self::DAYS[ $range[1] ] ) ) || count( $range ) > 2 ) {
					$ok = false;
					break;
				}
				$from = array_search( self::DAYS[ $range[0] ], $order, true );
				$to   = isset( $range[1] ) ? array_search( self::DAYS[ $range[1] ], $order, true ) : $from;
				$i    = $from;
				while ( true ) {
					$days[] = $order[ $i ];
					if ( $i === $to ) {
						break;
					}
					++$i;
					$i %= 7;
				}
			}
			$opens  = self::time( $m[2] );
			$closes = self::time( $m[3] );
			if ( ! $ok || null === $opens || null === $closes ) {
				/* translators: %s: opening hours line as typed. */
				$errors[] = sprintf( __( 'Could not read the opening hours "%s". Use a format like "Mo-Fr 08:00-17:30".', 'seo-console' ), $line );
				continue;
			}
			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => array_values( array_unique( $days ) ),
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}
		return array(
			'specs'  => $specs,
			'errors' => $errors,
		);
	}

	/**
	 * "9:00" → "09:00"; null when invalid.
	 *
	 * @param string $value Time.
	 */
	private static function time( string $value ): ?string {
		list( $h, $min ) = array_map( 'intval', explode( ':', $value ) );
		if ( $h > 23 || $min > 59 ) {
			return 24 === $h && 0 === $min ? '23:59' : null;
		}
		return sprintf( '%02d:%02d', $h, $min );
	}
}
