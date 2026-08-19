/**
 * Conditional field visibility engine.
 *
 * Mirrors `includes/Conditions.php` exactly — both implementations are
 * exercised against the same fixture (tests/fixtures/conditions.json) so
 * the editor/frontend and the server can never silently disagree about
 * what "visible" means.
 */

export const MAX_PASSES = 10;

const OPERATORS = [ 'equals', 'not_equals', 'contains', 'empty', 'not_empty' ];

/**
 * Evaluates a single rule against the current values.
 *
 * @param {Object} rule   { field, operator, value }
 * @param {Object} values slug => value
 */
function evaluateRule( rule, values ) {
	const field = rule.field || '';
	const operator = OPERATORS.includes( rule.operator )
		? rule.operator
		: 'equals';
	const expected = String( rule.value ?? '' );
	const actual = String( values[ field ] ?? '' );

	switch ( operator ) {
		case 'equals':
			return actual.trim() === expected.trim();
		case 'not_equals':
			return actual.trim() !== expected.trim();
		case 'contains':
			return (
				expected !== '' &&
				actual.toLowerCase().includes( expected.toLowerCase() )
			);
		case 'empty':
			return actual.trim() === '';
		case 'not_empty':
			return actual.trim() !== '';
		default:
			return false;
	}
}

/**
 * AND within a group.
 *
 * @param {Array}  rules  Rules in this group.
 * @param {Object} values slug => value.
 */
function evaluateGroup( rules, values ) {
	if ( ! rules || ! rules.length ) {
		return false;
	}

	return rules.every( ( rule ) => evaluateRule( rule, values ) );
}

/**
 * OR across groups.
 *
 * @param {Array}  groups Groups of rules.
 * @param {Object} values slug => value.
 */
function evaluateGroups( groups, values ) {
	if ( ! groups || ! groups.length ) {
		return false;
	}

	return groups.some( ( group ) => evaluateGroup( group, values ) );
}

/**
 * Whether one field should be visible given the current known values.
 *
 * @param {Object} conditions Field's `conditions` attribute.
 * @param {Object} values     slug => current value.
 */
export function isFieldVisible( conditions, values ) {
	if (
		! conditions ||
		! conditions.enabled ||
		! conditions.groups ||
		! conditions.groups.length
	) {
		return true;
	}

	const matches = evaluateGroups( conditions.groups, values );
	const action = conditions.action || 'show';

	return action === 'hide' ? ! matches : matches;
}

/**
 * Resolves visibility for every field, fixed-point over up to MAX_PASSES
 * rounds since conditions can chain (A hides B, B's visibility affects C).
 *
 * @param {Object} fields slug => { conditions }
 * @param {Object} values slug => submitted value
 * @return {Object} slug => boolean
 */
export function resolveVisibility( fields, values ) {
	const visibility = {};
	Object.keys( fields ).forEach( ( slug ) => {
		visibility[ slug ] = true;
	} );

	for ( let pass = 0; pass < MAX_PASSES; pass++ ) {
		const effectiveValues = { ...values };

		Object.keys( visibility ).forEach( ( slug ) => {
			if ( ! visibility[ slug ] ) {
				effectiveValues[ slug ] = '';
			}
		} );

		let changed = false;

		Object.keys( fields ).forEach( ( slug ) => {
			const conditions = fields[ slug ].conditions || {};
			const visible = isFieldVisible( conditions, effectiveValues );

			if ( visibility[ slug ] !== visible ) {
				visibility[ slug ] = visible;
				changed = true;
			}
		} );

		if ( ! changed ) {
			break;
		}
	}

	return visibility;
}
