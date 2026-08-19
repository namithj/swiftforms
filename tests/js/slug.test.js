import { slugify, maybeDeriveSlug } from '../../src/shared/slug';

describe( 'slugify', () => {
	it( 'lowercases and replaces non-word characters with underscores', () => {
		expect( slugify( 'Full Name!' ) ).toBe( 'full_name' );
	} );

	it( 'trims leading/trailing underscores', () => {
		expect( slugify( '  Hello World  ' ) ).toBe( 'hello_world' );
	} );

	it( 'returns an empty string for empty input', () => {
		expect( slugify( '' ) ).toBe( '' );
		expect( slugify( undefined ) ).toBe( '' );
	} );
} );

describe( 'maybeDeriveSlug', () => {
	it( 'auto-derives from the label when the slug still matches the default', () => {
		expect(
			maybeDeriveSlug( 'Email Address', '', 'text_field', 'text_field' )
		).toBe( 'email_address' );
	} );

	it( 'keeps deriving while the slug matches the previous label', () => {
		expect(
			maybeDeriveSlug( 'Full Name', 'Name', 'name', 'text_field' )
		).toBe( 'full_name' );
	} );

	it( 'stops deriving once the author customizes the slug', () => {
		expect(
			maybeDeriveSlug( 'Email Address', 'Email', 'custom_slug', 'text_field' )
		).toBe( 'custom_slug' );
	} );
} );
