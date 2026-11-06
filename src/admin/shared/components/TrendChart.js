/**
 * Lightweight inline-SVG line chart: several series over dates, optional threshold lines and bands
 * (e.g. Core Web Vitals "good" / "poor" limits), event markers, legend and a data-table alternative.
 * Missing values break the line; they are never drawn as zero.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { buildChartModel, segmentPath, toTime } from '../utils/chart';
import { formatDate, formatNumber } from '../utils/format';
import EmptyState from './EmptyState';

const DASHES = [ '', '6 4', '2 3', '10 3 2 3', '1 5', '12 6' ];
let chartSeq = 0;

function useWidth( fallback = 640 ) {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( fallback );
	useEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return undefined;
		}
		const measure = () => {
			const w = Math.round( el.getBoundingClientRect().width );
			if ( w > 0 ) {
				setWidth( w );
			}
		};
		measure();
		if ( typeof window.ResizeObserver === 'undefined' ) {
			window.addEventListener( 'resize', measure );
			return () => window.removeEventListener( 'resize', measure );
		}
		const ro = new window.ResizeObserver( measure );
		ro.observe( el );
		return () => ro.disconnect();
	}, [] );
	return [ ref, width ];
}

/**
 * @param {Object}   props
 * @param {string}   props.title        Chart title (required: used as the SVG's accessible name).
 * @param {string}   props.description  One plain sentence saying what a line shows (shown under the chart).
 * @param {Array}    props.series       [ { id, label, points: [ { date, value|null } ], dashed? } ].
 * @param {Array}    props.thresholds   [ { value, label, tone: 'good'|'needs-work'|'poor' } ] dashed lines.
 * @param {Array}    props.bands        [ { from, to, tone, label } ] tinted y-ranges.
 * @param {Array}    props.markers      [ { date, label } ] vertical event markers (e.g. "Applied 3 changes").
 * @param {number}   props.yMin         Force y minimum (e.g. 0).
 * @param {number}   props.yMax         Force y maximum.
 * @param {string}   props.unit         Unit appended to values ("s", "ms").
 * @param {Function} props.formatValue  Value formatter for axis + tooltips.
 * @param {number}   props.height       Height in px (default 240).
 * @param {Node}     props.footnote     Small line under the chart (source, time zone).
 * @param {Node}     props.emptyMessage Shown when no series has a value.
 * @return {JSX.Element} Chart.
 */
