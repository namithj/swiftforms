<?php
/**
 * Tests for SwiftForms\Field_Renderer: markup per input family, label positions,
 * required indicators, form state, escaping, ids.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-render-test-case.php';

use SwiftForms\Form_State;

/**
 * Class FieldRendererTest
 *
 * @group blocks
 */
class FieldRendererTest extends SF_Render_Test_Case {

	/**
	 * Prefix of the element ids of the first form on the page.
	 */
	const P = 'sf-11111111-';

	/**
	 * Text input with every optional attribute.
	 *
	 * @return void
	 */
	public function test_text_field_golden() {
		$html = $this->render_field(
			'text',
			[
				'name'           => 'company',
				'label'          => 'Company',
				'help'           => 'As on the invoice',
				'placeholder'    => 'Acme',
				'required'       => true,
				'defaultValue'   => 'Acme Ltd',
				'minLength'      => 2,
				'maxLength'      => 40,
				'pattern'        => '^[A-Z]',
				'patternMessage' => 'Start with a capital',
				'autocomplete'   => 'organization',
				'width'          => '50',
			]
		);

		$this->assertSame(
			'<div class="swiftforms-field swiftforms-field--text swiftforms-field--required swiftforms-w-50 wp-block-swiftforms-field-text">'
			. '<label class="swiftforms-label" for="' . self::P . 'company-1">Company<span class="swiftforms-required" aria-hidden="true">*</span><span class="swiftforms-sr-only"> (required)</span></label>'
			. '<input class="swiftforms-input" type="text" id="' . self::P . 'company-1" name="sf[company]" value="Acme Ltd" placeholder="Acme" minlength="2" maxlength="40" pattern="^[A-Z]" title="Start with a capital" autocomplete="organization" required aria-required="true" aria-describedby="' . self::P . 'company-1-help ' . self::P . 'company-1-error">'
			. '<p class="swiftforms-help" id="' . self::P . 'company-1-help">As on the invoice</p>'
			. '<p class="swiftforms-error" id="' . self::P . 'company-1-error" hidden></p>'
			. '</div>',
			$html
		);
	}

	/**
	 * The native input type of every input-family field, and the type-specific attributes.
	 *
	 * @return void
	 */
	public function test_input_types_and_limits() {
		foreach ( [ 'email', 'url', 'tel', 'number', 'date' ] as $type ) {
			$this->assertStringContainsString( 'type="' . $type . '"', $this->render_field( $type, [ 'name' => 'f' ] ), $type );
		}

		$number = $this->render_field(
			'number',
			[
				'name' => 'n',
				'min'  => '1',
				'max'  => '9.5',
				'step' => '0.5',
			]
		);
		$this->assertStringContainsString( ' min="1" max="9.5" step="0.5"', $number );

		$date = $this->render_field(
			'date',
			[
				'name' => 'd',
				'min'  => '2026-01-01',
				'max'  => '2026-12-31',
			]
		);
		$this->assertStringContainsString( ' min="2026-01-01" max="2026-12-31"', $date );
		$this->assertStringNotContainsString( 'step=', $date );
	}

	/**
	 * Textarea.
	 *
	 * @return void
	 */
	public function test_textarea_golden() {
		$html = $this->render_field(
			'textarea',
			[
				'name'         => 'message',
				'label'        => 'Message',
				'rows'         => 6,
				'maxLength'    => 500,
				'defaultValue' => "Line 1\nLine 2",
			]
		);

		$this->assertSame(
			'<div class="swiftforms-field swiftforms-field--textarea swiftforms-w-100 wp-block-swiftforms-field-textarea">'
			. '<label class="swiftforms-label" for="' . self::P . 'message-1">Message</label>'
			. '<textarea class="swiftforms-textarea" id="' . self::P . 'message-1" name="sf[message]" rows="6" maxlength="500" aria-describedby="' . self::P . 'message-1-error">Line 1' . "\n" . 'Line 2</textarea>'
			. '<p class="swiftforms-error" id="' . self::P . 'message-1-error" hidden></p>'
			. '</div>',
			$html
		);
	}

