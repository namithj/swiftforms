/**
 * Admin e2e (P11): Forms → Entries as a WordPress list table with a single-entry screen.
 *
 * Runs against a WordPress with the plugin active (the scratch site, or wp-env). It creates pages with forms,
 * submits entries through the public REST routes (the way the view module does), then drives the admin screens:
 * the menu and the empty state, list → entry click-through, every row action and every action on the entry
 * screen (with their notices), views, the form filter, sorting, Screen Options and pagination, and the XSS corpus
 * on the list, the entry screen and the print view (mutation 17: a `javascript:` value is never a link).
 * The Settings screen has its own spec (settings.spec.js).
 */
const crypto = require( 'crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { request: playwrightRequest } = require( '@playwright/test' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8889';

// The nasty corpus of config 05: none of it may run, become markup or become a link.
const NASTY = {
	script: '<script>window.__sfxss = "script"</script>',
	javascript: 'javascript:window.__sfxss="url"',
	attribute: '"><svg onload="window.__sfxss=\'svg\'">',
	rtl: 'evil‮gnp.exe',
};

/**
 * Create a page with a form of its own (a fresh formId, so the per-form limiter starts empty).
 *
 * @param {Object} requestUtils Core test utils.
 * @param {string} title        Page title.
 * @return {Promise<{formId: string, source: string}>} The form.
 */
async function newForm( requestUtils, title ) {
	const formId = crypto.randomUUID();
	const content = `<!-- wp:swiftforms/form {"formId":"${ formId }"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name"} /-->
<!-- wp:swiftforms/field-email {"label":"Email","name":"email"} /-->
<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message"} /-->
<!-- wp:swiftforms/submit /-->
<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>Thanks!</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->`;
	const page = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: { title, status: 'publish', content },
	} );
	return { formId, source: `post:${ page.id }` };
}

/**
 * Submit an entry the way the view module does.
 *
 * @param {Object} form   { formId, source } from newForm().
 * @param {Object} fields Field values by name.
 */
async function submit( form, fields ) {
	const api = await playwrightRequest.newContext( { baseURL: BASE } );
	const query = `formId=${ form.formId }&source=${ encodeURIComponent(
		form.source
	) }`;
	// The coarse per-visitor limiter (60 requests a minute) can trip when specs run back to back: wait it out.
	let token;
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		token = await api.get( `/?rest_route=/swiftforms/v1/token&${ query }` );
		if ( token.status() !== 429 ) {
			break;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 61000 ) );
	}
	expect( token.ok(), 'token route' ).toBe( true );
	const { token: renderToken } = await token.json();
	const response = await api.post( '/?rest_route=/swiftforms/v1/submit', {
		data: {
			_sf_form: form.formId,
			_sf_source: form.source,
			_sf_token: renderToken,
			_sf_hp: '',
			_sf_t: 'js.9000',
			_sf_v: '1',
			sf: fields,
		},
	} );
	expect( response.ok(), await response.text() ).toBe( true );
	await api.dispose();
}

/**
 * Delete every entry, through the REST API.
 *
 * @param {Object} requestUtils Core test utils.
 */
