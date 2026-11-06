/**
 * Pure text helpers: character ranges for SEO fields and an approximate Google SERP width heuristic.
 * No WordPress imports so they can be unit-tested directly.
 */

/** Recommended lengths (characters). */
export const RANGES = {
	title: { min: 30, max: 60 },
	description: { min: 120, max: 160 },
};

/**
 * Count characters as a person would (code points, so emoji and accents count once).
 *
 * @param {string} value Text.
 * @return {number} Length.
 */
export function countChars( value ) {
	return Array.from( String( value ?? '' ) ).length;
}

/**
 * @param {number}                    length Character count.
 * @param {{min: number,max: number}} range  Recommended range.
 * @return {'empty'|'short'|'good'|'long'} Status.
 */
export function lengthStatus( length, range ) {
	if ( ! length ) {
		return 'empty';
	}
	if ( length < range.min ) {
		return 'short';
	}
	if ( length > range.max ) {
		return 'long';
	}
	return 'good';
}

/**
 * Which SEO text kind a suggestion field is, if any.
 *
 * @param {string} field Field name, e.g. "title", "description", "meta_description".
 * @return {'title'|'description'|null} Kind.
 */
export function fieldKind( field ) {
	const f = String( field || '' ).toLowerCase();
	if ( [ 'title', 'seo_title', 'meta_title' ].includes( f ) ) {
		return 'title';
	}
	if (
		[
			'description',
			'meta_description',
			'seo_description',
			'metadesc',
		].includes( f )
	) {
		return 'description';
	}
	return null;
}

/* Arial advance widths in 1/1000 em (Google renders snippets in Arial). */
// prettier-ignore
const WIDTHS = {
	' ': 278, '!': 278, '"': 355, '#': 556, $: 556, '%': 889, '&': 667, "'": 191, '(': 333, ')': 333,
	'*': 389, '+': 584, ',': 278, '-': 333, '.': 278, '/': 278, ':': 278, ';': 278, '<': 584, '=': 584,
	'>': 584, '?': 556, '@': 1015, '[': 278, '\\': 278, ']': 278, '^': 469, _: 556, '`': 333, '{': 334,
	'|': 260, '}': 334, '~': 584, '–': 556, '—': 1000, '…': 1000, '·': 278, '’': 222, '‘': 222,
	'“': 333, '”': 333,
	A: 667, B: 667, C: 722, D: 722, E: 667, F: 611, G: 778, H: 722, I: 278, J: 500, K: 667, L: 556,
	M: 833, N: 722, O: 778, P: 667, Q: 778, R: 722, S: 667, T: 611, U: 722, V: 667, W: 944, X: 667,
	Y: 667, Z: 611,
	a: 556, b: 556, c: 500, d: 556, e: 556, f: 278, g: 556, h: 556, i: 222, j: 222, k: 500, l: 222,
	m: 833, n: 556, o: 556, p: 556, q: 556, r: 333, s: 500, t: 278, u: 556, v: 500, w: 722, x: 500,
	y: 500, z: 500,
};
const DIGIT = 556;
const DEFAULT_WIDTH = 556;
const WIDE = 1000; // CJK and other full-width characters.

function charWidth( ch ) {
	if ( WIDTHS[ ch ] !== undefined ) {
		return WIDTHS[ ch ];
	}
	if ( ch >= '0' && ch <= '9' ) {
		return DIGIT;
	}
	const base = ch.normalize( 'NFD' ).charAt( 0 );
	if ( base !== ch && WIDTHS[ base ] !== undefined ) {
		return WIDTHS[ base ];
	}
	const code = ch.codePointAt( 0 );
	if (
		( code >= 0x1100 && code <= 0x115f ) ||
		( code >= 0x2e80 && code <= 0xa4cf ) ||
		( code >= 0xac00 && code <= 0xd7a3 ) ||
		( code >= 0xf900 && code <= 0xfaff ) ||
		( code >= 0xff00 && code <= 0xff60 ) ||
		code >= 0x1f300
	) {
		return WIDE;
	}
	return DEFAULT_WIDTH;
}

