/**
 * A status pill: icon + words, never colour alone.
 */
import { __ } from '@wordpress/i18n';

const ICONS = {
	good: 'yes-alt',
	'needs-work': 'warning',
	poor: 'dismiss',
	unknown: 'minus',
	info: 'info-outline',
	neutral: 'marker',
};

export function statusLabel( status ) {
	switch ( status ) {
		case 'good':
			return __( 'Good', 'seo-console' );
		case 'needs-work':
			return __( 'Needs work', 'seo-console' );
		case 'poor':
			return __( 'Poor', 'seo-console' );
		case 'unknown':
			return __( 'Unknown', 'seo-console' );
		default:
			return '';
	}
}

/**
 * @param {Object} props
 * @param {string} props.status 'good' | 'needs-work' | 'poor' | 'unknown' (also 'info' | 'neutral').
 * @param {string} props.label  Override the words (keep them meaningful without colour).
 * @param {string} props.title  Tooltip / longer explanation.
 * @return {JSX.Element} Badge.
 */
export default function StatusBadge( { status = 'unknown', label, title } ) {
	const tone = ICONS[ status ] ? status : 'unknown';
	const text = label || statusLabel( tone ) || statusLabel( 'unknown' );
	return (
		<span className={ `seoc-badge seoc-badge--${ tone }` } title={ title }>
			<span
				className={ `dashicons dashicons-${ ICONS[ tone ] }` }
				aria-hidden="true"
			/>
			<span className="seoc-badge__text">{ text }</span>
		</span>
	);
}
