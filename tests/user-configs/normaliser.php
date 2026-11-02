<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Fixture class name SF_Normaliser is fixed by the R00 plan.
/**
 * Output normaliser for the user-config snapshots (R00).
 *
 * Snapshots must change only when SwiftForms' output changes, never because
 * WordPress core, the clock or a random token changed. Everything this class
 * rewrites is listed here, and each rule has a test in NormaliserTest.php.
 * Nothing else is touched: if a snapshot differs, it is a real difference.
 *
 * @package SwiftForms
 */

/**
 * Normalises HTML, JSON and plain text before they are compared with a snapshot.
 *
 * HTML rules (applied in this order):
 *  1. Line endings: CRLF and CR become LF.
 *  2. Whitespace between tags: any run of whitespace that contains only
 *     whitespace between ">" and "<" becomes one LF. Text inside elements is untouched.
 *  3. Asset version strings: the "ver" query argument is removed from URLs
 *     (core's version, or a plugin version that changes every release).
 *  4. Hashed layout classes: "wp-container-core-group-is-layout-9f0d2b3c" and
 *     "wp-container-61a3b2c1" become "wp-container-{HASH}"; "wp-elements-<32 hex>"
 *     becomes "wp-elements-{HASH}". Core derives these from a style hash that
 *     changes between versions. Stable classes such as "wp-block-group-is-layout-flow"
 *     and handle ids such as "wp-block-library-css" are kept.
 *  5. Nonces: ten hex digits after "_wpnonce=", in a "_wpnonce" input value or in
 *     a "data-nonce" attribute become "{NONCE}". (The suite asserts validity
 *     first; the value changes on every run.)
 *  6. Tokens: SwiftForms form tokens of the shape "v1.<base36>.<32 hex>" become "{TOKEN}".
 *  7. Core-version classes: core 7.0 and later add the class "wp-block-paragraph"
 *     to every paragraph block, 6.8 does not. The class is removed (and an
 *     emptied class attribute with it). This is the only such class listed; add
 *     another here, with a test, only when a core release changes it.
 *  8. Signed render times: the "_sf_t" control of a rendered form, "{unix}.{16 hex}" (the Time_Trap MAC), becomes
 *     "{T}" in an input tag. The suite asserts the signature in S1 before it is replaced.
 *  9. Result keys: the 128-bit key of a no-JS result, "sf_result={32 hex}", becomes "sf_result={RESULT}". The suite
 *     asserts that it is a valid, stored key before it is replaced (S4).
 * 10. Plugin version: the "_sf_v" control carries SWIFTFORMS_VERSION, which changes every release; its value becomes
 *     "{VERSION}".
 *     The REST nonce in the view module's client state (JSON, "nonce": ten hex digits) becomes "{NONCE}" like the other nonces.
 * 11. Emoji images: core turns an emoji in an HTML mail into an image from s.w.org whose path carries the Unicode
 *     version core ships ("/emoji/17.0.2/"); the version becomes "{EMOJI}".
 * 12. Edges: leading and trailing whitespace is trimmed.
 * 13. Anchors: core prints the "anchor" of a dynamic block as an id only from the release that added anchor.php to
 *     block-supports (after 6.8). The id attribute of a SwiftForms form element (a form, or the div that replaces it with
 *     its confirmation) is removed; the runner asserts the id is there when core supports it (S1).
 *  "http://example.org" (the test home URL) is deliberately NOT rewritten.
 *
 * tokens() applies rules 5, 6, 8, 9 and 10 to text that is not HTML (JSON, mail bodies, request dumps). scrub() is a
 * literal alias map for values that depend on the database (post IDs, derived formIds, entry IDs); the runner builds it
 * from the config it applied, so nothing is guessed.
 *
 * Every rule is idempotent: normalise( normalise( x ) ) === normalise( x ).
 */
class SF_Normaliser {

	/**
	 * Normalise an HTML fragment.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html( $html ) {
		$html = str_replace( [ "\r\n", "\r" ], "\n", (string) $html );

		// 2. Whitespace between tags.
		$html = preg_replace( '/>\s+</', ">\n<", $html );

		// 3. Asset version strings.
		$html = preg_replace( '/\?ver=[^&"\'#\s<>]*&(?:amp;|#038;)?/', '?', $html );
		$html = preg_replace( '/(?:\?|&(?:amp;|#038;)?)ver=[^&"\'#\s<>]*/', '', $html );

		// 4. Hashed layout classes.
		$html = preg_replace( '/wp-container-(?:[a-z0-9-]+-)?[0-9a-f]{8}\b/', 'wp-container-{HASH}', $html );
		$html = preg_replace( '/wp-elements-[0-9a-f]{32}\b/', 'wp-elements-{HASH}', $html );

