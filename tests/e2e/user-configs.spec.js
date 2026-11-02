/**
 * User-config e2e checks (S12, S13), run once per config by bin/run-e2e-configs.mjs with SF_CONFIG=<slug>.
 *
 * The runner has already reset the site, applied the config (tests/user-configs/apply-cli.php) and written what the
 * config stored (tests/e2e/dump-posts.php) to tests/e2e/.state/<slug>.json.
 *
 * S12 (editor validity and lossless save). Every post, page and synced pattern, every template and template part saved to
 * the database, and every theme-file template or part that holds a form opens in the block editor (the site editor for
 * templates) with no block that fails validation, and saving it without edits leaves post_content byte for byte as it was.
 * Two documented exceptions: a block the editor does not know (config 18's future blocks) shows the editor's "missing block"
 * placeholder, which is not a validation failure; and the server writes the effective formId into a block on save (C-2.7: a
 * form without an ID, a copied ID), which is the only difference config 18 may show. A theme-file template is opened and never saved.
 *
 * S13 (front-end JavaScript). On every public page that shows a form, as a guest: the client validation messages and the focus on
 * the error summary, the AJAX submission and its confirmation (or the plain form post for a form without JavaScript, or the redirect),
 * two forms on one page, and axe (no serious or critical finding inside the SwiftForms markup) in the initial, error and confirmation states.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );

const slug = process.env.SF_CONFIG;
const statePath = slug
	? path.join( __dirname, '.state', `${ slug }.json` )
	: '';
const records =
	statePath && fs.existsSync( statePath )
		? JSON.parse( fs.readFileSync( statePath, 'utf8' ) )
		: [];
const NOW_FILE = process.env.SF_E2E_NOW_FILE || '';
const FROZEN_TIME = 1795082400;
const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

// Config 12 asks for eight seconds before a submission counts as human; the others for three.
const WAIT_MS = '12-spam-hardened' === slug ? 8500 : 3500;

/**
 * Whether a raw block markup string differs only by the formId values (C-2.7 writes).
 *
 * @param {string} content Block markup.
 * @return {string} The markup with every formId replaced by a fixed value and an empty attribute object removed.
 */
function withoutFormIds( content ) {
	return content
		.replace( /"formId":"[^"]*",?/g, '' )
		.replace(
			/<!-- wp:swiftforms\/form \{\} -->/g,
			'<!-- wp:swiftforms/form -->'
		)
		.replace( /,\}/g, '}' );
}

/**
 * Open a record in the editor and wait until its blocks are on the screen.
 *
 * @param {Object} fixtures        Playwright fixtures: admin, editor, page.
 * @param {Object} fixtures.admin  Admin utilities.
 * @param {Object} fixtures.editor Editor utilities.
 * @param {Object} fixtures.page   Page.
 * @param {Object} record          An entry of the state file.
 */
async function openInEditor( { admin, editor, page }, record ) {
	if ( 'post' === record.kind ) {
		await admin.visitAdminPage(
			'post.php',
			`post=${ record.id }&action=edit`
		);
		await editor.setPreferences( 'core/edit-post', {
			welcomeGuide: false,
			fullscreenMode: false,
		} );
	} else {
		await admin.visitAdminPage(
			'site-editor.php',
			`postType=${ record.type }&postId=${ encodeURIComponent(
				record.id
			) }&canvas=edit`
		);
		await editor.setPreferences( 'core/edit-site', {
			welcomeGuide: false,
			welcomeGuideStyles: false,
			welcomeGuideEditor: false,
			welcomeGuideTemplate: false,
			welcomeGuidePage: false,
		} );
	}
	await page.reload();
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.()
	);
	if ( record.hasBlocks ) {
		await page.waitForFunction(
			() =>
				window.wp.data.select( 'core/block-editor' ).getBlocks()
					.length > 0
		);
	}
	// Block validation runs while the blocks are parsed; give the canvas a moment to draw the warnings.
	await page.waitForTimeout( 1500 );
}

/**
 * The blocks of the open editor that fail validation, and the ones that are placeholders for a block the editor does not know.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<{invalid: string[], missing: string[]}>} Block names.
 */
function blockProblems( page ) {
	return page.evaluate( () => {
		const names = { invalid: [], missing: [] };
		const walk = ( blocks ) => {
			for ( const block of blocks ) {
				if ( 'core/missing' === block.name ) {
					names.missing.push(
						block.attributes.originalName || 'core/missing'
					);
				} else if ( ! block.isValid ) {
					names.invalid.push( block.name );
				}
				walk( block.innerBlocks );
			}
		};
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return names;
	} );
}

/**
 * Block warnings on the screen, in the page and in its frames (the editor canvas).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Count.
 */
