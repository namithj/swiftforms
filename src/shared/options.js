/**
 * "Label|value" option-list parsing, mirroring includes/Fields/OptionParser.php.
 * Used only for editor-canvas previews of select/radio fields; the actual
 * <option>/radio markup visitors see is always rendered server-side.
 */

/**
 * Parses a newline-separated option list into label/value pairs.
 *
 * @param {string} raw Raw textarea value, one option per line.
 * @return {Array<{label: string, value: string}>} Parsed label/value pairs.
 */
export function parseOptionPairs( raw ) {
	return String( raw || '' )
		.split( /\r\n|\r|\n/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.map( ( line ) => {
			if ( line.includes( '|' ) ) {
				const [ label, value ] = line
					.split( '|' )
					.map( ( part ) => part.trim() );
				return { label, value };
			}

			return { label: line, value: line };
		} )
		.filter( ( pair ) => pair.value !== '' );
}
