/**
 * Field-name rules shared by the editor script and its tests.
 *
 * A field name must match ^[a-z][a-z0-9_-]{0,63}$ (data contract C-2.4). The
 * block builder derives a name from the label with a plain slug, which can
 * start with a digit ("2nd choice") or come out empty. The editor repairs a
 * leading digit by prefixing "f-" and cuts to 64 characters, the same repair
 * the server applies (Schema_Resolver::derive_name), so both agree. An empty
 * name stays empty here: the server then uses the field type as the name.
 */

export const NAME_PATTERN = /^[a-z][a-z0-9_-]{0,63}$/;

/**
 * Whether a string is a valid field name.
 *
 * @param {string|null} name Candidate.
 * @return {boolean} True when valid.
 */
export function isValidName( name ) {
	return 'string' === typeof name && NAME_PATTERN.test( name );
}

/**
 * Repair a slug-shaped name that is not valid yet.
 *
 * Only names made of slug characters are touched; anything else (a name the
 * author is still typing, with spaces or capitals) is returned unchanged so
 * the repair never fights the keyboard.
 *
 * @param {string} name A field name.
 * @return {string} The repaired name, or the input when nothing applies.
 */
export function repairName( name ) {
	if ( 'string' !== typeof name || '' === name || isValidName( name ) ) {
		return name;
	}
	if ( ! /^[a-z0-9_-]+$/.test( name ) ) {
		return name;
	}
	const prefixed = /^[a-z]/.test( name ) ? name : 'f-' + name;
	return prefixed.slice( 0, 64 );
}
