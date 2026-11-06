/**
 * Settings: model, budget and Safe Mode persist after save + reload; API keys are only ever shown masked.
 */
const { test, expect } = require( '@playwright/test' );
const { collectErrors, login, openAdminPage, rest } = require( './helpers' );

const SETTINGS = { path: '/seo-console/v1/settings' };

test.describe( 'Settings', () => {
	let original;

	test.beforeEach( async ( { page } ) => {
		await login( page );
		await openAdminPage( page, 'seo-console-settings' );
		original = await rest( page, SETTINGS );
	} );

	test.afterEach( async ( { page } ) => {
		await openAdminPage( page, 'seo-console-settings' );
		const g = original.general;
		await rest( page, {
			...SETTINGS,
			method: 'POST',
			data: {
				general: {
					ai_model: g.ai_model,
					ai_monthly_budget_usd: g.ai_monthly_budget_usd,
					safe_mode: g.safe_mode,
				},
			},
		} );
	} );

	test( 'model, budget and Safe Mode are saved and survive a reload', async ( { page } ) => {
		const errors = collectErrors( page );
		const newModel = original.general.ai_model === 'claude-sonnet-5-5' ? 'claude-haiku-5-5' : 'claude-sonnet-5-5';
		const newBudget = String( ( original.general.ai_monthly_budget_usd || 0 ) + 7 );
		const newSafe = ! original.general.safe_mode;

		await page.getByLabel( 'AI model' ).selectOption( newModel );
		await page.getByLabel( 'Monthly AI budget (USD)' ).fill( newBudget );
		const safeMode = page.getByRole( 'checkbox', { name: 'Safe Mode' } );
		if ( ( await safeMode.isChecked() ) !== newSafe ) {
			await safeMode.click();
		}

		const saved = page.waitForResponse(
			( r ) => decodeURIComponent( r.url() ).includes( 'seo-console/v1/settings' ) && r.request().method() === 'POST'
		);
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		expect( ( await saved ).status() ).toBe( 200 );

		await page.reload();
		await expect( page.getByLabel( 'AI model' ) ).toHaveValue( newModel );
		await expect( page.getByLabel( 'Monthly AI budget (USD)' ) ).toHaveValue( newBudget );
		await expect( page.getByRole( 'checkbox', { name: 'Safe Mode' } ) ).toBeChecked( { checked: newSafe } );

		const after = await rest( page, SETTINGS );
		expect( after.general.ai_model ).toBe( newModel );
		expect( after.general.ai_monthly_budget_usd ).toBe( Number( newBudget ) );
		expect( after.general.safe_mode ).toBe( newSafe );
		expect( errors ).toEqual( [] );
	} );

	test( 'a saved Claude key is only shown masked, never in HTML or REST', async ( { page } ) => {
		test.skip( original.secrets.claude_api_key.source === 'constant', 'Key is set by a constant on this site.' );
		const hadKey = original.secrets.claude_api_key.has;
		test.skip( hadKey, 'A real key is saved on this site; not overwriting it.' );

		const fakeKey = `sk-ant-e2e-${ Date.now() }-fakekeyvalue-QZ42`;
		await openAdminPage( page, 'seo-console-settings', '&tab=keys' );
		await page.locator( '#seoc-key-claude_api_key' ).fill( fakeKey );
		const saved = page.waitForResponse(
			( r ) => decodeURIComponent( r.url() ).includes( 'seo-console/v1/settings' ) && r.request().method() === 'POST'
		);
		await page.locator( 'form', { has: page.locator( '#seoc-key-claude_api_key' ) } ).getByRole( 'button', { name: 'Save key' } ).click();
		const response = await saved;
		expect( await response.text() ).not.toContain( fakeKey );

		await expect( page.locator( '.seoc-key__masked' ).first() ).toHaveText( 'sk-ant-…QZ42' );
		await expect( page.locator( '#seoc-key-claude_api_key' ) ).toHaveValue( '' );

		await page.reload();
		await expect( page.locator( '.seoc-key__masked' ).first() ).toHaveText( 'sk-ant-…QZ42' );
		expect( await page.content() ).not.toContain( fakeKey );

		const settings = await rest( page, SETTINGS );
		expect( JSON.stringify( settings ) ).not.toContain( fakeKey );
		expect( settings.secrets.claude_api_key ).toEqual( { has: true, masked: 'sk-ant-…QZ42', source: 'stored' } );
		expect( JSON.stringify( await page.evaluate( () => window.seocConfig ) ) ).not.toContain( fakeKey );

		// Clean up: delete the fake key.
		await rest( page, { ...SETTINGS, method: 'POST', data: { secrets: { claude_api_key: '' } } } );
	} );
} );
