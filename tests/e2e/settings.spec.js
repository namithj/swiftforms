/**
 * Settings e2e (P10): Forms → Settings is a cassette-cmf settings page (React renderer, REST route
 * swiftforms-cmf/v1/settings/swiftforms, one option per key).
 *
 * Runs against a WordPress with the plugin active. Set SF_WP_PATH to the WordPress folder to let the spec read
 * and reset the options with WP-CLI (the "writes nothing" checks need it); without it those checks are skipped.
 */
const { execSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const ROUTE = '/swiftforms-cmf/v1/settings/swiftforms';
const WP_PATH = process.env.SF_WP_PATH || '';

/**
 * Run WP-CLI against the site under test.
 *
 * @param {string} args Arguments.
 * @return {string} Output.
 */
function wp( args ) {
	return execSync( `wp --path="${ WP_PATH }" ${ args }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} ).trim();
}

/**
 * The settings options that exist, as { name: value }.
 *
 * @return {Object} Options.
 */
function storedOptions() {
	const rows = JSON.parse(
		wp(
			`option list --search="swiftforms_*_*" --fields=option_name,option_value --format=json`
		) || '[]'
	);
	const found = {};
	rows.filter( ( row ) =>
		/^swiftforms_(general|email|spam|privacy|advanced)_/.test(
			row.option_name
		)
	).forEach( ( row ) => {
		found[ row.option_name ] = row.option_value;
	} );
	return found;
}

test.describe( 'Forms → Settings', () => {
	test.beforeEach( () => {
		if ( WP_PATH ) {
			Object.keys( storedOptions() ).forEach( ( name ) =>
				wp( `option delete ${ name }` )
			);
		}
	} );

	async function openSettings( admin, page ) {
		await admin.visitAdminPage( 'admin.php', 'page=swiftforms-settings' );
		await expect(
			page.getByRole( 'heading', {
				name: 'SwiftForms settings',
				level: 1,
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'tab', { name: 'General' } )
		).toBeVisible();
	}

	test( 'is under the Forms menu and shows the five tabs', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );
		await page
			.locator( '#adminmenu a.menu-top', { hasText: 'Forms' } )
			.first()
			.click();
		const link = page.locator(
			'#adminmenu .wp-submenu a[href="admin.php?page=swiftforms-settings"]'
		);
		await expect( link ).toHaveCount( 1 );
		await link.click();
		await expect( page ).toHaveURL( /page=swiftforms-settings/ );
		for ( const name of [
			'General',
			'Email',
			'Spam',
			'Privacy',
			'Advanced',
		] ) {
			await expect( page.getByRole( 'tab', { name } ) ).toBeVisible();
		}
		await expect(
			page.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();
	} );

	test( 'a value on each tab is saved and survives a reload', async ( {
		admin,
		page,
	} ) => {
		await openSettings( admin, page );

		await page
			.getByLabel( 'Default success message' )
			.fill( 'Thanks, <b>we</b> will reply.' );
		await page.getByRole( 'tab', { name: 'Email' } ).click();
		await page.getByLabel( 'From name' ).fill( 'Acme Support' );
		await page.getByRole( 'tab', { name: 'Spam' } ).click();
		await page
			.getByLabel( 'Submissions per minute, per form and visitor' )
			.fill( '9' );
		await page.getByRole( 'tab', { name: 'Privacy' } ).click();
		await page.getByLabel( 'Delete entries after (days)' ).fill( '45' );
		await page.getByRole( 'tab', { name: 'Advanced' } ).click();
		await page
			.getByLabel( 'Write detailed diagnostics to the log' )
			.check();

		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect(
			page.getByText( 'Settings saved.' ).first()
		).toBeVisible();

		await page.reload();
		await expect(
			page.getByRole( 'tab', { name: 'General' } )
		).toBeVisible();
		await expect(
			page.getByLabel( 'Default success message' )
		).toHaveValue( 'Thanks, <b>we</b> will reply.' );
		await page.getByRole( 'tab', { name: 'Email' } ).click();
		await expect( page.getByLabel( 'From name' ) ).toHaveValue(
			'Acme Support'
		);
		await page.getByRole( 'tab', { name: 'Spam' } ).click();
		await expect(
			page.getByLabel( 'Submissions per minute, per form and visitor' )
		).toHaveValue( '9' );
		await page.getByRole( 'tab', { name: 'Privacy' } ).click();
		await expect(
			page.getByLabel( 'Delete entries after (days)' )
		).toHaveValue( '45' );
		await page.getByRole( 'tab', { name: 'Advanced' } ).click();
		await expect(
			page.getByLabel( 'Write detailed diagnostics to the log' )
		).toBeChecked();

		if ( WP_PATH ) {
			expect( storedOptions() ).toEqual( {
				swiftforms_advanced_debug_log: '1',
				swiftforms_email_from_name: 'Acme Support',
				swiftforms_general_default_success_message:
					'Thanks, <b>we</b> will reply.',
				swiftforms_general_retention_days: '45',
				swiftforms_spam_rate_limit_per_minute: '9',
			} );
		}
	} );

	test( 'the warning shows only while saving entries is off', async ( {
		admin,
		page,
	} ) => {
		await openSettings( admin, page );
		const warning = page.getByText(
			'If an email fails, the submission is lost.'
		);
		await expect( warning ).toHaveCount( 0 );
		await page.getByLabel( 'Save entries in the database' ).uncheck();
		await expect( warning ).toBeVisible();
		await page.getByLabel( 'Save entries in the database' ).check();
		await expect( warning ).toHaveCount( 0 );
	} );

	test( 'an invalid email shows an inline error and saves nothing', async ( {
		admin,
		page,
	} ) => {
		await openSettings( admin, page );
		await page.getByRole( 'tab', { name: 'Email' } ).click();
		await page.getByLabel( 'From email' ).fill( 'not-an-email' );
		await page
			.getByLabel( 'From name' )
			.fill( 'Valid but must not be saved' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect(
			page.getByText( 'Enter a valid email address, or leave it empty.' )
		).toBeVisible();
		await expect( page.getByLabel( 'From email' ) ).toBeFocused();

		if ( WP_PATH ) {
			expect( storedOptions() ).toEqual( {} );
		}

		await page.getByLabel( 'From email' ).fill( 'hello@example.org' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect(
			page.getByText( 'Settings saved.' ).first()
		).toBeVisible();
	} );

	test( 'saving what GET returned writes nothing', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await openSettings( admin, page );
		await expect(
			page.getByRole( 'button', { name: 'Save Changes' } )
		).toBeDisabled();

		const before = WP_PATH ? storedOptions() : {};
		const got = await requestUtils.rest( { path: ROUTE } );
		const posted = await requestUtils.rest( {
			method: 'POST',
			path: ROUTE,
			data: { values: got.values },
		} );
		expect( posted.values ).toEqual( got.values );
		if ( WP_PATH ) {
			expect( before ).toEqual( {} );
			expect( storedOptions() ).toEqual( {} );
		}
	} );
} );
