<?php
/**
 * Tests for the form post without JavaScript (Post/Redirect/Get).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Form_Renderer;
use SwiftForms\Form_State;
use SwiftForms\Nojs_Handler;
use SwiftForms\Render_Token;
use SwiftForms\Submission;
use SwiftForms\Time_Trap;

/**
 * Class NojsHandlerTest
 *
 * @group submission
 * @group security
 */
class NojsHandlerTest extends SF_Submission_Test_Case {

	/**
	 * Page ID of the form.
	 *
	 * @var int
	 */
	private $page = 0;

	/**
	 * Contexts handed to the notifier.
	 *
	 * @var \SwiftForms\Submission_Context[]
	 */
	private $notified = [];

	/**
	 * Exits are captured, the per-form limit is off, time is frozen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->capture_exits();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 0 ] ] );
		Clock::freeze( 1900000000 );
		$this->notified       = [];
		Submission::$notifier = function ( $ctx ) {
			$this->notified[] = $ctx;
		};
	}

	/**
	 * Reset the globals the handler reads.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_GET                      = [];
		Submission::$notifier      = null;
		Nojs_Handler::$uncacheable = false;
		Nojs_Handler::$headers     = [];
		Form_State::reset();
		parent::tear_down();
	}

	/**
	 * Make the form page.
	 *
	 * @param array $attrs Form attributes.
	 * @param array $inner Inner markup (default form when null).
	 * @return void
	 */
	private function page( array $attrs = [], $inner = null ) {
		$this->page = $this->make_form( $inner, $attrs );
	}

