/**
 * Every SEO Console admin page loads and renders without JavaScript errors.
 */
const { test, expect } = require( '@playwright/test' );
const { CORE_PAGES, collectErrors, login, openAdminPage } = require( './helpers' );

test.describe( 'SEO Console admin pages', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	for ( const slug of CORE_PAGES ) {
		test( `${ slug } loads without JS errors`, async ( { page } ) => {
			const errors = collectErrors( page );
			await openAdminPage( page, slug );
			await expect( page.locator( '.notice-error' ) ).toHaveCount( 0 );
			const config = await page.evaluate( () => window.seocConfig );
			expect( config.restNamespace ).toBe( 'seo-console/v1' );
			// Give async requests a moment to finish and report errors.
			await page.waitForLoadState( 'networkidle' );
			expect( errors ).toEqual( [] );
		} );
	}

	test( 'module pages from seocConfig.pages load without JS errors', async ( { page } ) => {
		await openAdminPage( page, 'seo-console' );
		const pages = await page.evaluate( () => window.seocConfig.pages || [] );
		const modulePages = pages.map( ( p ) => p.slug ).filter( ( slug ) => ! CORE_PAGES.includes( slug ) );
		for ( const slug of modulePages ) {
			const errors = collectErrors( page );
			await openAdminPage( page, slug );
			await page.waitForLoadState( 'networkidle' );
			expect( errors, slug ).toEqual( [] );
		}
	} );

	test( 'SEO Console assets do not load on other admin pages', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php' );
		expect( await page.evaluate( () => typeof window.seocConfig ) ).toBe( 'undefined' );
	} );
} );
