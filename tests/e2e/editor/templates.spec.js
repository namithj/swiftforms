/**
 * E2E: SwiftForms starter templates (block patterns).
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'SwiftForms form templates', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'swiftforms' );
	} );

	test( 'registers the four starter patterns for the form post type', async ( { requestUtils } ) => {
		const patterns = await requestUtils.rest( {
			path: '/wp/v2/block-patterns/patterns',
		} );

		const swiftformsPatterns = patterns.filter( ( pattern ) =>
			( pattern.categories || [] ).includes( 'swiftforms' )
		);
		const names = swiftformsPatterns.map( ( pattern ) => pattern.name );

		expect( names ).toEqual(
			expect.arrayContaining( [
				'swiftforms/contact-form',
				'swiftforms/quote-request',
				'swiftforms/feedback-survey',
				'swiftforms/event-registration',
			] )
		);

		for ( const pattern of swiftformsPatterns ) {
			expect( pattern.post_types ).toEqual( [ 'swiftforms_form' ] );
			expect( pattern.content ).toContain( 'wp:swiftforms/' );
		}
	} );

	test( 'inserting the contact pattern into a form yields its field blocks', async ( { admin, editor, requestUtils } ) => {
		await admin.createNewPost( { postType: 'swiftforms_form', title: 'From template' } );

		// Close the pattern-choice modal if it opened, then insert explicitly
		// so the test doesn't depend on modal heuristics.
		await editor.page.keyboard.press( 'Escape' );

		const patterns = await requestUtils.rest( { path: '/wp/v2/block-patterns/patterns' } );
		const contact = patterns.find( ( pattern ) => pattern.name === 'swiftforms/contact-form' );

		await editor.setContent( contact.content );

		const blocks = await editor.getBlocks();
		const names = blocks.map( ( block ) => block.name );

		expect( names ).toEqual( [
			'swiftforms/text-field',
			'swiftforms/email-field',
			'swiftforms/textarea-field',
		] );
	} );
} );
