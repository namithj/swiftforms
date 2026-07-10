/**
 * E2E: SwiftForms radio, date, hidden fields and Label|value select options.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	selectField,
	radioField,
	dateField,
	hiddenField,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

test.describe( 'SwiftForms new field types', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'radio, date, and hidden values round-trip into a submission', async ( { admin, page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'New fields',
			fields: [
				radioField( { label: 'Meal', slug: 'meal', options: [ 'Veggie|v', 'Meat|m' ], required: true } ),
				dateField( { label: 'Date', slug: 'event_date', min: '2020-01-01', required: true } ),
				hiddenField( { slug: 'campaign', value: 'spring-launch' } ),
			],
			settings: { successMessage: 'Booked!' },
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );

		// Required radio blocks submission until picked.
		await page.fill( 'input[name="event_date"]', '2026-08-01' );
		await page.click( '.swiftforms-form__submit' );
		await expect( page.locator( '[data-swiftforms-status]' ) ).toHaveAttribute( 'data-state', 'error' );

		await page.check( 'input[name="meal"][value="v"]' );
		await page.click( '.swiftforms-form__submit' );

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'Booked!' );

		// The stored entry holds the radio VALUE (not label) plus the hidden
		// and date values — verified through the admin submission metabox.
		// Filter by this spec's form: entries from other specs share the list.
		await admin.visitAdminPage( 'edit.php', `post_type=swiftform_entry&swiftforms_form_id=${ formId }` );
		await page.locator( '.wp-list-table tbody tr' ).first().locator( 'a.row-title' ).click();

		const details = page.locator( '#swiftforms-submission-details' );
		await expect( details ).toContainText( 'spring-launch' );
		await expect( details ).toContainText( '2026-08-01' );
		// The radio stored its VALUE ('v'), not the 'Veggie' label, under the
		// humanized 'Meal' field label.
		await expect( details ).toContainText( 'Mealv' );
		await expect( details ).not.toContainText( 'Veggie' );
	} );

	test( 'select with Label|value options submits the value, not the label', async ( { page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Pair select',
			fields: [
				selectField( {
					label: 'Topic',
					slug: 'topic',
					options: [ 'Sales question|sales', 'Support request|support' ],
				} ),
			],
			settings: { successMessage: 'Got it!' },
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );
		await page.selectOption( 'select[name="topic"]', 'sales' );
		await page.click( '.swiftforms-form__submit' );

		await expect( page.locator( '[data-swiftforms-status]' ) ).toHaveText( 'Got it!' );
	} );
} );
