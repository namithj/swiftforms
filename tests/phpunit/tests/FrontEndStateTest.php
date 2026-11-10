<?php
/**
 * Tests for what the form hands the view module: context, directives and Interactivity state.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Form_Renderer;
use SwiftForms\Validator;

/**
 * Class FrontEndStateTest
 *
 * @group frontend
 */
class FrontEndStateTest extends SF_Submission_Test_Case {

	/**
	 * Page ID.
	 *
	 * @var int
	 */
	private $page = 0;

	/**
	 * Frozen time, one page, an empty Interactivity store.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::freeze( 1900000000 );
		$this->reset_store();
		$this->page = $this->make_form();
	}

	/**
	 * Leave no state behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_store();
		parent::tear_down();
	}

	/**
	 * Forget the "swiftforms" store of the Interactivity API (it lives for the process).
	 *
	 * @return void
	 */
	private function reset_store() {
		$api  = wp_interactivity();
		$prop = new ReflectionProperty( $api, 'state_data' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$data = $prop->getValue( $api );
		unset( $data['swiftforms'] );
		$prop->setValue( $api, $data );
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
	 * The form's context object.
	 *
	 * @param string $html Markup.
	 * @return array<string, mixed>
	 */
	private function context_of( $html ) {
		$this->assertSame( 1, preg_match( '/data-wp-context="([^"]*)"/', $html, $m ) );
		return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}

	/**
	 * The directives, additive context keys and the shared state for a guest.
	 *
	 * @return void
	 */
	public function test_guest_form_and_state() {
		$html = $this->render();

		foreach ( [ 'data-wp-init="callbacks.init"', 'data-wp-on--submit="actions.submit"', 'data-wp-on--input="actions.input"', 'data-wp-on--focusout="actions.focusout"', 'data-wp-on-document--visibilitychange="callbacks.visible"' ] as $directive ) {
			$this->assertStringContainsString( $directive, $html );
		}
		$this->assertSame(
			[
				'formId'   => $this->form_id,
				'source'   => $this->source,
				'instance' => 1,
				'ajax'     => true,
				'issued'   => intdiv( 1900000000, HOUR_IN_SECONDS ),
			],
			$this->context_of( $html )
		);

		$state = wp_interactivity_state( 'swiftforms' );
		$this->assertSame( rest_url( 'swiftforms/v1/submit' ), $state['submitUrl'] );
		$this->assertSame( rest_url( 'swiftforms/v1/token' ), $state['tokenUrl'] );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $state['ajaxUrl'] );
		$this->assertSame( Validator::messages(), $state['messages'] );
		$this->assertArrayHasKey( 'network', $state['i18n'] );
		$this->assertArrayNotHasKey( 'nonce', $state, 'A guest gets no nonce.' );
	}

	/**
	 * A logged-in user's nonce is in the state, never in the context, and the form markup stays user-independent apart from the existing hidden input.
	 *
	 * @return void
	 */
	public function test_logged_in_nonce_only_in_state() {
		$guest = $this->render();
		$this->reset_store();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$html  = $this->render();
		$state = wp_interactivity_state( 'swiftforms' );

		$this->assertSame( wp_create_nonce( 'wp_rest' ), $state['nonce'] );
		$this->assertSame( $this->context_of( $guest ), $this->context_of( $html ) );
		$this->assertStringNotContainsString( 'nonce', html_entity_decode( $this->context_of_raw( $html ), ENT_QUOTES ) );
		$this->assertSame( $guest, preg_replace( '/<input type="hidden" name="_wpnonce" value="[0-9a-f]+">/', '', $html ) );
	}

	/**
	 * The raw data-wp-context attribute.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function context_of_raw( $html ) {
		preg_match( '/data-wp-context="([^"]*)"/', $html, $m );
		return $m[1] ?? '';
	}

	/**
	 * Two guests get the same form bytes and the same state.
	 *
	 * @return void
	 */
	public function test_guest_bytes_and_state_are_identical() {
		$first       = $this->render();
		$first_state = wp_interactivity_state( 'swiftforms' );
		$this->reset_store();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$second                 = $this->render();

		$this->assertSame( $first, $second );
		$this->assertSame( $first_state, wp_interactivity_state( 'swiftforms' ) );
	}

	/**
	 * With ajax off the form posts natively: no behaviour directives, no state, context says so.
	 *
	 * @return void
	 */
	public function test_ajax_off_markup() {
		$this->page = $this->make_form( null, [ 'ajax' => false ] );
		$html       = $this->render();

		$this->assertFalse( $this->context_of( $html )['ajax'] );
		$this->assertStringNotContainsString( 'data-wp-on--submit', $html );
		$this->assertStringNotContainsString( 'data-wp-init', $html );
		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertArrayNotHasKey( 'restUrl', wp_interactivity_state( 'swiftforms' ) );
	}

	/**
	 * A second form on the page adds its own context and does not change the shared state.
	 *
	 * @return void
	 */
	public function test_second_form_has_its_own_context() {
		$one       = $this->render();
		$one_state = wp_interactivity_state( 'swiftforms' );

		Form_Renderer::reset();
		$this->go_to( get_permalink( $this->page ) );
		$post  = get_queried_object();
		$twice = apply_filters( 'the_content', $post->post_content . $post->post_content );

		$this->assertSame( 2, substr_count( $twice, 'data-wp-interactive="swiftforms"' ) );
		preg_match_all( '/data-wp-context="([^"]*)"/', $twice, $m );
		$instances = array_map(
			static function ( $raw ) {
				return json_decode( html_entity_decode( $raw, ENT_QUOTES ), true )['instance'];
			},
			$m[1]
		);
		$this->assertSame( [ 1, 2 ], $instances );
		$this->assertSame( $one_state, wp_interactivity_state( 'swiftforms' ) );
		$this->assertNotSame( '', $one );
	}

	/**
	 * Checkbox groups hand their limits to the client; the markup has the data attributes only when set.
	 *
	 * @return void
	 */
	public function test_checkbox_limits_are_data_attributes() {
		$opts       = [
			[
				'label' => 'A',
				'value' => 'a',
			],
			[
				'label' => 'B',
				'value' => 'b',
			],
		];
		$limits     = $this->field_markup(
			'checkboxes',
			[
				'name'       => 'c',
				'options'    => $opts,
				'minChoices' => 1,
				'maxChoices' => 2,
			]
		);
		$plain      = $this->field_markup(
			'checkboxes',
			[
				'name'    => 'd',
				'options' => $opts,
			]
		);
		$this->page = $this->make_form( $limits . $plain );
		$html       = $this->render();

		$this->assertSame( 1, substr_count( $html, 'data-sf-min-choices="1"' ) );
		$this->assertSame( 1, substr_count( $html, 'data-sf-max-choices="2"' ) );
	}
}
