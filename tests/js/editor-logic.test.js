import { describe, expect, it } from 'vitest';
import { isValidName, repairName } from '../../src/editor/field-name';
import {
	DEFAULT_STATE,
	actions,
	reducer,
	selectors,
} from '../../src/editor/store';

describe( 'field names', () => {
	it( 'accepts the contract pattern and nothing else', () => {
		expect( isValidName( 'email' ) ).toBe( true );
		expect( isValidName( 'a' ) ).toBe( true );
		expect( isValidName( 'a-b_c9' ) ).toBe( true );
		expect( isValidName( 'a'.repeat( 64 ) ) ).toBe( true );
		expect( isValidName( 'a'.repeat( 65 ) ) ).toBe( false );
		expect( isValidName( '2nd' ) ).toBe( false );
		expect( isValidName( '' ) ).toBe( false );
		expect( isValidName( 'Name' ) ).toBe( false );
		expect( isValidName( 'a.b' ) ).toBe( false );
		expect( isValidName( null ) ).toBe( false );
	} );

	it( 'prefixes a leading digit with f- like the server does', () => {
		expect( repairName( '2nd-choice' ) ).toBe( 'f-2nd-choice' );
		expect( repairName( '-x' ) ).toBe( 'f--x' );
		expect( isValidName( repairName( '2nd-choice' ) ) ).toBe( true );
	} );

	it( 'cuts to 64 characters', () => {
		const long = 'a'.repeat( 80 );
		expect( repairName( long ) ).toBe( 'a'.repeat( 64 ) );
		expect( repairName( '1' + 'a'.repeat( 80 ) ) ).toHaveLength( 64 );
	} );

	it( 'leaves empty, valid and half-typed names alone', () => {
		expect( repairName( '' ) ).toBe( '' );
		expect( repairName( 'email' ) ).toBe( 'email' );
		expect( repairName( 'Phone Number' ) ).toBe( 'Phone Number' );
	} );
} );

describe( 'confirmation preview store', () => {
	it( 'toggles per form and never touches other forms', () => {
		let state = reducer( DEFAULT_STATE, { type: 'unknown' } );
		expect( selectors.isPreviewingConfirmation( state, 'a' ) ).toBe(
			false
		);
		state = reducer( state, actions.toggleConfirmationPreview( 'a' ) );
		expect( selectors.isPreviewingConfirmation( state, 'a' ) ).toBe( true );
		expect( selectors.isPreviewingConfirmation( state, 'b' ) ).toBe(
			false
		);
		state = reducer( state, actions.toggleConfirmationPreview( 'a' ) );
		expect( selectors.isPreviewingConfirmation( state, 'a' ) ).toBe(
			false
		);
	} );
} );
