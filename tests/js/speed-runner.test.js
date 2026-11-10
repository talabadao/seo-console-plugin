/**
 * Unit tests for the browser-side speed runner helpers (no network).
 */
import {
	codeForError,
	codeForStatus,
	fetchPsi,
	stripResult,
} from '../../src/admin/speed/runner';

describe( 'stripResult', () => {
	it( 'drops heavy Lighthouse parts and keeps the rest', () => {
		const raw = {
			id: 'https://example.org/',
			lighthouseResult: {
				finalUrl: 'https://example.org/',
				audits: {
					'final-screenshot': { x: 1 },
					'screenshot-thumbnails': { x: 1 },
					'largest-contentful-paint': { numericValue: 2500 },
				},
				fullPageScreenshot: { nodes: 1 },
				i18n: { x: 1 },
				categories: { performance: { score: 0.5 } },
			},
		};
		const out = stripResult( raw );
		expect( out.lighthouseResult.audits[ 'final-screenshot' ] ).toBeUndefined();
		expect(
			out.lighthouseResult.audits[ 'screenshot-thumbnails' ]
		).toBeUndefined();
		expect( out.lighthouseResult.fullPageScreenshot ).toBeUndefined();
		expect( out.lighthouseResult.i18n ).toBeUndefined();
		expect(
			out.lighthouseResult.audits[ 'largest-contentful-paint' ].numericValue
		).toBe( 2500 );
		expect( out.lighthouseResult.categories.performance.score ).toBe( 0.5 );
		// The input is not mutated.
		expect( raw.lighthouseResult.i18n ).toEqual( { x: 1 } );
	} );

	it( 'is safe on junk input', () => {
		expect( stripResult( null ) ).toEqual( {} );
		expect( stripResult( 'x' ) ).toEqual( {} );
	} );
} );

describe( 'codeForStatus', () => {
	it( 'maps HTTP statuses to server codes', () => {
		expect( codeForStatus( 429 ) ).toBe( 'quota' );
		expect( codeForStatus( 403 ) ).toBe( 'forbidden' );
		expect( codeForStatus( 500 ) ).toBe( 'server' );
		expect( codeForStatus( 503 ) ).toBe( 'server' );
		expect( codeForStatus( 400 ) ).toBe( 'unknown' );
	} );
} );

describe( 'codeForError', () => {
	it( 'distinguishes an abort (timeout) from a network error', () => {
		const abort = new Error( 'aborted' );
		abort.name = 'AbortError';
		expect( codeForError( abort ) ).toBe( 'timeout' );
		expect( codeForError( new Error( 'offline' ) ) ).toBe( 'network' );
	} );
} );

describe( 'fetchPsi', () => {
	it( 'returns parsed JSON on success', async () => {
		const fakeFetch = jest.fn().mockResolvedValue( {
			ok: true,
			status: 200,
			json: async () => ( { id: 'ok' } ),
		} );
		const out = await fetchPsi( 'https://psi/', 1000, fakeFetch );
		expect( out ).toEqual( { id: 'ok' } );
	} );

	it( 'throws with .status on a non-OK response', async () => {
		const fakeFetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 429,
			json: async () => ( {} ),
		} );
		await expect( fetchPsi( 'https://psi/', 1000, fakeFetch ) ).rejects.toMatchObject(
			{ status: 429, psi: true }
		);
	} );
} );
