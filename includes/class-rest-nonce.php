<?php
/**
 * REST nonce refresh.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Nonce
 *
 * admin-ajax.php?action=swiftforms_rest_nonce . A logged-in visitor's page may be older than the nonce it
 * carries; when core answers rest_cookie_invalid_nonce the view module fetches a fresh `wp_rest` nonce here
 * and retries once (ADR-002). The cookie authenticates the request, so no nonce is needed to ask. There is
 * NO wp_ajax_nopriv_ hook: a guest gets nothing. The answer is never cached.
 *
 *   { "nonce": "..." }
 */
class Rest_Nonce {

	/**
	 * Hook the AJAX action for logged-in users only.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_swiftforms_rest_nonce', [ self::class, 'respond' ] );
	}

	/**
	 * Send the nonce and stop.
	 *
	 * @return void
	 */
	public static function respond() {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}
		wp_send_json( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
	}
}
