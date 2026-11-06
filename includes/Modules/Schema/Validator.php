<?php
/**
 * Validates schema nodes against the Types registry and returns plain-English errors (block saving)
 * and warnings (worth fixing, do not block).
 *
 * Result shape: [ 'valid' => bool, 'errors' => string[], 'warnings' => string[] ].
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Schema;

defined( 'ABSPATH' ) || exit;

final class Validator {

	/** Max size of one post's encoded schema. */
	const MAX_BYTES = 65536;

	/** Max characters in one text value. */
	const MAX_TEXT = 5000;

	/** Max items in one list (questions, steps, ...). */
	const MAX_ITEMS = 100;

	/** Max nesting depth. */
	const MAX_DEPTH = 6;

	/** Google truncates longer article headlines. */
	const HEADLINE_MAX = 110;

	/** @var string[] */
	private $errors = array();

	/** @var string[] */
	private $warnings = array();

	/** @var array */
	private $context = array();

	/**
	 * Validates the list of top-level nodes stored for one post.
	 *
	 * @param array $nodes   List of nodes.
	 * @param array $context Optional: 'content' (post HTML, enables "visible on page" checks for FAQ).
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate_graph( array $nodes, array $context = array() ): array {
		$this->start( $context );
		if ( array() !== $nodes && ! self::is_list( $nodes ) ) {
			$nodes = array( $nodes );
		}
		$encoded = wp_json_encode( $nodes );
		if ( false === $encoded ) {
			$this->errors[] = __( 'The schema could not be encoded as JSON.', 'seo-console' );
			return $this->result();
		}
		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			/* translators: %d: size limit in kilobytes. */
			$this->errors[] = sprintf( __( 'The schema for this page is larger than %d KB. Remove some items.', 'seo-console' ), (int) ( self::MAX_BYTES / 1024 ) );
		}
		$families = array();
		foreach ( $nodes as $i => $node ) {
			$path = sprintf( '#%d', $i + 1 );
			if ( ! is_array( $node ) ) {
				/* translators: %s: item position such as #2. */
				$this->errors[] = sprintf( __( 'Item %s is not a schema object.', 'seo-console' ), $path );
				continue;
			}
			$type = isset( $node['@type'] ) ? Types::primary( $node['@type'] ) : null;
			$this->check_node( $node, null !== $type ? Types::label( $type ) : $path, 0, true );
			if ( null !== $type ) {
				$def   = Types::get( $type );
				$group = Types::group( $type );
				if ( $def['single'] && isset( $families[ $group ] ) ) {
					/* translators: %s: schema type label such as "FAQ page". */
					$this->errors[] = sprintf( __( 'A page can only have one %s. Merge them into one.', 'seo-console' ), Types::label( $type ) );
				}
				$families[ $group ] = true;
			}
		}
		return $this->result();
	}

	/**
	 * Validates one top-level node.
	 *
	 * @param array $node    Node.
	 * @param array $context See validate_graph().
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate_node( array $node, array $context = array() ): array {
		return $this->validate_graph( array( $node ), $context );
	}

	/**
	 * Removes properties the registry does not allow (recursively) and JSON-LD keywords other than
	 * @type/@id. Used on AI output so invented properties never reach a suggestion.
	 *
	 * @param array $node Node.
	 * @return array{node: array, dropped: string[]} Cleaned node and dropped paths ("Product.foo").
	 */
	public function strip_unknown( array $node ): array {
		$dropped = array();
		$clean   = $this->strip_node( $node, '', $dropped, 0 );
		return array(
			'node'    => $clean,
			'dropped' => $dropped,
		);
	}

	/**
	 * @param array    $node    Node.
	 * @param string   $path    Path prefix.
	 * @param string[] $dropped Collected dropped paths.
	 * @param int      $depth   Depth.
	 * @return array
	 */
	private function strip_node( array $node, string $path, array &$dropped, int $depth ): array {
		$type = isset( $node['@type'] ) ? Types::primary( $node['@type'] ) : null;
		$name = '' === $path ? (string) $type : $path;
		if ( null === $type ) {
			return $node;
		}
		$allowed = Types::allowed_properties( $type );
		$out     = array();
		foreach ( $node as $prop => $value ) {
			if ( '@type' === $prop || '@id' === $prop ) {
				$out[ $prop ] = $value;
				continue;
			}
			if ( ! in_array( (string) $prop, $allowed, true ) ) {
				$dropped[] = $name . '.' . $prop;
				continue;
			}
			if ( is_array( $value ) && $depth < self::MAX_DEPTH ) {
				if ( self::is_list( $value ) ) {
					foreach ( $value as $k => $item ) {
						if ( is_array( $item ) ) {
							$value[ $k ] = $this->strip_node( $item, $name . '.' . $prop . '[' . $k . ']', $dropped, $depth + 1 );
						}
					}
				} else {
					$value = $this->strip_node( $value, $name . '.' . $prop, $dropped, $depth + 1 );
				}
			}
			$out[ $prop ] = $value;
		}
		return $out;
	}

	/**
	 * @param array $context Context.
	 */
	private function start( array $context ): void {
		$this->errors   = array();
		$this->warnings = array();
		$this->context  = $context;
	}

	/** @return array{valid: bool, errors: string[], warnings: string[]} */
	private function result(): array {
		return array(
			'valid'    => array() === $this->errors,
			'errors'   => array_values( array_unique( $this->errors ) ),
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	/**
	 * @param array  $node  Node.
	 * @param string $path  Human path ("FAQ page › questions #2").
	 * @param int    $depth Nesting depth.
	 * @param bool   $top   Whether this is a top-level node.
	 * @param array  $kinds Allowed node types for this position (empty = any).
	 */
	private function check_node( array $node, string $path, int $depth, bool $top, array $kinds = array() ): void {
		if ( $depth > self::MAX_DEPTH ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s is nested too deeply.', 'seo-console' ), $path );
			return;
		}
		if ( self::is_list( $node ) ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s must be an object, not a list.', 'seo-console' ), $path );
			return;
		}
		foreach ( array_keys( $node ) as $key ) {
			if ( is_string( $key ) && '@' === substr( $key, 0, 1 ) && ! in_array( $key, array( '@type', '@id', '@context' ), true ) ) {
				/* translators: 1: location in the schema, 2: JSON-LD keyword. */
				$this->errors[] = sprintf( __( '%1$s uses %2$s, which is not supported. Remove it.', 'seo-console' ), $path, $key );
			}
		}
		if ( isset( $node['@context'] ) ) {
			$ctx = is_string( $node['@context'] ) ? $node['@context'] : '';
			if ( ! $top || ! preg_match( '#^https?://schema\.org/?$#', $ctx ) ) {
				/* translators: %s: location in the schema. */
				$this->errors[] = sprintf( __( '%s has an unexpected @context. Use https://schema.org or leave it out.', 'seo-console' ), $path );
			}
		}

		// A bare reference to another node ({"@id": "..."}).
		if ( ! isset( $node['@type'] ) && isset( $node['@id'] ) && ! $top ) {
			if ( ! is_string( $node['@id'] ) || '' === trim( $node['@id'] ) ) {
				/* translators: %s: location in the schema. */
				$this->errors[] = sprintf( __( '%s has an empty @id reference.', 'seo-console' ), $path );
			}
			return;
		}
		if ( ! isset( $node['@type'] ) || array() === (array) $node['@type'] ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s has no @type.', 'seo-console' ), $path );
			return;
		}
		$type = Types::primary( $node['@type'] );
		if ( null === $type ) {
			/* translators: 1: location in the schema, 2: type name. */
			$this->errors[] = sprintf( __( '%1$s: the type "%2$s" is not one SEO Console supports.', 'seo-console' ), $path, implode( ', ', array_map( 'strval', array_filter( (array) $node['@type'], 'is_string' ) ) ) );
			return;
		}
		$def = Types::get( $type );
		if ( $top && ! $def['top_level'] ) {
			/* translators: 1: type label, e.g. "Offer". */
			$this->errors[] = sprintf( __( '%1$s cannot be used on its own; it belongs inside another item (for example an Offer inside a Product).', 'seo-console' ), $def['label'] );
			return;
		}
		if ( array() !== $kinds && ! in_array( '*', $kinds, true ) && ! $this->type_matches( $type, $kinds ) ) {
			/* translators: 1: location in the schema, 2: type found, 3: expected types. */
			$this->errors[] = sprintf( __( '%1$s is a %2$s, but %3$s is expected here.', 'seo-console' ), $path, $type, implode( ' / ', $kinds ) );
			return;
		}
		if ( isset( $node['@id'] ) && ( ! is_string( $node['@id'] ) || '' === trim( $node['@id'] ) ) ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s has an empty @id.', 'seo-console' ), $path );
		}

		foreach ( $def['required'] as $prop ) {
			if ( ! self::filled( $node, $prop ) ) {
				/* translators: 1: location in the schema, 2: property name. */
				$this->errors[] = sprintf( __( '%1$s is missing its %2$s (required).', 'seo-console' ), $path, Types::property_label( $prop ) );
			}
		}
		foreach ( $def['one_of'] as $group ) {
			$found = false;
			foreach ( $group as $prop ) {
				$found = $found || self::filled( $node, $prop );
			}
			if ( ! $found ) {
				/* translators: 1: location in the schema, 2: list of property names. */
				$this->errors[] = sprintf( __( '%1$s needs at least one of: %2$s.', 'seo-console' ), $path, implode( ', ', array_map( array( Types::class, 'property_label' ), $group ) ) );
			}
		}
		foreach ( $def['recommended'] as $prop ) {
			if ( ! self::filled( $node, $prop ) && ! in_array( $prop, $def['required'], true ) ) {
				/* translators: 1: location in the schema, 2: property name. */
				$this->warnings[] = sprintf( __( '%1$s: adding %2$s is recommended.', 'seo-console' ), $path, Types::property_label( $prop ) );
			}
		}

		foreach ( $node as $prop => $value ) {
			$prop = (string) $prop;
			if ( '@' === substr( $prop, 0, 1 ) ) {
				continue;
			}
			if ( ! in_array( $prop, $def['allowed'], true ) ) {
				/* translators: 1: location in the schema, 2: property name. */
				$this->warnings[] = sprintf( __( '%1$s: "%2$s" is not a property SEO Console checks for this type; search engines may ignore it.', 'seo-console' ), $path, $prop );
				$this->check_any( $value, $path . ' › ' . $prop, $depth );
				continue;
			}
			$this->check_property( $type, $prop, $value, $path . ' › ' . Types::property_label( $prop ), $depth );
		}

		$this->check_type_rules( $type, $node, $path );
	}

	/**
	 * Checks a property value (single value or list) against its kind.
	 *
	 * @param string $type  Owner type.
	 * @param string $prop  Property.
	 * @param mixed  $value Value.
	 * @param string $path  Human path.
	 * @param int    $depth Depth.
	 */
	private function check_property( string $type, string $prop, $value, string $path, int $depth ): void {
		if ( is_array( $value ) && self::is_list( $value ) ) {
			if ( count( $value ) > self::MAX_ITEMS ) {
				/* translators: 1: location in the schema, 2: max number of items. */
				$this->errors[] = sprintf( __( '%1$s has more than %2$d items.', 'seo-console' ), $path, self::MAX_ITEMS );
			}
			foreach ( $value as $i => $item ) {
				$this->check_value( $type, $prop, $item, $path . ' #' . ( $i + 1 ), $depth );
			}
			return;
		}
		$this->check_value( $type, $prop, $value, $path, $depth );
	}

	/**
	 * @param string $type  Owner type.
	 * @param string $prop  Property.
	 * @param mixed  $value Single value.
	 * @param string $path  Human path.
	 * @param int    $depth Depth.
	 */
	private function check_value( string $type, string $prop, $value, string $path, int $depth ): void {
		$alternatives = explode( '|', Types::kind( $type, $prop ) );
		$node_types   = array();
		$scalar_kinds = array();
		foreach ( $alternatives as $alt ) {
			if ( '*' === $alt || ctype_upper( substr( $alt, 0, 1 ) ) ) {
				$node_types[] = $alt;
			} else {
				$scalar_kinds[] = $alt;
			}
		}

		if ( is_array( $value ) ) {
			if ( array() === $node_types ) {
				/* translators: %s: location in the schema. */
				$this->errors[] = sprintf( __( '%s must be a simple value, not an object.', 'seo-console' ), $path );
				return;
			}
			$this->check_node( $value, $path, $depth + 1, false, $node_types );
			return;
		}
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			/* translators: %s: location in the schema. */
			$this->warnings[] = sprintf( __( '%s is empty; remove it or fill it in.', 'seo-console' ), $path );
			return;
		}
		if ( array() === $scalar_kinds ) {
			/* translators: 1: location in the schema, 2: expected type. */
			$this->errors[] = sprintf( __( '%1$s must be a %2$s item, not plain text.', 'seo-console' ), $path, implode( ' / ', $node_types ) );
			return;
		}
		$messages = array();
		foreach ( $scalar_kinds as $kind ) {
			$message = $this->scalar_error( $kind, $value );
			if ( null === $message ) {
				$this->check_text_limits( $prop, $value, $path );
				return;
			}
			$messages[] = $message;
		}
		/* translators: 1: location in the schema, 2: what is wrong. */
		$this->errors[] = sprintf( __( '%1$s: %2$s', 'seo-console' ), $path, $messages[0] );
	}

	/**
	 * Returns null when $value matches $kind, otherwise a plain-English problem.
	 *
	 * @param string $kind  Kind.
	 * @param mixed  $value Scalar value.
	 * @return string|null
	 */
	private function scalar_error( string $kind, $value ): ?string {
		$string = is_scalar( $value ) ? (string) $value : '';
		switch ( true ) {
			case 'bool' === $kind:
				return is_bool( $value ) ? null : __( 'must be true or false.', 'seo-console' );
			case 'text' === $kind:
				if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
					return __( 'must be text.', 'seo-console' );
				}
				if ( Html::has_html( $string ) ) {
					return __( 'must be plain text without HTML tags.', 'seo-console' );
				}
				return null;
			case 'url' === $kind:
				return self::is_absolute_url( $string ) ? null : __( 'must be a full web address starting with https:// or http://.', 'seo-console' );
			case 'email' === $kind:
				return is_email( $string ) ? null : __( 'must be an email address.', 'seo-console' );
			case 'date' === $kind:
				return self::is_iso_date( $string ) ? null : __( 'must be a date in ISO 8601 format, e.g. 2026-03-15 or 2026-03-15T09:00:00+07:00.', 'seo-console' );
			case 'duration' === $kind:
				return self::is_iso_duration( $string ) ? null : __( 'must be a duration in ISO 8601 format, e.g. PT30M for 30 minutes or PT1H15M.', 'seo-console' );
			case 'time' === $kind:
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?([+-]\d{2}:\d{2}|Z)?$/', $string ) ? null : __( 'must be a time like 09:00 or 17:30.', 'seo-console' );
			case 'price' === $kind:
				return ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+(\.\d+)?$/', $value ) ) ) && (float) $value >= 0
					? null
					: __( 'must be a number without currency symbols or thousands separators, e.g. 1299.00.', 'seo-console' );
			case 'currency' === $kind:
				return preg_match( '/^[A-Z]{3}$/', $string ) ? null : __( 'must be a 3-letter currency code such as USD, EUR or VND.', 'seo-console' );
			case 'number' === $kind:
				return ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) ? null : __( 'must be a number.', 'seo-console' );
			case 'integer' === $kind:
				return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 0 ? null : __( 'must be a whole number.', 'seo-console' );
			case 0 === strpos( $kind, 'enum:' ):
				$enums = Types::enums();
				$list  = isset( $enums[ substr( $kind, 5 ) ] ) ? $enums[ substr( $kind, 5 ) ] : array();
				$short = preg_replace( '#^https?://schema\.org/#', '', $string );
				/* translators: %s: list of allowed values. */
				return in_array( $short, $list, true ) ? null : sprintf( __( 'must be one of: %s.', 'seo-console' ), implode( ', ', $list ) );
		}
		return null;
	}

	/**
	 * @param string $prop  Property.
	 * @param mixed  $value Value.
	 * @param string $path  Human path.
	 */
	private function check_text_limits( string $prop, $value, string $path ): void {
		if ( ! is_string( $value ) ) {
			return;
		}
		$length = Html::length( $value );
		if ( $length > self::MAX_TEXT ) {
			/* translators: 1: location in the schema, 2: max characters. */
			$this->errors[] = sprintf( __( '%1$s is longer than %2$d characters.', 'seo-console' ), $path, self::MAX_TEXT );
		}
		if ( 'headline' === $prop && $length > self::HEADLINE_MAX ) {
			/* translators: 1: location in the schema, 2: max characters. */
			$this->warnings[] = sprintf( __( '%1$s is longer than %2$d characters; Google may cut it off.', 'seo-console' ), $path, self::HEADLINE_MAX );
		}
	}

	/**
	 * Light checks for properties we do not know: still no HTML, still bounded.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Human path.
	 * @param int    $depth Depth.
	 */
	private function check_any( $value, string $path, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( is_string( $value ) && Html::has_html( $value ) ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s must be plain text without HTML tags.', 'seo-console' ), $path );
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->check_any( $item, $path, $depth + 1 );
			}
		}
	}

	/**
	 * Rules that span several properties of one type.
	 *
	 * @param string $type Type.
	 * @param array  $node Node.
	 * @param string $path Human path.
	 */
	private function check_type_rules( string $type, array $node, string $path ): void {
		if ( 'FAQPage' === $type ) {
			$this->check_faq_visible( $node, $path );
		}

		if ( in_array( $type, array( 'AggregateRating', 'Rating' ), true ) && isset( $node['ratingValue'] ) && is_numeric( $node['ratingValue'] ) ) {
			$best  = isset( $node['bestRating'] ) && is_numeric( $node['bestRating'] ) ? (float) $node['bestRating'] : 5.0;
			$worst = isset( $node['worstRating'] ) && is_numeric( $node['worstRating'] ) ? (float) $node['worstRating'] : 1.0;
			$value = (float) $node['ratingValue'];
			if ( $worst >= $best || $value < $worst || $value > $best ) {
				/* translators: 1: location in the schema, 2: lowest rating, 3: highest rating. */
				$this->errors[] = sprintf( __( '%1$s: the rating must be between %2$s and %3$s.', 'seo-console' ), $path, $worst, $best );
			}
		}
		if ( 'AggregateRating' === $type ) {
			foreach ( array( 'ratingCount', 'reviewCount' ) as $count ) {
				if ( isset( $node[ $count ] ) && is_numeric( $node[ $count ] ) && (int) $node[ $count ] < 1 ) {
					/* translators: %s: location in the schema. */
					$this->errors[] = sprintf( __( '%s: a rating summary needs at least one real rating.', 'seo-console' ), $path );
				}
			}
		}

		if ( 'GeoCoordinates' === $type ) {
			$lat = isset( $node['latitude'] ) && is_numeric( $node['latitude'] ) ? (float) $node['latitude'] : 0.0;
			$lng = isset( $node['longitude'] ) && is_numeric( $node['longitude'] ) ? (float) $node['longitude'] : 0.0;
			if ( abs( $lat ) > 90 || abs( $lng ) > 180 ) {
				/* translators: %s: location in the schema. */
				$this->errors[] = sprintf( __( '%s: latitude must be between -90 and 90 and longitude between -180 and 180.', 'seo-console' ), $path );
			}
		}

		if ( 'AggregateOffer' === $type && isset( $node['lowPrice'], $node['highPrice'] ) && is_numeric( $node['lowPrice'] ) && is_numeric( $node['highPrice'] ) && (float) $node['lowPrice'] > (float) $node['highPrice'] ) {
			/* translators: %s: location in the schema. */
			$this->errors[] = sprintf( __( '%s: the lowest price is higher than the highest price.', 'seo-console' ), $path );
		}

		if ( Types::is_a( $type, 'Event' ) && isset( $node['startDate'], $node['endDate'] ) && is_string( $node['startDate'] ) && is_string( $node['endDate'] ) ) {
			$start = strtotime( $node['startDate'] );
			$end   = strtotime( $node['endDate'] );
			if ( false !== $start && false !== $end && $end < $start ) {
				/* translators: %s: location in the schema. */
				$this->errors[] = sprintf( __( '%s: the end date is before the start date.', 'seo-console' ), $path );
			}
		}

		if ( 'BreadcrumbList' === $type && isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) && self::is_list( $node['itemListElement'] ) ) {
			$items = $node['itemListElement'];
			$last  = count( $items ) - 1;
			foreach ( $items as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( $i < $last && ! self::filled( $item, 'item' ) ) {
					/* translators: 1: location in the schema, 2: item number. */
					$this->errors[] = sprintf( __( '%1$s: item #%2$d needs a link (only the last item may omit it).', 'seo-console' ), $path, $i + 1 );
				}
				if ( isset( $item['position'] ) && (int) $item['position'] !== $i + 1 ) {
					/* translators: %s: location in the schema. */
					$this->warnings[] = sprintf( __( '%s: positions should count up from 1 in order.', 'seo-console' ), $path );
				}
			}
		}
	}

	/**
	 * FAQ content must be visible on the page: each question must appear in the post content.
	 *
	 * @param array  $node FAQPage node.
	 * @param string $path Human path.
	 */
	private function check_faq_visible( array $node, string $path ): void {
		$content = isset( $this->context['content'] ) ? (string) $this->context['content'] : '';
		if ( '' === trim( $content ) || ! isset( $node['mainEntity'] ) || ! is_array( $node['mainEntity'] ) ) {
			return;
		}
		$questions = self::is_list( $node['mainEntity'] ) ? $node['mainEntity'] : array( $node['mainEntity'] );
		foreach ( $questions as $i => $question ) {
			if ( ! is_array( $question ) || ! isset( $question['name'] ) || ! is_string( $question['name'] ) ) {
				continue;
			}
			if ( ! Html::contains( $question['name'], $content ) ) {
				/* translators: 1: location in the schema, 2: question number, 3: question text. */
				$this->errors[] = sprintf( __( '%1$s: question #%2$d ("%3$s") does not appear on the page. Google requires FAQ questions and answers to be visible to visitors.', 'seo-console' ), $path, $i + 1, Html::truncate( $question['name'], 80 ) );
				continue;
			}
			$answer = isset( $question['acceptedAnswer']['text'] ) && is_string( $question['acceptedAnswer']['text'] ) ? $question['acceptedAnswer']['text'] : '';
			$start  = Html::truncate( Html::to_text( $answer ), 60 );
			$start  = rtrim( $start, '…' );
			if ( '' !== $start && ! Html::contains( $start, $content ) ) {
				/* translators: 1: location in the schema, 2: question number. */
				$this->warnings[] = sprintf( __( '%1$s: the answer to question #%2$d does not match the text on the page. Keep answers the same as what visitors see.', 'seo-console' ), $path, $i + 1 );
			}
		}
	}

	/**
	 * Whether a node type satisfies one of the expected types (inheritance-aware).
	 *
	 * @param string   $type  Type.
	 * @param string[] $kinds Expected types.
	 */
	private function type_matches( string $type, array $kinds ): bool {
		foreach ( $kinds as $expected ) {
			if ( Types::is_a( $type, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a property is present and non-empty.
	 *
	 * @param array  $node Node.
	 * @param string $prop Property.
	 */
	public static function filled( array $node, string $prop ): bool {
		if ( ! array_key_exists( $prop, $node ) ) {
			return false;
		}
		$value = $node[ $prop ];
		if ( null === $value || array() === $value ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		return true;
	}

	/**
	 * Sequential array check (PHP 7.4 has no array_is_list).
	 *
	 * @param array $value Array.
	 */
	public static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Absolute http(s) URL.
	 *
	 * @param string $url URL.
	 */
	public static function is_absolute_url( string $url ): bool {
		if ( ! preg_match( '#^https?://#i', $url ) || preg_match( '/\s/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] );
	}

	/**
	 * ISO 8601 date or date-time (YYYY-MM-DD with optional time and timezone).
	 *
	 * @param string $value Value.
	 */
	public static function is_iso_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d(\.\d+)?)?(Z|[+-]([01]\d|2[0-3]):?[0-5]\d)?)?$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * ISO 8601 duration (PT1H30M, P1D, ...).
	 *
	 * @param string $value Value.
	 */
	public static function is_iso_duration( string $value ): bool {
		return 'P' !== $value && 'T' !== substr( $value, -1 ) && 1 === preg_match( '/^P(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$/', $value );
	}
}
