import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {
	check,
	isEmail,
	isHttpUrl,
	messageFor,
} from '../../src/view/validation';

const cases = JSON.parse(
	fs.readFileSync(
		path.join( __dirname, '../fixtures/validation-cases.json' ),
		'utf8'
	)
);

const messages = {
	required: 'This field is required.',
	number_min: 'Please enter a number that is at least {min}.',
	number_max: 'Please enter a number that is at most {max}.',
	number_step: 'Please enter a multiple of {step}.',
	max_length: 'Please use at most {max} characters.',
	min_length: 'Please use at least {min} characters.',
	invalid: 'Please check this field.',
};

describe( 'client rules against the shared fixture (PHP runs the same file)', () => {
	for ( const testCase of cases ) {
		it( `${ testCase.name }`, () => {
			const value = testCase.valueRepeat
				? testCase.valueRepeat.char.repeat( testCase.valueRepeat.times )
				: testCase.value;
			const expected =
				'clientExpect' in testCase
					? testCase.clientExpect
					: testCase.expect;
			expect( check( testCase.field, value ) ).toBe( expected );
		} );
	}
} );

describe( 'messages', () => {
	it( 'fills the placeholders like Validator::message()', () => {
		expect( messageFor( 'number_min', { min: '10' }, messages ) ).toBe(
			'Please enter a number that is at least 10.'
		);
		expect( messageFor( 'number_step', { step: '5' }, messages ) ).toBe(
			'Please enter a multiple of 5.'
		);
		expect( messageFor( 'max_length', { maxLength: '' }, messages ) ).toBe(
			'Please use at most 5000 characters.'
		);
		expect(
			messageFor( 'max_length', { maxLength: '20' }, messages )
		).toBe( 'Please use at most 20 characters.' );
	} );

	it( 'uses the author pattern message and falls back to "invalid" for unknown keys', () => {
		expect(
			messageFor( 'pattern', { patternMessage: 'Use ABC.' }, messages )
		).toBe( 'Use ABC.' );
		expect( messageFor( 'nope', {}, messages ) ).toBe(
			'Please check this field.'
		);
	} );
} );

describe( 'helpers', () => {
	it( 'isEmail and isHttpUrl agree with the fixture on edge cases', () => {
		expect( isEmail( 'a@b.co' ) ).toBe( true );
		expect( isEmail( '@b.co' ) ).toBe( false );
		expect( isHttpUrl( 'http://localhost:8889/x' ) ).toBe( true );
		expect( isHttpUrl( 'https://' + 'a'.repeat( 2100 ) + '.com' ) ).toBe(
			false
		);
	} );
} );
