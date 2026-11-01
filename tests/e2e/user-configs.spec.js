/**
 * User-config e2e checks (S12, S13), run once per config by
 * bin/run-e2e-configs.mjs with SF_CONFIG=<slug>.
 *
 * The runner has already reset the site, applied the config
 * (tests/user-configs/apply-cli.php) and written the list of stored posts to
 * tests/e2e/.state/<slug>.json.
 *
 * S12: every stored post opens in the block editor without a block-validation
 *      warning, and saving it without edits leaves post_content unchanged.
 * S13: every form on the page works on the front end (R01; skipped until a
 *      config lists forms).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const slug = process.env.SF_CONFIG;
const statePath = slug
	? path.join( __dirname, '.state', `${ slug }.json` )
	: '';
const posts =
	statePath && fs.existsSync( statePath )
		? JSON.parse( fs.readFileSync( statePath, 'utf8' ) )
		: [];

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

	for ( const post of posts ) {
		test( `S12 editor validity and lossless save: ${ post.type } #${ post.id } "${ post.title }"`, async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			const endpoint = `/wp/v2/${ post.restBase }/${ post.id }`;
			const readRaw = async () => {
				const record = await requestUtils.rest( {
					path: endpoint,
					params: { context: 'edit' },
				} );
				return record.content.raw;
			};

			const before = await readRaw();

			await admin.visitAdminPage(
				'post.php',
				`post=${ post.id }&action=edit`
			);
			await editor.setPreferences( 'core/edit-post', {
				welcomeGuide: false,
				fullscreenMode: false,
			} );
			await page.reload();
			await page.waitForFunction( () =>
				window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.()
			);

			// A block that fails validation shows the "unexpected or invalid content" warning.
			await expect( page.locator( '.block-editor-warning' ) ).toHaveCount(
				0
			);

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

			expect( await readRaw() ).toBe( before );
		} );
	}

	test( 'S13 front-end form behaviour', () => {
		const withForms = posts.filter( ( post ) => post.hasForms );
		test.skip( withForms.length === 0, 'pending: R01' );
		// R01 fills this in: submit each form per its config and assert the outcome.
	} );
} );
