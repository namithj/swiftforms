<?php
/**
 * Tests for SwiftForms\Form_Renderer and the form-level markup.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-render-test-case.php';

use SwiftForms\Form_Renderer;
use SwiftForms\Form_State;
use SwiftForms\Source_Stack;

/**
 * Class FormRendererTest
 *
 * @group blocks
 */
class FormRendererTest extends SF_Render_Test_Case {

	/**
	 * The form element, its control fields and the data attributes.
	 *
	 * @return void
	 */
	public function test_form_markup_and_control_fields() {
		$post_id = 0;
		$html    = $this->render_page(
			$this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ),
			$post_id
		);

		$this->assertSame( 1, preg_match( '#^<form class="swiftforms-form swiftforms-form--labels-top wp-block-swiftforms-form" method="post" data-wp-interactive="swiftforms" data-wp-context="([^"]*)" data-wp-init="callbacks.init" data-wp-on--submit="actions.submit" data-wp-on--input="actions.input" data-wp-on--focusout="actions.focusout" data-wp-on-document--visibilitychange="callbacks.visible">#', $html, $m ) );
		$this->assertSame(
			[
				'formId'   => self::ID_A,
				'source'   => 'post:' . $post_id,
				'instance' => 1,
				'ajax'     => true,
				'issued'   => intdiv( \SwiftForms\Clock::now(), HOUR_IN_SECONDS ),
			],
			json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )
		);
		$this->assertStringContainsString( '<div class="swiftforms-errors-summary" tabindex="-1" hidden></div>', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_sf_form" value="' . self::ID_A . '">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_sf_source" value="post:' . $post_id . '">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_sf_v" value="' . SWIFTFORMS_VERSION . '">', $html );
		$this->assertStringContainsString( '<div class="swiftforms-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="_sf_hp" value="" tabindex="-1" autocomplete="off"></label></div></form>', $html );
		$this->assertStringNotContainsString( 'name="sf[a]" value', $html );
		$this->assertSame( 0, Form_Renderer::depth(), 'The form stack is empty after the render.' );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * The default form a fresh insert starts from (the P2 insert template) renders its five parts.
	 *
	 * @return void
	 */
	public function test_default_template_form() {
		$inner = '<!-- wp:swiftforms/field-text {"name":"name","label":"Name","autocomplete":"name"} /-->'
			. '<!-- wp:swiftforms/field-email {"name":"email","label":"Email","required":true,"autocomplete":"email"} /-->'
			. '<!-- wp:swiftforms/field-textarea {"name":"message","label":"Message","required":true} /-->'
			. '<!-- wp:swiftforms/submit /-->'
			. '<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>Thanks! We\'ll be in touch.</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->';
		$html  = $this->render_form( $inner );

		$this->assertStringContainsString( 'name="sf[name]"', $html );
		$this->assertStringContainsString( 'name="sf[email]"', $html );
		$this->assertStringContainsString( 'name="sf[message]"', $html );
		$this->assertStringContainsString( '<button type="submit"', $html );
		$this->assertStringNotContainsString( 'Thanks!', $html, 'The confirmation does not show until the form succeeds.' );
		$this->assertStringNotContainsString( 'swiftforms-confirmation', $html );
	}

	/**
	 * After a successful submission the confirmation blocks replace the fields.
	 *
	 * @return void
	 */
	public function test_success_shows_the_confirmation_blocks() {
		Form_State::set( self::ID_A, [ 'success' => true ] );
		$inner = $this->field_markup( 'text', [ 'name' => 'a' ] )
			. '<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>All <em>done</em>.</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->';
		$html  = $this->render_form( $inner );

		// Newer cores add a wp-block-paragraph class to the paragraph.
		$this->assertMatchesRegularExpression( '#<div class="swiftforms-confirmation" role="status"><p( class="wp-block-paragraph")?>All <em>done</em>\.</p></div>#', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'sf[a]', $html );
		$this->assertStringContainsString( 'swiftforms-form--submitted', $html );
	}

	/**
	 * No confirmation block: the site's default success message.
	 *
	 * @return void
	 */
	public function test_success_default_message() {
		Form_State::set( self::ID_A, [ 'success' => true ] );
		$html = $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ) );
		$this->assertStringContainsString( '<div class="swiftforms-confirmation" role="status"><p>Thank you. Your message has been sent.</p></div>', $html );
	}

	/**
	 * Prepared HTML from the submission handler wins over the confirmation blocks.
	 *
	 * @return void
	 */
	public function test_success_prepared_html() {
		Form_State::set(
			self::ID_A,
			[
				'success'           => true,
				'confirmation_html' => '<p>Hello Ada</p>',
			]
		);
		$inner = '<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>Block text</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->';
		$html  = $this->render_form( $inner );
		$this->assertStringContainsString( '<div class="swiftforms-confirmation" role="status"><p>Hello Ada</p></div>', $html );
		$this->assertStringNotContainsString( 'Block text', $html );
	}

	/**
	 * A notice above the fields.
	 *
	 * @return void
	 */
	public function test_notice() {
		Form_State::set(
			self::ID_A,
			[
				'notice'      => 'Please submit again <now>.',
				'notice_type' => 'info',
			]
		);
		$html = $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ) );
		$this->assertStringContainsString( '<div class="swiftforms-notice swiftforms-notice--info" role="status">Please submit again &lt;now&gt;.</div>', $html );
	}

	/**
	 * The same form twice on a page (a synced pattern used twice): one effective ID, instances 1 and 2, unique element IDs.
	 *
	 * @return void
	 */
	public function test_same_form_twice_has_unique_ids() {
		$pattern = $this->make_post(
			$this->form_markup(
				self::ID_B,
				$this->field_markup( 'text', [ 'name' => 'who' ] ) . $this->field_markup(
					'radio',
					[
						'name'    => 'pick',
						'options' => [
							[
								'value' => 'a',
								'label' => 'A',
							],
						],
					]
				)
			),
			[
				'post_type'  => 'wp_block',
				'post_title' => 'Shared form',
			]
		);
		$html    = $this->render_page( '<!-- wp:block {"ref":' . $pattern . '} /--><!-- wp:block {"ref":' . $pattern . '} /-->' );

		preg_match_all( '/\bid="([^"]+)"/', $html, $ids );
		$this->assertNotEmpty( $ids[1] );
		$this->assertSame( $ids[1], array_values( array_unique( $ids[1] ) ), 'Every element ID is unique on the page.' );
		$this->assertStringContainsString( 'id="sf-22222222-who-1"', $html );
		$this->assertStringContainsString( 'id="sf-22222222-who-2"', $html );
		$this->assertStringContainsString( 'id="sf-22222222-pick-2-1"', $html );
		$this->assertStringContainsString( '&quot;instance&quot;:2', $html );
		$this->assertSame( 2, substr_count( $html, 'name="_sf_form" value="' . self::ID_B . '"' ) );
	}

	/**
	 * Two forms in one post render with their own IDs and field names.
	 *
	 * @return void
	 */
	public function test_two_forms_in_one_post() {
		$html = $this->render_page(
			$this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'one' ] ) )
			. $this->form_markup( self::ID_B, $this->field_markup( 'text', [ 'name' => 'two' ] ) )
		);

		$this->assertStringContainsString( 'id="sf-11111111-one-1"', $html );
		$this->assertStringContainsString( 'id="sf-22222222-two-1"', $html );
		$this->assertStringNotContainsString( 'sf-11111111-two', $html );
		$this->assertSame( 0, Form_Renderer::depth() );
	}

	/**
	 * A copy of a form (the same stored formId in another post) renders with the effective, derived ID.
	 *
	 * @return void
	 */
	public function test_copied_form_uses_the_effective_id() {
		$markup = $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) );
		$this->make_post( $markup, [ 'post_date' => '2020-01-01 00:00:00' ] );
		$html = $this->render_page( $markup );

		$this->assertSame( 1, preg_match( '/name="_sf_form" value="([0-9a-f-]{36})"/', $html, $m ) );
		$this->assertNotSame( self::ID_A, $m[1], 'The later copy gets its own effective formId.' );
		$this->assertStringContainsString( 'id="sf-' . substr( $m[1], 0, 8 ) . '-a-1"', $html );
	}

	/**
	 * A form with no stored formId gets the derived "missing" ID, the same on every render.
	 *
	 * @return void
	 */
	public function test_form_without_form_id() {
		$markup = $this->form_markup( '', $this->field_markup( 'text', [ 'name' => 'a' ] ) );
		$first  = $this->render_page( $markup );
		preg_match( '/name="_sf_form" value="([0-9a-f-]{36})"/', $first, $m );
		$this->assertNotEmpty( $m );
		$this->assertTrue( \SwiftForms\Uuid::is_valid( $m[1] ) );
	}

	/**
	 * The public filter sees and may replace the HTML.
	 *
	 * @return void
	 */
	public function test_render_form_filter() {
		$seen = [];
		add_filter(
			'swiftforms_render_form',
			static function ( $html, $form_id, $instance, $attributes ) use ( &$seen ) {
				$seen = [ $form_id, $instance, $attributes['labelPosition'] ];
				return '<!--before-->' . $html;
			},
			10,
			4
		);

		$html = $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ), [ 'labelPosition' => 'inline' ] );
		$this->assertStringStartsWith( '<!--before--><form', $html );
		$this->assertSame( [ self::ID_A, 1, 'inline' ], $seen );

		add_filter( 'swiftforms_render_form', '__return_null', 20 );
		$this->assertStringStartsWith( '<form', $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ) ), 'A non-string result is ignored: the unfiltered form prints.' );
	}

	/**
	 * A draft preview renders like a published form.
	 *
	 * @return void
	 */
	public function test_draft_preview_renders() {
		$draft = $this->make_post( $this->form_markup( self::ID_B, $this->field_markup( 'text', [ 'name' => 'a' ] ) ), [ 'post_status' => 'draft' ] );
		$this->go_to( get_preview_post_link( $draft ) );
		$html = $this->render_current();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="sf[a]"', $html );
		$this->assertSame( 0, Form_Renderer::depth() );
	}

	/**
	 * The ajax attribute is carried to the view module.
	 *
	 * @return void
	 */
	public function test_ajax_flag_in_context() {
		$html = $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ), [ 'ajax' => false ] );
		$this->assertStringContainsString( '&quot;ajax&quot;:false', $html );
	}

	/**
	 * Supports (colours, anchor, class) reach the form element.
	 *
	 * @return void
	 */
	public function test_block_supports_on_the_form_element() {
		$html = $this->render_form(
			$this->field_markup( 'text', [ 'name' => 'a' ] ),
			[
				'anchor'          => 'contact',
				'className'       => 'my-form',
				'backgroundColor' => 'vivid-red',
			]
		);
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) ) {
			// WordPress 6.8 does not print the anchor of a dynamic block from the block comment.
			$this->assertStringContainsString( 'id="contact"', $html );
		}
		$this->assertStringContainsString( 'my-form', $html );
		$this->assertStringContainsString( 'has-vivid-red-background-color', $html );
	}

	/**
	 * A form rendered by a direct block call (no pre_render_block) still produces a form element.
	 *
	 * @return void
	 */
	public function test_direct_block_render() {
		$parsed = parse_blocks( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ) )[0];
		$block  = new WP_Block( $parsed );
		$html   = $block->render();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'sf[a]', $html );
		$this->assertSame( 0, Form_Renderer::depth() );
	}
}