async function countWarnings( page ) {
	let total = 0;
	for ( const frame of page.frames() ) {
		total += await frame.locator( '.block-editor-warning' ).count();
	}
	return total;
}

test.describe( `User config ${ slug || '(SF_CONFIG not set)' }`, () => {
	test( 'has a state file written by the runner', () => {
		expect(
			slug,
			'SF_CONFIG must be set (use npm run test:e2e:configs)'
		).toBeTruthy();
		expect( fs.existsSync( statePath ), `missing ${ statePath }` ).toBe(
			true
		);
	} );

	// ---------------------------------------------------------------------
	// S12.
	// ---------------------------------------------------------------------
	for ( const record of records ) {
		test( `S12 editor validity and lossless save: ${ record.kind } (${ record.source }) #${ record.id } "${ record.title }"`, async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			const endpoint = `/wp/v2/${ record.restBase }/${ record.id }`;
			const readRaw = async () => {
				const entity = await requestUtils.rest( {
					path: endpoint,
					params: { context: 'edit' },
				} );
				return entity.content.raw;
			};

			const before = await readRaw();
			await openInEditor( { admin, editor, page }, record );

			const problems = await blockProblems( page );
			if ( process.env.SF_E2E_DEBUG ) {
				// eslint-disable-next-line no-console
				console.log( JSON.stringify( problems ) );
			}
			expect(
				problems.invalid,
				'blocks that fail validation (the "unexpected or invalid content" warning)'
			).toEqual( [] );
			// The warnings on the screen are exactly the placeholders for blocks the editor does not know.
			// The canvas is an iframe in the current editors: count the warnings in the page and in every frame.
			await expect
				.poll( () => countWarnings( page ), {
					message: 'warnings on the editor canvas',
				} )
				.toBe( problems.missing.length );
			if ( '18-corrupt-and-partial' !== slug ) {
				expect( problems.missing, 'unknown blocks' ).toEqual( [] );
			} else if ( record.title === 'From the future' ) {
				expect( problems.missing.sort() ).toEqual( [
					'swiftforms/field-rating',
					'swiftforms/step',
				] );
			}

			if ( 'theme' === record.source ) {
				return; // Saving would copy the file into the database; a theme file is only opened.
			}

			// Save without edits: re-serialise exactly what the editor holds and save it.
			await page.evaluate( async () => {
				const { select, dispatch } = window.wp.data;
				dispatch( 'core/editor' ).editPost( {
					content: select( 'core/editor' ).getEditedPostContent(),
				} );
				await dispatch( 'core/editor' ).savePost();
			} );
			await page.waitForFunction(
				() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
			);

			const after = await readRaw();
			if ( '18-corrupt-and-partial' === slug ) {
				// C-2.7: the server writes the effective formId of a copy and of a form without one; nothing else may change.
				expect( withoutFormIds( after ) ).toBe(
					withoutFormIds( before )
				);
			} else {
				expect( after ).toBe( before );
			}
		} );
	}

	// ---------------------------------------------------------------------
	// S13.
	// ---------------------------------------------------------------------
	const pages = records.filter(
		( record ) =>
			'post' === record.kind &&
			record.hasForms &&
			'publish' === record.status &&
			! record.password &&
			record.link
	);
	// A form in a template or a widget shows on every page of the site: visit one public page for those.
	const sitePages = records.filter(
		( record ) =>
			'post' === record.kind &&
			'publish' === record.status &&
			! record.password &&
			record.link &&
			'page' === record.type
	);
	const sharedForms = records.some(
		( record ) => 'post' !== record.kind && record.hasForms
	);
	const visits = [ ...pages ];
	if ( sharedForms && sitePages.length ) {
		const plain = sitePages.find( ( record ) => ! record.hasForms );
		visits.push( plain || sitePages[ 0 ] );
	}
	if ( 0 === visits.length ) {
		test( 'S13 front end', () => {
			test.skip( true, 'this config has no public page with a form' );
		} );
	}

	for ( const visit of visits ) {
		test( `S13 front end: "${ visit.title }" (${ visit.link })`, async ( {
			browser,
		} ) => {
			test.setTimeout( 600000 );
			const guest = await browser.newContext( { baseURL: BASE_URL } );
			const page = await guest.newPage();
			const failures = [];
			page.on( 'pageerror', ( error ) => failures.push( error.message ) );
			await keepOffTheNetwork( page );

			await page.goto( visit.link );
			const forms = page.locator( 'form.swiftforms-form' );
			const count = await forms.count();
			expect( count, 'forms on the page' ).toBeGreaterThan( 0 );
			await page.waitForTimeout( WAIT_MS );
			await expectNoBlockingAxe( page, 'the page as loaded' );

			// Every form in turn; a form that leaves the page (a redirect, a post without JavaScript) is followed by a fresh page load.
			for ( let i = 0; i < count; i++ ) {
				if ( page.url() !== visit.link ) {
					await page.goto( visit.link );
					await page.waitForTimeout( WAIT_MS );
				}
				await checkForm( page, i, visit );
			}

			// Two forms on one page work independently.
			if ( count > 1 ) {
				await page.goto( visit.link );
				await page.waitForTimeout( WAIT_MS );
				await checkTwoForms( page, visit );
			}

			expect( failures, 'JavaScript errors on the page' ).toEqual( [] );
			await guest.close();
		} );
	}
} );

