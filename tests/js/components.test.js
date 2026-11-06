/**
 * Render smoke tests for the shared components and pages. The WordPress packages "components",
 * "api-fetch" and "date" are stubbed: WordPress provides them at runtime and they are not installed here.
 */
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock( '@wordpress/components', () => require( './stubs/components' ), {
	virtual: true,
} );
jest.mock(
	'@wordpress/date',
	() => ( {
		dateI18n: ( format, date ) =>
			new Date( date ).toISOString().slice( 0, 10 ),
		humanTimeDiff: () => '2 days ago',
		getSettings: () => ( { timezone: { abbr: 'UTC', offset: 0 } } ),
	} ),
	{ virtual: true }
);

import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
// React is installed by @wordpress/scripts; `act` is not re-exported by @wordpress/element.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import ChangeLogTable from '../../src/admin/shared/components/ChangeLogTable';
import SuggestionList from '../../src/admin/shared/components/SuggestionList';
import TrendChart from '../../src/admin/shared/components/TrendChart';
import SerpPreview from '../../src/admin/shared/components/SerpPreview';
import MetricTile from '../../src/admin/shared/components/MetricTile';
import { getConfig } from '../../src/admin/shared/config';

global.IS_REACT_ACT_ENVIRONMENT = true;

let container;
let root;

async function render( element ) {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( element );
	} );
	await flush();
	return container;
}