/**
 * Estimated rendered width in pixels.
 *
 * @param {string} text   Text.
 * @param {number} fontPx Font size in px.
 * @return {number} Width in px (rounded).
 */
export function estimateWidth( text, fontPx ) {
	let units = 0;
	for ( const ch of String( text ?? '' ) ) {
		units += charWidth( ch );
	}
	return Math.round( ( units / 1000 ) * fontPx );
}

/** Desktop Google result limits (approximate). */
export const SERP = {
	title: { fontPx: 20, maxPx: 600 },
	description: { fontPx: 14, maxPx: 920 },
};

const ELLIPSIS = ' ...';

/**
 * Truncate text the way Google roughly does: cut at the last whole word that fits, then " ...".
 *
 * @param {string} text   Text.
 * @param {number} maxPx  Available width.
 * @param {number} fontPx Font size.
 * @return {{text: string, truncated: boolean, width: number, maxPx: number}} Result (width = full width).
 */
export function truncateToWidth( text, maxPx, fontPx ) {
	const clean = String( text ?? '' )
		.replace( /\s+/g, ' ' )
		.trim();
	const width = estimateWidth( clean, fontPx );
	if ( width <= maxPx ) {
		return { text: clean, truncated: false, width, maxPx };
	}
	const budget = maxPx - estimateWidth( ELLIPSIS, fontPx );
	const chars = Array.from( clean );
	let used = 0;
	let cut = 0;
	for ( ; cut < chars.length; cut++ ) {
		const w = ( charWidth( chars[ cut ] ) / 1000 ) * fontPx;
		if ( used + w > budget ) {
			break;
		}
		used += w;
	}
	let head = chars.slice( 0, cut ).join( '' );
	const lastSpace = head.lastIndexOf( ' ' );
	// Prefer a word boundary unless that would throw away more than a third of the text.
	if ( lastSpace > head.length * 0.66 ) {
		head = head.slice( 0, lastSpace );
	}
	head = head.replace( /[\s,;:.\-–—|]+$/, '' );
	return { text: head + ELLIPSIS, truncated: true, width, maxPx };
}

/**
 * Turn a URL into Google's breadcrumb-like display ("example.com › blog › post").
 *
 * @param {string} url URL.
 * @return {{host: string, path: string}} Parts (path may be empty).
 */
export function serpUrlParts( url ) {
	if ( ! url ) {
		return { host: '', path: '' };
	}
	try {
		const u = new URL( url );
		const segments = u.pathname
			.split( '/' )
			.filter( Boolean )
			.map( ( s ) => {
				try {
					return decodeURIComponent( s );
				} catch ( e ) {
					return s;
				}
			} );
		return {
			host: u.host.replace( /^www\./, '' ),
			path: segments.join( ' › ' ),
		};
	} catch ( e ) {
		return { host: String( url ), path: '' };
	}
}

/**
 * Shorten long text for table cells (by characters, at a word boundary when possible).
 *
 * @param {string} text Text.
 * @param {number} max  Max characters.
 * @return {string} Text.
 */
export function shorten( text, max = 80 ) {
	const chars = Array.from( String( text ?? '' ) );
	if ( chars.length <= max ) {
		return chars.join( '' );
	}
	let head = chars.slice( 0, max - 1 ).join( '' );
	const sp = head.lastIndexOf( ' ' );
	if ( sp > max * 0.6 ) {
		head = head.slice( 0, sp );
	}
	return head + '…';
}

/**
 * Human label for a field name when the server gives none ("focus_keyword" → "Focus keyword").
 *
 * @param {string} field Field name.
 * @return {string} Label.
 */
export function humanise( field ) {
	const s = String( field || '' )
		.replace( /[._-]+/g, ' ' )
		.trim();
	return s ? s.charAt( 0 ).toUpperCase() + s.slice( 1 ) : '';
}

/**
 * Display text for a stored value (JSON-decoded). Empty → '' (callers show an "(empty)" label).
 *
 * @param {*} value Value.
 * @return {string} Text.
 */
export function valueToText( value ) {
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}
	try {
		return JSON.stringify( value, null, 2 );
	} catch ( e ) {
		return String( value );
	}
}
