/**
 * Pure scaling helpers for TrendChart (no DOM, no WordPress imports).
 */

const DAY = 86400000;

/**
 * Convert a date-ish value to a UTC timestamp (ms). "YYYY-MM-DD" is read as UTC midnight;
 * "YYYY-MM-DD HH:MM:SS" (MySQL, UTC per §7.2) as UTC.
 *
 * @param {Date|number|string} value Date value.
 * @return {number|null} Timestamp or null when invalid.
 */
export function toTime( value ) {
	if ( value instanceof Date ) {
		const t = value.getTime();
		return Number.isFinite( t ) ? t : null;
	}
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) ? value : null;
	}
	if ( typeof value !== 'string' || ! value ) {
		return null;
	}
	let s = value.trim();
	if ( /^\d{4}-\d{2}-\d{2}$/.test( s ) ) {
		s += 'T00:00:00Z';
	} else if ( /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test( s ) ) {
		s = s.replace( ' ', 'T' ) + 'Z';
	}
	const t = Date.parse( s );
	return Number.isFinite( t ) ? t : null;
}

function niceStep( rough ) {
	const exp = Math.floor( Math.log10( rough ) );
	const base = Math.pow( 10, exp );
	const f = rough / base;
	let nice = 10;
	if ( f <= 1 ) {
		nice = 1;
	} else if ( f <= 2 ) {
		nice = 2;
	} else if ( f <= 2.5 ) {
		nice = 2.5;
	} else if ( f <= 5 ) {
		nice = 5;
	}
	return nice * base;
}

/**
 * "Nice" axis ticks covering [min, max].
 *
 * @param {number} min   Minimum.
 * @param {number} max   Maximum.
 * @param {number} count Approximate number of ticks.
 * @return {{ticks: number[], min: number, max: number}} Ticks and the widened domain.
 */
export function niceTicks( min, max, count = 5 ) {
	if ( ! Number.isFinite( min ) || ! Number.isFinite( max ) ) {
		return { ticks: [ 0, 1 ], min: 0, max: 1 };
	}
	if ( min === max ) {
		const pad = min === 0 ? 1 : Math.abs( min ) * 0.1;
		min -= pad;
		max += pad;
		if ( min < 0 && min + pad >= 0 ) {
			min = 0;
		}
	}
	const step = niceStep( ( max - min ) / Math.max( 1, count - 1 ) );
	const lo = Math.floor( min / step ) * step;
	const hi = Math.ceil( max / step ) * step;
	const ticks = [];
	for ( let v = lo; v <= hi + step / 2; v += step ) {
		ticks.push( Number( v.toFixed( 10 ) ) );
	}
	return { ticks, min: lo, max: hi };
}

/**
 * Pick up to `max` evenly spread items (always keeps first and last).
 *
 * @param {Array}  list Items.
 * @param {number} max  Max items.
 * @return {Array} Items.
 */
export function spread( list, max ) {
	if ( list.length <= max ) {
		return list.slice();
	}
	const out = [];
	const step = ( list.length - 1 ) / ( max - 1 );
	for ( let i = 0; i < max; i++ ) {
		out.push( list[ Math.round( i * step ) ] );
	}
	return out;
}

/**
 * Build everything TrendChart needs to draw: scales, ticks and path segments.
 * Null / missing values break the line (missing is never zero).
 *
 * @param {Object} opts
 * @param {Array}  opts.series     [ { id, points: [ { date, value } ] } ].
 * @param {Array}  opts.thresholds [ { value } ] (included in the y domain).
 * @param {number} opts.width      Total width (px, viewBox units).
 * @param {number} opts.height     Total height.
 * @param {Object} opts.padding    { top, right, bottom, left }.
 * @param {number} opts.yMin       Force a y minimum (e.g. 0).
 * @param {number} opts.yMax       Force a y maximum.
 * @return {Object} Model: { empty, x, y, xTicks, yTicks, series: [ { id, segments, points } ], plot }.
 */
