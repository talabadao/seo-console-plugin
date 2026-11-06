/**
 * Character count against the recommended range (title 30–60, description 120–160).
 */
import { __, sprintf } from '@wordpress/i18n';

import { RANGES, countChars, lengthStatus } from '../utils/text';

const ICON = {
	empty: 'minus',
	short: 'warning',
	good: 'yes-alt',
	long: 'warning',
};

export function lengthMessage( status ) {
	switch ( status ) {
		case 'empty':
			return __( 'Empty', 'seo-console' );
		case 'short':
			return __( 'A bit short', 'seo-console' );
		case 'long':
			return __( 'Too long: may be cut off', 'seo-console' );
		default:
			return __( 'Good length', 'seo-console' );
	}
}

/**
 * @param {Object} props
 * @param {string} props.value Text to count.
 * @param {string} props.kind  'title' | 'description' (sets the range).
 * @param {number} props.min   Override the minimum.
 * @param {number} props.max   Override the maximum.
 * @param {string} props.id    Id (use it in the input's aria-describedby).
 * @return {JSX.Element} Counter.
 */
export default function CharCounter( { value, kind = 'title', min, max, id } ) {
	const base = RANGES[ kind ] || RANGES.title;
	const range = { min: min ?? base.min, max: max ?? base.max };
	const length = countChars( value );
	const status = lengthStatus( length, range );
	return (
		<p
			id={ id }
			className={ `seoc-counter seoc-counter--${ status }` }
			aria-live="polite"
		>
			<span
				className={ `dashicons dashicons-${ ICON[ status ] }` }
				aria-hidden="true"
			/>
			<span>
				{ sprintf(
					/* translators: 1: number of characters, 2: minimum, 3: maximum, 4: assessment */
					__(
						'%1$d characters (recommended %2$d–%3$d) · %4$s',
						'seo-console'
					),
					length,
					range.min,
					range.max,
					lengthMessage( status )
				) }
			</span>
		</p>
	);
}
