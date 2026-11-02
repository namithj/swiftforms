/**
 * Client-side validation that mirrors the server (Validator, R01 2.5).
 *
 * The rules work on a plain field description (the same keys as a schema field on the server) and a plain
 * value (a string, or a list of strings for checkbox groups and multiple selects), so they run the same
 * cases as the PHP side (tests/fixtures/validation-cases.json). Option membership is checked on the server
 * only. Messages are the server's translated templates (state.messages).
 */

const DEFAULT_MAX_LENGTH = 5000;
const MAX_URL_LENGTH = 2048;

/**
 * Length in characters (code points), like mb_strlen on the server.
 *
 * @param {string} value Text.
 * @return {number} Length.
 */
const length = ( value ) => Array.from( value ).length;

/**
 * Whether a field holds a list of values.
 *
 * @param {Object} field Field description.
 * @return {boolean} True for checkbox groups and multiple selects.
 */
export const isMulti = ( field ) =>
	field.type === 'checkboxes' ||
	( field.type === 'select' && field.multiple === true );

/**
 * The longest allowed length: maxLength, never above the default cap.
 *
 * @param {Object} field Field description.
 * @return {number} Limit.
 */
export const maxLengthOf = ( field ) => {
	const max = parseInt( field.maxLength, 10 ) || 0;
	return max > 0 && max < DEFAULT_MAX_LENGTH ? max : DEFAULT_MAX_LENGTH;
};

/**
 * Port of WordPress is_email().
 *
 * @param {string} email Value.
 * @return {boolean} Valid.
 */
export function isEmail( email ) {
	if ( email.length < 6 || email.indexOf( '@', 1 ) < 1 ) {
		return false;
	}
	const at = email.indexOf( '@' );
	if ( ! /^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test( email.slice( 0, at ) ) ) {
		return false;
	}
	const domain = email.slice( at + 1 );
	if ( /\.{2,}/.test( domain ) || /^[\s.]|[\s.]$/.test( domain ) ) {
		return false;
	}
	const subs = domain.split( '.' );
	if ( subs.length < 2 ) {
		return false;
	}
	return subs.every(
		( sub ) => ! /^[\s-]|[\s-]$/.test( sub ) && /^[a-z0-9-]+$/i.test( sub )
	);
}

/**
 * Whether a string is an absolute http or https URL with a host.
 *
 * @param {string} value Value.
 * @return {boolean} Valid.
 */
