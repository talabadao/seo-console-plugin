/**
 * Approval flow: seeded seo_meta suggestion → edit → approve → listed in the Change log → Undo → value restored.
 * Seeds through wp-cli (bin/dev-site.sh wp eval, or SEOC_WP_CLI).
 */
const { test, expect } = require( '@playwright/test' );
const { collectErrors, login, openAdminPage, rest, wpEvalJson } = require( './helpers' );

const ORIGINAL = 'Original e2e description';
const EDITED = 'Edited during the e2e test, then undone.';

test.describe( 'Suggestions → Change log → Undo', () => {
	let seed;

	test.beforeAll( () => {
		seed = wpEvalJson( `
			$plugin   = \\SEOConsole\\Plugin::instance();
			$previous = $plugin->settings()->get( 'general', 'seo_plugin' );
			$plugin->settings()->set( 'general', 'seo_plugin', 'yoast' );
			$title = 'E2E running shoes ' . time();
			$post  = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post' ) );
			update_post_meta( $post, '_yoast_wpseo_metadesc', '${ ORIGINAL }' );
			$id = $plugin->suggestions()->create( array(
				'module' => 'meta', 'type' => 'seo_meta', 'object_type' => 'post', 'object_id' => $post,
				'field' => 'description', 'proposed_value' => 'AI proposal for the e2e test.',
				'rationale' => 'E2E seed.', 'source' => 'ai', 'confidence' => 0.9,
			) );
			echo wp_json_encode( array( 'post' => $post, 'title' => $title, 'suggestion' => is_wp_error( $id ) ? $id->get_error_message() : $id, 'previous' => $previous ) );
		` );
		expect( typeof seed.suggestion ).toBe( 'number' );
	} );

	test.afterAll( () => {
		if ( ! seed ) {
			return;
		}
		wpEvalJson( `
			wp_delete_post( ${ Number( seed.post ) }, true );
			\\SEOConsole\\Plugin::instance()->settings()->set( 'general', 'seo_plugin', '${ seed.previous }' );
			echo wp_json_encode( true );
		` );
	} );

	test( 'edit, approve, see it in the change log, undo', async ( { page } ) => {
		await login( page );
		const errors = collectErrors( page );

		await openAdminPage( page, 'seo-console-suggestions' );
		const row = page.locator( 'li.seoc-suggestion', { hasText: seed.title } ).first();
		await expect( row ).toBeVisible();

		await row.getByRole( 'textbox' ).first().fill( EDITED );
		const approved = page.waitForResponse(
			( r ) => decodeURIComponent( r.url() ).includes( `/suggestions/${ seed.suggestion }/approve` )
		);
		await row.getByRole( 'button', { name: 'Approve my edit' } ).click();
		const approveResponse = await approved;
		expect( approveResponse.status(), await approveResponse.text() ).toBe( 200 );

		const post = await rest( page, { path: `/wp/v2/posts/${ seed.post }?context=edit` } );
		expect( post.meta._yoast_wpseo_metadesc ).toBe( EDITED );

		await openAdminPage( page, 'seo-console-changes' );
		const changeRow = page.locator( 'tr', { hasText: seed.title } ).first();
		await expect( changeRow ).toBeVisible();
		await expect( changeRow ).toContainText( EDITED );

		await changeRow.getByRole( 'button', { name: 'Undo' } ).click();
		const reverted = page.waitForResponse( ( r ) => /\/changes\/\d+\/revert/.test( decodeURIComponent( r.url() ) ) );
		await page.getByRole( 'button', { name: 'Yes, undo' } ).click();
		expect( ( await reverted ).status() ).toBe( 200 );

		const restored = await rest( page, { path: `/wp/v2/posts/${ seed.post }?context=edit` } );
		expect( restored.meta._yoast_wpseo_metadesc ).toBe( ORIGINAL );

		const changes = await rest( page, { path: `/seo-console/v1/changes?object_type=post&object_id=${ seed.post }` } );
		expect( changes.items[ 0 ].status ).toBe( 'reverted' );
		expect( errors ).toEqual( [] );
	} );
} );
