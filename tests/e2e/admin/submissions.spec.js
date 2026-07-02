/**
 * E2E: SwiftForms submission administration.
 *
 * Verifies that a frontend submission is stored, its values are readable in the
 * submission detail metabox, and the CSV export bulk action is available.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	textField,
	emailField,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

test.describe( 'SwiftForms submission admin', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'stores a submission and exposes it in wp-admin', async ( { page, admin, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Admin Contact',
			fields: [
				textField( { label: 'Name', slug: 'name', required: true } ),
				emailField( { label: 'Email', slug: 'email', required: true } ),
			],
		} );
		const formPage = await createFormPage( requestUtils, formId );

		// Generate a submission through the real frontend flow.
		await page.goto( formPage.link );
		await page.fill( 'input[name="name"]', 'Katherine Johnson' );
		await page.fill( 'input[name="email"]', 'katherine@example.com' );
		await page.click( '.swiftforms-form__submit' );
		await expect(
			page.locator( '[data-swiftforms-status]' )
		).toHaveAttribute( 'data-state', 'success' );

		// The submission shows up in the entries list.
		await admin.visitAdminPage( 'edit.php', 'post_type=swiftform_entry' );
		await expect( page.locator( '.wp-list-table .row-title' ).first() ).toBeVisible();

		// The CSV export bulk action is registered.
		await expect(
			page.locator( '#bulk-action-selector-top option[value="swiftforms_export_csv"]' )
		).toHaveCount( 1 );

		// Opening the submission reveals the field values in the metabox.
		await page.locator( '.wp-list-table .row-title' ).first().click();
		const metabox = page.locator( '#swiftforms-submission-details' );
		await expect( metabox ).toContainText( 'Katherine Johnson' );
		await expect( metabox ).toContainText( 'katherine@example.com' );
	} );
} );
