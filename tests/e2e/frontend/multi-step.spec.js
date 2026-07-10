/**
 * E2E: SwiftForms multi-step forms.
 *
 * Step navigation is injected client-side by view.js: Back/Next buttons, an
 * aria-live progress line, per-step validation before advancing, and the
 * submit button only on the last step. Inputs in inactive steps stay enabled
 * so their values submit with the final request.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	textField,
	emailField,
	step,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

test.describe( 'SwiftForms multi-step forms', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	const createSteppedForm = async ( requestUtils ) => {
		const formId = await createForm( requestUtils, {
			title: 'Stepped',
			fields: [
				step( 'About you', [ textField( { label: 'Name', slug: 'name', required: true } ) ] ),
				step( 'Contact', [ emailField( { label: 'Email', slug: 'email', required: true } ) ] ),
			],
			settings: { successMessage: 'All done!' },
		} );

		return createFormPage( requestUtils, formId );
	};

	test( 'walks through steps with per-step validation', async ( { page, requestUtils } ) => {
		const formPage = await createSteppedForm( requestUtils );

		await page.goto( formPage.link );

		const nameInput = page.locator( 'input[name="name"]' );
		const emailInput = page.locator( 'input[name="email"]' );
		const nextButton = page.locator( '.swiftforms-step-nav__next' );
		const previousButton = page.locator( '.swiftforms-step-nav__previous' );
		const submitButton = page.locator( '.swiftforms-form__submit' );
		const progress = page.locator( '.swiftforms-step-progress' );

		// Step 1 visible, step 2 hidden, submit hidden, no Back on first step.
		await expect( nameInput ).toBeVisible();
		await expect( emailInput ).toBeHidden();
		await expect( submitButton ).toBeHidden();
		await expect( previousButton ).toBeHidden();
		await expect( progress ).toHaveText( 'Step 1 of 2 — About you' );

		// Next is blocked by the empty required field in the current step.
		await nextButton.click();
		await expect( page.locator( '.swiftforms-field__error' ) ).toHaveText( 'This field is required.' );
		await expect( nameInput ).toBeVisible();

		// Filling it advances to step 2.
		await nameInput.fill( 'Ada Lovelace' );
		await nextButton.click();
		await expect( emailInput ).toBeVisible();
		await expect( nameInput ).toBeHidden();
		await expect( nextButton ).toBeHidden();
		await expect( submitButton ).toBeVisible();
		await expect( progress ).toHaveText( 'Step 2 of 2 — Contact' );

		// Back returns without validation.
		await previousButton.click();
		await expect( nameInput ).toBeVisible();
		await nextButton.click();

		// Submitting from the last step sends values from BOTH steps.
		await emailInput.fill( 'ada@example.com' );
		await submitButton.click();

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'All done!' );
		await expect( status ).toHaveAttribute( 'data-state', 'success' );
	} );
} );