export function isHttpUrl( value ) {
	if (
		value.length > MAX_URL_LENGTH ||
		/[\u0000- \u007f"<>\\^`{|}]/.test( value )
	) {
		return false;
	}
	if ( ! /^https?:\/\//i.test( value ) ) {
		return false;
	}
	let url;
	try {
		url = new URL( value );
	} catch {
		return false;
	}
	return /^(?:\[[0-9a-f:.]+\]|[^\s/?#@:]+)$/iu.test( url.hostname );
}

/**
 * Whether a string is a real calendar date as Y-m-d.
 *
 * @param {string} value Value.
 * @return {boolean} Valid.
 */
export function isDate( value ) {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( value );
	if ( ! m || +m[ 1 ] < 1 ) {
		return false;
	}
	const date = new Date( 0 );
	date.setUTCFullYear( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] );
	return (
		date.getUTCFullYear() === +m[ 1 ] &&
		date.getUTCMonth() === +m[ 2 ] - 1 &&
		date.getUTCDate() === +m[ 3 ]
	);
}

/**
 * A numeric attribute as a number, or null (empty, "any", not a number).
 *
 * @param {*} raw Attribute value.
 * @return {number|null} Number.
 */
function numberAttr( raw ) {
	const text = raw === undefined || raw === null ? '' : String( raw ).trim();
	return /^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test( text )
		? Number( text )
		: null;
}

function ruleNumber( field, value ) {
	if ( ! /^-?(?:\d+|\d*\.\d+)$/.test( value ) ) {
		return 'number';
	}
	const number = Number( value );
	const min = numberAttr( field.min );
	if ( min !== null && number < min ) {
		return 'number_min';
	}
	const max = numberAttr( field.max );
	if ( max !== null && number > max ) {
		return 'number_max';
	}
	const step = numberAttr( field.step );
	if ( step !== null && step > 0 ) {
		const quotient = ( number - ( min ?? 0 ) ) / step;
		if (
			Math.abs( quotient - Math.round( quotient ) ) >
			1e-8 * Math.max( 1, Math.abs( quotient ) )
		) {
			return 'number_step';
		}
	}
	return null;
}

function ruleDate( field, value ) {
	if ( ! isDate( value ) ) {
		return 'date';
	}
	const min = String( field.min ?? '' ).trim();
	if ( isDate( min ) && value < min ) {
		return 'date_min';
	}
	const max = String( field.max ?? '' ).trim();
	if ( isDate( max ) && value > max ) {
		return 'date_max';
	}
	return null;
}

function rulePattern( field, value ) {
	if ( ! field.pattern ) {
		return null;
	}
	let regex;
	try {
		// Anchored like the HTML pattern attribute; a pattern that does not compile is ignored.
		regex = new RegExp( '^(?:' + field.pattern + ')$', 'u' );
	} catch {
		return null;
	}
	return regex.test( value ) ? null : 'pattern';
}

/**
 * The first failing rule of a field, as a message key.
 *
 * @param {Object}          field Field description (type, required, minLength, maxLength, pattern, min, max, step, minChoices, maxChoices, multiple).
 * @param {string|string[]} value Collected value.
 * @return {string|null} Message key, or null when valid.
 */
export function check( field, value ) {
	const required = field.required === true;

	if ( isMulti( field ) ) {
		const list = Array.isArray( value ) ? value : [];
		if ( required && list.length === 0 ) {
			return 'required';
		}
		const min = parseInt( field.minChoices, 10 ) || 0;
		const max = parseInt( field.maxChoices, 10 ) || 0;
		if ( min > 0 && list.length > 0 && list.length < min ) {
			return 'min_choices';
		}
		return max > 0 && list.length > max ? 'max_choices' : null;
	}

	const text = typeof value === 'string' ? value : '';

	if ( field.type === 'consent' ) {
		return required && text === '' ? 'consent' : null;
	}
	if ( text === '' ) {
		return required ? 'required' : null;
	}

	if ( length( text ) > maxLengthOf( field ) ) {
		return 'max_length';
	}
	const minLength = parseInt( field.minLength, 10 ) || 0;
	if ( minLength > 0 && length( text ) < minLength ) {
		return 'min_length';
	}

	switch ( field.type ) {
		case 'email':
			return isEmail( text ) ? null : 'email';
		case 'url':
			return isHttpUrl( text ) ? null : 'url';
		case 'number':
			return ruleNumber( field, text );
		case 'date':
			return ruleDate( field, text );
		case 'text':
			return rulePattern( field, text );
	}
	return null;
}

/**
 * The limit a message names.
 *
 * @param {string} key   Message key.
 * @param {Object} field Field description.
 * @return {string} Limit text.
 */
function limitText( key, field ) {
	switch ( key ) {
		case 'max_length':
			return String( maxLengthOf( field ) );
		case 'min_length':
			return String( parseInt( field.minLength, 10 ) || 0 );
		case 'min_choices':
			return String( parseInt( field.minChoices, 10 ) || 0 );
		case 'max_choices':
			return String( parseInt( field.maxChoices, 10 ) || 0 );
		case 'number_min':
		case 'date_min':
			return String( field.min ?? '' );
		case 'number_max':
		case 'date_max':
			return String( field.max ?? '' );
	}
	return '';
}

/**
 * The translated message for a rule key, with its placeholders filled.
 *
 * @param {string} key      Message key.
 * @param {Object} field    Field description.
 * @param {Object} messages Templates from the server (state.messages).
 * @return {string} Message.
 */
export function messageFor( key, field, messages ) {
	if ( key === 'pattern' && field.patternMessage ) {
		return field.patternMessage;
	}
	const template =
		( messages && ( messages[ key ] || messages.invalid ) ) || '';
	const limit = limitText( key, field );
	return template
		.split( '{min}' )
		.join( limit )
		.split( '{max}' )
		.join( limit )
		.split( '{step}' )
		.join( String( field.step ?? '' ) );
}

// ---------------------------------------------------------------------
// DOM.
// ---------------------------------------------------------------------

const TYPE_PREFIX = 'swiftforms-field--';

/**
 * Describe a rendered field wrapper and read its value.
 *
 * @param {Element} wrapper A `.swiftforms-field` element.
 * @return {{field: Object, value: string|string[], bad: boolean}|null} Null for hidden or unknown markup.
 */
export function readField( wrapper ) {
	const type = Array.from( wrapper.classList )
		.filter(
			( c ) =>
				c.startsWith( TYPE_PREFIX ) && c !== TYPE_PREFIX + 'required'
		)
		.map( ( c ) => c.slice( TYPE_PREFIX.length ) )[ 0 ];
	const control = wrapper.querySelector( 'input, select, textarea' );
	if ( ! type || type === 'hidden' || ! control ) {
		return null;
	}

	const attr = ( name ) => control.getAttribute( name ) || '';
	const field = {
		type,
		required: wrapper.classList.contains( TYPE_PREFIX + 'required' ),
		minLength: attr( 'minlength' ),
		maxLength: attr( 'maxlength' ),
		pattern: type === 'text' ? attr( 'pattern' ) : '',
		patternMessage:
			type === 'text' && attr( 'pattern' ) ? attr( 'title' ) : '',
		min: attr( 'min' ),
		max: attr( 'max' ),
		step: attr( 'step' ),
		minChoices: wrapper.getAttribute( 'data-sf-min-choices' ) || '',
		maxChoices: wrapper.getAttribute( 'data-sf-max-choices' ) || '',
		multiple: control.multiple === true,
	};

	let value;
	let bad = false;
	const checked = () =>
		Array.from( wrapper.querySelectorAll( 'input:checked' ) ).map(
			( input ) => input.value
		);

	if ( type === 'consent' ) {
		value = control.checked ? '1' : '';
	} else if ( type === 'radio' ) {
		value = checked()[ 0 ] || '';
	} else if ( type === 'checkboxes' ) {
		value = Array.from( new Set( checked() ) );
	} else if ( type === 'select' ) {
		value = control.multiple
			? Array.from(
					new Set(
						Array.from( control.selectedOptions ).map(
							( o ) => o.value
						)
					)
				).filter( Boolean )
			: control.value;
	} else if ( type === 'textarea' ) {
		value = control.value.replace( /\r\n?/g, '\n' ).trim();
	} else {
		value = control.value.replace( /[\n\t]+/g, ' ' ).trim();
		bad = !! control.validity && control.validity.badInput === true;
	}
	return { field, value, bad };
}

/**
 * Validate one field wrapper.
 *
 * @param {Element} wrapper  A `.swiftforms-field` element.
 * @param {Object}  messages Templates from the server.
 * @return {{message: string, control: Element}|null} The problem, or null when valid or not checkable.
 */
export function validateWrapper( wrapper, messages ) {
	const read = readField( wrapper );
	if ( ! read ) {
		return null;
	}
	let key = check( read.field, read.value );
	if ( read.bad ) {
		key = read.field.type === 'date' ? 'date' : 'number';
	}
	if ( key === null ) {
		return null;
	}
	return {
		message: messageFor( key, read.field, messages ),
		control: wrapper.querySelector( 'input, select, textarea' ),
	};
}
