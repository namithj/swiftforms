<?php
/**
 * Nonce verification for form submissions.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

/**
 * Wraps `wp_verify_nonce()` for the one action SwiftForms cares about. A
 * stale-nonce rejection returns a fresh nonce in its own response body, so a
 * form sitting on a cached page can silently retry once (see view.js).
 */
final class NonceGuard {

	public const ACTION = 'swf_submit';

	/**
	 * Creates a nonce for embedding in rendered form markup.
	 */
	public function create(): string {
		return wp_create_nonce( self::ACTION );
	}

	/**
	 * Verifies a submitted nonce.
	 */
	public function verify( string $nonce ): bool {
		return false !== wp_verify_nonce( $nonce, self::ACTION );
	}
}
