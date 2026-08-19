import { parseOptionPairs } from '../../src/shared/options';

describe( 'parseOptionPairs', () => {
	it( 'parses "Label|value" pairs, one per line', () => {
		expect( parseOptionPairs( 'Free Plan|free\nPro Plan|pro' ) ).toEqual( [
			{ label: 'Free Plan', value: 'free' },
			{ label: 'Pro Plan', value: 'pro' },
		] );
	} );

	it( 'uses the bare line as both label and value when there is no pipe', () => {
		expect( parseOptionPairs( 'Option 1\nOption 2' ) ).toEqual( [
			{ label: 'Option 1', value: 'Option 1' },
			{ label: 'Option 2', value: 'Option 2' },
		] );
	} );

	it( 'skips blank lines and pairs with an empty value', () => {
		expect( parseOptionPairs( 'A|a\n\n|\nB|b' ) ).toEqual( [
			{ label: 'A', value: 'a' },
			{ label: 'B', value: 'b' },
		] );
	} );

	it( 'returns an empty array for empty input', () => {
		expect( parseOptionPairs( '' ) ).toEqual( [] );
		expect( parseOptionPairs( undefined ) ).toEqual( [] );
	} );
} );
