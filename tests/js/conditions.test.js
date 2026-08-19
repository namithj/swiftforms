import { isFieldVisible, resolveVisibility } from '../../src/shared/conditions';
import fixtures from '../fixtures/conditions.json';

describe( 'conditions: isFieldVisible (shared fixtures)', () => {
	fixtures.isFieldVisible.forEach( ( testCase ) => {
		it( testCase.name, () => {
			expect(
				isFieldVisible( testCase.conditions, testCase.values )
			).toBe( testCase.expected );
		} );
	} );
} );

describe( 'conditions: resolveVisibility (shared fixtures)', () => {
	fixtures.resolveVisibility.forEach( ( testCase ) => {
		it( testCase.name, () => {
			expect(
				resolveVisibility( testCase.fields, testCase.values )
			).toEqual( testCase.expected );
		} );
	} );
} );

describe( 'conditions: circular references terminate', () => {
	it( 'resolves without infinite looping when two fields depend on each other', () => {
		const fields = {
			a: {
				conditions: {
					enabled: true,
					action: 'show',
					groups: [
						[ { field: 'b', operator: 'equals', value: 'x' } ],
					],
				},
			},
			b: {
				conditions: {
					enabled: true,
					action: 'show',
					groups: [
						[ { field: 'a', operator: 'equals', value: 'x' } ],
					],
				},
			},
		};

		const result = resolveVisibility( fields, {} );

		expect( typeof result.a ).toBe( 'boolean' );
		expect( typeof result.b ).toBe( 'boolean' );
	} );
} );
