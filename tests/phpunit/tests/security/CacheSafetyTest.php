<?php
/**
 * Tests for cache safety of the rendered form (control fields, nonce, user data).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Form_Renderer;
use SwiftForms\Render_Token;
use SwiftForms\Time_Trap;

/**
 * Class CacheSafetyTest
 *
 * @group submission
 * @group security
 */
class CacheSafetyTest extends SF_Submission_Test_Case {

	/**
	 * Page ID.
	 *
	 * @var int
	 */
	private $page = 0;

	/**
	 * Frozen time, one page.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::freeze( 1900000000 );
		$this->page = $this->make_form();
	}

	/**
	 * Render the form page as the current user.
	 *
	 * @return string
	 */
	private function render() {
		Form_Renderer::reset();
		$this->go_to( get_permalink( $this->page ) );
		return $this->render_current();
	}

	/**
	 * The hidden controls of a render, name => value.
	 *
	 * @param string $html Markup.
	 * @return array<string, string>
	 */
	private function controls_of( $html ) {
		preg_match_all( '/<input type="hidden" name="(_[a-z_]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER );
		$controls = [];
		foreach ( $m as $match ) {
			$controls[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES );
		}
		return $controls;
	}

	/**
	 * The control fields: token, signed time, instance; no nonce for a guest.
	 *
	 * @return void
	 */
	public function test_control_fields() {
		$html     = $this->render();
		$controls = $this->controls_of( $html );

		$this->assertSame( [ '_sf_form', '_sf_source', '_sf_v', '_sf_token', '_sf_t', '_sf_i' ], array_keys( $controls ) );
		$this->assertSame( $this->form_id, $controls['_sf_form'] );
		$this->assertSame( $this->source, $controls['_sf_source'] );
		$this->assertSame( '1', $controls['_sf_i'] );
		$this->assertSame( 'valid', Render_Token::verify( $controls['_sf_token'], $this->form_id, $this->source, [ $this->schema()->hash ] )['state'] );
		$this->assertSame( $controls['_sf_t'], Time_Trap::issue( $this->form_id, $this->source ) );
		$this->assertStringNotContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'name="_sf_hp"', $html );
	}

	/**
	 * Two guests' renders are byte-identical (a page cache may serve either to anybody).
	 *
	 * @return void
	 */
	public function test_guest_renders_are_identical() {
		$first = $this->render();

		$_SERVER['REMOTE_ADDR']      = '198.51.100.77';
		$_SERVER['HTTP_USER_AGENT']  = 'Another browser';
		$_COOKIE['comment_author_x'] = 'Someone Else';
		$second                      = $this->render();
		unset( $_COOKIE['comment_author_x'] );

		$this->assertSame( $first, $second );
		$this->assertStringNotContainsString( '198.51.100.77', $second );
	}

	/**
	 * A logged-in user's render differs from a guest's by the nonce only, and carries nothing else about the user.
	 *
	 * @return void
	 */
	public function test_logged_in_render_differs_by_the_nonce_only() {
		$guest = $this->render();

		$user_a = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'alice@example.com',
				'display_name' => 'Alice Example',
			]
		);
		wp_set_current_user( $user_a );
		$as_a   = $this->render();
		$user_b = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => 'bob@example.com',
				'display_name' => 'Bob Example',
			]
		);
		wp_set_current_user( $user_b );
		$as_b = $this->render();

		$nonce_input = '/<input type="hidden" name="_wpnonce" value="[0-9a-f]+">/';
		$this->assertSame( 1, preg_match( $nonce_input, $as_a ) );
		$this->assertSame( 1, preg_match( $nonce_input, $as_b ) );
		$this->assertSame( $guest, preg_replace( $nonce_input, '', $as_a ) );
		$this->assertSame( $guest, preg_replace( $nonce_input, '', $as_b ) );
		foreach ( [ 'alice@example.com', 'Alice Example', 'bob@example.com', 'Bob Example' ] as $private ) {
			$this->assertStringNotContainsString( $private, $as_a . $as_b );
		}

		$controls = $this->controls_of( $as_a );
		wp_set_current_user( $user_a );
		$this->assertNotFalse( wp_verify_nonce( $controls['_wpnonce'], 'wp_rest' ) );
		$this->assertArrayNotHasKey( '_wpnonce', $this->controls_of( $guest ) );
	}

	/**
	 * The token changes when the form's fields change and not otherwise; the signed time follows the clock.
	 *
	 * @return void
	 */
	public function test_token_follows_the_schema_and_time() {
		$before = $this->controls_of( $this->render() );

		Clock::freeze( 1900000000 + 30 );
		$later = $this->controls_of( $this->render() );
		$this->assertSame( $before['_sf_token'], $later['_sf_token'], 'Same hour, same schema, same token.' );
		$this->assertNotSame( $before['_sf_t'], $later['_sf_t'] );

		Clock::freeze( 1900000000 + 2 * HOUR_IN_SECONDS );
		$hours = $this->controls_of( $this->render() );
		$this->assertNotSame( $before['_sf_token'], $hours['_sf_token'] );
		$this->assertSame( 'valid', Render_Token::verify( $hours['_sf_token'], $this->form_id, $this->source, [ $this->schema()->hash ] )['state'] );
	}

	/**
	 * A second copy of the form on the page counts as instance 2.
	 *
	 * @return void
	 */
	public function test_second_instance() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$block = $this->make_post( $this->form_markup( wp_generate_uuid4(), $this->default_inner() ), [ 'post_type' => 'wp_block' ] );
		$page  = $this->make_post( '<!-- wp:block {"ref":' . $block . '} --><!-- /wp:block --><!-- wp:block {"ref":' . $block . '} --><!-- /wp:block -->' );
		wp_set_current_user( 0 );

		Form_Renderer::reset();
		$this->go_to( get_permalink( $page ) );
		$html = $this->render_current();
		preg_match_all( '/name="_sf_i" value="(\d+)"/', $html, $m );
		$this->assertSame( [ '1', '2' ], $m[1] );
		preg_match_all( '/name="_sf_source" value="([^"]+)"/', $html, $s );
		$this->assertSame( [ 'post:' . $block, 'post:' . $block ], $s[1] );
	}
}