	/**
	 * Select: placeholder option, values with commas and pipes, several answers.
	 *
	 * @return void
	 */
	public function test_select_golden_and_multiple() {
		$options = [
			[
				'value' => 'a,b|c',
				'label' => 'A, B | C',
			],
			[
				'value' => 'plain',
				'label' => 'Plain',
			],
		];

		$single = $this->render_field(
			'select',
			[
				'name'              => 'pick',
				'label'             => 'Pick',
				'placeholderOption' => 'Choose',
				'options'           => $options,
				'defaultValue'      => 'a,b|c',
			]
		);
		$this->assertSame(
			'<div class="swiftforms-field swiftforms-field--select swiftforms-w-100 wp-block-swiftforms-field-select">'
			. '<label class="swiftforms-label" for="' . self::P . 'pick-1">Pick</label>'
			. '<select class="swiftforms-select" id="' . self::P . 'pick-1" name="sf[pick]" aria-describedby="' . self::P . 'pick-1-error">'
			. '<option value="">Choose</option><option value="a,b|c" selected>A, B | C</option><option value="plain">Plain</option></select>'
			. '<p class="swiftforms-error" id="' . self::P . 'pick-1-error" hidden></p>'
			. '</div>',
			$single
		);

		$multiple = $this->render_field(
			'select',
			[
				'name'              => 'pick',
				'multiple'          => true,
				'placeholderOption' => 'Choose',
				'options'           => $options,
				'defaultValue'      => "a,b|c\nplain",
			]
		);
		$this->assertStringContainsString( 'name="sf[pick][]" multiple', $multiple );
		$this->assertStringNotContainsString( '<option value="">', $multiple, 'A multiple select has no empty first option.' );
		$this->assertStringContainsString( '<option value="a,b|c" selected>', $multiple );
		$this->assertStringContainsString( '<option value="plain" selected>', $multiple );
	}

	/**
	 * Radio buttons: fieldset, legend, one native required per button.
	 *
	 * @return void
	 */
	public function test_radio_golden() {
		$html = $this->render_field(
			'radio',
			[
				'name'         => 'plan',
				'label'        => 'Plan',
				'required'     => true,
				'layout'       => 'inline',
				'defaultValue' => 'b',
				'options'      => [
					[
						'value' => 'a',
						'label' => 'Small',
					],
					[
						'value' => 'b',
						'label' => 'Large',
					],
				],
			],
			[ 'requiredIndicator' => 'text' ]
		);

		$this->assertSame(
			'<fieldset class="swiftforms-field swiftforms-field--radio swiftforms-field--required swiftforms-w-100 wp-block-swiftforms-field-radio" aria-describedby="' . self::P . 'plan-1-error" role="radiogroup">'
			. '<legend class="swiftforms-label">Plan<span class="swiftforms-required"> (required)</span></legend>'
			. '<div class="swiftforms-choices swiftforms-choices--inline">'
			. '<label class="swiftforms-choice"><input type="radio" id="' . self::P . 'plan-1-1" name="sf[plan]" value="a" required aria-required="true"><span>Small</span></label>'
			. '<label class="swiftforms-choice"><input type="radio" id="' . self::P . 'plan-1-2" name="sf[plan]" value="b" checked required aria-required="true"><span>Large</span></label>'
			. '</div>'
			. '<p class="swiftforms-error" id="' . self::P . 'plan-1-error" hidden></p>'
			. '</fieldset>',
			$html
		);
	}

