/**
 * E2E: SwiftForms frontend submission flow.
 *
 * Exercises the AJAX pipeline end to end — happy-path submission, server-side
 * required validation, and the math captcha challenge.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	textField,
	emailField,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

test.describe( 'SwiftForms frontend submission', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'submits a valid form and shows the success message', async ( { page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Contact',
			fields: [
				textField( { label: 'Name', slug: 'name', required: true } ),
				emailField( { label: 'Email', slug: 'email', required: true } ),
			],
			settings: { successMessage: 'Thanks for reaching out!' },
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );
		await page.fill( 'input[name="name"]', 'Ada Lovelace' );
		await page.fill( 'input[name="email"]', 'ada@example.com' );
		await page.click( '.swiftforms-form__submit' );

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'Thanks for reaching out!' );
		await expect( status ).toHaveAttribute( 'data-state', 'success' );
	} );

	test( 'rejects a submission with an empty required field', async ( { page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Required',
			fields: [ textField( { label: 'Name', slug: 'name', required: true } ) ],
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );
		// The form renders with `novalidate`, so submission reaches the server.
		await page.click( '.swiftforms-form__submit' );

		const status = page.locator( '[data-swiftforms-status]' );
		await expect( status ).toHaveText( 'This field is required.' );
		await expect( status ).toHaveAttribute( 'data-state', 'error' );
	} );

	test( 'enforces the math captcha challenge', async ( { page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Captcha',
			fields: [ textField( { label: 'Name', slug: 'name', required: true } ) ],
			settings: { enableCaptcha: true },
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );
		await page.fill( 'input[name="name"]', 'Grace Hopper' );

		const question = await page
			.locator( '.swiftforms-form__captcha-question' )
			.textContent();
		const [ a, b ] = question.match( /\d+/g ).map( Number );

		// Wrong answer is rejected.
		await page.fill( '[data-swiftforms-captcha-answer]', String( a + b + 1 ) );
		await page.click( '.swiftforms-form__submit' );
		await expect( page.locator( '[data-swiftforms-status]' ) ).toHaveText(
			'The captcha answer is incorrect.'
		);

		// Correct answer passes.
		await page.fill( '[data-swiftforms-captcha-answer]', String( a + b ) );
		await page.click( '.swiftforms-form__submit' );
		await expect(
			page.locator( '[data-swiftforms-status]' )
		).toHaveAttribute( 'data-state', 'success' );
	} );
} );