async function flush() {
	for ( let i = 0; i < 5; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

async function click( el ) {
	await act( async () => {
		el.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );
	} );
	await flush();
}

function button( text ) {
	return Array.from( container.querySelectorAll( 'button' ) ).find(
		( b ) => b.textContent.trim() === text
	);
}

function routes( handlers ) {
	apiFetch.mockImplementation( ( opts ) => {
		for ( const [ match, fn ] of handlers ) {
			if ( opts.path.includes( match ) ) {
				return fn( opts );
			}
		}
		return Promise.reject( { code: 'rest_no_route', message: 'No route' } );
	} );
}

const suggestion = ( over = {} ) => ( {
	id: 1,
	created_at: '2026-11-01 10:00:00',
	module: 'meta',
	type: 'seo_meta',
	object_type: 'post',
	object_id: 12,
	object_title: 'Coffee grinders',
	edit_link: '/wp-admin/post.php?post=12&action=edit',
	view_link: 'https://example.com/coffee-grinders/',
	field: 'title',
	field_label: 'SEO title',
	current_value: 'Grinders',
	proposed_value: 'Best Coffee Grinders of 2026, Tested',
	rationale: 'Adds the focus keyword.',
	confidence: 0.9,
	source: 'ai',
	model: 'claude-opus-5-5',
	status: 'pending',
	...over,
} );

beforeEach( () => {
	apiFetch.mockReset();
	window.seocConfig = {
		restNamespace: 'seo-console/v1',
		adminUrl: '/wp-admin/',
		modules: { meta: true, speed: false },
	};
	getConfig( true );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

describe( 'SuggestionList', () => {
	it( 'shows current vs proposed, counter and SERP preview, and approves an edit', async () => {
		const approve = jest.fn( () =>
			Promise.resolve( { change: { id: 9 } } )
		);
		routes( [
			[ '/suggestions/1/approve', approve ],
			[
				'/suggestions',
				() => Promise.resolve( { items: [ suggestion() ], total: 1 } ),
			],
		] );
		const onChanged = jest.fn();
		await render( <SuggestionList onChanged={ onChanged } /> );

		expect( container.textContent ).toContain( 'Coffee grinders' );
		expect( container.textContent ).toContain( 'Grinders' );
		expect( container.textContent ).toContain( 'recommended 30–60' );
		expect(
			container.querySelector( '.seoc-serp__title' ).textContent
		).toBe( 'Best Coffee Grinders of 2026, Tested' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
			'status=pending'
		);

		const input = container.querySelector( '#seoc-sugg-1' );
		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set;
			setter.call( input, 'My own better title for coffee grinders' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		expect( button( 'Approve my edit' ) ).toBeTruthy();
		await click( button( 'Approve my edit' ) );

		expect( approve ).toHaveBeenCalledTimes( 1 );
		expect( approve.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			value: 'My own better title for coffee grinders',
		} );
		expect( container.textContent ).toContain( 'Applied. You can undo it' );
		expect( onChanged ).toHaveBeenCalled();
	} );

	it( 'offers "Apply anyway" on seoc_stale and sends force', async () => {
		const approve = jest
			.fn()
			.mockImplementationOnce( () =>
				Promise.reject( {
					code: 'seoc_stale',
					message: 'Changed',
					data: {
						status: 409,
						current_value: 'Someone else edited this',
					},
				} )
			)
			.mockImplementationOnce( () => Promise.resolve( {} ) );
		routes( [
			[ '/suggestions/1/approve', approve ],
			[
				'/suggestions',
				() => Promise.resolve( { items: [ suggestion() ], total: 1 } ),
			],
		] );
		await render( <SuggestionList /> );
		await click( button( 'Approve' ) );

		expect( container.textContent ).toContain(
			'The value on the site has changed.'
		);
		expect( container.textContent ).toContain( 'Someone else edited this' );
		await click( button( 'Apply anyway' ) );
		expect( approve.mock.calls[ 1 ][ 0 ].data ).toEqual( { force: true } );
		expect( container.textContent ).toContain( 'Applied.' );
	} );

	it( 'explains an automatic rollback on seoc_health_failed', async () => {
		routes( [
			[
				'/suggestions/1/approve',
				() =>
					Promise.reject( {
						code: 'seoc_health_failed',
						message: 'Home page returned HTTP 500.',
						data: { status: 500 },
					} ),
			],
			[
				'/suggestions',
				() => Promise.resolve( { items: [ suggestion() ], total: 1 } ),
			],
		] );
		await render( <SuggestionList /> );
		await click( button( 'Approve' ) );
		expect( container.textContent ).toContain(
			'rolled back automatically'
		);
		expect( container.textContent ).toContain(
			'Home page returned HTTP 500.'
		);
	} );

	it( 'bulk approves selected rows and reports per-row problems', async () => {
		const bulk = jest.fn( () =>
			Promise.resolve( {
				results: [
					{ id: 1, ok: true },
					{
						id: 2,
						ok: false,
						code: 'seoc_stale',
						message: 'Changed',
					},
				],
			} )
		);
		routes( [
			[ '/suggestions/bulk', bulk ],
			[
				'/suggestions',
				() =>
					Promise.resolve( {
						items: [
							suggestion(),
							suggestion( {
								id: 2,
								field: 'alt',
								field_label: 'Alt text',
								proposed_value: { nested: true },
							} ),
						],
						total: 2,
					} ),
			],
		] );
		await render( <SuggestionList /> );
		expect( container.textContent ).toContain( '"nested": true' );

		const all = container.querySelector( '#seoc-select-all' );
		await click( all );
		expect( container.textContent ).toContain( '2 selected' );
		await click( button( 'Approve selected' ) );
		await click( button( 'Yes, apply' ) );

		expect( bulk.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			action: 'approve',
			ids: [ 1, 2 ],
		} );
		expect( container.textContent ).toContain(
			'1 applied. 1 needs your attention'
		);
		expect( container.textContent ).toContain( 'Apply anyway' );
	} );

	it( 'shows an empty state and a retryable error', async () => {
		routes( [
			[
				'/suggestions',
				() => Promise.resolve( { items: [], total: 0 } ),
			],
		] );
		await render( <SuggestionList /> );
		expect( container.textContent ).toContain(
			'Nothing waiting for review'
		);
		act( () => root.unmount() );
		container.remove();

		routes( [
			[ '/suggestions', () => Promise.reject( { code: 'fetch_error' } ) ],
		] );
		await render( <SuggestionList /> );
		expect( container.textContent ).toContain(
			'Could not load suggestions.'
		);
		expect( button( 'Try again' ) ).toBeTruthy();
	} );
} );

describe( 'ChangeLogTable', () => {
	const change = {
		id: 4,
		created_at: '2026-11-02 09:00:00',
		user_name: 'Anna',
		source: 'ai',
		module: 'meta',
		object_title: 'Coffee grinders',
		field: 'title',
		before_value: 'Old',
		after_value: 'New',
		status: 'applied',
	};

	it( 'undoes with confirmation and handles seoc_conflict with "Undo anyway"', async () => {
		const revert = jest
			.fn()
			.mockImplementationOnce( () =>
				Promise.reject( {
					code: 'seoc_conflict',
					message: 'Conflict',
					data: { status: 409, current_value: 'Edited by hand' },
				} )
			)
			.mockImplementationOnce( () => Promise.resolve( {} ) );
		routes( [
			[ '/changes/4/revert', revert ],
			[
				'/changes',
				() => Promise.resolve( { items: [ change ], total: 1 } ),
			],
		] );
		await render( <ChangeLogTable /> );
		expect( container.textContent ).toContain( 'Anna' );
		expect( container.textContent ).toContain( 'Old' );

		await click( button( 'Undo' ) );
		await click( button( 'Yes, undo' ) );
		expect( container.textContent ).toContain(
			'This value has changed since.'
		);
		expect( container.textContent ).toContain( 'Edited by hand' );

		await click( button( 'Undo anyway' ) );
		expect( revert.mock.calls[ 1 ][ 0 ].data ).toEqual( { force: true } );
		expect( container.textContent ).toContain(
			'Undone: Title for Coffee grinders.'
		);
	} );

	it( 'shows an empty state', async () => {
		routes( [
			[ '/changes', () => Promise.resolve( { items: [], total: 0 } ) ],
		] );
		await render( <ChangeLogTable compact perPage={ 5 } /> );
		expect( container.textContent ).toContain( 'No changes yet' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( 'per_page=5' );
	} );
} );

describe( 'display components', () => {
	it( 'TrendChart draws lines with gaps, thresholds and an accessible name', async () => {
		await render(
			<TrendChart
				title="LCP over time"
				description="Each line is the 75th percentile LCP."
				unit="s"
				series={ [
					{
						id: 'mobile',
						label: 'Mobile',
						points: [
							{ date: '2026-11-01', value: 3.1 },
							{ date: '2026-11-02', value: 2.9 },
							{ date: '2026-11-03', value: null },
							{ date: '2026-11-04', value: 2.4 },
							{ date: '2026-11-05', value: 2.2 },
						],
					},
				] }
				thresholds={ [
					{ value: 2.5, label: 'Good (≤ 2.5 s)', tone: 'good' },
					{ value: 4, label: 'Poor (> 4 s)', tone: 'poor' },
				] }
			/>
		);
		const svg = container.querySelector( 'svg[role="img"]' );
		expect( svg.querySelector( 'title' ).textContent ).toBe(
			'LCP over time'
		);
		expect( svg.querySelectorAll( 'path.seoc-chart__line' ) ).toHaveLength(
			2
		);
		expect( svg.querySelectorAll( '.seoc-chart__threshold' ) ).toHaveLength(
			2
		);
		expect( container.textContent ).toContain(
			'(1 missing — shown as gaps)'
		);
		expect( container.querySelectorAll( 'tbody tr' ) ).toHaveLength( 5 );
	} );

	it( 'TrendChart shows "No data yet" instead of zero', async () => {
		await render(
			<TrendChart
				title="Empty"
				series={ [
					{
						id: 'a',
						label: 'A',
						points: [ { date: '2026-11-01', value: null } ],
					},
				] }
			/>
		);
		expect( container.querySelector( 'svg' ) ).toBeNull();
		expect( container.textContent ).toContain( 'No data yet' );
	} );

	it( 'SerpPreview warns about truncation', async () => {
		await render(
			<SerpPreview
				url="https://example.com/a/b"
				title={
					'Very Long Title With Many Many Many Words That Google Will Definitely Cut Off Somewhere'
				}
			/>
		);
		expect( container.textContent ).toContain( 'will be cut off' );
		expect(
			container.querySelector( '.seoc-serp__title' ).textContent
		).toMatch( / \.\.\.$/ );
		expect( container.textContent ).toContain( 'example.com › a › b' );
	} );

	it( 'MetricTile never shows a missing value as zero', async () => {
		await render( <MetricTile label="LCP" value={ null } unit="s" /> );
		expect( container.textContent ).toContain( '—' );
		expect( container.textContent ).toContain( 'No data yet' );
		expect( container.textContent ).not.toContain( '0' );
	} );

	it( 'MetricTile shows an improvement when a lower value is better', async () => {
		await render(
			<MetricTile
				label="LCP"
				value={ 2.1 }
				unit="s"
				status="good"
				delta={ {
					value: -0.4,
					betterWhen: 'down',
					label: 'vs last week',
				} }
			/>
		);
		expect( container.querySelector( '.seoc-delta--good' ) ).not.toBeNull();
		expect( container.textContent ).toContain( '(improvement)' );
		expect( container.textContent ).toContain( 'Good' );
	} );
} );

describe( 'pages', () => {
	beforeEach( () => {
		document.body.innerHTML = '<div id="seoc-root"></div>';
		container = document.getElementById( 'seoc-root' );
		root = { unmount: () => {} };
	} );

	it( 'dashboard renders the first-run checklist with zero data', async () => {
		routes( [
			[ '/settings', () => Promise.resolve( {} ) ],
			[
				'/suggestions',
				() => Promise.resolve( { items: [], total: 0 } ),
			],
			[ '/changes', () => Promise.resolve( { items: [], total: 0 } ) ],
		] );
		await act( async () => {
			require( '../../src/admin/dashboard/index.js' );
		} );
		await flush();
		expect( container.textContent ).toContain( 'Get started' );
		expect( container.textContent ).toContain( 'Add your Claude API key' );
		expect( container.textContent ).toContain( 'No changes yet' );
		expect( container.textContent ).toContain( 'Turn on in Settings' );
	} );

	it( 'settings saves general settings and tests the AI key', async () => {
		const saved = jest.fn( ( opts ) =>
			Promise.resolve( {
				general: opts.data.general,
				modules: { meta: { enabled: true } },
			} )
		);
		const test = jest.fn( () =>
			Promise.resolve( { ok: true, model: 'claude-opus-5-5' } )
		);
		routes( [
			[ '/settings/test-ai', test ],
			[
				'/settings',
				( opts ) =>
					opts.method === 'POST'
						? saved( opts )
						: Promise.resolve( {
								general: {
									ai_model: 'claude-opus-5-5',
									ai_monthly_budget_usd: 20,
								},
								modules: { meta: { enabled: true } },
								module_info: {
									meta: {
										title: 'Meta',
										description: 'AI titles.',
									},
								},
								secrets: {
									claude_api_key: {
										has: true,
										masked: 'sk-ant-…3fA9',
										source: 'stored',
									},
									google_api_key: {
										has: true,
										masked: 'AIza…xy',
										source: 'constant',
									},
								},
						  } ),
			],
		] );
		await act( async () => {
			require( '../../src/admin/settings/index.js' );
		} );
		await flush();
		expect( container.textContent ).toContain( 'Best quality.' );

		const toggle = document.getElementById( 't-Safe-Mode' );
		await click( toggle );
		expect( container.textContent ).toContain( 'Unsaved changes' );
		await click( button( 'Save changes' ) );
		expect( saved.mock.calls[ 0 ][ 0 ].data.general.safe_mode ).toBe(
			true
		);
		expect( container.textContent ).toContain( 'Settings saved.' );
		expect( container.textContent ).toContain( 'Safe Mode is on.' );

		await click( button( 'API keys' ) );
		expect( container.textContent ).toContain( 'sk-ant-…3fA9' );
		expect( container.textContent ).toContain( 'SEOC_GOOGLE_API_KEY' );
		await click( button( 'Test connection' ) );
		expect( test.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			model: 'claude-opus-5-5',
		} );
		expect( container.textContent ).toContain( 'Connected.' );
	} );
} );