/**
 * Stop requests to other sites (a redirect form may point at an external address): answer them with an empty page.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function keepOffTheNetwork( page ) {
	const own = new URL( BASE_URL ).host;
	await page.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		if ( url.host === own || 'data:' === url.protocol ) {
			return route.continue();
		}
		return route.fulfill( {
			status: 200,
			contentType: 'text/html',
			body: '<!doctype html><title>external</title>',
		} );
	} );
}

/**
 * Fail on a serious or critical axe finding inside the SwiftForms markup.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          state Where the page is, for the message.
 */
async function expectNoBlockingAxe( page, state ) {
	const result = await new AxeBuilder( { page } )
		.include( '.swiftforms-form' )
		.analyze();
	const blocking = result.violations
		.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) )
		.map( ( v ) => `${ v.id } (${ v.impact }): ${ v.help }` );
	expect( blocking, `axe findings in ${ state }` ).toEqual( [] );
}

/**
 * The facts about one form that decide how to test it.
 *
 * @param {import('@playwright/test').Locator} form A form.
 * @return {Promise<Object>} ajax (the view module drives it), required (it has a required control).
 */
async function describeForm( form ) {
	return {
		ajax:
			( await form.getAttribute( 'data-wp-on--submit' ) ) !== null &&
			( await form.evaluate( ( el ) => el.tagName ) ) === 'FORM',
		required: ( await form.locator( '[required]' ).count() ) > 0,
	};
}

/**
 * Fill the controls a valid submission needs: every required field and the groups with a minimum number of choices.
 *
 * @param {import('@playwright/test').Locator} form A form.
 */
async function fillRequired( form ) {
	const fields = await form.locator( '.swiftforms-field' ).all();
	for ( const field of fields ) {
		const classes = ( await field.getAttribute( 'class' ) ) || '';
		const type = ( /swiftforms-field--([a-z]+)/.exec( classes ) ||
			[] )[ 1 ];
		const required = classes.includes( 'swiftforms-field--required' );
		const minChoices = parseInt(
			( await field
				.locator( '[data-sf-min-choices]' )
				.first()
				.getAttribute( 'data-sf-min-choices' )
				.catch( () => null ) ) || '0',
			10
		);
		if ( ! required && ! ( 'checkboxes' === type && minChoices > 0 ) ) {
			continue;
		}
		const input = field.locator( 'input, select, textarea' ).first();
		switch ( type ) {
			case 'email':
				await input.fill( 'e2e@example.org' );
				break;
			case 'url':
				await input.fill( 'https://example.org/e2e' );
				break;
			case 'tel':
				await input.fill( '+44 20 7946 0000' );
				break;
			case 'number': {
				const min = await input.getAttribute( 'min' );
				const max = await input.getAttribute( 'max' );
				await input.fill( min || max || '1' );
				break;
			}
			case 'date': {
				const min = await input.getAttribute( 'min' );
				const max = await input.getAttribute( 'max' );
				await input.fill( min || max || '2027-01-15' );
				break;
			}
			case 'select': {
				const values = await input
					.locator( 'option' )
					.evaluateAll( ( options ) =>
						options.map( ( option ) => option.value )
					);
				await input.selectOption( values.find( ( v ) => '' !== v ) );
				break;
			}
			case 'radio':
				await field.locator( 'input[type=radio]' ).first().check();
				break;
			case 'checkboxes': {
				const boxes = field.locator( 'input[type=checkbox]' );
				for ( let n = 0; n < Math.max( 1, minChoices ); n++ ) {
					await boxes.nth( n ).check();
				}
				break;
			}
			case 'consent':
				await field.locator( 'input[type=checkbox]' ).check();
				break;
			case 'hidden':
				break;
			default: {
				const pattern = await input.getAttribute( 'pattern' );
				const maxLength = await input.getAttribute( 'maxlength' );
				const value =
					pattern && pattern.includes( '[A-Z]{3}' )
						? 'ABC-1234'
						: 'Playwright check';
				await input.fill(
					maxLength
						? value.slice( 0, parseInt( maxLength, 10 ) )
						: value
				);
			}
		}
	}
}

