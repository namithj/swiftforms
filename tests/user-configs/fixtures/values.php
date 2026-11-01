<?php // phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Short fixture helper SF_VALUES() used inside config files.
/**
 * Named submission payloads (valid, invalid, spammy, nasty) for the user configs.
 *
 * R00 has only the self-test entry; R01 adds the real ones with the forms.
 *
 * @package SwiftForms
 */

if ( ! function_exists( 'SF_VALUES' ) ) {
	/**
	 * Return a named submission payload.
	 *
	 * @param string $name Payload name, for example "selftest".
	 * @return array<string, mixed>
	 */
	function SF_VALUES( $name ) {
		static $values = null;
		if ( null === $values ) {
			$values = [
				'selftest' => [ 'marker' => 'selftest-v1' ],
			];
		}
		if ( ! array_key_exists( $name, $values ) ) {
			throw new InvalidArgumentException( 'Unknown values fixture: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message.
		}
		return $values[ $name ];
	}
}
