/**
 * A KPI tile: label, big value + unit, status badge, change pill, data-honesty label.
 */
import { __, sprintf } from '@wordpress/i18n';

import DataHonesty from './DataHonesty';
import StatusBadge from './StatusBadge';
import { formatNumber } from '../utils/format';

/**
 * Describe a change. `betterWhen` says which direction is an improvement ('up', 'down' or 'none').
 *
 * @param {number} value      Change (current − previous).
 * @param {string} betterWhen 'up' | 'down' | 'none'.
 * @return {{tone: string, arrow: string, sign: string}} Presentation.
 */
export function describeDelta( value, betterWhen = 'up' ) {
	if ( ! value ) {
		return { tone: 'neutral', arrow: '→', sign: '' };
	}
	const up = value > 0;
	let tone = 'neutral';
	if ( betterWhen === 'up' ) {
		tone = up ? 'good' : 'poor';
	} else if ( betterWhen === 'down' ) {
		tone = up ? 'poor' : 'good';
	}
	return { tone, arrow: up ? '↑' : '↓', sign: up ? '+' : '−' };
}

/**
 * @param {Object}             props
 * @param {Node}               props.label       Metric name.
 * @param {number|string|null} props.value       Value; null/undefined shows "—" with "No data yet".
 * @param {string}             props.unit        Unit after the value ("s", "ms", "%").
 * @param {string}             props.status      StatusBadge status (optional).
 * @param {string}             props.statusLabel Override badge words.
 * @param {Object}             props.delta       { value: number, betterWhen: 'up'|'down'|'none', label: 'vs 7 days before' }.
 * @param {string}             props.help        Definition shown as tooltip and to screen readers.
 * @param {string}             props.dataKind    DataHonesty kind ('lab', 'field', 'stale', 'partial', 'estimate').
 * @param {*}                  props.since       For dataKind 'stale'.
 * @param {Node}               props.footnote    Small muted line under the value.
 * @param {string}             props.href        Makes the label a link.
 * @param {Function}           props.format      Value formatter (default: locale number).
 * @return {JSX.Element} Tile.
 */
export default function MetricTile( {
	label,
	value,
	unit,
	status,
	statusLabel,
	delta,
	help,
	dataKind,
	since,
	footnote,
	href,
	format,
} ) {
	const missing = value === null || value === undefined || value === '';
	let display = '—';
	if ( ! missing ) {
		if ( format ) {
			display = format( value );
		} else {
			display = typeof value === 'number' ? formatNumber( value ) : value;
		}
	}

	let deltaEl = null;
	if ( ! missing && delta && Number.isFinite( Number( delta.value ) ) ) {
		const d = describeDelta( Number( delta.value ), delta.betterWhen );
		const magnitude = formatNumber( Math.abs( Number( delta.value ) ) );
		let toneWord = __( 'no change', 'seo-console' );
		if ( d.tone === 'good' ) {
			toneWord = __( 'improvement', 'seo-console' );
		} else if ( d.tone === 'poor' ) {
			toneWord = __( 'decline', 'seo-console' );
		} else if ( Number( delta.value ) ) {
			toneWord = __( 'change', 'seo-console' );
		}
		deltaEl = (
			<span className={ `seoc-delta seoc-delta--${ d.tone }` }>
				<span aria-hidden="true">{ d.arrow }</span> { d.sign }
				{ magnitude }
				{ unit ? ` ${ unit }` : '' }
				<span className="screen-reader-text">{ ` (${ toneWord })` }</span>
				{ delta.label && (
					<span className="seoc-delta__label">{ ` ${ delta.label }` }</span>
				) }
			</span>
		);
	}

	return (
		<div className="seoc-metric">
			<div className="seoc-metric__label">
				{ href ? <a href={ href }>{ label }</a> : label }
				{ help && (
					<span
						className="seoc-help"
						title={ help }
						aria-label={ sprintf(
							/* translators: %s: definition */
							__( 'Definition: %s', 'seo-console' ),
							help
						) }
						role="img"
					>
						?
					</span>
				) }
			</div>
			<div className="seoc-metric__value">
				<span className="seoc-metric__number">{ display }</span>
				{ ! missing && unit && (
					<span className="seoc-metric__unit">{ unit }</span>
				) }
			</div>
			<div className="seoc-metric__meta">
				{ ! missing && status && (
					<StatusBadge status={ status } label={ statusLabel } />
				) }
				{ deltaEl }
				{ missing && <DataHonesty kind="none" /> }
				{ ! missing && dataKind && (
					<DataHonesty kind={ dataKind } since={ since } />
				) }
			</div>
			{ footnote && (
				<div className="seoc-metric__footnote">{ footnote }</div>
			) }
		</div>
	);
}