	/**
	 * Checkboxes: array name, no native required on the boxes, several defaults one per line.
	 *
	 * @return void
	 */
	public function test_checkboxes_golden() {
		$html = $this->render_field(
			'checkboxes',
			[
				'name'         => 'topics',
				'label'        => 'Topics',
				'required'     => true,
				'help'         => 'Pick some',
				'defaultValue' => "x,y\nz|w",
				'options'      => [
					[
						'value' => 'x,y',
						'label' => 'X and Y',
					],
					[
						'value' => 'z|w',
						'label' => 'Z or W',
					],
					[
						'value' => 'q',
						'label' => 'Q',
					],
				],
			]
		);

		$this->assertSame(
			'<fieldset class="swiftforms-field swiftforms-field--checkboxes swiftforms-field--required swiftforms-w-100 wp-block-swiftforms-field-checkboxes" aria-describedby="' . self::P . 'topics-1-help ' . self::P . 'topics-1-error">'
			. '<legend class="swiftforms-label">Topics<span class="swiftforms-required" aria-hidden="true">*</span><span class="swiftforms-sr-only"> (required)</span></legend>'
			. '<div class="swiftforms-choices swiftforms-choices--stack">'
			. '<label class="swiftforms-choice"><input type="checkbox" id="' . self::P . 'topics-1-1" name="sf[topics][]" value="x,y" checked><span>X and Y</span></label>'
			. '<label class="swiftforms-choice"><input type="checkbox" id="' . self::P . 'topics-1-2" name="sf[topics][]" value="z|w" checked><span>Z or W</span></label>'
			. '<label class="swiftforms-choice"><input type="checkbox" id="' . self::P . 'topics-1-3" name="sf[topics][]" value="q"><span>Q</span></label>'
			. '</div>'
			. '<p class="swiftforms-help" id="' . self::P . 'topics-1-help">Pick some</p>'
			. '<p class="swiftforms-error" id="' . self::P . 'topics-1-error" hidden></p>'
			. '</fieldset>',
			$html
		);
	}

	/**
	 * Consent: a checkbox whose label is the consent text; links allowed, scripts not.
	 *
	 * @return void
	 */
	public function test_consent_golden_and_kses() {
		$html = $this->render_field(
			'consent',
			[
				'name'  => 'agree',
				'label' => 'Agree',
				'text'  => 'I accept the <a href="https://example.org/terms" target="_blank" rel="noopener">terms</a><script>alert(1)</script> <strong>now</strong>',
			]
		);

		$this->assertSame(
			'<div class="swiftforms-field swiftforms-field--consent swiftforms-field--required swiftforms-w-100 wp-block-swiftforms-field-consent">'
			. '<label class="swiftforms-choice swiftforms-label swiftforms-consent" for="' . self::P . 'agree-1"><input type="checkbox" id="' . self::P . 'agree-1" name="sf[agree]" value="1" required aria-required="true" aria-describedby="' . self::P . 'agree-1-error">'
			. '<span>I accept the <a href="https://example.org/terms" target="_blank" rel="noopener">terms</a>alert(1) <strong>now</strong><span class="swiftforms-required" aria-hidden="true">*</span><span class="swiftforms-sr-only"> (required)</span></span></label>'
			. '<p class="swiftforms-error" id="' . self::P . 'agree-1-error" hidden></p>'
			. '</div>',
			$html
		);
	}

	/**
	 * Hidden field: the resolved value, in a bare input.
	 *
	 * @return void
	 */
	public function test_hidden_golden() {
		$html = $this->render_field(
			'hidden',
			[
				'name'  => 'campaign',
				'value' => 'spring-2026',
			]
		);
		$this->assertSame(
			'<input type="hidden" class="swiftforms-field swiftforms-field--hidden" id="' . self::P . 'campaign-1" name="sf[campaign]" value="spring-2026">',
			$html
		);
	}

