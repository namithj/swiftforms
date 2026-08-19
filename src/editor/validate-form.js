import { parseOptionPairs } from '../shared/options';

export const FIELD_PREFIX = 'smartlogix-swiftforms/field-';

export function flattenBlocks( blocks ) {
	return blocks.flatMap( ( block ) => [
		block,
		...flattenBlocks( block.innerBlocks || [] ),
	] );
}

export function validateFormBlocks( blocks, autoresponderField = '' ) {
	const fields = flattenBlocks( blocks ).filter( ( block ) =>
		block.name.startsWith( FIELD_PREFIX )
	);
	const slugs = new Set();
	const errors = [];

	fields.forEach( ( field ) => {
		const slug = field.attributes.slug || '';
		const type = field.name.slice( FIELD_PREFIX.length );
		if ( ! slug ) {
			errors.push( 'empty_slug' );
		} else if ( slugs.has( slug ) ) {
			errors.push( 'duplicate_slug' );
		}
		slugs.add( slug );

		if ( [ 'select', 'radio' ].includes( type ) ) {
			const options = parseOptionPairs( field.attributes.options );
			const values = options.map( ( option ) => option.value );
			if (
				! options.length ||
				new Set( values ).size !== values.length
			) {
				errors.push( 'invalid_options' );
			}
		}
	} );

	fields.forEach( ( field ) => {
		const ownSlug = field.attributes.slug || '';
		const conditions = field.attributes.conditions;
		if ( ! conditions?.enabled || ! conditions.groups?.length ) {
			return;
		}
		conditions.groups.flat().forEach( ( rule ) => {
			const source = fields.find(
				( candidate ) => candidate.attributes.slug === rule.field
			);
			if ( ! source || rule.field === ownSlug ) {
				errors.push( 'dangling_condition' );
				return;
			}
			const sourceType = source.name.slice( FIELD_PREFIX.length );
			if (
				[ 'select', 'radio' ].includes( sourceType ) &&
				! [ 'empty', 'not_empty' ].includes( rule.operator ) &&
				! parseOptionPairs( source.attributes.options ).some(
					( option ) => option.value === rule.value
				)
			) {
				errors.push( 'invalid_condition_value' );
			}
		} );
	} );

	if (
		autoresponderField &&
		! fields.some(
			( field ) =>
				field.name === `${ FIELD_PREFIX }email` &&
				field.attributes.slug === autoresponderField
		)
	) {
		errors.push( 'invalid_autoresponder' );
	}

	return [ ...new Set( errors ) ];
}
