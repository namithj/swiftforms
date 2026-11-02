/**
 * Editor e2e (P2): the Form block in the block editor.
 *
 * Runs against a WordPress with the plugin active (the scratch site, or wp-env). Covers the
 * insert template and formId, name derivation and repair, duplicate and paste rules, a lossless
 * open and save, the confirmation toggle, the unpublished notice, the canvas preview and the
 * six patterns.
 *
 * The exact serialized markup of a freshly inserted form is printed between
 * SF_DEFAULT_FORM_BEGIN and SF_DEFAULT_FORM_END and written to SF_DEFAULT_FORM_OUT when that
 * variable is set (it becomes fixture form-default for config 01).
 */
const fs = require( 'fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const UUID_V4 =
	/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const FORM = 'swiftforms/form';
const DEFAULT_CHILDREN = [
	'swiftforms/field-text',
	'swiftforms/field-email',
	'swiftforms/field-textarea',
	'swiftforms/submit',
	'swiftforms/confirmation',
];

/**
 * Helpers that talk to the editor stores of a page.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Object} Helpers.
 */
function editorApi( page ) {
	return {
		/** The block tree: name, clientId, attributes and inner blocks. */
		tree: () =>
			page.evaluate( () => {
				const walk = ( blocks ) =>
					blocks.map( ( block ) => ( {
						name: block.name,
						clientId: block.clientId,
						attributes: block.attributes,
						innerBlocks: walk( block.innerBlocks ),
					} ) );
				return walk(
					window.wp.data.select( 'core/block-editor' ).getBlocks()
				);
			} ),
		attributes: ( clientId ) =>
			page.evaluate(
				( id ) =>
					window.wp.data
						.select( 'core/block-editor' )
						.getBlockAttributes( id ),
				clientId
			),
		select: ( clientId ) =>
			page.evaluate(
				( id ) =>
					window.wp.data
						.dispatch( 'core/block-editor' )
						.selectBlock( id ),
				clientId
			),
		// Insert a block into a parent at an index and return its clientId.
		insertInto: ( name, parentClientId, index ) =>
			page.evaluate(
				( [ blockName, parent, position ] ) => {
					const block = window.wp.blocks.createBlock( blockName );
					window.wp.data
						.dispatch( 'core/block-editor' )
						.insertBlock( block, position, parent );
					return block.clientId;
				},
				[ name, parentClientId, index ]
			),
		content: () =>
			page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).getEditedPostContent()
			),
	};
}

/**
 * Open a new post with a Form block inserted.
 *
 * @param {Object} fixtures        Playwright fixtures.
 * @param {Object} fixtures.admin  Admin utilities.
 * @param {Object} fixtures.editor Editor utilities.
 * @param {Object} fixtures.page   Page.
 * @return {Promise<Object>} The form block (name, clientId, attributes, innerBlocks).
 */
async function newPostWithForm( { admin, editor, page } ) {
	await admin.createNewPost();
	await editor.setPreferences( 'core/edit-post', {
		welcomeGuide: false,
		fullscreenMode: false,
	} );
	await editor.insertBlock( { name: FORM } );
	const api = editorApi( page );
	const tree = await api.tree();
	const form = tree.find( ( block ) => block.name === FORM );
	expect( form, 'the form block was inserted' ).toBeTruthy();
	return form;
}

