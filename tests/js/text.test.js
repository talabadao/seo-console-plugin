import {
	RANGES,
	SERP,
	countChars,
	estimateWidth,
	fieldKind,
	humanise,
	lengthStatus,
	serpUrlParts,
	shorten,
	truncateToWidth,
	valueToText,
} from '../../src/admin/shared/utils/text';

describe( 'character ranges', () => {
	it( 'uses the recommended title and description ranges', () => {
		expect( RANGES.title ).toEqual( { min: 30, max: 60 } );
		expect( RANGES.description ).toEqual( { min: 120, max: 160 } );
	} );

	it( 'classifies lengths at the boundaries', () => {
		const r = RANGES.title;
		expect( lengthStatus( 0, r ) ).toBe( 'empty' );
		expect( lengthStatus( 29, r ) ).toBe( 'short' );
		expect( lengthStatus( 30, r ) ).toBe( 'good' );
		expect( lengthStatus( 60, r ) ).toBe( 'good' );
		expect( lengthStatus( 61, r ) ).toBe( 'long' );
		expect( lengthStatus( 119, RANGES.description ) ).toBe( 'short' );
		expect( lengthStatus( 160, RANGES.description ) ).toBe( 'good' );
		expect( lengthStatus( 161, RANGES.description ) ).toBe( 'long' );
	} );

	it( 'counts code points, not UTF-16 units', () => {
		expect( countChars( 'Tiếng Việt' ) ).toBe( 10 );
		expect( countChars( '😀ab' ) ).toBe( 3 );
		expect( countChars( null ) ).toBe( 0 );
		expect( countChars( undefined ) ).toBe( 0 );
	} );

	it( 'maps field names to SEO text kinds', () => {
		expect( fieldKind( 'title' ) ).toBe( 'title' );
		expect( fieldKind( 'meta_description' ) ).toBe( 'description' );
		expect( fieldKind( 'description' ) ).toBe( 'description' );
		expect( fieldKind( 'alt' ) ).toBeNull();
		expect( fieldKind( undefined ) ).toBeNull();
	} );
} );

describe( 'SERP width heuristic', () => {
	it( 'estimates narrow letters narrower than wide ones', () => {
		expect( estimateWidth( 'iiii', 20 ) ).toBeLessThan(
			estimateWidth( 'WWWW', 20 )
		);
		expect( estimateWidth( '', 20 ) ).toBe( 0 );
	} );

	it( 'treats accented letters like their base letter', () => {
		expect( estimateWidth( 'é', 20 ) ).toBe( estimateWidth( 'e', 20 ) );
	} );

	it( 'leaves a normal 55-character title untouched', () => {
		const title = 'Best Coffee Grinders of 2026: Tested and Reviewed Here';
		const r = truncateToWidth( title, SERP.title.maxPx, SERP.title.fontPx );
		expect( r.truncated ).toBe( false );
		expect( r.text ).toBe( title );
		expect( r.width ).toBeLessThanOrEqual( SERP.title.maxPx );
	} );

	it( 'truncates a long title at a word boundary with " ..."', () => {
		const title =
			'The Complete Beginner Guide To Choosing A Burr Coffee Grinder For Espresso And Filter At Home';
		const r = truncateToWidth( title, SERP.title.maxPx, SERP.title.fontPx );
		expect( r.truncated ).toBe( true );
		expect( r.text.endsWith( ' ...' ) ).toBe( true );
		expect( r.width ).toBeGreaterThan( SERP.title.maxPx );
		const head = r.text.slice( 0, -4 );
		expect( title.startsWith( head ) ).toBe( true );
		// Cut falls between words.
		expect( title.charAt( head.length ) ).toBe( ' ' );
		expect(
			estimateWidth( r.text, SERP.title.fontPx )
		).toBeLessThanOrEqual( SERP.title.maxPx );
	} );

	it( 'flags all-caps titles sooner than lowercase ones of the same length', () => {
		const lower = 'a'.repeat( 50 );
		const upper = 'W'.repeat( 50 );
		expect(
			truncateToWidth( lower, SERP.title.maxPx, SERP.title.fontPx )
				.truncated
		).toBe( false );
		expect(
			truncateToWidth( upper, SERP.title.maxPx, SERP.title.fontPx )
				.truncated
		).toBe( true );
	} );

	it( 'truncates a long description', () => {
		const desc = 'word '.repeat( 60 );
		const r = truncateToWidth(
			desc,
			SERP.description.maxPx,
			SERP.description.fontPx
		);
		expect( r.truncated ).toBe( true );
		expect( r.text.endsWith( 'word ...' ) ).toBe( true );
	} );

	it( 'normalises whitespace', () => {
		expect( truncateToWidth( '  a \n b  ', 600, 20 ).text ).toBe( 'a b' );
	} );

	it( 'builds a breadcrumb URL', () => {
		expect(
			serpUrlParts( 'https://www.example.com/blog/my-post/' )
		).toEqual( { host: 'example.com', path: 'blog › my-post' } );
		expect( serpUrlParts( '' ) ).toEqual( { host: '', path: '' } );
		expect( serpUrlParts( 'not a url' ).host ).toBe( 'not a url' );
	} );
} );

describe( 'small text helpers', () => {
	it( 'shortens long text with an ellipsis', () => {
		expect( shorten( 'short', 10 ) ).toBe( 'short' );
		const s = shorten( 'one two three four five six seven', 20 );
		expect( s.endsWith( '…' ) ).toBe( true );
		expect( countChars( s ) ).toBeLessThanOrEqual( 20 );
	} );

	it( 'humanises field names', () => {
		expect( humanise( 'focus_keyword' ) ).toBe( 'Focus keyword' );
		expect( humanise( 'speed.disable_emojis' ) ).toBe(
			'Speed disable emojis'
		);
		expect( humanise( '' ) ).toBe( '' );
	} );

	it( 'renders values as text', () => {
		expect( valueToText( null ) ).toBe( '' );
		expect( valueToText( 'x' ) ).toBe( 'x' );
		expect( valueToText( 3 ) ).toBe( '3' );
		expect( valueToText( false ) ).toBe( 'false' );
		expect( valueToText( { a: 1 } ) ).toBe( '{\n  "a": 1\n}' );
	} );
} );
