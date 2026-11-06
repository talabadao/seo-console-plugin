/**
 * Shared E2E helpers: login, JS error collection, REST calls through wp.apiFetch, wp-cli.
 */
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const { expect } = require( '@playwright/test' );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'admin';

/** Core SEO Console admin pages (module pages are read from window.seocConfig.pages). */
const CORE_PAGES = [
	'seo-console',
	'seo-console-suggestions',
	'seo-console-changes',
	'seo-console-settings',
];

/**
 * Logs in as the admin user.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );
	if ( page.url().includes( '/wp-admin' ) ) {
		return;
	}
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.click( '#wp-submit' ),
	] );
}

/**
 * Records console errors and uncaught page errors. Network errors for external assets blocked by a sandbox proxy
 * (net::ERR_TUNNEL_CONNECTION_FAILED and friends) are ignored.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {string[]} Live array of error messages.
 */
function collectErrors( page ) {
	const errors = [];
	const ignored = ( text ) =>
		/net::ERR_/.test( text ) ||
		/Failed to load resource/.test( text );
	page.on( 'pageerror', ( err ) => errors.push( `pageerror: ${ err.message }` ) );
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' && ! ignored( msg.text() ) ) {
			errors.push( `console: ${ msg.text() }` );
		}
	} );
	return errors;
}

/**
 * Opens an SEO Console admin page and waits until React rendered into #seoc-root.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                         slug Page slug.
 * @param {string}                         query Extra query string (e.g. "&tab=keys").
 */
async function openAdminPage( page, slug, query = '' ) {
	await page.goto( `/wp-admin/admin.php?page=${ slug }${ query }` );
	await expect( page.locator( '#seoc-root' ) ).toBeVisible();
	await expect( page.locator( '#seoc-root > *' ).first() ).toBeVisible();
}

/**
 * Calls the REST API from the logged-in admin page (wp.apiFetch adds the nonce). Must be on an SEO Console page.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {Object}                         options apiFetch options { path, method, data }.
 * @return {Promise<any>} Response body.
 */
async function rest( page, options ) {
	return page.evaluate( ( opts ) => window.wp.apiFetch( opts ), options );
}

/**
 * Runs wp-cli on the dev site: `bin/dev-site.sh wp <args>` by default, or the command in SEOC_WP_CLI
 * (space-separated, e.g. "docker compose -f /path/dev/docker-compose.yml run --rm cli").
 *
 * @param {string[]} args wp-cli arguments.
 * @return {string} stdout.
 */
function wpCli( args ) {
	const base = process.env.SEOC_WP_CLI
		? process.env.SEOC_WP_CLI.split( ' ' ).filter( Boolean )
		: [ path.resolve( __dirname, '../../bin/dev-site.sh' ), 'wp' ];
	return execFileSync( base[ 0 ], [ ...base.slice( 1 ), ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		timeout: 120000,
	} );
}

/**
 * Runs PHP through `wp eval` and parses the last JSON line it printed.
 *
 * @param {string} php PHP code that echoes wp_json_encode( ... ).
 * @return {any} Decoded value.
 */
function wpEvalJson( php ) {
	const lines = wpCli( [ 'eval', php ] ).trim().split( '\n' );
	return JSON.parse( lines[ lines.length - 1 ] );
}

module.exports = {
	CORE_PAGES,
	collectErrors,
	login,
	openAdminPage,
	rest,
	wpCli,
	wpEvalJson,
};
