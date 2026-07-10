/**
 * E2E: SwiftForms spam-protection wiring.
 *
 * The time-trap *logic* (HMAC verification, minimum age, absorb response) is
 * covered by PHPUnit; the suite's mu-plugin disables the minimum so specs can
 * submit instantly. Here we verify the browser-side wiring: the signed
 * timestamp renders into the form and travels with the AJAX request.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	textField,
	createForm,
	createFormPage,
} = require( '../utils/forms' );

test.describe( 'SwiftForms spam protection', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'renders a signed timestamp and submits it with the request', async ( { page, requestUtils } ) => {
		const formId = await createForm( requestUtils, {
			title: 'Trap wiring',
			fields: [ textField( { label: 'Name', slug: 'name', required: true } ) ],
			settings: { successMessage: 'Thanks!' },
		} );
		const formPage = await createFormPage( requestUtils, formId );

		await page.goto( formPage.link );

		// The hidden input carries a `{unix}.{hmac}` token.
		const token = await page
			.locator( '[data-swiftforms-render-ts]' )
			.inputValue();
		expect( token ).toMatch( /^\d{10,}\.[0-9a-f]{64}$/ );

		// The token is forwarded in the AJAX body.
		const requestPromise = page.waitForRequest(
			( request ) =>
				request.url().includes( 'admin-ajax.php' ) &&
				request.method() === 'POST'
		);

		await page.fill( 'input[name="name"]', 'Ada Lovelace' );
		await page.click( '.swiftforms-form__submit' );

		const ajaxRequest = await requestPromise;
		expect( ajaxRequest.postData() ).toContain( 'render_ts' );
		expect( ajaxRequest.postData() ).toContain( token.split( '.' )[ 1 ] );

		await expect( page.locator( '[data-swiftforms-status]' ) ).toHaveText( 'Thanks!' );
	} );
} );
