/**
 * E2E: SwiftForms global settings page.
 *
 * Verifies the page registers under the Forms menu, saves via the Settings
 * API, and round-trips values.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'SwiftForms settings page', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test( 'renders under the Forms menu and saves settings', async ( { page, admin } ) => {
		await admin.visitAdminPage(
			'edit.php',
			'post_type=swiftforms_form&page=swiftforms-settings'
		);

		await expect(
			page.getByRole( 'heading', { name: 'SwiftForms Settings' } )
		).toBeVisible();

		// Fill a couple of SMTP fields and save.
		await page.fill( 'input[name="swiftforms_settings[smtpHost]"]', 'smtp.example.test' );
		await page.fill( 'input[name="swiftforms_settings[smtpPort]"]', '2525' );
		await page.fill(
			'textarea[name="swiftforms_settings[defaultAdminRecipients]"]',
			'team@example.test'
		);
		await page.click( '#submit' );

		// The Settings API redirects back with the saved values.
		await expect( page.locator( '.notice, #setting-error-settings_updated' ).first() ).toBeVisible();
		await expect( page.locator( 'input[name="swiftforms_settings[smtpHost]"]' ) ).toHaveValue( 'smtp.example.test' );
		await expect( page.locator( 'input[name="swiftforms_settings[smtpPort]"]' ) ).toHaveValue( '2525' );
		await expect(
			page.locator( 'textarea[name="swiftforms_settings[defaultAdminRecipients]"]' )
		).toHaveValue( 'team@example.test' );

		// The test email button is present.
		await expect( page.getByRole( 'link', { name: 'Send test email' } ) ).toBeVisible();
	} );
} );
