/* @wordpress/api-fetch is provided by WordPress at runtime (not installed locally). */
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

import apiFetch from '@wordpress/api-fetch';

import {
	apiGet,
	apiPost,
	approveSuggestion,
	buildPath,
	getSuggestions,
	normaliseBulkResults,
	normaliseChange,
	normaliseError,
	normaliseList,
	normaliseSettings,
	normaliseSuggestion,
} from '../../src/admin/shared/api';
import { getConfig, normaliseConfig } from '../../src/admin/shared/config';

beforeEach( () => {
	apiFetch.mockReset();
	window.seocConfig = { restNamespace: 'seo-console/v1' };
	getConfig( true );
} );

describe( 'normaliseError', () => {
	it( 'keeps WP_Error code, message and status', () => {
		const e = normaliseError( {
			code: 'seoc_stale',
			message: 'Changed.',
			data: { status: 409, current_value: 'Live' },
		} );
		expect( e ).toMatchObject( {
			code: 'seoc_stale',
			message: 'Changed.',
			status: 409,
			data: { current_value: 'Live' },
		} );
	} );

	it( 'explains network and JSON failures in plain words', () => {
		const net = normaliseError( {
			code: 'fetch_error',
			message: 'You are probably offline.',
		} );
		expect( net.code ).toBe( 'fetch_error' );
		expect( net.message ).toMatch( /could not reach your site/i );
		expect( normaliseError( { code: 'invalid_json' } ).message ).toMatch(
			/unexpected response/i
		);
	} );

	it( 'handles Error objects, strings and nothing', () => {
		expect( normaliseError( new Error( 'Boom' ) ) ).toMatchObject( {
			code: 'seoc_unknown',
			message: 'Boom',
			status: null,
		} );
		expect( normaliseError( 'Plain' ).message ).toBe( 'Plain' );
		expect( normaliseError( undefined ).message ).toMatch(
			/something went wrong/i
		);
	} );

	it( 'strips HTML from server messages', () => {
		expect(
			normaliseError( { code: 'x', message: '<b>Bad</b> thing' } ).message
		).toBe( 'Bad thing' );
	} );

	it( 'is idempotent', () => {
		const once = normaliseError( { code: 'a', message: 'b' } );
		expect( normaliseError( once ) ).toBe( once );
	} );

	it( 'recognises aborted requests', () => {
		const abort = new Error( 'aborted' );
		abort.name = 'AbortError';
		expect( normaliseError( abort ).code ).toBe( 'aborted' );
	} );
} );

describe( 'buildPath', () => {
	it( 'prefixes the namespace and drops empty query args', () => {
		expect(
			buildPath( 'suggestions', {
				status: 'pending',
				module: '',
				page: 2,
				x: null,
			} )
		).toBe( '/seo-console/v1/suggestions?page=2&status=pending' );
		expect( buildPath( '/changes/4/revert' ) ).toBe(
			'/seo-console/v1/changes/4/revert'
		);
	} );
} );

describe( 'request helpers', () => {
	it( 'GETs with the right path', async () => {
		apiFetch.mockResolvedValue( { ok: true } );
		await apiGet( 'settings' );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/seo-console/v1/settings',
				method: 'GET',
			} )
		);
	} );

	it( 'rejects with a normalised error', async () => {
		apiFetch.mockRejectedValue( {
			code: 'seoc_conflict',
			message: 'Conflict',
			data: { status: 409 },
		} );
		await expect( apiPost( 'changes/1/revert' ) ).rejects.toMatchObject( {
			code: 'seoc_conflict',
			status: 409,
		} );
	} );

	it( 'sends only edited value and force when approving', async () => {
		apiFetch.mockResolvedValue( {} );
		await approveSuggestion( 7 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {} );
		await approveSuggestion( 7, { value: 'New', force: true } );
		expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toMatchObject( {
			path: '/seo-console/v1/suggestions/7/approve',
			method: 'POST',
			data: { value: 'New', force: true },
		} );
	} );

	it( 'adapts suggestion lists', async () => {
		apiFetch.mockResolvedValue( {
			items: [ { id: '3', field: 'title', proposed_value: 'X' } ],
			total: '41',
		} );
		const r = await getSuggestions( { status: 'pending' } );
		expect( r.total ).toBe( 41 );
		expect( r.items[ 0 ] ).toMatchObject( {
			id: 3,
			field: 'title',
			proposedValue: 'X',
			status: 'pending',
		} );
	} );
} );