test.describe( 'SwiftForms editor', () => {
	let problems;

	test.beforeEach( ( { page } ) => {
		problems = [];
		page.on( 'pageerror', ( error ) => problems.push( error.message ) );
	} );

	test.afterEach( async ( { page } ) => {
		expect( problems, 'no uncaught page errors' ).toEqual( [] );
		await expect( page.locator( '.block-editor-warning' ) ).toHaveCount(
			0
		);
	} );

	test( 'inserting a form adds the default template and a UUID formId', async ( {
		admin,
		editor,
		page,
	}, testInfo ) => {
		const requests = [];
		page.on( 'request', ( request ) => {
			if (
				/block-renderer|swiftforms-blocks\/v1|swiftforms\/v1/.test(
					request.url()
				)
			) {
				requests.push( request.url() );
			}
		} );

		const form = await newPostWithForm( { admin, editor, page } );

		expect( form.attributes.formId ).toMatch( UUID_V4 );
		expect( form.innerBlocks.map( ( block ) => block.name ) ).toEqual(
			DEFAULT_CHILDREN
		);
		const [ name, email, message, , confirmation ] = form.innerBlocks;
		expect( name.attributes ).toMatchObject( {
			label: 'Name',
			name: 'name',
			autocomplete: 'name',
			required: false,
		} );
		expect( email.attributes ).toMatchObject( {
			label: 'Email',
			name: 'email',
			required: true,
			autocomplete: 'email',
		} );
		expect( message.attributes ).toMatchObject( {
			label: 'Message',
			name: 'message',
			required: true,
		} );
		expect( confirmation.innerBlocks ).toHaveLength( 1 );
		expect( confirmation.innerBlocks[ 0 ].name ).toBe( 'core/paragraph' );
		expect( confirmation.innerBlocks[ 0 ].attributes.content ).toBe(
			"Thanks! We'll be in touch."
		);

		// The exact serialized markup (fixture form-default).
		const markup = await editorApi( page ).content();
		// eslint-disable-next-line no-console
		console.log(
			`SF_DEFAULT_FORM_BEGIN\n${ markup }\nSF_DEFAULT_FORM_END`
		);
		fs.writeFileSync( testInfo.outputPath( 'form-default.html' ), markup );
		if ( process.env.SF_DEFAULT_FORM_OUT ) {
			fs.writeFileSync( process.env.SF_DEFAULT_FORM_OUT, markup );
		}
		expect( markup ).toContain(
			`<!-- wp:swiftforms/form {"formId":"${ form.attributes.formId }"} -->`
		);
		expect( markup.endsWith( '<!-- /wp:swiftforms/form -->' ) ).toBe(
			true
		);
		expect( markup ).toContain( '<!-- wp:swiftforms/submit /-->' );

		// Real, disabled inputs in the canvas, and not one request for the preview.
		const canvasForm = editor.canvas.locator( '.swiftforms-form' ).first();
		await expect( canvasForm.locator( 'input[disabled]' ) ).toHaveCount(
			2
		);
		await expect( canvasForm.locator( 'textarea[disabled]' ) ).toHaveCount(
			1
		);
		await expect(
			canvasForm.locator( 'button[disabled]' ).first()
		).toContainText( 'Submit' );
		expect( requests, 'no network request for the canvas' ).toEqual( [] );

		// The "Forms" block category exists.
		const categories = await page.evaluate( () =>
			window.wp.blocks
				.getCategories()
				.map( ( category ) => category.slug )
		);
		expect( categories ).toContain( 'swiftforms' );
	} );

	test( 'a new field derives its name from the label and repairs a bad one', async ( {
		admin,
		editor,
		page,
	} ) => {
		const form = await newPostWithForm( { admin, editor, page } );
		const api = editorApi( page );
		const fieldId = await api.insertInto(
			'swiftforms/field-text',
			form.clientId,
			3
		);
		await api.select( fieldId );
		await editor.openDocumentSettingsSidebar();

		const label = page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'textbox', { name: 'Label', exact: true } );
		await label.fill( 'Phone number' );
		await expect
			.poll( async () => ( await api.attributes( fieldId ) ).name )
			.toBe( 'phone-number' );
		await expect( label ).toHaveValue( 'Phone number' );

		// A label that would make a name starting with a digit.
		await label.fill( '2nd choice' );
		await expect
			.poll( async () => ( await api.attributes( fieldId ) ).name, {
				timeout: 5000,
			} )
			.toBe( 'f-2nd-choice' );
	} );

	test( 'duplicating a field suffixes its name, duplicating the form gives a new formId', async ( {
		admin,
		editor,
		page,
	} ) => {
		const form = await newPostWithForm( { admin, editor, page } );
		const api = editorApi( page );
		const nameField = form.innerBlocks[ 0 ];

		await api.select( nameField.clientId );
		await editor.clickBlockOptionsMenuItem( 'Duplicate' );
		await expect
			.poll( async () => {
				const tree = await api.tree();
				return tree[ 0 ].innerBlocks
					.filter(
						( block ) => block.name === 'swiftforms/field-text'
					)
					.map( ( block ) => block.attributes.name );
			} )
			.toEqual( [ 'name', 'name-2' ] );

		await api.select( form.clientId );
		await editor.clickBlockOptionsMenuItem( 'Duplicate' );
		await expect.poll( async () => ( await api.tree() ).length ).toBe( 2 );
		const forms = await api.tree();
		const ids = forms.map( ( block ) => block.attributes.formId );
		expect( ids[ 0 ] ).toMatch( UUID_V4 );
		expect( ids[ 1 ] ).toMatch( UUID_V4 );
		expect( ids[ 0 ] ).toBe( form.attributes.formId );
		expect( ids[ 1 ] ).not.toBe( ids[ 0 ] );
	} );

	test( 'pasting a copy of a form gives it a new formId', async ( {
		admin,
		editor,
		page,
	} ) => {
		const form = await newPostWithForm( { admin, editor, page } );
		const api = editorApi( page );

		// An empty paragraph below the form to paste into, like a writer would.
		const paragraphId = await page.evaluate( () => {
			const block = window.wp.blocks.createBlock( 'core/paragraph' );
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlock( block, 1 );
			return block.clientId;
		} );

		// Copy and paste with one shared clipboard object, as the browser does.
		const clipboardEvent = ( type, clientId ) =>
			page.evaluate(
				( [ eventType, id ] ) => {
					const frame = document.querySelector(
						'iframe[name="editor-canvas"]'
					);
					const win = frame ? frame.contentWindow : window;
					window.sfClipboard =
						window.sfClipboard || new win.DataTransfer();
					window.wp.data
						.dispatch( 'core/block-editor' )
						.selectBlock( id );
					const element = win.document.querySelector(
						`[data-block="${ id }"]`
					);
					element.focus();
					element.dispatchEvent(
						new win.ClipboardEvent( eventType, {
							bubbles: true,
							cancelable: true,
							clipboardData: window.sfClipboard,
						} )
					);
				},
				[ type, clientId ]
			);
		await clipboardEvent( 'copy', form.clientId );
		await clipboardEvent( 'paste', paragraphId );

		await expect.poll( async () => ( await api.tree() ).length ).toBe( 2 );
		const ids = ( await api.tree() ).map(
			( block ) => block.attributes.formId
		);
		expect( ids[ 0 ] ).toBe( form.attributes.formId );
		expect( ids[ 1 ] ).toMatch( UUID_V4 );
		expect( ids[ 1 ] ).not.toBe( ids[ 0 ] );
	} );

	test( 'opening and saving a form without edits leaves post_content identical', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await newPostWithForm( { admin, editor, page } );
		await editor.saveDraft();
		const postId = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);
		const readRaw = async () =>
			(
				await requestUtils.rest( {
					path: `/wp/v2/posts/${ postId }`,
					params: { context: 'edit' },
				} )
			).content.raw;
		const before = await readRaw();
		expect( before ).toContain( '<!-- wp:swiftforms/form ' );

		await admin.visitAdminPage(
			'post.php',
			`post=${ postId }&action=edit`
		);
		await editor.setPreferences( 'core/edit-post', {
			welcomeGuide: false,
			fullscreenMode: false,
		} );
		await page.reload();
		await page.waitForFunction( () =>
			window.wp?.data?.select( 'core/editor' )?.getCurrentPostId?.()
		);
		await expect( page.locator( '.block-editor-warning' ) ).toHaveCount(
			0
		);
		expect( await editorApi( page ).tree() ).toHaveLength( 1 );

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

		// Loading alone did not make the post dirty, and the formId did not change.
		const dirty = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		);
		expect( dirty ).toBe( false );
	} );

	test( 'the Preview confirmation toggle shows and hides the confirmation without saving anything', async ( {
		admin,
		editor,
		page,
	} ) => {
		const form = await newPostWithForm( { admin, editor, page } );
		await editor.saveDraft();
		const api = editorApi( page );
		const confirmation = editor.canvas.locator(
			'[data-type="swiftforms/confirmation"]'
		);
		const contentBefore = await api.content();

		await expect( confirmation ).toBeHidden();

		await api.select( form.clientId );
		const toggle = page.getByRole( 'button', {
			name: 'Preview confirmation',
		} );
		await expect( toggle ).toHaveAttribute( 'aria-pressed', 'false' );
		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( confirmation ).toBeVisible();
		await expect( confirmation ).toContainText(
			"Thanks! We'll be in touch."
		);

		await toggle.click();
		await expect( confirmation ).toBeHidden();

		expect( await api.content() ).toBe( contentBefore );
		expect(
			await page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).isEditedPostDirty()
			)
		).toBe( false );
	} );

	test( 'the inspector warns when the post is not published', async ( {
		admin,
		editor,
		page,
	} ) => {
		const form = await newPostWithForm( { admin, editor, page } );
		await editorApi( page ).select( form.clientId );
		await editor.openDocumentSettingsSidebar();

		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		await expect( sidebar ).toContainText(
			"Previews of unpublished content can't receive submissions."
		);
		// The Form panel and the Notifications panel are there.
		await expect(
			sidebar.getByRole( 'button', { name: 'Form', exact: true } )
		).toBeVisible();
		await expect(
			sidebar.getByRole( 'button', { name: 'Notifications' } )
		).toBeVisible();
		await expect(
			sidebar.getByRole( 'button', { name: 'Advanced' } ).first()
		).toBeVisible();

		await editor.publishPost();
		await editorApi( page ).select( form.clientId );
		await expect( sidebar ).not.toContainText(
			"Previews of unpublished content can't receive submissions."
		);
	} );

	test( 'the six patterns are in the Forms category and insert as forms', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost();
		await editor.setPreferences( 'core/edit-post', {
			welcomeGuide: false,
			fullscreenMode: false,
		} );

		const titles = [
			'Contact form',
			'Quote request',
			'Callback request',
			'Newsletter signup',
			'Event RSVP',
			'Feedback form',
		];

		const patterns = await requestUtils.rest( {
			path: '/wp/v2/block-patterns/patterns',
		} );
		const categories = await requestUtils.rest( {
			path: '/wp/v2/block-patterns/categories',
		} );
		const ours = patterns.filter( ( pattern ) =>
			( pattern.categories || [] ).includes( 'swiftforms' )
		);
		expect( ours.map( ( pattern ) => pattern.title ).sort() ).toEqual(
			[ ...titles ].sort()
		);
		expect(
			categories.find( ( category ) => 'swiftforms' === category.name )
				.label
		).toBe( 'Forms' );
		for ( const pattern of ours ) {
			expect( pattern.content ).not.toContain( 'formId' );
		}

		const countForms = async () =>
			( await editorApi( page ).tree() ).flatMap( function find( block ) {
				return FORM === block.name
					? [ block ]
					: block.innerBlocks.flatMap( find );
			} ).length;

		let inserted = 0;
		for ( const title of titles ) {
			await page
				.getByRole( 'button', { name: 'Block Inserter', exact: true } )
				.click();
			const inserter = page.getByRole( 'region', {
				name: 'Block Library',
			} );
			await inserter.getByRole( 'tab', { name: 'Patterns' } ).click();
			// Browse the "Forms" category: a theme can ship a pattern with the same title (Twenty Twenty-Five has an "Event RSVP").
			await inserter
				.getByRole( 'tab', { name: 'Forms', exact: true } )
				.click();
			await inserter
				.getByRole( 'option', { name: title, exact: true } )
				.click();
			inserted++;
			await expect
				.poll( countForms, { message: `${ title } was inserted` } )
				.toBe( inserted );
			await page
				.getByRole( 'button', { name: 'Close Block Inserter' } )
				.click();
		}

		const forms = ( await editorApi( page ).tree() ).flatMap(
			function find( block ) {
				return block.name === FORM
					? [ block ]
					: block.innerBlocks.flatMap( find );
			}
		);
		expect( forms ).toHaveLength( 6 );
		const ids = forms.map( ( block ) => block.attributes.formId );
		for ( const id of ids ) {
			expect( id ).toMatch( UUID_V4 );
		}
		expect( new Set( ids ).size ).toBe( 6 );
		for ( const form of forms ) {
			const names = form.innerBlocks.map( ( block ) => block.name );
			expect( names ).toContain( 'swiftforms/submit' );
			expect( names ).toContain( 'swiftforms/confirmation' );
		}
	} );
} );