	/**
	 * Hidden tokens resolve from the source and the user, never from the request.
	 *
	 * @return void
	 */
	public function test_hidden_tokens_in_markup() {
		$_POST['sf']['pid'] = 'forged';
		$markup             = $this->form_markup(
			self::ID_A,
			$this->field_markup(
				'hidden',
				[
					'name'  => 'pid',
					'value' => 'post {post:id}: {post:title} ({post:url}) by {user:id}',
				]
			)
		);
		$post_id            = 0;
		$html               = $this->render_page( $markup, $post_id, [ 'post_title' => 'My <b>page</b>' ] );
		unset( $_POST['sf'] );

		$expected = 'post ' . $post_id . ': My page (' . get_permalink( $post_id ) . ') by ';
		$this->assertStringContainsString( 'value="' . esc_attr( $expected ) . '"', $html, 'Visitor: the user token is empty and the posted value is ignored.' );
	}

	/**
	 * The label position of the form: hidden is visually hidden but still in the DOM.
	 *
	 * @return void
	 */
	public function test_label_positions() {
		$top = $this->render_field(
			'text',
			[
				'name'  => 'a',
				'label' => 'Alpha',
			]
		);
		$this->assertStringContainsString( '<label class="swiftforms-label" for=', $top );

		$hidden = $this->render_field(
			'text',
			[
				'name'  => 'a',
				'label' => 'Alpha',
			],
			[ 'labelPosition' => 'hidden' ]
		);
		$this->assertStringContainsString( '<label class="swiftforms-label swiftforms-sr-only" for=', $hidden );
		$this->assertStringContainsString( '>Alpha</label>', $hidden );

		$html = $this->render_form( $this->field_markup( 'text', [ 'name' => 'a' ] ), [ 'labelPosition' => 'inline' ] );
		$this->assertStringContainsString( 'swiftforms-form--labels-inline', $html );

		$legend = $this->render_field(
			'radio',
			[
				'name'    => 'r',
				'label'   => 'Choose',
				'options' => [
					[
						'value' => 'a',
						'label' => 'A',
					],
				],
			],
			[ 'labelPosition' => 'hidden' ]
		);
		$this->assertStringContainsString( '<legend class="swiftforms-label swiftforms-sr-only">Choose</legend>', $legend );

		$consent = $this->render_field(
			'consent',
			[
				'name' => 'c',
				'text' => 'I agree',
			],
			[ 'labelPosition' => 'hidden' ]
		);
		$this->assertStringNotContainsString( 'swiftforms-sr-only">I agree', $consent, 'A consent text is never hidden.' );
	}

	/**
	 * The required indicator: asterisk, text, none. aria-required stays in every case.
	 *
	 * @return void
	 */
	public function test_required_indicators() {
		$attrs = [
			'name'     => 'a',
			'label'    => 'Alpha',
			'required' => true,
		];

		$asterisk = $this->render_field( 'text', $attrs );
		$this->assertStringContainsString( '<span class="swiftforms-required" aria-hidden="true">*</span><span class="swiftforms-sr-only"> (required)</span>', $asterisk );

		$text = $this->render_field( 'text', $attrs, [ 'requiredIndicator' => 'text' ] );
		$this->assertStringContainsString( '<span class="swiftforms-required"> (required)</span>', $text );
		$this->assertStringNotContainsString( 'aria-hidden="true">*', $text );

		$none = $this->render_field( 'text', $attrs, [ 'requiredIndicator' => 'none' ] );
		$this->assertStringNotContainsString( 'swiftforms-required', $none );
		$this->assertStringNotContainsString( '(required)', $none );

		foreach ( [ $asterisk, $text, $none ] as $html ) {
			$this->assertStringContainsString( ' required aria-required="true"', $html );
		}

		$optional = $this->render_field(
			'text',
			[
				'name'  => 'a',
				'label' => 'Alpha',
			]
		);
		$this->assertStringNotContainsString( 'aria-required', $optional );
	}

	/**
	 * A label-less field is labelled with its name.
	 *
	 * @return void
	 */
	public function test_missing_label_falls_back_to_the_name() {
		$this->assertStringContainsString( '>phone</label>', $this->render_field( 'tel', [ 'name' => 'phone' ] ) );
	}