export function buildChartModel( {
	series = [],
	thresholds = [],
	width = 640,
	height = 240,
	padding = { top: 12, right: 16, bottom: 28, left: 48 },
	yMin,
	yMax,
} = {} ) {
	const plot = {
		left: padding.left,
		top: padding.top,
		right: width - padding.right,
		bottom: height - padding.bottom,
	};
	plot.width = Math.max( 1, plot.right - plot.left );
	plot.height = Math.max( 1, plot.bottom - plot.top );

	const clean = series.map( ( s ) => ( {
		...s,
		points: ( s.points || [] )
			.map( ( p ) => ( {
				...p,
				t: toTime( p.date ),
				v:
					p.value === null || p.value === undefined || p.value === ''
						? null
						: Number( p.value ),
			} ) )
			.filter( ( p ) => p.t !== null )
			.map( ( p ) => ( {
				...p,
				v: Number.isFinite( p.v ) ? p.v : null,
			} ) )
			.sort( ( a, b ) => a.t - b.t ),
	} ) );

	const times = [];
	const values = [];
	clean.forEach( ( s ) =>
		s.points.forEach( ( p ) => {
			times.push( p.t );
			if ( p.v !== null ) {
				values.push( p.v );
			}
		} )
	);
	const empty = values.length === 0;

	thresholds.forEach( ( th ) => {
		if ( Number.isFinite( Number( th.value ) ) ) {
			values.push( Number( th.value ) );
		}
	} );

	let tMin = times.length ? Math.min( ...times ) : 0;
	let tMax = times.length ? Math.max( ...times ) : DAY;
	if ( tMin === tMax ) {
		tMin -= DAY / 2;
		tMax += DAY / 2;
	}
	const rawMin = Number.isFinite( yMin )
		? yMin
		: Math.min( ...( values.length ? values : [ 0 ] ) );
	const rawMax = Number.isFinite( yMax )
		? yMax
		: Math.max( ...( values.length ? values : [ 1 ] ) );
	const nice = niceTicks( rawMin, rawMax, 5 );
	const dMin = Number.isFinite( yMin ) ? yMin : nice.min;
	const dMax = Number.isFinite( yMax ) ? yMax : nice.max;
	const yTicks = nice.ticks.filter(
		( v ) => v >= dMin - 1e-9 && v <= dMax + 1e-9
	);

	const x = ( t ) =>
		plot.left + ( ( t - tMin ) / ( tMax - tMin ) ) * plot.width;
	const y = ( v ) => {
		const span = dMax - dMin || 1;
		const clamped = Math.min( dMax, Math.max( dMin, v ) );
		return plot.bottom - ( ( clamped - dMin ) / span ) * plot.height;
	};

	const uniqueTimes = Array.from( new Set( times ) ).sort(
		( a, b ) => a - b
	);
	const xTicks = spread(
		uniqueTimes,
		Math.max( 2, Math.floor( plot.width / 90 ) )
	);

	const round = ( n ) => Math.round( n * 10 ) / 10;
	const outSeries = clean.map( ( s ) => {
		const segments = [];
		let current = [];
		s.points.forEach( ( p ) => {
			if ( p.v === null ) {
				if ( current.length ) {
					segments.push( current );
				}
				current = [];
				return;
			}
			current.push( {
				x: round( x( p.t ) ),
				y: round( y( p.v ) ),
				t: p.t,
				v: p.v,
			} );
		} );
		if ( current.length ) {
			segments.push( current );
		}
		return {
			id: s.id,
			segments,
			points: segments.flat(),
			gaps: s.points.filter( ( p ) => p.v === null ).length,
		};
	} );

	return {
		empty,
		x,
		y,
		xTicks,
		yTicks,
		domain: { tMin, tMax, yMin: dMin, yMax: dMax },
		series: outSeries,
		plot,
	};
}

/**
 * SVG path data for one segment ("M x y L x y …").
 *
 * @param {Array} points [ { x, y } ].
 * @return {string} Path.
 */
export function segmentPath( points ) {
	return points
		.map( ( p, i ) => `${ i ? 'L' : 'M' }${ p.x } ${ p.y }` )
		.join( ' ' );
}