		// 5. Nonces.
		$html = preg_replace( '/(_wpnonce=|data-nonce=["\'])[a-f0-9]{10}\b/', '$1{NONCE}', $html );
		$html = preg_replace( '/(name=["\']_wpnonce["\'][^>]*?value=["\'])[a-f0-9]{10}\b/', '$1{NONCE}', $html );
		$html = preg_replace( '/(value=["\'])[a-f0-9]{10}(["\'][^>]*?name=["\']_wpnonce["\'])/', '$1{NONCE}$2', $html );

		// 6. Tokens.
		$html = preg_replace( '/\bv1\.[a-z0-9]+\.[a-f0-9]{32}\b/', '{TOKEN}', $html );

		// 8. Signed render times, 9. result keys, 10. plugin version.
		$html = self::controls( $html );

		// 7. Core-version classes.
		$html = preg_replace_callback(
			'/(\s)class="([^"]*)"/',
			static function ( $m ) {
				$tokens = preg_split( '/\s+/', $m[2], -1, PREG_SPLIT_NO_EMPTY );
				if ( ! in_array( 'wp-block-paragraph', $tokens, true ) ) {
					return $m[0];
				}
				$tokens = array_diff( $tokens, [ 'wp-block-paragraph' ] );
				return $tokens ? $m[1] . 'class="' . implode( ' ', $tokens ) . '"' : '';
			},
			$html
		);

		// 13. Anchors of form elements.
		$html = preg_replace_callback(
			'/<(form|div) class="swiftforms-form[ "][^>]*>/',
			static function ( $m ) {
				return preg_replace( '/ id="[^"]*"/', '', $m[0], 1 );
			},
			$html
		);

		// 11. Emoji images.
		$html = preg_replace( '#(s\.w\.org/images/core/emoji/)[0-9][0-9.]*/#', '$1{EMOJI}/', $html );

		// 12. Edges.
		return trim( $html );
	}

	/**
	 * Rules 5, 6, 8, 9 and 10 for text that is not HTML.
	 *
	 * @param string $text Text (JSON, a mail, a request dump).
	 * @return string
	 */
	public static function tokens( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/(_wpnonce=|data-nonce=["\'])[a-f0-9]{10}\b/', '$1{NONCE}', $text );
		$text = preg_replace( '/\bv1\.[a-z0-9]+\.[a-f0-9]{32}\b/', '{TOKEN}', $text );
		return self::controls( $text );
	}

	/**
	 * Replace literal values using an alias map, longest value first.
	 *
	 * @param string                $text    Text.
	 * @param array<string, string> $aliases Value => replacement.
	 * @return string
	 */
	public static function scrub( $text, array $aliases ) {
		uksort(
			$aliases,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return strtr( (string) $text, $aliases );
	}

	/**
	 * Rules 8 to 10: the hidden controls of a form and the result key of a no-JS redirect.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function controls( $text ) {
		$text = preg_replace( '/(name=["\']_sf_t["\'][^>]*?value=["\'])\d{9,12}\.[0-9a-f]{16}(["\'])/', '$1{T}$2', $text );
		$text = preg_replace( '/(value=["\'])\d{9,12}\.[0-9a-f]{16}(["\'][^>]*?name=["\']_sf_t["\'])/', '$1{T}$2', $text );
		$text = preg_replace( '/(name=["\']_sf_v["\'][^>]*?value=["\'])[0-9][0-9a-zA-Z.+-]*(["\'])/', '$1{VERSION}$2', $text );
		$text = preg_replace( '/(value=["\'])[0-9][0-9a-zA-Z.+-]*(["\'][^>]*?name=["\']_sf_v["\'])/', '$1{VERSION}$2', $text );
		$text = preg_replace( '/(sf_result=)[0-9a-f]{32}\b/', '$1{RESULT}', $text );
		$text = preg_replace( '/("nonce":\s*")[a-f0-9]{10}"/', '$1{NONCE}"', $text );
		return $text;
	}

	/**
	 * Encode a value as pretty-printed JSON with a trailing newline.
	 *
	 * Key order is kept exactly as given: reordering keys is a change worth seeing.
	 * Slashes and unicode are not escaped, so the snapshot is readable.
	 *
	 * @param mixed $value Any JSON-encodable value.
	 * @return string
	 */
	public static function json( $value ) {
		return json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable bytes are required; wp_json_encode may differ between versions.
	}

	/**
	 * Normalise plain text: LF line endings and no trailing whitespace on any line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( $text ) {
		$text = str_replace( [ "\r\n", "\r" ], "\n", (string) $text );
		return preg_replace( '/[ \t]+$/m', '', $text );
	}
}