	/**
	 * Form_State: submitted values beat defaults, errors mark the field and fill the error element and the summary.
	 *
	 * @return void
	 */
	public function test_form_state_values_and_errors() {
		Form_State::set(
			self::ID_A,
			[
				'values' => [
					'who'    => 'typed',
					'topics' => [ 'q' ],
					'agree'  => '1',
				],
				'errors' => [
					'who' => 'Please enter a <b>name</b>.',
				],
			]
		);

		$markup = $this->field_markup(
			'text',
			[
				'name'         => 'who',
				'label'        => 'Who',
				'defaultValue' => 'default',
			]
		) . $this->field_markup(
			'checkboxes',
			[
				'name'         => 'topics',
				'defaultValue' => 'x',
				'options'      => [
					[
						'value' => 'x',
						'label' => 'X',
					],
					[
						'value' => 'q',
						'label' => 'Q',
					],
				],
			]
		) . $this->field_markup(
			'consent',
			[
				'name' => 'agree',
				'text' => 'ok',
			]
		);
		$html   = $this->render_page( $this->form_markup( self::ID_A, $markup ) );

		$this->assertStringContainsString( 'value="typed"', $html );
		$this->assertStringNotContainsString( 'value="default"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( '<p class="swiftforms-error" id="' . self::P . 'who-1-error">Please enter a &lt;b&gt;name&lt;/b&gt;.</p>', $html );
		$this->assertStringContainsString( '<input type="checkbox" id="' . self::P . 'topics-1-2" name="sf[topics][]" value="q" checked>', $html );
		$this->assertStringContainsString( '<input type="checkbox" id="' . self::P . 'topics-1-1" name="sf[topics][]" value="x">', $html );
		$this->assertStringContainsString( ' value="1" checked', $html );
		$this->assertStringContainsString( '<div class="swiftforms-errors-summary" tabindex="-1" role="alert"><p>Please fix the following:</p><ul><li><a href="#' . self::P . 'who-1">Who: Please enter a &lt;b&gt;name&lt;/b&gt;.</a></li></ul></div>', $html );
		$this->assertStringNotContainsString( 'errors-summary" tabindex="-1" hidden', $html );
	}

	/**
	 * Author text is escaped everywhere: unicode and right-to-left text pass, markup and merge tags do not act.
	 *
	 * @return void
	 */
	public function test_escaping_corpus() {
		$corpus = [
			'unicode'     => 'Ünïcödé 日本語 😀',
			'rtl'         => 'שלום עולם مرحبا',
			'entities'    => 'Tom &amp; Jerry &lt;3 &#8364; "quoted" \'single\'',
			'textarea'    => '</textarea><script>alert(1)</script>',
			'script'      => '<script>alert("x")</script><img src=x onerror=alert(1)>',
			'merge tag'   => '{field:email} {{ attributes.label }} {post:title}',
			'attribute'   => '"><script>alert(1)</script>',
			'backslashes' => 'a\\b \\u003c \\" %41',
		];

		foreach ( $corpus as $case => $text ) {
			$html = $this->render_field(
				'text',
				[
					'name'         => 'x',
					'label'        => $text,
					'help'         => $text,
					'placeholder'  => $text,
					'defaultValue' => $text,
					'pattern'      => '^.*$',
				]
			);

			$this->assertStringNotContainsString( '<script', $html, $case );
			$this->assertStringNotContainsString( '<img', $html, $case );
			$this->assertStringContainsString( '>' . esc_html( $text ) . '</p>', $html, $case . ' help' );
			$this->assertStringContainsString( 'value="' . esc_attr( $text ) . '"', $html, $case . ' value' );
			$this->assertStringContainsString( 'placeholder="' . esc_attr( $text ) . '"', $html, $case . ' placeholder' );
		}

		$area = $this->render_field(
			'textarea',
			[
				'name'         => 'x',
				'defaultValue' => $corpus['textarea'],
			]
		);
		$this->assertStringContainsString( '>&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;</textarea>', $area );

		$options = $this->render_field(
			'select',
			[
				'name'    => 'x',
				'options' => [
					[
						'value' => $corpus['script'],
						'label' => $corpus['script'],
					],
				],
			]
		);
		$this->assertStringNotContainsString( '<script', $options );
		$this->assertStringNotContainsString( '<img', $options );
	}

	/**
	 * A 2 KB label renders whole.
	 *
	 * @return void
	 */
	public function test_long_label() {
		$label = str_repeat( 'Lorem ipsum ', 180 );
		$html  = $this->render_field(
			'text',
			[
				'name'  => 'long',
				'label' => $label,
			]
		);
		$this->assertGreaterThan( 2000, strlen( $label ) );
		$this->assertStringContainsString( '>' . esc_html( $label ) . '</label>', $html );
	}

	/**
	 * Names follow the resolver: an invalid or empty name is derived, a repeated one gets a suffix (C-3.9).
	 *
	 * @return void
	 */
	public function test_names_follow_the_resolver() {
		$html = $this->render_form(
			$this->field_markup( 'text', [ 'name' => 'dup' ] )
			. $this->field_markup( 'text', [ 'name' => 'dup' ] )
			. $this->field_markup( 'email' )
			. $this->field_markup( 'text', [ 'name' => '2 Nd' ] )
		);

		$this->assertStringContainsString( 'name="sf[dup]"', $html );
		$this->assertStringContainsString( 'name="sf[dup-2]"', $html );
		$this->assertStringContainsString( 'name="sf[email]"', $html );
		$this->assertStringContainsString( 'name="sf[f-2-nd]"', $html );
	}

	/**
	 * Field, submit and confirmation blocks outside a form render nothing.
	 *
	 * @return void
	 */
	public function test_outside_a_form_nothing_renders() {
		$this->assertSame( '', do_blocks( '<!-- wp:swiftforms/field-text {"name":"x"} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:swiftforms/field-hidden {"name":"x"} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:swiftforms/submit /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->' ) );
	}

	/**
	 * Fields inside layout blocks still belong to the form, in document order.
	 *
	 * @return void
	 */
	public function test_fields_inside_layout_blocks() {
		$columns = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. $this->field_markup( 'text', [ 'name' => 'left' ] )
			. '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">'
			. $this->field_markup( 'text', [ 'name' => 'right' ] )
			. '</div><!-- /wp:column --></div><!-- /wp:columns -->';
		$html    = $this->render_form( $columns );

		$this->assertGreaterThan( strpos( $html, 'sf[left]' ), strpos( $html, 'sf[right]' ) );
		$this->assertStringContainsString( 'wp-block-column', $html );
	}

	/**
	 * Submit: translated default, typed label verbatim, escaped.
	 *
	 * @return void
	 */
	public function test_submit_block() {
		$default = $this->render_form( '<!-- wp:swiftforms/submit /-->' );
		$this->assertStringContainsString( '<div class="swiftforms-submit wp-block-swiftforms-submit"><button type="submit" class="swiftforms-submit__button wp-element-button">Submit</button></div>', $default );

		$typed = $this->render_form( '<!-- wp:swiftforms/submit {"label":"Send <it> & go"} /-->' );
		$this->assertStringContainsString( '>Send &lt;it&gt; &amp; go</button>', $typed );

		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				return ( 'swiftforms' === $domain && 'Submit' === $text ) ? 'Absenden' : $translation;
			},
			10,
			3
		);
		Form_State::reset();
		$german = $this->render_form( '<!-- wp:swiftforms/submit /-->' );
		$this->assertStringContainsString( '>Absenden</button>', $german );
		$typed_again = $this->render_form( '<!-- wp:swiftforms/submit {"label":"Submit now"} /-->' );
		$this->assertStringContainsString( '>Submit now</button>', $typed_again );
	}
}
