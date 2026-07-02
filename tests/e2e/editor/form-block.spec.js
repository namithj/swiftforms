/**
 * E2E: SwiftForms block-editor integration.
 *
 * Mirrors includes/blocks — verifies the form embed block and the field blocks
 * are registered and usable in the correct editors.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'SwiftForms editor blocks', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'form embed block can be inserted on a page', async ( { admin, editor } ) => {
		await admin.createNewPost();

		await editor.insertBlock( { name: 'swiftforms/form' } );

		await expect(
			editor.canvas.locator( '[data-type="swiftforms/form"]' )
		).toBeVisible();
	} );

	test( 'field blocks are available inside the form builder', async ( { admin, editor } ) => {
		await admin.createNewPost( {
			postType: 'swiftforms_form',
			title: 'Field Availability',
		} );

		await editor.insertBlock( {
			name: 'swiftforms/text-field',
			attributes: { label: 'Name', slug: 'name' },
		} );
		await editor.insertBlock( {
			name: 'swiftforms/email-field',
			attributes: { label: 'Email', slug: 'email' },
		} );

		await expect(
			editor.canvas.locator( '[data-type="swiftforms/text-field"]' )
		).toBeVisible();
		await expect(
			editor.canvas.locator( '[data-type="swiftforms/email-field"]' )
		).toBeVisible();
	} );
} );
