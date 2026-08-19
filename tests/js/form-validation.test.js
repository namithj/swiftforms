import { validateFormBlocks } from '../../src/editor/validate-form';

const field = ( type, slug, attributes = {} ) => ( {
	name: `smartlogix-swiftforms/field-${ type }`,
	attributes: { slug, ...attributes },
	innerBlocks: [],
} );

test( 'accepts a valid schema and autoresponder selection', () => {
	const blocks = [
		field( 'email', 'email' ),
		field( 'select', 'plan', { options: 'Free|free\nPro|pro' } ),
		field( 'text', 'details', {
			conditions: {
				enabled: true,
				groups: [
					[ { field: 'plan', operator: 'equals', value: 'pro' } ],
				],
			},
		} ),
	];
	expect( validateFormBlocks( blocks, 'email' ) ).toEqual( [] );
} );

test( 'blocks every known invalid builder configuration', () => {
	const blocks = [
		field( 'text', '' ),
		field( 'text', 'same' ),
		field( 'text', 'same' ),
		field( 'select', 'plan', { options: 'One|x\nTwo|x' } ),
		field( 'text', 'details', {
			conditions: {
				enabled: true,
				groups: [
					[ { field: 'missing', operator: 'equals', value: 'x' } ],
				],
			},
		} ),
	];
	expect( validateFormBlocks( blocks, 'not_email' ) ).toEqual(
		expect.arrayContaining( [
			'empty_slug',
			'duplicate_slug',
			'invalid_options',
			'dangling_condition',
			'invalid_autoresponder',
		] )
	);
} );
