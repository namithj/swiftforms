/**
 * Front-end e2e (P6): the view module on a real WordPress.
 *
 * Covers the AJAX submit and confirmation focus, client validation (summary, field errors, focus),
 * errors only the server finds, the token refresh paths (an old page, an expired token), the logged-in
 * nonce refresh, two forms on one page, the no-JS fallback (PRG) and axe on the form, its error state
 * and its confirmation.
 *
 * Needs a WordPress with the plugin active, admin/password, and WP_BASE_URL. The two token tests move the
 * plugin's clock (the mu-plugin tests/e2e/mu/sf-test-clock.php freezes it to a constant); they run when
 * SF_E2E_NOW_FILE names a file the site reads as SWIFTFORMS_TEST_NOW (the scratch site does) and skip
 * otherwise.
 */
const fs = require( 'fs' );
const crypto = require( 'crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );

const NOW_FILE = process.env.SF_E2E_NOW_FILE || '';
const setClock = ( seconds ) =>
	fs.writeFileSync( NOW_FILE, String( Math.floor( seconds ) ) );
const realNow = () => Date.now() / 1000;

const uuid = () => crypto.randomUUID();
const block = ( name, attrs = {} ) =>
	`<!-- wp:swiftforms/${ name } ${ JSON.stringify( attrs ) } /-->`;
const OPTIONS = [
	{ label: 'Sales', value: 'sales' },
	{ label: 'Support', value: 'support' },
];

/**
 * Markup of a form block with the fields these tests use.
 *
 * @param {string} message Confirmation text.
 * @return {string} Block markup.
 */
function formMarkup( message ) {
	return (
		`<!-- wp:swiftforms/form ${ JSON.stringify( { formId: uuid() } ) } -->` +
		block( 'field-text', {
			label: 'Your name',
			name: 'name',
			required: true,
		} ) +
		block( 'field-email', {
			label: 'Email',
			name: 'email',
			required: true,
		} ) +
		block( 'field-number', {
			label: 'Seats',
			name: 'seats',
			min: '1',
			max: '10',
			step: '1',
		} ) +
		block( 'field-select', {
			label: 'Topic',
			name: 'topic',
			required: true,
			options: OPTIONS,
			placeholderOption: 'Choose a topic',
		} ) +
		block( 'field-checkboxes', {
			label: 'Extras',
			name: 'extras',
			options: OPTIONS,
			minChoices: 2,
		} ) +
		block( 'field-consent', {
			label: 'Consent',
			name: 'agree',
			required: true,
			text: 'I agree to be contacted',
		} ) +
		block( 'submit' ) +
		`<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>${ message }</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->` +
		'<!-- /wp:swiftforms/form -->'
	);
}

const urls = {};

test.describe( 'Front end', () => {
	const make = async ( requestUtils, key, title, content ) => {
		const post = await requestUtils.rest( {
			path: '/wp/v2/pages',
			method: 'POST',
			data: { title, content, status: 'publish' },
		} );
		urls[ key ] = post.link;
	};

	// A new form for every test: the server allows 5 submissions per minute per visitor and form.
	test.beforeEach( async ( { requestUtils } ) => {
		const stamp = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;
		await make(
			requestUtils,
			'one',
			`SF front end ${ stamp }`,
			formMarkup( 'Thank you, we got it.' )
		);
		await make(
			requestUtils,
			'two',
			`SF two forms ${ stamp }`,
			formMarkup( 'First form done.' ) + formMarkup( 'Second form done.' )
		);
		if ( NOW_FILE ) {
			setClock( realNow() );
		}
	} );

	test.afterAll( () => {
		if ( NOW_FILE ) {
			setClock( realNow() );
		}
	} );

	/**
	 * Fill the required fields of a form, then wait out the time trap: a submission faster than
	 * spam.min_seconds (3) is treated as spam, which looks like success and skips validation.
	 *
	 * @param {import('@playwright/test').Locator} form The form.
	 */
	const fillValid = async ( form ) => {
		await form.getByLabel( 'Your name' ).fill( 'Ann Example' );
		await form.getByLabel( 'Email' ).first().fill( 'ann@example.com' );
		await form.getByLabel( 'Topic' ).selectOption( 'sales' );
		await form.getByLabel( 'I agree to be contacted' ).check();
		await form.page().waitForTimeout( 3200 );
	};

	test.describe( 'as a guest', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'client validation shows a summary and field errors and focuses the summary', async ( {
			page,
		} ) => {
			const posts = [];
			page.on(
				'request',
				( r ) => r.method() === 'POST' && posts.push( r.url() )
			);
			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			await expect( form ).toHaveAttribute( 'novalidate', '' );

			await form.getByRole( 'button', { name: 'Submit' } ).click();

			const summary = form.locator( '.swiftforms-errors-summary' );
			await expect( summary ).toBeVisible();
			await expect( summary ).toBeFocused();
			await expect( summary.locator( 'li a' ) ).toHaveCount( 4 );
			await expect(
				form.locator( '.swiftforms-error:not([hidden])' )
			).toHaveCount( 4 );
			await expect( form.getByLabel( 'Your name' ) ).toHaveAttribute(
				'aria-invalid',
				'true'
			);
			await expect(
				form.getByLabel( 'Your name' )
			).toHaveAccessibleDescription( 'This field is required.' );
			await expect(
				form.locator( '.swiftforms-error:not([hidden])' ).first()
			).toHaveText( 'This field is required.' );
			expect( posts ).toEqual( [] );

			// A summary link moves focus to the field.
			await summary.locator( 'li a' ).first().click();
			await expect( form.getByLabel( 'Your name' ) ).toBeFocused();

			// Live: fixing a field clears its error and its summary entry.
			await form.getByLabel( 'Your name' ).fill( 'Ann' );
			await expect( form.getByLabel( 'Your name' ) ).not.toHaveAttribute(
				'aria-invalid',
				'true'
			);
			await expect( summary.locator( 'li a' ) ).toHaveCount( 3 );

			// Client messages are the server's templates.
			await form.getByLabel( 'Email' ).first().fill( 'nope' );
			await form.getByLabel( 'Seats' ).fill( '99' );
			await form.getByRole( 'button', { name: 'Submit' } ).click();
			await expect(
				form.locator( '.swiftforms-error:not([hidden])', {
					hasText: 'valid email',
				} )
			).toHaveText( 'Please enter a valid email address.' );
			await expect(
				form.locator( '.swiftforms-error:not([hidden])', {
					hasText: 'at most 10',
				} )
			).toHaveText( 'Please enter a number that is at most 10.' );
		} );

		test( 'AJAX success replaces the fields with the confirmation and focuses it', async ( {
			page,
		} ) => {
			let body = null;
			page.on( 'request', ( r ) => {
				if (
					r.method() === 'POST' &&
					r.url().includes( 'swiftforms/v1/submit' )
				) {
					body = r.postDataJSON();
				}
			} );
			await page.goto( urls.one );
			await page.evaluate( () => ( window.__noReload = true ) );
			const form = page.locator( 'form.swiftforms-form' );
			await fillValid( form );
			await form.getByLabel( 'Sales' ).check();
			await form.getByLabel( 'Support' ).check();
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			const confirmation = page.locator( '.swiftforms-confirmation' );
			await expect( confirmation ).toHaveText( 'Thank you, we got it.' );
			await expect( confirmation ).toHaveAttribute( 'role', 'status' );
			await expect( confirmation ).toBeFocused();
			await expect(
				page.getByRole( 'button', { name: 'Submit' } )
			).toHaveCount( 0 );
			expect( await page.evaluate( () => window.__noReload ) ).toBe(
				true
			);

			expect( body.sf ).toEqual( {
				name: 'Ann Example',
				email: 'ann@example.com',
				seats: '',
				topic: 'sales',
				extras: [ 'sales', 'support' ],
				agree: '1',
			} );
			expect( body._sf_t ).toMatch( /^js\.\d+$/ );
			expect( body._sf_token ).toMatch( /^v1\./ );
			expect( body._sf_hp ).toBe( '' );
			expect( body ).not.toHaveProperty( '_wpnonce' );
		} );

		test( 'a rule only the server checks shows its error with textContent', async ( {
			page,
		} ) => {
			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			await fillValid( form );
			// An option the form does not have: the client does not know the options.
			await form.getByLabel( 'Topic' ).evaluate( ( select ) => {
				const option = document.createElement( 'option' );
				option.value = '<img src=x onerror=window.__xss=1>';
				option.textContent = 'Other';
				select.append( option );
				select.value = option.value;
			} );
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			const summary = form.locator( '.swiftforms-errors-summary' );
			await expect( summary ).toBeFocused();
			await expect( summary ).toContainText(
				'Please choose one of the available options.'
			);
			await expect( form.getByLabel( 'Topic' ) ).toHaveAttribute(
				'aria-invalid',
				'true'
			);
			await expect(
				form.getByRole( 'button', { name: 'Submit' } )
			).toBeEnabled();
			expect( await page.evaluate( () => window.__xss ) ).toBeUndefined();
		} );

		test( 'closed, rate-limited, unknown and network answers show a message and re-enable the button', async ( {
			page,
		} ) => {
			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			const button = form.getByRole( 'button', { name: 'Submit' } );
			const summary = form.locator( '.swiftforms-errors-summary' );
			await fillValid( form );

			const answer = ( status, json ) =>
				page.route( '**/swiftforms/v1/submit*', ( route ) =>
					route.fulfill( { status, json } )
				);

			await answer( 403, { status: 'closed' } );
			await button.click();
			await expect( summary ).toHaveText(
				'This form is no longer accepting submissions.'
			);
			await expect( summary ).toBeFocused();
			await expect( button ).toBeEnabled();

			await page.unroute( '**/swiftforms/v1/submit*' );
			await answer( 429, { status: 'rate_limited' } );
			await button.click();
			await expect( summary ).toContainText( 'Too many attempts' );

			await page.unroute( '**/swiftforms/v1/submit*' );
			await answer( 200, { status: 'something-new' } );
			await button.click();
			await expect( summary ).toContainText( 'could not be accepted' );

			await page.unroute( '**/swiftforms/v1/submit*' );
			await page.route( '**/swiftforms/v1/submit*', ( route ) =>
				route.abort()
			);
			await button.click();
			await expect( summary ).toContainText( 'could not be sent' );
			await expect( button ).toBeEnabled();
			await expect( form ).not.toHaveAttribute( 'aria-busy', 'true' );
		} );

		test( 'an expired token is refreshed once and the submission is retried', async ( {
			page,
		} ) => {
			test.skip( ! NOW_FILE, 'SF_E2E_NOW_FILE is not set' );
			setClock( realNow() );
			await page.goto( urls.one );
			const log = [];
			page.on( 'request', ( r ) => {
				if ( r.url().includes( 'swiftforms/v1/' ) ) {
					log.push(
						r.method() +
							' ' +
							new URL( r.url() ).pathname.split( '/' ).pop()
					);
				}
			} );
			const form = page.locator( 'form.swiftforms-form' );
			await fillValid( form );

			// The server's clock jumps past the token's life (168 hours); the page does not know.
			setClock( realNow() + 200 * 3600 );
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toHaveText( 'Thank you, we got it.' );
			expect( log ).toEqual( [
				'POST submit',
				'GET token',
				'POST submit',
			] );
			setClock( realNow() );
		} );

		test( 'a page older than 12 hours refreshes its token before the first submit', async ( {
			page,
		} ) => {
			test.skip( ! NOW_FILE, 'SF_E2E_NOW_FILE is not set' );
			setClock( realNow() - 20 * 3600 );
			await page.goto( urls.one );
			const log = [];
			page.on( 'request', ( r ) => {
				if ( r.url().includes( 'swiftforms/v1/' ) ) {
					log.push(
						r.method() +
							' ' +
							new URL( r.url() ).pathname.split( '/' ).pop()
					);
				}
			} );
			const form = page.locator( 'form.swiftforms-form' );
			await fillValid( form );
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toBeVisible();
			expect( log ).toEqual( [ 'GET token', 'POST submit' ] );
			setClock( realNow() );
		} );

		test( 'markup from an older release (no issued key, no summary container) still works', async ( {
			page,
		} ) => {
			await page.route( urls.one, async ( route ) => {
				const response = await route.fetch();
				const html = ( await response.text() )
					.replace( /,&quot;issued&quot;:\d+/, '' )
					.replace(
						/<div class="swiftforms-errors-summary"[^>]*><\/div>/,
						''
					);
				await route.fulfill( { response, body: html } );
			} );
			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			await expect(
				form.locator( '.swiftforms-errors-summary' )
			).toHaveCount( 0 );

			await form.getByRole( 'button', { name: 'Submit' } ).click();
			await expect(
				form.locator( '.swiftforms-errors-summary' )
			).toBeFocused();

			// An unknown token age is refreshed before the first submit.
			const log = [];
			page.on( 'request', ( r ) => {
				if ( r.url().includes( 'swiftforms/v1/' ) ) {
					log.push(
						r.method() +
							' ' +
							new URL( r.url() ).pathname.split( '/' ).pop()
					);
				}
			} );
			await fillValid( form );
			await form.getByRole( 'button', { name: 'Submit' } ).click();
			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toBeVisible();
			expect( log ).toEqual( [ 'GET token', 'POST submit' ] );
		} );

		test( 'two forms on one page work independently', async ( {
			page,
		} ) => {
			await page.goto( urls.two );
			const forms = page.locator( 'form.swiftforms-form' );
			await expect( forms ).toHaveCount( 2 );

			const ids = await page
				.locator( '[id]' )
				.evaluateAll( ( els ) => els.map( ( el ) => el.id ) );
			expect( new Set( ids ).size ).toBe( ids.length );

			// Errors in the second form leave the first alone.
			await forms
				.nth( 1 )
				.getByRole( 'button', { name: 'Submit' } )
				.click();
			await expect(
				forms.nth( 1 ).locator( '.swiftforms-errors-summary' )
			).toBeFocused();
			await expect(
				forms.nth( 0 ).locator( '.swiftforms-errors-summary' )
			).toBeHidden();
			await expect(
				forms.nth( 0 ).locator( '[aria-invalid="true"]' )
			).toHaveCount( 0 );

			// Submitting the first replaces only the first.
			await fillValid( forms.nth( 0 ) );
			await forms
				.nth( 0 )
				.getByRole( 'button', { name: 'Submit' } )
				.click();
			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toHaveText( 'First form done.' );
			await expect(
				forms.nth( 1 ).getByRole( 'button', { name: 'Submit' } )
			).toBeVisible();

			await fillValid( forms.nth( 1 ) );
			await forms
				.nth( 1 )
				.getByRole( 'button', { name: 'Submit' } )
				.click();
			await expect(
				page.locator( '.swiftforms-confirmation' ).nth( 1 )
			).toHaveText( 'Second form done.' );
		} );

		test( 'axe finds no serious violations on the form, its errors and its confirmation', async ( {
			page,
		} ) => {
			const serious = async ( label ) => {
				const results = await new AxeBuilder( { page } )
					.include( 'form.swiftforms-form' )
					.withTags( [
						'wcag2a',
						'wcag2aa',
						'wcag21a',
						'wcag21aa',
						'wcag22aa',
					] )
					.analyze();
				const bad = results.violations
					.filter( ( v ) =>
						[ 'serious', 'critical' ].includes( v.impact )
					)
					.map(
						( v ) =>
							`${ label }: ${ v.id } (${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) })`
					);
				expect( bad ).toEqual( [] );
			};

			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			await serious( 'form' );

			await form.getByRole( 'button', { name: 'Submit' } ).click();
			await expect(
				form.locator( '.swiftforms-errors-summary' )
			).toBeFocused();
			await serious( 'errors' );

			await fillValid( form );
			await form.getByLabel( 'Sales' ).check();
			await form.getByLabel( 'Support' ).check();
			await form.getByRole( 'button', { name: 'Submit' } ).click();
			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toBeFocused();
			await serious( 'confirmation' );
		} );

		test( 'targets are at least 24 by 24 CSS pixels', async ( {
			page,
		} ) => {
			await page.goto( urls.one );
			const small = await page
				.locator(
					'form.swiftforms-form :is(input:not([type="hidden"]):not([tabindex="-1"]), select, textarea, button)'
				)
				.evaluateAll( ( els ) =>
					els
						.map( ( el ) => ( {
							name: el.name || el.tagName,
							box: el.getBoundingClientRect(),
						} ) )
						.filter(
							( { box } ) => box.width < 24 || box.height < 24
						)
						.map( ( { name } ) => name )
				);
			expect( small ).toEqual( [] );
		} );

		test( 'a guest page carries no nonce', async ( { request } ) => {
			const html = await ( await request.get( urls.one ) ).text();
			expect( html ).toContain( 'swiftforms/v1/submit' );
			expect( html ).not.toContain( '"nonce"' );
			expect( html ).not.toContain( '_wpnonce' );
		} );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( {
			storageState: { cookies: [], origins: [] },
			javaScriptEnabled: false,
		} );

		test( 'the form posts natively and the result page shows the confirmation (PRG)', async ( {
			page,
		} ) => {
			await page.goto( urls.one );
			const form = page.locator( 'form.swiftforms-form' );
			await expect( form ).not.toHaveAttribute( 'novalidate', '' );
			await form.getByLabel( 'Your name' ).fill( 'Ann Example' );
			await form.getByLabel( 'Email' ).first().fill( 'ann@example.com' );
			await form.getByLabel( 'Topic' ).selectOption( 'sales' );
			await form.getByLabel( 'I agree to be contacted' ).check();
			// Without JS the server decides what is acceptable and a bot-fast submit is refused; wait the minimum.
			await page.waitForTimeout( 3500 );
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			await expect( page ).toHaveURL( /sf_result=/ );
			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toContainText( 'Thank you, we got it.' );
		} );
	} );

	test.describe( 'as a logged-in user', () => {
		test( 'a stale REST nonce is refreshed once and the submission is retried', async ( {
			page,
		} ) => {
			await page.goto( urls.one );
			const html = await page.content();
			expect( html ).toContain( '"nonce"' );

			const nonces = [];
			let ajaxNonceCalls = 0;
			await page.route( '**/swiftforms/v1/submit*', ( route ) => {
				const headers = route.request().headers();
				nonces.push( headers[ 'x-wp-nonce' ] );
				if ( nonces.length === 1 ) {
					route.continue( {
						headers: { ...headers, 'x-wp-nonce': 'stale0nonce' },
					} );
				} else {
					route.continue();
				}
			} );
			page.on( 'request', ( r ) => {
				if ( r.url().includes( 'action=swiftforms_rest_nonce' ) ) {
					ajaxNonceCalls++;
				}
			} );

			const form = page.locator( 'form.swiftforms-form' );
			await fillValid( form );
			await form.getByLabel( 'Sales' ).check();
			await form.getByLabel( 'Support' ).check();
			await form.getByRole( 'button', { name: 'Submit' } ).click();

			await expect(
				page.locator( '.swiftforms-confirmation' )
			).toHaveText( 'Thank you, we got it.' );
			expect( ajaxNonceCalls ).toBe( 1 );
			expect( nonces ).toHaveLength( 2 );
			expect( nonces[ 0 ] ).toMatch( /^[0-9a-f]{10}$/ );
			expect( nonces[ 1 ] ).toMatch( /^[0-9a-f]{10}$/ );
		} );
	} );
} );
