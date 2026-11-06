import {
	buildChartModel,
	niceTicks,
	segmentPath,
	spread,
	toTime,
} from '../../src/admin/shared/utils/chart';

const opts = {
	width: 500,
	height: 200,
	padding: { top: 10, right: 10, bottom: 30, left: 40 },
};

describe( 'toTime', () => {
	it( 'reads dates as UTC', () => {
		expect( toTime( '2026-11-01' ) ).toBe( Date.UTC( 2026, 10, 1 ) );
		expect( toTime( '2026-11-01 12:30:00' ) ).toBe(
			Date.UTC( 2026, 10, 1, 12, 30 )
		);
		expect( toTime( 5 ) ).toBe( 5 );
		expect( toTime( new Date( 7 ) ) ).toBe( 7 );
	} );

	it( 'returns null for junk', () => {
		expect( toTime( '' ) ).toBeNull();
		expect( toTime( 'nope' ) ).toBeNull();
		expect( toTime( null ) ).toBeNull();
		expect( toTime( NaN ) ).toBeNull();
	} );
} );

describe( 'niceTicks', () => {
	it( 'produces round steps covering the range', () => {
		const r = niceTicks( 0.3, 4.7, 5 );
		expect( r.min ).toBeLessThanOrEqual( 0.3 );
		expect( r.max ).toBeGreaterThanOrEqual( 4.7 );
		expect( r.ticks ).toEqual( [ 0, 2, 4, 6 ] );
	} );

	it( 'handles a flat series', () => {
		const r = niceTicks( 2, 2, 5 );
		expect( r.min ).toBeLessThan( 2 );
		expect( r.max ).toBeGreaterThan( 2 );
	} );

	it( 'handles zero-only data', () => {
		const r = niceTicks( 0, 0, 5 );
		expect( r.min ).toBe( 0 );
		expect( r.max ).toBeGreaterThan( 0 );
	} );
} );

describe( 'spread', () => {
	it( 'keeps first and last', () => {
		expect( spread( [ 1, 2, 3, 4, 5, 6, 7 ], 3 ) ).toEqual( [ 1, 4, 7 ] );
		expect( spread( [ 1, 2 ], 5 ) ).toEqual( [ 1, 2 ] );
	} );
} );

describe( 'buildChartModel', () => {
	it( 'maps the first and last dates to the plot edges and values to y', () => {
		const m = buildChartModel( {
			...opts,
			yMin: 0,
			yMax: 4,
			series: [
				{
					id: 'lcp',
					points: [
						{ date: '2026-11-01', value: 0 },
						{ date: '2026-11-03', value: 4 },
					],
				},
			],
		} );
		const pts = m.series[ 0 ].points;
		expect( pts[ 0 ].x ).toBe( 40 );
		expect( pts[ 1 ].x ).toBe( 490 );
		expect( pts[ 0 ].y ).toBe( 170 ); // bottom of plot
		expect( pts[ 1 ].y ).toBe( 10 ); // top of plot
		expect( m.y( 2 ) ).toBe( 90 );
		expect( m.empty ).toBe( false );
	} );

	it( 'breaks lines at missing values instead of drawing zero', () => {
		const m = buildChartModel( {
			...opts,
			series: [
				{
					id: 's',
					points: [
						{ date: '2026-11-01', value: 2 },
						{ date: '2026-11-02', value: 3 },
						{ date: '2026-11-03', value: null },
						{ date: '2026-11-04', value: 2.5 },
						{ date: '2026-11-05', value: 2 },
					],
				},
			],
		} );
		const s = m.series[ 0 ];
		expect( s.segments ).toHaveLength( 2 );
		expect( s.gaps ).toBe( 1 );
		expect( s.points.map( ( p ) => p.v ) ).toEqual( [ 2, 3, 2.5, 2 ] );
	} );

	it( 'includes thresholds in the y domain', () => {
		const m = buildChartModel( {
			...opts,
			thresholds: [ { value: 4 } ],
			series: [
				{ id: 's', points: [ { date: '2026-11-01', value: 1 } ] },
			],
		} );
		expect( m.domain.yMax ).toBeGreaterThanOrEqual( 4 );
		expect( m.domain.yMin ).toBeLessThanOrEqual( 1 );
	} );

	it( 'sorts points by date and handles a single date', () => {
		const m = buildChartModel( {
			...opts,
			series: [
				{
					id: 's',
					points: [
						{ date: '2026-11-03', value: 1 },
						{ date: '2026-11-01', value: 2 },
					],
				},
				{ id: 'one', points: [ { date: '2026-11-02', value: 1 } ] },
			],
		} );
		expect( m.series[ 0 ].points[ 0 ].v ).toBe( 2 );
		const single = buildChartModel( {
			...opts,
			series: [
				{ id: 'x', points: [ { date: '2026-11-02', value: 5 } ] },
			],
		} );
		expect( single.series[ 0 ].points[ 0 ].x ).toBeCloseTo( 265, 0 );
	} );

	it( 'reports empty when there are no values', () => {
		const m = buildChartModel( {
			...opts,
			series: [
				{ id: 's', points: [ { date: '2026-11-01', value: null } ] },
			],
		} );
		expect( m.empty ).toBe( true );
		expect( buildChartModel( { ...opts, series: [] } ).empty ).toBe( true );
	} );

	it( 'clamps values outside a forced domain', () => {
		const m = buildChartModel( {
			...opts,
			yMin: 0,
			yMax: 1,
			series: [
				{ id: 's', points: [ { date: '2026-11-01', value: 9 } ] },
			],
		} );
		expect( m.series[ 0 ].points[ 0 ].y ).toBe( 10 );
	} );
} );

describe( 'segmentPath', () => {
	it( 'draws M then L commands', () => {
		expect(
			segmentPath( [
				{ x: 1, y: 2 },
				{ x: 3, y: 4 },
			] )
		).toBe( 'M1 2 L3 4' );
	} );
} );
