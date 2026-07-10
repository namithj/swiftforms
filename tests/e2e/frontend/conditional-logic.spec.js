/**
 * E2E: SwiftForms conditional logic.
 *
 * The rules live in the block comment attributes; the server injects them as
 * `data-sf-conditions` on render, view.js toggles visibility live, and the
 * submission pipeline re-evaluates them server-side.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	textField,
	selectField,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

const showWhenTopicIsSupport = {
	enabled: true,
	action: 'show',
	groups: [ [ { field: 'topic', operator: 'equals', value: 'support' } ] ],
};

test.describe( 'SwiftForms conditional logic', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	const createConditionalForm = async ( requestUtils ) => {
		const formId = await createForm( requestUtils, {
			title: 'Conditional',
			fields: [
				selectField( { label: 'Topic', slug: 'topic', options: [ 'support', 'sales' ] } ),
				textField( {
					label: 'Details',
					slug: 'details',
					required: true,
					conditions: showWhenTopicIsSupport,
				} ),
			],
			settings: { successMessage: 'Received!' },
		} );

		return createFormPage( requestUtils, formId );
	};

	test( 'toggles field visibility as the controlling value changes', async ( { page, requestUtils } ) => {
		const formPage = await createConditionalForm( requestUtils );

		await page.goto( formPage.link );

		const details = page.locator( '[data-field-slug="details"]' );
		const detailsInput = page.locator( 'input[name="details"]' );

		// Hidden on load (topic starts on the empty placeholder option).
		await expect( details ).toBeHidden();
		await expect( detailsInput ).toBeDisabled();

		await page.selectOption( 'select[name="topic"]', 'support' );
		await expect( details ).toBeVisible();
		await expect( detailsInput ).toBeEnabled();

		await page.selectOption( 'select[name="topic"]', 'sales' );
		await expect( details ).toBeHidden();
	} );

	test( 'hidden required field does not block submission and its value is not stored', async ( { page, requestUtils } ) => {
		const formPage = await createConditionalForm( requestUtils );

		await page.goto( formPage.link );
		await page.selectOption( 'select[name="topic"]', 'sales' );
		await page.click( '.swiftforms-form__submit' );

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'Received!' );
		await expect( status ).toHaveAttribute( 'data-state', 'success' );
	} );

	test( 'visible conditional field is still required end to end', async ( { page, requestUtils } ) => {
		const formPage = await createConditionalForm( requestUtils );

		await page.goto( formPage.link );
		await page.selectOption( 'select[name="topic"]', 'support' );
		await page.click( '.swiftforms-form__submit' );

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'This field is required.' );
		await expect( status ).toHaveAttribute( 'data-state', 'error' );

		await page.fill( 'input[name="details"]', 'My printer is on fire' );
		await page.click( '.swiftforms-form__submit' );
		await expect( status ).toHaveAttribute( 'data-state', 'success' );
	} );
} );
