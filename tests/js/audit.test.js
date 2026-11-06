/**
 * Render smoke tests for the Site audit page parts (REST mocked through apiFetch).
 */
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock(
	'@wordpress/components',
	() => {
		const stubs = require( './stubs/components' );
		const { createElement } = require( '@wordpress/element' );
		return {
			...stubs,
			Modal: ( { title, children } ) =>
				createElement(
					'div',
					{ role: 'dialog', 'aria-label': title },
					children
				),
		};
	},
	{ virtual: true }
);
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
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import Overview from '../../src/admin/audit/Overview';
import IssuesTab from '../../src/admin/audit/IssuesTab';
import { phaseSentence, progressShare } from '../../src/admin/audit/labels';
import { getConfig } from '../../src/admin/shared/config';

global.IS_REACT_ACT_ENVIRONMENT = true;

let container;
let root;

async function flush() {
	for ( let i = 0; i < 6; i++ ) {
		await act( async () => {
			await Promise.resolve();
		} );
	}
}

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

function routes( handlers ) {
	apiFetch.mockImplementation( ( opts ) => {
		for ( const [ match, fn ] of handlers ) {
			if ( opts.path.includes( match ) ) {
				return Promise.resolve( fn( opts ) );
			}
		}
		return Promise.reject( { code: 'rest_no_route', message: 'No route' } );
	} );
}

const summary = {
	score: 72,
	by_severity: { error: 3, warning: 5, notice: 2 },
	by_category: {
		content: { error: 1, warning: 4, notice: 0, total: 5 },
		links: { error: 2, warning: 1, notice: 2, total: 5 },
	},
	by_rule: {
		title_missing: {
			count: 1,
			severity: 'error',
			category: 'content',
			title: 'Missing or empty title',
			fixable_by: 'ai',
		},
		broken_internal_links: {
			count: 2,
			severity: 'error',
			category: 'links',
			title: 'Links to broken pages',
			fixable_by: 'user',
		},
	},
	top_issues: [
		{
			rule_id: 'broken_internal_links',
			count: 2,
			severity: 'error',
			title: 'Links to broken pages',
		},
	],
};

beforeEach( () => {
	apiFetch.mockReset();
	window.seocConfig = {
		restNamespace: 'seo-console/v1',
		adminUrl: '/wp-admin/',
		modules: { meta: true, audit: true },
		aiConfigured: true,
	};
	getConfig( true );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

describe( 'Audit overview', () => {
	it( 'shows score, counts, top issues and sends AI fixes', async () => {
		const posted = [];
		routes( [
			[
				'audit/rules',
				() => ( {
					rules: {
						broken_internal_links: {
							why: 'Visitors hit an error page.',
							how_to_fix: 'Edit the page.',
							fixable_by: 'user',
						},
					},
				} ),
			],
			[
				'audit/ai-fixes',
				( opts ) => {
					posted.push( opts.data );
					return {
						queued: 1,
						fields: { title: 1 },
						suggestions_url: '/wp-admin/x',
					};
				},
			],
		] );
		const notices = [];
		const el = await render(
			<Overview
				detail={ {
					run: {
						id: 7,
						score: 72,
						pages_crawled: 40,
						pages_found: 55,
					},
					summary,
					diff: {
						score_change: 4,
						new: {
							count: 1,
							items: [ { rule_id: 'x', url: 'u' } ],
						},
						fixed: { count: 2, items: [] },
						open: { count: 3, items: [] },
					},
				} }
				runs={ [] }
				onNotice={ ( status, message ) =>
					notices.push( [ status, message ] )
				}
				openTab={ () => {} }
			/>
		);
		expect( el.textContent ).toContain( 'Health score' );
		expect( el.textContent ).toContain( '72' );
		expect( el.textContent ).toContain( 'Links to broken pages' );
		expect( el.textContent ).toContain( 'Visitors hit an error page.' );
		expect( el.textContent ).toContain( 'issues fixed' );

		const button = Array.from( el.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Get AI suggestions' )
		);
		await act( async () => {
			button.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );
		await flush();
		expect( posted ).toEqual( [ { run_id: 7, rule_ids: [] } ] );
		expect( notices[ 0 ][ 0 ] ).toBe( 'success' );
	} );
} );

describe( 'Audit issues tab', () => {
	it( 'lists rule groups and expands one', async () => {
		routes( [
			[
				'rule_id=thin_content',
				() => ( {
					items: [
						{
							id: 1,
							url: 'https://example.org/thin/',
							edit_link: '/wp-admin/post.php?post=3',
							data: { words: 40, minimum: 200 },
						},
					],
					total: 1,
				} ),
			],
			[
				'group=1',
				() => ( {
					groups: [
						{
							rule_id: 'thin_content',
							severity: 'warning',
							category: 'content',
							title: 'Thin content',
							why: 'Short pages rarely rank.',
							how_to_fix: 'Add useful content.',
							fixable_by: 'user',
							scope: 'page',
							count: 1,
						},
					],
					total: 1,
				} ),
			],
		] );
		const el = await render( <IssuesTab runId={ 7 } /> );
		expect( el.textContent ).toContain( 'Thin content' );
		const show = Array.from( el.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Show details' )
		);
		await act( async () => {
			show.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );
		await flush();
		expect( el.textContent ).toContain( 'Add useful content.' );
		expect( el.textContent ).toContain( '40 words' );
		expect(
			el.querySelector( 'a[href="/wp-admin/post.php?post=3"]' )
		).not.toBeNull();
	} );
} );

describe( 'Audit labels', () => {
	it( 'describes progress', () => {
		const crawl = {
			phase: 'crawl',
			pages_crawled: 10,
			pages_found: 40,
			max_pages: 500,
			done: false,
		};
		expect( phaseSentence( crawl ) ).toContain( 'Step 2 of 3' );
		expect( progressShare( crawl ) ).toBeCloseTo( 0.25 );
		expect(
			progressShare( { phase: 'finalize', checked: 5, to_check: 10 } )
		).toBeCloseTo( 0.925 );
		expect( progressShare( { done: true } ) ).toBe( 1 );
	} );
} );
