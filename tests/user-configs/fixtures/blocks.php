<?php // phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Short fixture helper SF_FX() used inside config files.
/**
 * Named block-markup fragments for the user configs.
 *
 * Each fragment is a nowdoc, so the bytes in the database are exactly the bytes
 * written here. Never edit a fragment that a frozen config uses: add a new one.
 *
 * @package SwiftForms
 */

if ( ! function_exists( 'SF_FX' ) ) {
	/**
	 * Return the exact bytes of a named block-markup fragment.
	 *
	 * @param string $name Fragment name, for example "selftest-paragraph".
	 * @return string
	 */
	function SF_FX( $name ) {
		static $fragments = null;
		if ( null === $fragments ) {
			$fragments = [
				// Harness self-test (R00): a core block, no SwiftForms markup.
				'selftest-paragraph' => <<<'HTML'
<!-- wp:paragraph -->
<p>SwiftForms harness self-test.</p>
<!-- /wp:paragraph -->
HTML
				,
			];
		}
		if ( ! array_key_exists( $name, $fragments ) ) {
			throw new InvalidArgumentException( 'Unknown block fixture: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message.
		}
		return $fragments[ $name ];
	}
}
