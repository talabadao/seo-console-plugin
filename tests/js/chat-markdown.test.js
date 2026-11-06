import { parseInline, parseMarkdown } from '../../src/admin/chat/markdown';

describe( 'chat markdown', () => {
	it( 'parses inline formatting', () => {
		expect(
			parseInline(
				'Use **bold**, *em*, `code` and [docs](https://x.test/a)'
			)
		).toEqual( [
			{ type: 'text', value: 'Use ' },
			{ type: 'strong', children: [ { type: 'text', value: 'bold' } ] },
			{ type: 'text', value: ', ' },
			{ type: 'em', children: [ { type: 'text', value: 'em' } ] },
			{ type: 'text', value: ', ' },
			{ type: 'code', value: 'code' },
			{ type: 'text', value: ' and ' },
			{
				type: 'link',
				href: 'https://x.test/a',
				children: [ { type: 'text', value: 'docs' } ],
			},
		] );
	} );

	it( 'never turns unsafe links or HTML into elements', () => {
		const nodes = parseInline(
			'[x](javascript:alert(1)) <img src=x onerror=alert(1)>'
		);
		expect( nodes.every( ( n ) => n.type === 'text' ) ).toBe( true );
		expect( nodes.map( ( n ) => n.value ).join( '' ) ).toContain(
			'<img src=x onerror=alert(1)>'
		);
	} );

	it( 'autolinks bare URLs without trailing punctuation', () => {
		const nodes = parseInline( 'See https://example.com/page.' );
		expect( nodes[ 1 ] ).toEqual( {
			type: 'link',
			href: 'https://example.com/page',
			children: [ { type: 'text', value: 'https://example.com/page' } ],
		} );
		expect( nodes[ 2 ] ).toEqual( { type: 'text', value: '.' } );
	} );

	it( 'keeps snake_case and multiplication as text', () => {
		expect( parseInline( 'wp_enqueue_script and 2 * 3 * 4' ) ).toEqual( [
			{ type: 'text', value: 'wp_enqueue_script and 2 * 3 * 4' },
		] );
	} );

	it( 'parses blocks', () => {
		const blocks = parseMarkdown(
			'## Why\nLine one\nline two\n\n- a\n- **b**\n\n1. first\n2. second\n\n```php\n<?php echo 1;\n```'
		);
		expect( blocks.map( ( b ) => b.type ) ).toEqual( [
			'heading',
			'p',
			'ul',
			'ol',
			'code',
		] );
		expect( blocks[ 0 ].level ).toBe( 2 );
		expect( blocks[ 1 ].lines ).toHaveLength( 2 );
		expect( blocks[ 2 ].items ).toHaveLength( 2 );
		expect( blocks[ 3 ].start ).toBe( 1 );
		expect( blocks[ 4 ] ).toEqual( {
			type: 'code',
			lang: 'php',
			value: '<?php echo 1;',
		} );
	} );

	it( 'handles an unclosed code fence and empty input', () => {
		expect( parseMarkdown( '```\ncode' ) ).toEqual( [
			{ type: 'code', lang: '', value: 'code' },
		] );
		expect( parseMarkdown( '' ) ).toEqual( [] );
		expect( parseMarkdown( null ) ).toEqual( [] );
	} );
} );
