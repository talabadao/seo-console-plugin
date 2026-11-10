/**
 * Browser-side PageSpeed Insights runner (docs/ARCHITECTURE.md §17.4).
 *
 * While the Speed page is open it fetches PSI itself (keyless, the visitor's own quota — no API key
 * is ever sent to the browser), strips the heavy parts, and uploads the result. This removes the
 * dependency on WP-Cron, which barely ticks on low-traffic sites and made runs slow and flaky.
 *
 * The functions that don't touch the network are pure so they can be unit-tested.
 */

/** Heavy Lighthouse fields we never need and never upload (screenshots, locale strings, timings). */
const DROP_AUDITS = [
	'final-screenshot',
	'screenshot-thumbnails',
	'full-page-screenshot',
];
const DROP_LH = [
	'fullPageScreenshot',
	'i18n',
	'timing',
	'stackPacks',
	'categoryGroups',
	'entities',
];

/**
 * Removes the large parts of a PSI result before upload. Returns a new object; the input is not changed.
 *
 * @param {Object} raw PSI JSON.
 * @return {Object} Trimmed PSI JSON.
 */
export function stripResult( raw ) {
	if ( ! raw || typeof raw !== 'object' ) {
		return {};
	}
	const out = { ...raw };
	if ( out.lighthouseResult && typeof out.lighthouseResult === 'object' ) {
		const lh = { ...out.lighthouseResult };
		DROP_LH.forEach( ( key ) => delete lh[ key ] );
		if ( lh.audits && typeof lh.audits === 'object' ) {
			const audits = { ...lh.audits };
			DROP_AUDITS.forEach( ( key ) => delete audits[ key ] );
			lh.audits = audits;
		}
		out.lighthouseResult = lh;
	}
	return out;
}

/**
 * Classifies a PSI HTTP status into the code the server understands.
 *
 * @param {number} status HTTP status from the PSI fetch.
 * @return {string} quota | forbidden | server | unknown.
 */
export function codeForStatus( status ) {
	if ( status === 429 ) {
		return 'quota';
	}
	if ( status === 403 ) {
		return 'forbidden';
	}
	if ( status >= 500 ) {
		return 'server';
	}
	return 'unknown';
}

/**
 * Classifies a thrown fetch error (network down, or our own abort timeout).
 *
 * @param {Error} error Caught error.
 * @return {string} timeout | network.
 */
export function codeForError( error ) {
	return error && error.name === 'AbortError' ? 'timeout' : 'network';
}

/**
 * Fetches one PSI result in the browser, with a hard timeout. Throws on a non-OK status (with a
 * `.status` property) or a network/abort error.
 *
 * @param {string}   psiUrl      Keyless PSI URL from the server.
 * @param {number}   timeoutMs   Abort after this long.
 * @param {Function} [fetchImpl] Injectable fetch (tests).
 * @return {Promise<Object>} Raw PSI JSON.
 */
export async function fetchPsi( psiUrl, timeoutMs = 120000, fetchImpl ) {
	const doFetch = fetchImpl || window.fetch.bind( window );
	const controller =
		typeof AbortController !== 'undefined' ? new AbortController() : null;
	const timer = controller
		? setTimeout( () => controller.abort(), timeoutMs )
		: null;
	try {
		const response = await doFetch( psiUrl, {
			signal: controller ? controller.signal : undefined,
			credentials: 'omit',
		} );
		if ( ! response.ok ) {
			const err = new Error( `PSI HTTP ${ response.status }` );
			err.status = response.status;
			err.psi = true;
			throw err;
		}
		return await response.json();
	} finally {
		if ( timer ) {
			clearTimeout( timer );
		}
	}
}