	/**
	 * Post the form as a browser without JavaScript would, five seconds after the page was rendered.
	 *
	 * @param array $sf       Field values.
	 * @param array $controls Control overrides.
	 * @param string $uri      Request URI (default: the page's path).
	 * @return SF_Test_Redirect|SF_Test_Die The exit the handler took.
	 */
	private function post( array $sf, array $controls = [], $uri = null ) {
		$issued = Time_Trap::issue( $this->form_id, $this->source );
		Clock::freeze( Clock::now() + 5 );

		$controls  = $this->controls(
			array_merge(
				[
					'_sf_t'  => $issued,
					'_sf_hp' => '',
				],
				$controls
			)
		);
		$permalink = get_permalink( $this->page );
		$this->go_to( $permalink );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = null === $uri ? (string) wp_parse_url( $permalink, PHP_URL_PATH ) . ( (string) wp_parse_url( $permalink, PHP_URL_QUERY ) !== '' ? '?' . wp_parse_url( $permalink, PHP_URL_QUERY ) : '' ) : $uri;
		$_POST                     = wp_slash( array_merge( $controls, [ 'sf' => $sf ] ) );

		try {
			Nojs_Handler::maybe_handle();
		} catch ( SF_Test_Redirect $e ) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = [];
			return $e;
		} catch ( SF_Test_Die $e ) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = [];
			return $e;
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->fail( 'The handler did not redirect or die.' );
	}

	/**
	 * The result key of a Post/Redirect/Get redirect.
	 *
	 * @param SF_Test_Redirect $redirect Redirect.
	 * @return string
	 */
	private function key( SF_Test_Redirect $redirect ) {
		parse_str( (string) wp_parse_url( $redirect->location, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'sf_result', $query );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $query['sf_result'] );
		return $query['sf_result'];
	}

	/**
	 * Visit the result page and return what the form renders.
	 *
	 * @param string $key Result key.
	 * @return string
	 */
	private function visit( $key ) {
		Form_State::reset();
		Form_Renderer::reset();
		Nojs_Handler::$uncacheable = false;
		Nojs_Handler::$headers     = [];
		$this->go_to( add_query_arg( 'sf_result', $key, get_permalink( $this->page ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['sf_result']         = $key;
		Nojs_Handler::maybe_handle();
		return $this->render_current();
	}

	/**
	 * Whether a result transient exists.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function stored( $key ) {
		return false !== get_transient( Nojs_Handler::TRANSIENT_PREFIX . $key );
	}

	/**
	 * Success: 303 to the same URL with a one-time key; the key shows the confirmation once and is deleted.
	 *
	 * @return void
	 */
	public function test_post_redirect_get() {
		$this->page();
		$exit = $this->post( $this->good() );

		$this->assertInstanceOf( SF_Test_Redirect::class, $exit );
		$this->assertSame( 303, $exit->status );
		$this->assertSame( get_permalink( $this->page ), remove_query_arg( 'sf_result', $exit->location ) );
		$key = $this->key( $exit );
		$this->assertCount( 1, $this->entries() );
		$this->assertCount( 1, $this->notified );
		$this->assertSame( 'nojs', $this->notified[0]->transport );

		// The stored result holds this visitor's confirmation and nothing else.
		$data = get_transient( Nojs_Handler::TRANSIENT_PREFIX . $key );
		$this->assertSame( $this->form_id, $data['form_id'] );
		$this->assertSame( 1, $data['instance'] );
		$this->assertTrue( $data['state']['success'] );
		$this->assertSame( '<p>Thanks!</p>', $this->norm( $data['state']['confirmation_html'] ) );
		$this->assertArrayNotHasKey( 'values', $data['state'] );

		// Ten minutes.
		global $wpdb;
		$timeout = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . Nojs_Handler::TRANSIENT_PREFIX . $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertEqualsWithDelta( time() + 600, $timeout, 5 );

		// The result page shows the confirmation.
		$html = $this->visit( $key );
		$this->assertStringContainsString( '<div class="swiftforms-confirmation" role="status"><p>Thanks!</p></div>', $this->norm( $html ) );
		$this->assertStringNotContainsString( '<form', $html );

		// Single read: the key is gone.
		$this->assertFalse( $this->stored( $key ), 'Deleted on the first read (mutation 10).' );
		$again = $this->visit( $key );
		$this->assertStringContainsString( '<form', $again, 'A second visit shows the plain form.' );
		$this->assertStringNotContainsString( 'swiftforms-confirmation', $again );
	}

	/**
	 * The result page is uncacheable: headers, constant and referrer policy.
	 *
	 * @return void
	 */
	public function test_result_page_is_uncacheable() {
		$this->page();
		$key = $this->key( $this->post( $this->good() ) );
		$this->visit( $key );

		$this->assertTrue( Nojs_Handler::$uncacheable );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$headers = implode( "\n", Nojs_Handler::$headers );
		$this->assertStringContainsString( 'Referrer-Policy: no-referrer', $headers );
		$this->assertStringContainsString( 'Cache-Control: no-cache', $headers );
		$this->assertStringContainsString( 'no-store', $headers . "\nno-store", 'nocache_headers() asks for no caching.' );

		// A stale or malformed key still gets the uncacheable treatment and the plain form.
		foreach ( [ str_repeat( 'a', 32 ), 'short', str_repeat( 'g', 32 ), '../../etc', str_repeat( 'a', 33 ) ] as $bad ) {
			$html = $this->visit( $bad );
			$this->assertTrue( Nojs_Handler::$uncacheable, $bad );
			$this->assertStringContainsString( '<form', $html, $bad );
			$this->assertStringNotContainsString( 'swiftforms-confirmation', $html, $bad );
		}
	}

	/**
	 * Keys are 128 random bits and differ per submission.
	 *
	 * @return void
	 */
	public function test_keys_are_random() {
		$this->page();
		$a = $this->key( $this->post( $this->good( [ 'name' => 'A' ] ) ) );
		$b = $this->key( $this->post( $this->good( [ 'name' => 'B' ] ) ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 32, strlen( $a ) );
	}

	/**
	 * Invalid input: errors and the visitor's values come back, nothing is stored.
	 *
	 * @return void
	 */
	public function test_invalid_rerenders_errors_and_values() {
		$this->page();
		$exit = $this->post(
			[
				'name'    => '',
				'email'   => 'not-an-email',
				'message' => 'Keep <b>this</b> & that',
			]
		);
		$this->assertSame( 303, $exit->status );
		$this->assertCount( 0, $this->entries() );

		$html = $this->visit( $this->key( $exit ) );
		$this->assertStringContainsString( 'swiftforms-errors-summary', $html );
		$this->assertStringContainsString( 'This field is required.', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
		$this->assertStringContainsString( 'value="not-an-email"', $html );
		$this->assertStringContainsString( 'Keep &lt;b&gt;this&lt;/b&gt; &amp; that', $html, 'The value comes back escaped.' );
		$this->assertStringNotContainsString( 'Keep <b>', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
	}

	/**
	 * An expired token re-renders the form with the visitor's values and "Please submit again."; the form carries a fresh token.
	 *
	 * @return void
	 */
	public function test_expired_token_rerenders_with_values() {
		$this->page();
		$old = Render_Token::issue( $this->form_id, $this->source, $this->schema()->hash );
		Clock::freeze( Clock::now() + 9 * DAY_IN_SECONDS );
		$exit = $this->post(
			$this->good( [ 'message' => 'Do not lose me' ] ),
			[
				'_sf_token' => $old,
				'_sf_t'     => Time_Trap::issue( $this->form_id, $this->source ),
			]
		);
		$this->assertSame( 303, $exit->status );
		$this->assertCount( 0, $this->entries() );

		$html = $this->visit( $this->key( $exit ) );
		$this->assertStringContainsString( 'Please submit again.', $html );
		$this->assertStringContainsString( 'Do not lose me', $html );
		$this->assertStringContainsString( 'value="ada@example.com"', $html );
		$this->assertStringNotContainsString( 'swiftforms-errors-summary" tabindex="-1" role="alert"', $html );

		preg_match( '/name="_sf_token" value="([^"]+)"/', $html, $m );
		$this->assertNotSame( $old, $m[1] );
		$this->assertSame( 'valid', Render_Token::verify( $m[1], $this->form_id, $this->source, [ $this->schema()->hash ] )['state'], 'The re-rendered form has a fresh, valid token.' );
	}

	/**
	 * A redirect confirmation goes straight to the target: no result key, no stored result.
	 *
	 * @return void
	 */
	public function test_redirect_confirmation() {
		global $wpdb;
		$this->page(
			[
				'confirmationType' => 'redirect',
				'redirectUrl'      => 'https://example.com/thanks?n={field:name}',
			]
		);
		$exit = $this->post( $this->good( [ 'name' => 'Ada & Bob' ] ) );

		$this->assertSame( 303, $exit->status );
		$this->assertSame( 'https://example.com/thanks?n=Ada%20%26%20Bob', $exit->location );
		$this->assertCount( 1, $this->entries() );
		$stored = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_" . Nojs_Handler::TRANSIENT_PREFIX . "%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '0', $stored );
	}

	/**
	 * A redirect confirmation that is refused shows the message after the PRG.
	 *
	 * @return void
	 */
	public function test_bad_redirect_falls_back_to_the_message() {
		$this->page(
			[
				'confirmationType' => 'redirect',
				'redirectUrl'      => 'https://{field:name}/x',
			]
		);
		$exit = $this->post( $this->good( [ 'name' => 'evil.com' ] ) );
		$this->assertSame( get_permalink( $this->page ), remove_query_arg( 'sf_result', $exit->location ) );
		$this->assertStringContainsString( 'swiftforms-confirmation', $this->visit( $this->key( $exit ) ) );
	}

	/**
	 * Spam gets the same redirect shape and the same confirmation.
	 *
	 * @return void
	 */
	public function test_spam_looks_like_success() {
		$this->page();
		$clean = $this->post( $this->good() );
		$spam  = $this->post( $this->good( [ 'name' => 'Bot' ] ), [ '_sf_hp' => 'http://spam' ] );

		$this->assertSame( $clean->status, $spam->status );
		$this->assertSame( remove_query_arg( 'sf_result', $clean->location ), remove_query_arg( 'sf_result', $spam->location ) );
		$clean_html = $this->visit( $this->key( $clean ) );
		$spam_html  = $this->visit( $this->key( $spam ) );
		$this->assertStringContainsString( 'swiftforms-confirmation', $spam_html );
		$this->assertSame( $this->confirmation_of( $clean_html ), $this->confirmation_of( $spam_html ) );
		$this->assertSame( [ 'new', 'spam' ], array_column( $this->entries(), 'status' ) );
		$this->assertCount( 1, $this->notified );
	}

	/**
	 * The confirmation block of a page.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	private function confirmation_of( $html ) {
		preg_match( '#<div class="swiftforms-confirmation".*?</div>#s', $html, $m );
		return $m[0] ?? '';
	}

	/**
	 * A time-trap failure without JavaScript: posted too fast.
	 *
	 * @return void
	 */
	public function test_too_fast_is_spam_but_looks_fine() {
		$this->page();
		$issued = Time_Trap::issue( $this->form_id, $this->source );
		Clock::freeze( 1900000001 );
		// post() freezes at +5 seconds, so build the request by hand for a 1 second gap.
		$permalink = get_permalink( $this->page );
		$this->go_to( $permalink );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$_POST                     = wp_slash( array_merge( $this->controls( [ '_sf_t' => $issued ] ), [ 'sf' => $this->good() ] ) );
		try {
			Nojs_Handler::maybe_handle();
			$this->fail( 'No redirect.' );
		} catch ( SF_Test_Redirect $e ) {
			$this->assertSame( 303, $e->status );
		}
		$this->assertSame( [ 'spam' ], array_column( $this->entries(), 'status' ) );
	}

	/**
	 * A browser-measured time cannot be posted by a form without JavaScript: it is spam.
	 *
	 * @return void
	 */
	public function test_js_time_format_is_refused_without_js() {
		$this->page();
		$this->post( $this->good(), [ '_sf_t' => 'js.99999' ] );
		$this->assertSame( [ 'spam' ], array_column( $this->entries(), 'status' ) );
	}

	/**
	 * Logged in with a valid nonce: the submitter is the user. Invalid or missing nonce: a guest.
	 *
	 * @return void
	 */
	public function test_logged_in_nonce() {
		$this->page();
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$this->post( $this->good( [ 'name' => 'With nonce' ] ), [ '_wpnonce' => wp_create_nonce( 'wp_rest' ) ] );
		$this->assertSame( $user_id, $this->notified[0]->user_id() );

		wp_set_current_user( $user_id );
		$this->post( $this->good( [ 'name' => 'Bad nonce' ] ), [ '_wpnonce' => 'deadbeef00' ] );
		$this->assertNull( $this->notified[1]->user, 'An invalid nonce is a guest submission.' );
		$this->assertSame( 0, $this->notified[1]->user_id() );

		wp_set_current_user( $user_id );
		$this->post( $this->good( [ 'name' => 'No nonce' ] ), [ '_wpnonce' => null ] );
		$this->assertNull( $this->notified[2]->user );

		wp_set_current_user( $user_id );
		$this->post( $this->good( [ 'name' => 'Wrong action' ] ), [ '_wpnonce' => wp_create_nonce( 'something_else' ) ] );
		$this->assertNull( $this->notified[3]->user, 'A nonce for another action is no good.' );

		$users = array_column( $this->entries(), 'user_id' );
		$this->assertSame( [ (string) $user_id, '0', '0', '0' ], $users );
	}

	/**
	 * The gate refuses before anything is stored: closed, blocked, rate limited. Plain pages, no result key.
	 *
	 * @return void
	 */
	public function test_gate_refusals() {
		global $wpdb;
		$this->page();

		$exit = $this->post( $this->good(), [ '_sf_form' => wp_generate_uuid4() ] );
		$this->assertInstanceOf( SF_Test_Die::class, $exit );
		$this->assertSame( 403, $exit->status );
		$this->assertSame( 'This form is not accepting submissions.', $exit->getMessage() );

		$exit = $this->post( $this->good(), [ '_sf_source' => 'post:999999' ] );
		$this->assertSame( 403, $exit->status );

		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 1;
			}
		);
		$exit = $this->post( $this->good() );
		$this->assertInstanceOf( SF_Test_Die::class, $exit );
		$this->assertSame( 429, $exit->status, 'Over the coarse throttle.' );

		$stored = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_" . Nojs_Handler::TRANSIENT_PREFIX . "%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '0', $stored, 'Refusals write nothing.' );
		$this->assertCount( 0, $this->entries() );
	}

	/**
	 * The per-form limiter shows a notice after the PRG.
	 *
	 * @return void
	 */
	public function test_per_form_limit_notice() {
		$this->page();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 1 ] ] );
		$this->post( $this->good( [ 'name' => 'One' ] ) );
		$exit = $this->post( $this->good( [ 'name' => 'Two' ] ) );
		$this->assertInstanceOf( SF_Test_Redirect::class, $exit );
		$html = $this->visit( $this->key( $exit ) );
		$this->assertStringContainsString( 'Too many submissions. Please wait a minute and try again.', $html );
		$this->assertStringContainsString( 'value="Two"', $html );
	}

	/**
	 * The handler does nothing for requests that are not form posts, or not page views.
	 *
	 * @return void
	 */
	public function test_ignores_other_requests() {
		$this->page();
		$this->go_to( get_permalink( $this->page ) );

		// No _sf_form: nothing.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [ 'comment' => 'hi' ];
		Nojs_Handler::maybe_handle();

		// A GET with _sf_form in the query is not a post.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_GET['_sf_form']          = $this->form_id;
		Nojs_Handler::maybe_handle();

		// Admin, AJAX.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [ '_sf_form' => $this->form_id ];
		set_current_screen( 'dashboard' );
		Nojs_Handler::maybe_handle();
		set_current_screen( 'front' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		Nojs_Handler::maybe_handle();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertCount( 0, $this->entries() );
		$this->assertFalse( Nojs_Handler::$uncacheable );
	}

	/**
	 * A plain GET (no key) touches nothing.
	 *
	 * @return void
	 */
	public function test_plain_get_is_untouched() {
		$this->page();
		$this->go_to( get_permalink( $this->page ) );
		Nojs_Handler::maybe_handle();
		$this->assertFalse( Nojs_Handler::$uncacheable );
		$this->assertSame( [], Nojs_Handler::$headers );
	}

	/**
	 * The result goes to the copy of the form that was submitted.
	 *
	 * @return void
	 */
	public function test_instance() {
		$this->page();
		$exit = $this->post( $this->good(), [ '_sf_i' => '2' ] );
		$key  = $this->key( $exit );
		$data = get_transient( Nojs_Handler::TRANSIENT_PREFIX . $key );
		$this->assertSame( 2, $data['instance'] );

		$this->visit( $key );
		$this->assertTrue( Form_State::get( $this->form_id, 2 )['success'] );
		$this->assertFalse( Form_State::get( $this->form_id, 1 )['success'] );

		foreach ( [ '0', '-1', 'x', '1000', '1.5', [ '2' ] ] as $bad ) {
			$exit = $this->post( $this->good( [ 'message' => 'm' . wp_json_encode( $bad ) ] ), [ '_sf_i' => $bad ] );
			$data = get_transient( Nojs_Handler::TRANSIENT_PREFIX . $this->key( $exit ) );
			$this->assertSame( 1, $data['instance'], wp_json_encode( $bad ) );
		}
	}

	/**
	 * The redirect goes to this site, with the existing query kept and an old key replaced.
	 *
	 * @return void
	 */
	public function test_redirect_url_is_this_site() {
		$this->page();
		$exit = $this->post( $this->good(), [], '/some/path/?utm=1&sf_result=old' );
		$home = wp_parse_url( home_url() );
		$loc  = wp_parse_url( $exit->location );
		$this->assertSame( $home['host'], $loc['host'] );
		parse_str( $loc['query'], $query );
		$this->assertSame( '1', $query['utm'] );
		$this->assertNotSame( 'old', $query['sf_result'] );

		// A hostile request URI cannot move the redirect off the site.
		foreach ( [ '//evil.com/x', 'http://evil.com/', "/ok\r\nLocation: http://evil.com", '' ] as $uri ) {
			$exit = $this->post( $this->good( [ 'message' => 'm' . md5( $uri ) ] ), [], $uri );
			$this->assertSame( $home['host'], wp_parse_url( $exit->location, PHP_URL_HOST ), $uri );
			$this->assertStringNotContainsString( "\n", $exit->location );
		}
	}

	/**
	 * The stored result never holds anybody else's data: the one transient has this submission's state only.
	 *
	 * @return void
	 */
	public function test_result_holds_only_this_visitors_state() {
		$this->page();
		$this->post(
			$this->good(
				[
					'name'  => 'First',
					'email' => 'first@example.com',
				]
			)
		);
		$exit = $this->post(
			[
				'name'  => 'Second',
				'email' => 'bad',
			]
		);
		$data = get_transient( Nojs_Handler::TRANSIENT_PREFIX . $this->key( $exit ) );
		$this->assertStringNotContainsString( 'First', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'first@example.com', wp_json_encode( $data ) );
		$this->assertSame( 'Second', $data['state']['values']['name'] );
	}
}
