/**
 * Labels that say honestly what kind of data a number is (or that it is missing).
 * Missing data is never shown as zero.
 */
import { __, sprintf } from '@wordpress/i18n';

import { formatDate } from '../utils/format';

const KINDS = {
	none: {
		icon: 'minus',
		label: () => __( 'No data yet', 'seo-console' ),
		help: () =>
			__(
				'Nothing has been measured or collected for this yet.',
				'seo-console'
			),
	},
	stale: {
		icon: 'clock',
		label: ( since ) =>
			since
				? sprintf(
						/* translators: %s: date */
						__( 'Stale since %s', 'seo-console' ),
						since
				  )
				: __( 'Stale', 'seo-console' ),
		help: () =>
			__(
				'This data is older than expected. It may not reflect the site today.',
				'seo-console'
			),
	},
	partial: {
		icon: 'chart-pie',
		label: () => __( 'Partial data', 'seo-console' ),
		help: () =>
			__(
				'Only part of the data could be collected. Totals may be lower than reality.',
				'seo-console'
			),
	},
	lab: {
		icon: 'desktop',
		label: () => __( 'Lab data', 'seo-console' ),
		help: () =>
			__(
				'Measured by a single simulated visit (PageSpeed Insights). Useful for finding problems; it varies between runs.',
				'seo-console'
			),
	},
	field: {
		icon: 'groups',
		label: () => __( 'Field data', 'seo-console' ),
		help: () =>
			__(
				'Measured from real Chrome visitors over the last 28 days (Chrome UX Report). This is what Google uses for Core Web Vitals.',
				'seo-console'
			),
	},
	estimate: {
		icon: 'calculator',
		label: () => __( 'Estimate', 'seo-console' ),
		help: () =>
			__(
				'Calculated by SEO Console, not reported by the provider.',
				'seo-console'
			),
	},
};

/**
 * @param {Object}             props
 * @param {string}             props.kind   'none' | 'stale' | 'partial' | 'lab' | 'field' | 'estimate'.
 * @param {string|number|Date} props.since  For 'stale': when the data was last fresh.
 * @param {string}             props.asOf   Optional "as of" date shown after the label.
 * @param {string}             props.detail Extra sentence appended to the explanation.
 * @param {boolean}            props.inline Render as plain muted text instead of a pill.
 * @return {JSX.Element|null} Label.
 */
export default function DataHonesty( { kind, since, asOf, detail, inline } ) {
	const def = KINDS[ kind ];
	if ( ! def ) {
		return null;
	}
	const sinceText = since ? formatDate( since, false ) : '';
	let label = def.label( sinceText );
	if ( asOf ) {
		label = sprintf(
			/* translators: 1: data label such as "Lab data", 2: date */
			__( '%1$s · as of %2$s', 'seo-console' ),
			label,
			formatDate( asOf )
		);
	}
	const help = [ def.help(), detail ].filter( Boolean ).join( ' ' );
	return (
		<span
			className={ `seoc-honesty seoc-honesty--${ kind }${
				inline ? ' seoc-honesty--inline' : ''
			}` }
			title={ help }
		>
			<span
				className={ `dashicons dashicons-${ def.icon }` }
				aria-hidden="true"
			/>
			<span>{ label }</span>
			<span className="screen-reader-text">{ ` — ${ help }` }</span>
		</span>
	);
}

/**
 * A muted "where this number comes from" line, e.g. under a KPI strip.
 *
 * @param {Object} props
 * @param {Node}   props.children Sentence(s).
 * @return {JSX.Element} Line.
 */
export function SourceLine( { children } ) {
	return <p className="seoc-source-line">{ children }</p>;
}