/**
 * Client validation, then a valid submission, of the form at an index on a freshly loaded page.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {number}                          index Position of the form on the page.
 * @param {Object}                          visit The state-file entry of the page.
 */
async function checkForm( page, index, visit ) {
	const form = page.locator( 'form.swiftforms-form' ).nth( index );
	const facts = await describeForm( form );
	const confirmations = page.locator( '.swiftforms-confirmation' );
	const confirmationsBefore = await confirmations.count();
	const submit = form.locator( 'button[type=submit]' );

	if ( facts.ajax && facts.required ) {
		// Client validation: the messages, the summary, and the focus on the summary.
		await submit.click();
		const summary = form.locator( '.swiftforms-errors-summary' );
		await expect( summary, 'error summary' ).toBeVisible();
		await expect( summary, 'focus on the error summary' ).toBeFocused();
		expect(
			await form.locator( '.swiftforms-error:not([hidden])' ).count(),
			'visible field error messages'
		).toBeGreaterThan( 0 );
		expect( ( await summary.innerText() ).trim() ).not.toBe( '' );
		await expectNoBlockingAxe( page, `${ visit.title }: error state` );
	} else if ( ! facts.ajax && facts.required ) {
		// Without JavaScript the browser's own validation stops an empty post.
		expect(
			await form.evaluate( ( el ) => el.checkValidity() ),
			'a form without JavaScript starts out invalid'
		).toBe( false );
	}

	await fillRequired( form );
	const urlBefore = page.url();
	if ( ! facts.ajax && NOW_FILE ) {
		// A plain form post is judged by the server's clock (the signed render time): let a few seconds pass there.
		fs.writeFileSync( NOW_FILE, String( FROZEN_TIME + 60 ) );
	}
	await submit.click();

	// Success is a confirmation on the page, or a redirect away from it.
	await expect
		.poll(
			async () =>
				( await confirmations.count() ) > confirmationsBefore ||
				page.url() !== urlBefore,
			{ message: 'a confirmation or a redirect', timeout: 20000 }
		)
		.toBe( true );
	if ( NOW_FILE ) {
		fs.writeFileSync( NOW_FILE, String( FROZEN_TIME ) );
	}
	if (
		page.url().startsWith( BASE_URL ) &&
		( await confirmations.count() ) > 0
	) {
		await expect( confirmations.last() ).toBeVisible();
		await expectNoBlockingAxe( page, `${ visit.title }: confirmation` );
	}
}

/**
 * Two forms on one page: an error in one leaves the other untouched, and each one submits on its own.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Object}                          visit The state-file entry of the page.
 */
async function checkTwoForms( page, visit ) {
	const forms = page.locator( 'form.swiftforms-form' );
	const total = await forms.count();
	let first = -1;
	let second = -1;
	for ( let i = 0; i < total; i++ ) {
		const facts = await describeForm( forms.nth( i ) );
		if ( facts.ajax && facts.required ) {
			if ( first < 0 ) {
				first = i;
			} else if ( second < 0 ) {
				second = i;
			}
		}
	}
	if ( second < 0 ) {
		return; // Fewer than two forms the view module drives: nothing to compare.
	}

	await forms.nth( first ).locator( 'button[type=submit]' ).click();
	await expect(
		forms.nth( first ).locator( '.swiftforms-errors-summary' )
	).toBeVisible();
	await expect(
		forms.nth( second ).locator( '.swiftforms-errors-summary' ),
		`${ visit.title }: the other form shows no summary`
	).toBeHidden();
	expect(
		await forms
			.nth( second )
			.locator( '.swiftforms-error:not([hidden])' )
			.count(),
		'the other form shows no field errors'
	).toBe( 0 );

	const confirmations = page.locator( '.swiftforms-confirmation' );
	const before = await confirmations.count();
	await fillRequired( forms.nth( second ) );
	const urlBefore = page.url();
	await forms.nth( second ).locator( 'button[type=submit]' ).click();
	await expect
		.poll(
			async () =>
				( await confirmations.count() ) > before ||
				page.url() !== urlBefore,
			{ message: 'the second form confirms or redirects', timeout: 20000 }
		)
		.toBe( true );
	if ( page.url() !== urlBefore ) {
		return; // A redirect form leaves the page: nothing left to compare.
	}
	// The first form still shows its own errors.
	await expect(
		page
			.locator( 'form.swiftforms-form' )
			.nth( first )
			.locator( '.swiftforms-errors-summary' )
	).toBeVisible();
}