export default function TrendChart( {
	title,
	description,
	series = [],
	thresholds = [],
	bands = [],
	markers = [],
	yMin,
	yMax,
	unit = '',
	formatValue,
	height = 240,
	footnote,
	emptyMessage,
} ) {
	const [ wrapRef, width ] = useWidth();
	const [ ids ] = useState( () => {
		chartSeq += 1;
		return {
			title: `seoc-chart-t-${ chartSeq }`,
			desc: `seoc-chart-d-${ chartSeq }`,
		};
	} );

	const fmt = ( v ) => {
		const s = formatValue
			? formatValue( v )
			: formatNumber( v, { maximumFractionDigits: 2 } );
		return unit ? `${ s } ${ unit }` : s;
	};
	const bandValues = bands.flatMap( ( b ) => [
		{ value: b.from },
		{ value: b.to },
	] );
	const model = buildChartModel( {
		series,
		thresholds: [ ...thresholds, ...bandValues ],
		width,
		height,
		yMin,
		yMax,
	} );
	const { plot } = model;

	if ( model.empty ) {
		return (
			<div className="seoc-chart" ref={ wrapRef }>
				<EmptyState
					icon="chart-line"
					title={ __( 'No data yet', 'seo-console' ) }
				>
					{ emptyMessage ||
						__(
							'This chart fills in after the first measurements are saved.',
							'seo-console'
						) }
				</EmptyState>
			</div>
		);
	}

	const seriesDesc = series
		.map( ( s, i ) => {
			const pts = model.series[ i ].points;
			if ( ! pts.length ) {
				return sprintf(
					/* translators: %s: series name */
					__( '%s: no data.', 'seo-console' ),
					s.label
				);
			}
			const last = pts[ pts.length - 1 ];
			return sprintf(
				/* translators: 1: series name, 2: latest value, 3: date */
				__( '%1$s: latest %2$s on %3$s.', 'seo-console' ),
				s.label,
				fmt( last.v ),
				formatDate( last.t, false )
			);
		} )
		.join( ' ' );

	const tableDates = Array.from(
		new Set(
			series.flatMap( ( s ) =>
				( s.points || [] )
					.map( ( p ) => toTime( p.date ) )
					.filter( ( t ) => t !== null )
			)
		)
	).sort( ( a, b ) => a - b );

	return (
		<div className="seoc-chart" ref={ wrapRef }>
			<svg
				className="seoc-chart__svg"
				width={ width }
				height={ height }
				viewBox={ `0 0 ${ width } ${ height }` }
				role="img"
				aria-labelledby={ `${ ids.title } ${ ids.desc }` }
			>
				<title id={ ids.title }>{ title }</title>
				<desc id={ ids.desc }>
					{ [ description, seriesDesc ]
						.filter( Boolean )
						.join( ' ' ) }
				</desc>

				{ bands.map( ( b, i ) => {
					const y1 = model.y( Math.max( b.from, b.to ) );
					const y2 = model.y( Math.min( b.from, b.to ) );
					return (
						<rect
							key={ `band-${ i }` }
							className={ `seoc-chart__band seoc-tone--${
								b.tone || 'unknown'
							}` }
							x={ plot.left }
							y={ y1 }
							width={ plot.width }
							height={ Math.max( 0, y2 - y1 ) }
						/>
					);
				} ) }

				{ model.yTicks.map( ( v ) => (
					<g key={ `y-${ v }` }>
						<line
							className="seoc-chart__grid"
							x1={ plot.left }
							x2={ plot.right }
							y1={ model.y( v ) }
							y2={ model.y( v ) }
						/>
						<text
							className="seoc-chart__tick"
							x={ plot.left - 6 }
							y={ model.y( v ) }
							textAnchor="end"
							dominantBaseline="middle"
						>
							{ formatValue
								? formatValue( v )
								: formatNumber( v, {
										maximumFractionDigits: 2,
								  } ) }
						</text>
					</g>
				) ) }

				{ model.xTicks.map( ( t ) => (
					<text
						key={ `x-${ t }` }
						className="seoc-chart__tick"
						x={ model.x( t ) }
						y={ plot.bottom + 18 }
						textAnchor="middle"
					>
						{ formatDate( t, false ) }
					</text>
				) ) }

				{ thresholds.map( ( th, i ) => (
					<g
						key={ `th-${ i }` }
						className={ `seoc-tone--${ th.tone || 'unknown' }` }
					>
						<line
							className="seoc-chart__threshold"
							x1={ plot.left }
							x2={ plot.right }
							y1={ model.y( th.value ) }
							y2={ model.y( th.value ) }
						/>
						{ th.label && (
							<text
								className="seoc-chart__threshold-label"
								x={ plot.right - 4 }
								y={ model.y( th.value ) - 4 }
								textAnchor="end"
							>
								{ th.label }
							</text>
						) }
					</g>
				) ) }

				{ markers.map( ( m, i ) => {
					const t = toTime( m.date );
					if (
						t === null ||
						t < model.domain.tMin ||
						t > model.domain.tMax
					) {
						return null;
					}
					return (
						<g key={ `m-${ i }` } className="seoc-chart__marker">
							<line
								x1={ model.x( t ) }
								x2={ model.x( t ) }
								y1={ plot.top }
								y2={ plot.bottom }
							/>
							<circle
								cx={ model.x( t ) }
								cy={ plot.top + 4 }
								r="4"
							>
								<title>{ `${ formatDate( t, false ) }: ${
									m.label
								}` }</title>
							</circle>
						</g>
					);
				} ) }

				{ model.series.map( ( s, i ) => {
					const src = series[ i ];
					const dash =
						src.dashed === true
							? '6 4'
							: DASHES[ i % DASHES.length ];
					return (
						<g
							key={ s.id || i }
							className={ `seoc-series seoc-series--${
								( i % 6 ) + 1
							}` }
						>
							{ s.segments.map( ( seg, j ) =>
								seg.length > 1 ? (
									<path
										key={ j }
										className="seoc-chart__line"
										d={ segmentPath( seg ) }
										strokeDasharray={ dash || undefined }
									/>
								) : null
							) }
							{ s.points.map( ( p ) => (
								<circle
									key={ p.t }
									className="seoc-chart__dot"
									cx={ p.x }
									cy={ p.y }
									r={ s.points.length > 40 ? 2 : 3 }
								>
									<title>
										{ `${ src.label } · ${ formatDate(
											p.t,
											false
										) }: ${ fmt( p.v ) }` }
									</title>
								</circle>
							) ) }
						</g>
					);
				} ) }
			</svg>

			<ul className="seoc-chart__legend">
				{ series.map( ( s, i ) => (
					<li
						key={ s.id || i }
						className={ `seoc-series seoc-series--${
							( i % 6 ) + 1
						}` }
					>
						<svg
							width="28"
							height="10"
							aria-hidden="true"
							focusable="false"
						>
							<line
								className="seoc-chart__line"
								x1="1"
								x2="27"
								y1="5"
								y2="5"
								strokeDasharray={
									( s.dashed === true
										? '6 4'
										: DASHES[ i % DASHES.length ] ) ||
									undefined
								}
							/>
						</svg>
						{ s.label }
						{ model.series[ i ].gaps > 0 && (
							<span className="seoc-muted">
								{ ' ' }
								{ sprintf(
									/* translators: %d: number of days without data */
									__(
										'(%d missing — shown as gaps)',
										'seo-console'
									),
									model.series[ i ].gaps
								) }
							</span>
						) }
					</li>
				) ) }
				{ [ ...thresholds, ...bands ]
					.filter( ( x ) => x.label )
					.map( ( x, i ) => (
						<li
							key={ `legend-th-${ i }` }
							className={ `seoc-tone--${ x.tone || 'unknown' }` }
						>
							<span
								className="seoc-chart__legend-band"
								aria-hidden="true"
							/>
							{ x.label }
						</li>
					) ) }
				{ markers.length > 0 && (
					<li className="seoc-chart__marker">
						<span
							className="seoc-chart__legend-marker"
							aria-hidden="true"
						/>
						{ __(
							'Events (hover a dot for details)',
							'seo-console'
						) }
					</li>
				) }
			</ul>

			{ description && (
				<p className="seoc-chart__description">{ description }</p>
			) }
			{ footnote && <p className="seoc-source-line">{ footnote }</p> }

			<details className="seoc-chart__table">
				<summary>
					{ __( 'Show the data as a table', 'seo-console' ) }
				</summary>
				<div className="seoc-table-scroll">
					<table className="widefat striped">
						<caption className="screen-reader-text">
							{ title }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Date', 'seo-console' ) }
								</th>
								{ series.map( ( s, i ) => (
									<th scope="col" key={ s.id || i }>
										{ s.label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ tableDates.map( ( t ) => (
								<tr key={ t }>
									<th scope="row">
										{ formatDate( t, false ) }
									</th>
									{ model.series.map( ( s, i ) => {
										const p = s.points.find(
											( pt ) => pt.t === t
										);
										return (
											<td
												key={ s.id || i }
												className="seoc-num"
											>
												{ p
													? fmt( p.v )
													: __(
															'No data',
															'seo-console'
													  ) }
											</td>
										);
									} ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			</details>
		</div>
	);
}
