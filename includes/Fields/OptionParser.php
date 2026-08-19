<?php
/**
 * Shared "Label|value" option-list parsing for select/radio fields.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields;

/**
 * Mirrors `src/shared/options.js` exactly — both sides must parse the same
 * newline-separated `Label|value` (or bare `Label`) syntax the same way.
 */
final class OptionParser {

	/**
	 * Parses a newline-separated option list into label/value pairs.
	 *
	 * @param string $raw Raw textarea value, one option per line.
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function parse( string $raw ): array {
		$pairs = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, '|' ) ) {
				[ $label, $value ] = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$label = $line;
				$value = $line;
			}

			if ( '' === $value ) {
				continue;
			}

			$pairs[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $pairs;
	}

	/**
	 * Returns just the allowed values from a raw option list.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	public static function values( string $raw ): array {
		return array_column( self::parse( $raw ), 'value' );
	}
}