async function clearEntries( requestUtils ) {
	for ( const status of [ 'new', 'read', 'spam', 'trash' ] ) {
		const items = await requestUtils.rest( {
			path: '/swiftforms/v1/entries',
			params: { status, per_page: 100 },
		} );
		for ( const item of items ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/swiftforms/v1/entries/${ item.id }`,
			} );
		}
	}
}

/**
 * The row of an entry in the list, found by a text it shows.
 *
 * @param {Object} page Playwright page.
 * @param {string} text Text in the row.
 * @return {Object} Locator.
 */
function row( page, text ) {
	return page.locator( '#the-list tr', { hasText: text } );
}

/**
 * Hover a row (row actions show on hover) and click one of its actions.
 *
 * @param {Object} page   Playwright page.
 * @param {string} text   Text in the row.
 * @param {string} action Row action class: read, unread, star, unstar, spam, unspam, trash, untrash, delete, view.
 */
async function rowAction( page, text, action ) {
	const target = row( page, text );
	await target.hover();
	await target.locator( `.row-actions .${ action } a` ).click();
}

/**
 * A status view link above the table (All, Unread, Read, Starred, Spam, Trash).
 *
 * @param {Object} page Playwright page.
 * @param {string} name View name.
 * @return {Object} Locator.
 */
function viewLink( page, name ) {
	return page
		.locator( '.subsubsub' )
		.getByRole( 'link', { name: new RegExp( `^${ name }` ) } );
}

const notice = ( page ) => page.locator( '#message.notice-success' );

test.describe( 'Forms admin: Entries', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// Switch the per-form limiter off where the settings can be written through the REST API. Every test uses a form of its
		// own, so the default limit is enough where that is not possible.
		try {
			await requestUtils.rest( {
				method: 'POST',
				path: '/swiftforms/v1/settings',
				data: { spam: { rate_limit_per_minute: 0, min_seconds: 0 } },
			} );
		} catch {
			// The settings route is not there (Settings is a cassette-cmf page).
		}
		await clearEntries( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await clearEntries( requestUtils );
	} );

	test( 'the menu has Forms with Entries first, and the screen is a list table', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		await expect(
			page.locator( '#toplevel_page_swiftforms' )
		).toBeVisible();
		const submenu = page.locator(
			'#toplevel_page_swiftforms .wp-submenu li:not(.wp-submenu-head) a'
		);
		await expect( submenu.first() ).toHaveText( 'Entries' );
		await expect(
			page.locator( 'body.toplevel_page_swiftforms' )
		).toHaveCount( 1 );
		await expect(
			page.getByRole( 'heading', { name: 'Entries', level: 1 } )
		).toBeVisible();
		await expect( page.locator( 'table.wp-list-table' ) ).toBeVisible();
		await expect( page.locator( '.subsubsub' ) ).toContainText( 'All' );
	} );

	test( 'shows the empty state', async ( { page, admin, requestUtils } ) => {
		await clearEntries( requestUtils );
		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		await expect(
			page.getByText( 'No entries yet. Add a Form block to any page.' )
		).toBeVisible();

		// With some entries stored, an empty view says so differently.
		const form = await newForm( requestUtils, 'Contact' );
		await submit( form, {
			name: 'Only One',
			email: 'one@example.com',
			message: 'x',
		} );
		await admin.visitAdminPage(
			'admin.php',
			'page=swiftforms&status=trash'
		);
		await expect( page.getByText( 'No entries found.' ) ).toBeVisible();
	} );

	test( 'opens an entry from the list and applies the actions on the entry screen', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		await clearEntries( requestUtils );
		const form = await newForm( requestUtils, 'Contact' );
		await submit( form, {
			name: 'Ada Lovelace',
			email: 'ada@example.com',
			message: 'Hello from Ada',
		} );

		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		const ada = row( page, 'Ada Lovelace' );
		await expect( ada ).toHaveClass( /unread/ );

		// Click through to the entry screen: labels and values from the snapshot, the address as a mailto link.
		await ada.getByRole( 'link', { name: /Ada Lovelace/ } ).click();
		await expect( page ).toHaveURL( /action=view&entry=\d+/ );
		await expect(
			page.getByRole( 'heading', { name: /^Entry #\d+/, level: 1 } )
		).toBeVisible();
		const fields = page.locator( 'table.swiftforms-entry-fields' );
		await expect( fields.getByText( 'Hello from Ada' ) ).toBeVisible();
		await expect(
			fields.getByText( 'Message', { exact: true } )
		).toBeVisible();
		await expect(
			fields.getByRole( 'link', { name: 'ada@example.com' } )
		).toHaveAttribute( 'href', 'mailto:ada@example.com' );
		await expect( page.locator( '#swiftforms-entry-box' ) ).toContainText(
			'Contact'
		);

		// Opening marked it read.
		const status = page.locator( '.swiftforms-entry-status' );
		await expect( status ).toHaveText( 'Read' );

		// Actions in the box, each with its notice, staying on the entry screen.
		const box = page.locator( '#swiftforms-entry-box' );
		await box.getByRole( 'link', { name: 'Mark as unread' } ).click();
		await expect( notice( page ) ).toHaveText( /Entry marked as unread\./ );
		await expect( status ).toHaveText( 'Unread' );
		await box.getByRole( 'link', { name: 'Star', exact: true } ).click();
		await expect( notice( page ) ).toHaveText( /Entry starred\./ );
		await expect( box ).toContainText( 'Starred: Yes' );
		await box.getByRole( 'link', { name: 'Unstar' } ).click();
		await expect( box ).toContainText( 'Starred: No' );
		await box.getByRole( 'link', { name: 'Spam', exact: true } ).click();
		await expect( status ).toHaveText( 'Spam' );
		await box.getByRole( 'link', { name: 'Not spam' } ).click();
		await expect( status ).toHaveText( 'Unread' );

		// Print: the button calls window.print(); the print view keeps only the heading and the fields.
		await page.evaluate( () => {
			window.__printed = 0;
			window.print = () => {
				window.__printed++;
			};
		} );
		await box.getByRole( 'button', { name: 'Print' } ).click();
		expect( await page.evaluate( () => window.__printed ) ).toBe( 1 );
		await page.emulateMedia( { media: 'print' } );
		await expect( fields ).toBeVisible();
		await expect( page.locator( '#adminmenumain' ) ).toBeHidden();
		await expect( page.locator( '#wpadminbar' ) ).toBeHidden();
		await expect( box ).toBeHidden();
		await page.emulateMedia( { media: 'screen' } );

		// Trash goes back to the list with an Undo link.
		await box.getByRole( 'link', { name: 'Trash' } ).click();
		await expect( page ).not.toHaveURL( /action=view/ );
		await expect( notice( page ) ).toContainText(
			'Entry moved to the Trash.'
		);
		await expect( row( page, 'Ada Lovelace' ) ).toHaveCount( 0 );
		await notice( page ).getByRole( 'link', { name: 'Undo' } ).click();
		await expect( notice( page ) ).toContainText(
			'Entry restored from the Trash.'
		);
		await expect( row( page, 'Ada Lovelace' ) ).toBeVisible();

		// A deleted or unknown entry is a clear error, not a blank page.
		await page.goto(
			'/wp-admin/admin.php?page=swiftforms&action=view&entry=99999999'
		);
		await expect(
			page.getByText( 'There is no such entry.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Back to Entries' } )
		).toBeVisible();
	} );

	test( 'applies every row action from the list', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		await clearEntries( requestUtils );
		const form = await newForm( requestUtils, 'Contact' );
		await submit( form, {
			name: 'Grace Hopper',
			email: 'grace@example.com',
			message: 'Hello from Grace',
		} );

		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		const grace = () => row( page, 'Grace Hopper' );
		await expect( grace() ).toHaveClass( /unread/ );

		await rowAction( page, 'Grace Hopper', 'read' );
		await expect( notice( page ) ).toContainText( 'Entry marked as read.' );
		await expect( grace() ).not.toHaveClass( /unread/ );

		await rowAction( page, 'Grace Hopper', 'unread' );
		await expect( notice( page ) ).toContainText(
			'Entry marked as unread.'
		);
		await expect( grace() ).toHaveClass( /unread/ );

		await rowAction( page, 'Grace Hopper', 'star' );
		await expect( notice( page ) ).toContainText( 'Entry starred.' );
		await expect( grace().locator( '.swiftforms-star' ) ).toBeVisible();
		await expect(
			grace().locator( '.screen-reader-text', { hasText: 'Starred' } )
		).toHaveCount( 1 );
		await rowAction( page, 'Grace Hopper', 'unstar' );
		await expect( notice( page ) ).toContainText( 'Star removed.' );
		await expect( grace().locator( '.swiftforms-star' ) ).toHaveCount( 0 );

		// Spam leaves All and shows in the Spam view, where it can be restored.
		await rowAction( page, 'Grace Hopper', 'spam' );
		await expect( notice( page ) ).toContainText( 'Entry marked as spam.' );
		await expect( grace() ).toHaveCount( 0 );
		await viewLink( page, 'Spam' ).click();
		await expect( grace() ).toBeVisible();
		await rowAction( page, 'Grace Hopper', 'unspam' );
		await expect( notice( page ) ).toContainText(
			'Entry marked as not spam.'
		);
		await expect( page ).toHaveURL( /status=spam/ );
		await expect( grace() ).toHaveCount( 0 );

		// Trash, restore from the Trash view, trash again and delete permanently.
		await viewLink( page, 'All' ).click();
		await rowAction( page, 'Grace Hopper', 'trash' );
		await expect( notice( page ) ).toContainText(
			'Entry moved to the Trash.'
		);
		await viewLink( page, 'Trash' ).click();
		await rowAction( page, 'Grace Hopper', 'untrash' );
		await expect( notice( page ) ).toContainText(
			'Entry restored from the Trash.'
		);
		await viewLink( page, 'All' ).click();
		await rowAction( page, 'Grace Hopper', 'trash' );
		await viewLink( page, 'Trash' ).click();
		await rowAction( page, 'Grace Hopper', 'delete' );
		await expect( notice( page ) ).toContainText(
			'Entry deleted permanently.'
		);
		await expect( grace() ).toHaveCount( 0 );
		const left = await requestUtils.rest( {
			path: '/swiftforms/v1/entries',
			params: { status: 'trash' },
		} );
		expect( left ).toHaveLength( 0 );
	} );

	test( 'filters by view and form, sorts, and pages with Screen Options', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		await clearEntries( requestUtils );
		const a = await newForm( requestUtils, 'Alpha form page' );
		const b = await newForm( requestUtils, 'Beta form page' );
		for ( const name of [ 'Ann One', 'Bob Two', 'Cy Three' ] ) {
			await submit( a, {
				name,
				email: `${ name.split( ' ' )[ 0 ].toLowerCase() }@example.com`,
				message: 'x',
			} );
		}
		await submit( b, {
			name: 'Dee Four',
			email: 'dee@example.com',
			message: 'x',
		} );
		const all = await requestUtils.rest( {
			path: '/swiftforms/v1/entries',
			params: { per_page: 100 },
		} );
		const idOf = ( name ) =>
			all.find( ( item ) => item.summary.startsWith( name ) ).id;
		await requestUtils.rest( {
			method: 'PATCH',
			path: `/swiftforms/v1/entries/${ idOf( 'Ann One' ) }`,
			data: { starred: true, status: 'read' },
		} );
		await requestUtils.rest( {
			method: 'PATCH',
			path: `/swiftforms/v1/entries/${ idOf( 'Bob Two' ) }`,
			data: { status: 'spam' },
		} );

		// Views with counts.
		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		const views = page.locator( '.subsubsub' );
		await expect( views ).toContainText( 'All (3)' );
		await expect( views ).toContainText( 'Unread (2)' );
		await expect( views ).toContainText( 'Read (1)' );
		await expect( views ).toContainText( 'Starred (1)' );
		await expect( views ).toContainText( 'Spam (1)' );
		await expect( views ).toContainText( 'Trash (0)' );
		await expect( row( page, 'Bob Two' ) ).toHaveCount( 0 );
		await views.getByRole( 'link', { name: /^Starred/ } ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( row( page, 'Ann One' ) ).toBeVisible();
		await views.getByRole( 'link', { name: /^Unread/ } ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );

		// Form filter.
		await views.getByRole( 'link', { name: /^All/ } ).click();
		await page
			.locator( '#swiftforms-filter-form' )
			.selectOption( { label: 'Beta form page' } );
		await page.locator( '#swiftforms-filter-submit' ).click();
		await expect( page ).toHaveURL( /form=/ );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );
		await expect( row( page, 'Dee Four' ) ).toBeVisible();
		await page
			.locator( '#swiftforms-filter-form' )
			.selectOption( { label: 'All forms' } );
		await page.locator( '#swiftforms-filter-submit' ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 3 );

		// Date sorting: newest first by default, oldest first after a click on the header.
		const first = () => page.locator( '#the-list tr' ).first();
		await expect( first() ).toContainText( 'Dee Four' );
		await page.locator( 'th#date a' ).click();
		await expect( page ).toHaveURL( /order=asc/ );
		await expect( first() ).toContainText( 'Ann One' );

		// Screen Options: two entries a page, then the pager.
		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		await page.locator( '#swiftforms_entries_per_page' ).fill( '2' );
		await page.locator( '#screen-options-apply' ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 2 );
		await expect(
			page.locator( '.tablenav.top .displaying-num' )
		).toHaveText( '3 items' );
		await page.locator( '.tablenav.top .next-page' ).click();
		await expect( page ).toHaveURL( /paged=2/ );
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 1 );

		// Put the page size back.
		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		await page.locator( '#swiftforms_entries_per_page' ).fill( '20' );
		await page.locator( '#screen-options-apply' ).click();
		await expect( page.locator( '#the-list tr' ) ).toHaveCount( 3 );
	} );

	test( 'visitor values stay text on the list, the entry screen and the print view', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		await clearEntries( requestUtils );
		const form = await newForm( requestUtils, 'Nasty' );
		const dialogs = [];
		page.on( 'dialog', async ( dialog ) => {
			dialogs.push( dialog.message() );
			await dialog.dismiss();
		} );

		await submit( form, {
			name: NASTY.script,
			email: 'nasty@example.com',
			message: NASTY.javascript,
		} );
		await submit( form, {
			name: NASTY.attribute,
			email: 'nasty2@example.com',
			message: NASTY.rtl,
		} );
		await submit( form, {
			name: 'Link person',
			email: 'link@example.com',
			message: 'https://example.com/path?a=b',
		} );

		const assertClean = async () => {
			expect( dialogs, 'no dialog opened' ).toEqual( [] );
			expect(
				await page.evaluate( () => window.__sfxss ),
				'no payload ran'
			).toBeUndefined();
			await expect(
				page.locator(
					'#wpbody-content svg[onload], #wpbody-content script:not([src])'
				)
			).toHaveCount( 0 );
			await expect(
				page.locator( '#wpbody-content [onload]' )
			).toHaveCount( 0 );
			await expect(
				page.locator( 'a[href^="javascript:" i]' )
			).toHaveCount( 0 );
		};

		await admin.visitAdminPage( 'admin.php', 'page=swiftforms' );
		await expect( row( page, 'Link person' ) ).toBeVisible();
		// The script text is shown as text.
		await expect( page.getByText( NASTY.script ).first() ).toBeVisible();
		await assertClean();

		// The entry screen of each nasty entry, then its print view.
		for ( const email of [ 'nasty@example.com', 'nasty2@example.com' ] ) {
			await row( page, email )
				.getByRole( 'link', { name: new RegExp( email ) } )
				.first()
				.click();
			await expect(
				page.locator( 'table.swiftforms-entry-fields' )
			).toBeVisible();
			await assertClean();
			await page.emulateMedia( { media: 'print' } );
			await expect(
				page.locator( 'table.swiftforms-entry-fields' )
			).toBeVisible();
			await assertClean();
			await page.emulateMedia( { media: 'screen' } );
			await page.getByRole( 'link', { name: /Back to Entries/ } ).click();
		}

		// Mutation 17: the javascript: URL is text, not a link.
		await row( page, 'nasty@example.com' )
			.getByRole( 'link', { name: /nasty@example\.com/ } )
			.first()
			.click();
		const fields = page.locator( 'table.swiftforms-entry-fields' );
		await expect( fields.getByText( NASTY.javascript ) ).toBeVisible();
		await expect(
			fields.getByRole( 'link', { name: /javascript/ } )
		).toHaveCount( 0 );
		await expect(
			fields.locator( 'td', { hasText: NASTY.javascript } ).locator( 'a' )
		).toHaveCount( 0 );
		await assertClean();
		await page.getByRole( 'link', { name: /Back to Entries/ } ).click();

		// The RTL override is shown visibly escaped.
		await row( page, 'nasty2@example.com' )
			.getByRole( 'link', { name: /nasty2@example\.com/ } )
			.first()
			.click();
		await expect(
			page.getByText( /evil\[U\+202E\]gnp\.exe/ )
		).toBeVisible();
		await page.getByRole( 'link', { name: /Back to Entries/ } ).click();

		// An https URL is a safe link.
		await row( page, 'Link person' )
			.getByRole( 'link', { name: /Link person/ } )
			.first()
			.click();
		const link = page
			.locator( 'table.swiftforms-entry-fields' )
			.getByRole( 'link', { name: 'https://example.com/path?a=b' } );
		await expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		await expect( link ).toHaveAttribute(
			'href',
			'https://example.com/path?a=b'
		);
		await assertClean();
	} );
} );