describe( 'adapters', () => {
	it( 'accepts a bare array as a list', () => {
		expect( normaliseList( [ 1, 2 ] ) ).toEqual( {
			items: [ 1, 2 ],
			total: 2,
		} );
		expect( normaliseList( null ) ).toEqual( { items: [], total: 0 } );
	} );

	it( 'normalises a suggestion', () => {
		const s = normaliseSuggestion( {
			id: 1,
			object_id: '12',
			permalink: 'https://example.com/p',
			current_value: { a: 1 },
			confidence: '0.8',
			model: 'claude-opus-5-5',
		} );
		expect( s.objectId ).toBe( 12 );
		expect( s.viewLink ).toBe( 'https://example.com/p' );
		expect( s.currentValue ).toEqual( { a: 1 } );
		expect( s.confidence ).toBe( 0.8 );
		expect(
			normaliseSuggestion( { confidence: null } ).confidence
		).toBeNull();
	} );

	it( 'normalises a change', () => {
		const c = normaliseChange( {
			id: '5',
			before_value: '',
			after_value: 'New',
			status: 'reverted',
		} );
		expect( c ).toMatchObject( {
			id: 5,
			before: '',
			after: 'New',
			status: 'reverted',
			objectId: null,
		} );
	} );

	it( 'normalises bulk results in both shapes', () => {
		expect(
			normaliseBulkResults( {
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
		).toEqual( [
			{ id: 1, ok: true, code: '', message: '' },
			{ id: 2, ok: false, code: 'seoc_stale', message: 'Changed' },
		] );
		expect(
			normaliseBulkResults( {
				results: {
					3: true,
					4: { code: 'seoc_health_failed', message: 'x' },
				},
			} )
		).toEqual( [
			{ id: 3, ok: true, code: '', message: '' },
			{ id: 4, ok: false, code: 'seoc_health_failed', message: 'x' },
		] );
		expect( normaliseBulkResults( null ) ).toEqual( [] );
	} );

	it( 'normalises settings with safe defaults', () => {
		const s = normaliseSettings( {
			general: { ai_monthly_budget_usd: '0' },
			modules: { speed: { enabled: true, title: 'Speed' } },
			module_info: { audit: { title: 'Audit', description: 'Crawl.' } },
			secrets: {
				claude_api_key: {
					has: true,
					masked: 'sk-ant-…3fA9',
					source: 'constant',
				},
			},
		} );
		expect( s.general ).toEqual( {
			ai_model: 'claude-opus-5-5',
			ai_monthly_budget_usd: 0,
			keep_data_on_uninstall: false,
			seo_plugin: 'auto',
			safe_mode: false,
		} );
		expect( s.modules.speed ).toMatchObject( {
			enabled: true,
			title: 'Speed',
		} );
		expect( s.modules.audit ).toMatchObject( {
			enabled: false,
			title: 'Audit',
			description: 'Crawl.',
		} );
		expect( s.secrets.claude_api_key.source ).toBe( 'constant' );
		expect( s.secrets.google_api_key ).toEqual( {
			has: false,
			masked: '',
			source: 'none',
		} );
		expect( s.aiUsage ).toBeNull();
	} );
} );

describe( 'config', () => {
	it( 'falls back to defaults and drops wrong types', () => {
		const c = normaliseConfig( {
			restNamespace: '/seo-console/v1/',
			adminUrl: 'https://example.com/wp-admin',
			modules: 'nope',
			safeMode: 1,
			cachePlugins: null,
		} );
		expect( c.restNamespace ).toBe( 'seo-console/v1' );
		expect( c.adminUrl ).toBe( 'https://example.com/wp-admin/' );
		expect( c.modules ).toEqual( {} );
		expect( c.safeMode ).toBe( true );
		expect( c.cachePlugins ).toEqual( [] );
		expect( normaliseConfig( undefined ).seoPlugin ).toBe( 'none' );
	} );
} );

describe( 'saveSettings', () => {
	it( 'keeps sections the response leaves out', async () => {
		const { saveSettings } = require( '../../src/admin/shared/api' );
		const previous = normaliseSettings( {
			modules: { meta: { enabled: true, title: 'Meta' } },
			secrets: {
				claude_api_key: { has: true, masked: 'sk…1', source: 'stored' },
			},
		} );
		apiFetch.mockResolvedValue( {
			general: { safe_mode: true },
			modules: { meta: { enabled: false } },
		} );
		const next = await saveSettings(
			{ general: { safe_mode: true } },
			previous
		);
		expect( next.general.safe_mode ).toBe( true );
		expect( next.modules.meta ).toMatchObject( {
			enabled: false,
			title: 'Meta',
		} );
		expect( next.secrets.claude_api_key.masked ).toBe( 'sk…1' );
	} );
} );
