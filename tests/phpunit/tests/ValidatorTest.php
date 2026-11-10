<?php
/**
 * Tests for SwiftForms\Validator.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Database;
use SwiftForms\Field_Types;
use SwiftForms\Form_Schema;
use SwiftForms\Submission_Context;
use SwiftForms\Validator;

/**
 * Class ValidatorTest
 *
 * @group submission
 * @group security
 */
class ValidatorTest extends SF_Storage_Test_Case {

	const FORM = '11111111-1111-4111-8111-111111111111';

	/**
	 * A field array as the resolver builds it.
	 *
	 * @param string $type  Field type.
	 * @param array  $attrs Attribute overrides.
	 * @return array
	 */
	private function field( $type, array $attrs = [] ) {
		$attrs += [ 'name' => 'f' ];
		return array_merge( [ 'type' => $type ], Field_Types::defaults( $type ), $attrs );
	}

	/**
	 * A schema of fields.
	 *
	 * @param array[] $fields Fields.
	 * @return Form_Schema
	 */
	private function schema( array $fields ) {
		return new Form_Schema(
			[
				'form_id' => self::FORM,
				'source'  => 'post:5',
				'fields'  => $fields,
			]
		);
	}

	/**
	 * A context for a schema.
	 *
	 * @param Form_Schema $schema Schema.
	 * @return Submission_Context
	 */
	private function ctx( Form_Schema $schema ) {
		return new Submission_Context( $schema );
	}

	/**
	 * Check one value.
	 *
	 * @param array        $field Field.
	 * @param string|array $value Value.
	 * @return string|null
	 */
	private function check( array $field, $value ) {
		return Validator::check( $field, $value, self::FORM );
	}

	/**
	 * Rows of the log.
	 *
	 * @return array
	 */
	private function log_messages() {
		return array_column( $this->rows( 'log' ), 'message' );
	}

	/**
	 * Required: empty strings and empty lists fail, "0" passes.
	 *
	 * @return void
	 */
	public function test_required() {
		$this->assertSame( 'required', $this->check( $this->field( 'text', [ 'required' => true ] ), '' ) );
		$this->assertNull( $this->check( $this->field( 'text', [ 'required' => true ] ), 'x' ) );
		$this->assertNull( $this->check( $this->field( 'number', [ 'required' => true ] ), '0' ) );
		$this->assertNull( $this->check( $this->field( 'text' ), '' ), 'Optional and empty.' );
		$this->assertSame(
			'required',
			$this->check(
				$this->field(
					'checkboxes',
					[
						'required' => true,
						'options'  => [
							[
								'value' => 'a',
								'label' => 'a',
							],
						],
					]
				),
				[]
			)
		);
		$this->assertSame(
			'required',
			$this->check(
				$this->field(
					'select',
					[
						'required' => true,
						'multiple' => true,
						'options'  => [
							[
								'value' => 'a',
								'label' => 'a',
							],
						],
					]
				),
				[]
			)
		);
		$this->assertSame(
			'required',
			$this->check(
				$this->field(
					'select',
					[
						'required' => true,
						'options'  => [
							[
								'value' => 'a',
								'label' => 'a',
							],
						],
					]
				),
				''
			)
		);
	}

	/**
	 * Email.
	 *
	 * @return void
	 */
	public function test_email() {
		$field = $this->field( 'email' );
		$this->assertNull( $this->check( $field, 'ada@example.com' ) );
		foreach ( [ 'ada', 'ada@', '@example.com', 'a b@example.com', "ada@example.com\nBcc: x@y.z", 'ada@example.com,bob@example.com', 'a@b' ] as $bad ) {
			$this->assertSame( 'email', $this->check( $field, $bad ), $bad );
		}
	}

	/**
	 * URLs are http or https with a host.
	 *
	 * @return void
	 */
	public function test_url() {
		$field = $this->field( 'url' );
		foreach ( [ 'https://example.com', 'http://example.com/a/b?c=d#e', 'https://example.com:8080/x', 'HTTP://EXAMPLE.COM', 'http://localhost/x', 'https://[2001:db8::1]/' ] as $good ) {
			$this->assertNull( $this->check( $field, $good ), $good );
		}
		foreach ( [ 'example.com', 'javascript:alert(1)', 'ftp://example.com', 'data:text/html;base64,AAAA', '//example.com', 'http://', 'http:///x', 'https://exa mple.com', 'https://example.com/<script>', "https://example.com/\n", 'mailto:a@b.c', 'https://' . str_repeat( 'a', 2100 ) . '.com' ] as $bad ) {
			$this->assertSame( 'url', $this->check( $field, $bad ), $bad );
		}
	}

	/**
	 * URL validation never resolves host names (no DNS lookup per submission).
	 *
	 * @return void
	 */
	public function test_url_does_not_use_wp_http_validate_url() {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-validator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$code   = (string) preg_replace( '~^\s*(\*|//).*$~m', '', $source );
		$this->assertDoesNotMatchRegularExpression( '/wp_http_validate_url\s*\(/', $code );
	}

	/**
	 * Number range and step.
	 *
	 * @return void
	 */
	public function test_number() {
		$field = $this->field(
			'number',
			[
				'min'  => '1',
				'max'  => '10',
				'step' => '0.5',
			]
		);
		$this->assertNull( $this->check( $field, '1' ) );
		$this->assertNull( $this->check( $field, '10' ) );
		$this->assertNull( $this->check( $field, '2.5' ) );
		$this->assertSame( 'number_min', $this->check( $field, '0.5' ) );
		$this->assertSame( 'number_max', $this->check( $field, '10.5' ) );
		$this->assertSame( 'number_step', $this->check( $field, '2.3' ) );
		foreach ( [ 'abc', '1e3', '1,5', '--1', '.', '1.', '0b101', ' 5', '5 ', 'NaN', 'INF' ] as $bad ) {
			$this->assertSame( 'number', $this->check( $field, $bad ), $bad );
		}

		// Float-safe steps.
		$tenth = $this->field( 'number', [ 'step' => '0.1' ] );
		foreach ( [ '0.3', '0.7', '1.1', '2.9', '-0.3', '123456.7' ] as $ok ) {
			$this->assertNull( $this->check( $tenth, $ok ), $ok );
		}
		$this->assertSame( 'number_step', $this->check( $tenth, '0.35' ) );

		// The step grid starts at min.
		$offset = $this->field(
			'number',
			[
				'min'  => '0.25',
				'step' => '0.5',
			]
		);
		$this->assertNull( $this->check( $offset, '0.75' ) );
		$this->assertSame( 'number_step', $this->check( $offset, '0.5' ) );

		// "any", empty and non-numeric limits are ignored.
		$any = $this->field(
			'number',
			[
				'min'  => 'abc',
				'max'  => '',
				'step' => 'any',
			]
		);
		$this->assertNull( $this->check( $any, '-5.123' ) );
	}

	/**
	 * Dates are real Y-m-d dates within min and max.
	 *
	 * @return void
	 */
	public function test_date() {
		$field = $this->field(
			'date',
			[
				'min' => '2026-01-01',
				'max' => '2026-12-31',
			]
		);
		$this->assertNull( $this->check( $field, '2026-02-28' ) );
		$this->assertNull( $this->check( $field, '2026-01-01' ) );
		$this->assertNull( $this->check( $field, '2026-12-31' ) );
		$this->assertSame( 'date_min', $this->check( $field, '2025-12-31' ) );
		$this->assertSame( 'date_max', $this->check( $field, '2027-01-01' ) );
		foreach ( [ '2026-02-30', '2026-13-01', '26-01-01', '2026/01/01', '2026-1-1', 'tomorrow', '2026-01-01T10:00', '0000-00-00' ] as $bad ) {
			$this->assertSame( 'date', $this->check( $field, $bad ), $bad );
		}
		$this->assertNull( $this->check( $this->field( 'date', [ 'min' => 'junk' ] ), '1999-01-01' ), 'A junk limit is ignored.' );
	}

	/**
	 * Length: the default cap is 5,000 characters, applied before any regex.
	 *
	 * @return void
	 */
	public function test_length_rules() {
		$text = $this->field(
			'text',
			[
				'minLength' => 3,
				'maxLength' => 5,
			]
		);
		$this->assertNull( $this->check( $text, 'abc' ) );
		$this->assertNull( $this->check( $text, 'abcde' ) );
		$this->assertSame( 'min_length', $this->check( $text, 'ab' ) );
		$this->assertSame( 'max_length', $this->check( $text, 'abcdef' ) );
		$this->assertNull( $this->check( $text, 'äöüÄÖ' ), 'Characters, not bytes.' );

		$plain = $this->field( 'text' );
		$this->assertNull( $this->check( $plain, str_repeat( 'a', 5000 ) ) );
		$this->assertSame( 'max_length', $this->check( $plain, str_repeat( 'a', 5001 ) ) );
		$this->assertSame( 'max_length', $this->check( $this->field( 'text', [ 'maxLength' => 100000 ] ), str_repeat( 'a', 5001 ) ), 'A larger maxLength cannot lift the cap.' );
		$this->assertSame( 'max_length', $this->check( $this->field( 'textarea' ), str_repeat( 'a', 5001 ) ) );
		$this->assertSame( 'max_length', $this->check( $this->field( 'email' ), str_repeat( 'a', 5001 ) . '@example.com' ) );
	}

	/**
	 * Patterns: anchored, delimiter-safe, no visitor modifiers.
	 *
	 * @return void
	 */
	public function test_pattern() {
		$field = $this->field( 'text', [ 'pattern' => '[A-Z]{2}[0-9]{3}' ] );
		$this->assertNull( $this->check( $field, 'AB123' ) );
		$this->assertSame( 'pattern', $this->check( $field, 'AB1234' ), 'Anchored at the end.' );
		$this->assertSame( 'pattern', $this->check( $field, 'xAB123' ), 'Anchored at the start.' );
		$this->assertSame( 'pattern', $this->check( $field, 'ab123' ), 'Case-sensitive.' );

		// Alternation stays inside the anchors.
		$alt = $this->field( 'text', [ 'pattern' => 'cat|dog' ] );
		$this->assertNull( $this->check( $alt, 'dog' ) );
		$this->assertSame( 'pattern', $this->check( $alt, 'hotdog' ) );

		// The delimiter is safe: a "#" in the pattern, escaped or not.
		$hash = $this->field( 'text', [ 'pattern' => '#[0-9]+' ] );
		$this->assertNull( $this->check( $hash, '#123' ) );
		$this->assertSame( 'pattern', $this->check( $hash, '123' ) );
		$escaped = $this->field( 'text', [ 'pattern' => '\#[0-9]+' ] );
		$this->assertNull( $this->check( $escaped, '#123' ) );
		$this->assertNull( $this->check( $this->field( 'text', [ 'pattern' => 'a\\\\#b' ] ), 'a\\#b' ), 'An escaped backslash before a hash.' );

		// Unicode input works (the u flag).
		$this->assertNull( $this->check( $this->field( 'text', [ 'pattern' => '\p{L}+' ] ), 'Zoë' ) );

		// A pattern may not break out with an end delimiter and flags.
		$evil = $this->field( 'text', [ 'pattern' => 'a#e' ] );
		$this->assertSame( 'pattern', $this->check( $evil, 'a' ) );
		$this->assertNull( $this->check( $evil, 'a#e' ) );

		// The author's patternMessage is used when set.
		$schema = $this->schema(
			[
				$this->field(
					'text',
					[
						'name'           => 'code',
						'pattern'        => '[0-9]+',
						'patternMessage' => 'Digits only, please.',
					]
				),
			]
		);
		$errors = Validator::validate( $schema, [ 'code' => 'x' ], $this->ctx( $schema ) );
		$this->assertSame( [ 'code' => 'Digits only, please.' ], $errors );
	}

	/**
	 * A pattern that does not compile is ignored and logged.
	 *
	 * @return void
	 */
	public function test_pattern_compile_failure_is_ignored_and_logged() {
		$field = $this->field( 'text', [ 'pattern' => '([a-z' ] );
		$this->assertNull( $this->check( $field, 'anything at all' ) );
		$this->assertNull( $this->check( $field, '123' ) );
		$this->assertContains( 'A field pattern does not compile; it is ignored.', $this->log_messages() );

		$this->assertNull( $this->check( $this->field( 'text', [ 'pattern' => '(?<name' ] ), 'x' ) );
		$this->assertNull( $this->check( $this->field( 'text', [ 'pattern' => '*abc' ] ), 'x' ) );
	}

	/**
	 * A PCRE runtime error (catastrophic backtracking) makes the value invalid and is logged.
	 *
	 * @return void
	 */
	public function test_pattern_runtime_error_is_invalid_and_logged() {
		$field = $this->field( 'text', [ 'pattern' => '(a+)+$' ] );
		$this->assertSame( 'pattern', $this->check( $field, str_repeat( 'a', 4000 ) . '!' ) );
		$this->assertContains( 'A field pattern failed at run time (PCRE error); the value counts as invalid.', $this->log_messages() );

		// The bound lives in the compiled regex; the ini setting is never touched.
		$before = ini_get( 'pcre.backtrack_limit' );
		$this->check( $field, str_repeat( 'a', 4000 ) . '!' );
		$this->assertSame( $before, ini_get( 'pcre.backtrack_limit' ) );
		$this->assertStringStartsWith( '#(*LIMIT_MATCH=' . Validator::PATTERN_BACKTRACK_LIMIT . ')^(?:', (string) Validator::compile( '(a+)+$' ) );
	}

	/**
	 * The length cap runs before the regex: an oversized value never reaches a catastrophic pattern.
	 *
	 * @return void
	 */
	public function test_max_length_is_checked_before_the_pattern() {
		$field = $this->field( 'text', [ 'pattern' => '(a+)+$' ] );
		$this->assertSame( 'max_length', $this->check( $field, str_repeat( 'a', 5001 ) . '!' ) );
		$this->assertNotContains( 'A field pattern failed at run time (PCRE error); the value counts as invalid.', $this->log_messages(), 'The regex never ran.' );

		$short = $this->field(
			'text',
			[
				'pattern'   => '(a+)+$',
				'maxLength' => 10,
			]
		);
		$this->assertSame( 'max_length', $this->check( $short, str_repeat( 'a', 30 ) . '!' ) );
		$this->assertNotContains( 'A field pattern failed at run time (PCRE error); the value counts as invalid.', $this->log_messages() );
	}

	/**
	 * Option membership for select and radio; commas and pipes in values are plain characters.
	 *
	 * @return void
	 */
	public function test_options_membership() {
		$options = [
			[
				'value' => 'a,b',
				'label' => 'A and B',
			],
			[
				'value' => 'x|y',
				'label' => 'X or Y',
			],
			[
				'value' => 'plain',
				'label' => 'Plain',
			],
		];
		foreach ( [ 'select', 'radio' ] as $type ) {
			$field = $this->field( $type, [ 'options' => $options ] );
			$this->assertNull( $this->check( $field, 'a,b' ), $type );
			$this->assertNull( $this->check( $field, 'x|y' ), $type );
			$this->assertNull( $this->check( $field, 'plain' ), $type );
			$this->assertSame( 'option', $this->check( $field, 'a' ), $type . ': half of a comma value' );
			$this->assertSame( 'option', $this->check( $field, 'x' ), $type );
			$this->assertSame( 'option', $this->check( $field, 'PLAIN' ), $type . ': exact match' );
			$this->assertSame( 'option', $this->check( $field, 'other' ), $type );
			$this->assertSame( 'option', $this->check( $field, 'plain ' ) );
		}

		$multi = $this->field(
			'select',
			[
				'multiple' => true,
				'options'  => $options,
			]
		);
		$this->assertNull( $this->check( $multi, [ 'a,b', 'x|y' ] ) );
		$this->assertSame( 'option', $this->check( $multi, [ 'a,b', 'tampered' ] ) );
		$this->assertSame( 'option', $this->check( $multi, [ 'a', 'b' ] ), 'A comma list is not two values.' );
	}

	/**
	 * Checkbox groups: membership, min and max choices.
	 *
	 * @return void
	 */
	public function test_checkboxes() {
		$options = [
			[
				'value' => 'a',
				'label' => 'a',
			],
			[
				'value' => 'b',
				'label' => 'b',
			],
			[
				'value' => 'c',
				'label' => 'c',
			],
		];
		$field   = $this->field(
			'checkboxes',
			[
				'options'    => $options,
				'minChoices' => 2,
				'maxChoices' => 2,
			]
		);
		$this->assertNull( $this->check( $field, [ 'a', 'c' ] ) );
		$this->assertSame( 'min_choices', $this->check( $field, [ 'a' ] ) );
		$this->assertSame( 'max_choices', $this->check( $field, [ 'a', 'b', 'c' ] ) );
		$this->assertSame( 'option', $this->check( $field, [ 'a', 'z' ] ) );
		$this->assertNull( $this->check( $field, [] ), 'An empty optional group is no answer.' );

		$required = $this->field(
			'checkboxes',
			[
				'options'    => $options,
				'required'   => true,
				'minChoices' => 2,
			]
		);
		$this->assertSame( 'required', $this->check( $required, [] ) );
		$this->assertSame( 'min_choices', $this->check( $required, [ 'a' ] ) );
	}

	/**
	 * Consent must be ticked when required.
	 *
	 * @return void
	 */
	public function test_consent() {
		$required = $this->field( 'consent' );
		$this->assertTrue( $required['required'], 'Consent is required by default.' );
		$this->assertSame( 'consent', $this->check( $required, '' ) );
		$this->assertNull( $this->check( $required, '1' ) );

		$optional = $this->field( 'consent', [ 'required' => false ] );
		$this->assertNull( $this->check( $optional, '' ) );
	}

	/**
	 * Collecting discards unknown fields, recomputes hidden fields and shapes values.
	 *
	 * @return void
	 */
	public function test_collect() {
		$schema = $this->schema(
			[
				$this->field( 'text', [ 'name' => 'name' ] ),
				$this->field( 'textarea', [ 'name' => 'message' ] ),
				$this->field(
					'hidden',
					[
						'name'  => 'ref',
						'value' => 'campaign-1',
					]
				),
				$this->field(
					'checkboxes',
					[
						'name'    => 'colors',
						'options' => [
							[
								'value' => 'r',
								'label' => 'r',
							],
							[
								'value' => 'g',
								'label' => 'g',
							],
						],
					]
				),
				$this->field( 'consent', [ 'name' => 'agree' ] ),
				$this->field(
					'select',
					[
						'name'    => 'size',
						'options' => [
							[
								'value' => 's',
								'label' => 's',
							],
						],
					]
				),
			]
		);
		$ctx    = $this->ctx( $schema );

		$values = Validator::collect(
			$schema,
			[
				'name'      => "  Ada\r\n\tLovelace\0  ",
				'message'   => "line one\r\nline two\r\n\r\n",
				'ref'       => 'HACKED',
				'colors'    => [ 'r', 'r', 'g', [ 'nested' ], '' ],
				'agree'     => 'on',
				'size'      => [ 's' ],
				'unknown'   => 'dropped',
				'__proto__' => 'dropped',
			],
			$ctx
		);

		$this->assertSame(
			[
				'name'    => 'Ada Lovelace',
				'message' => "line one\nline two",
				'ref'     => 'campaign-1',
				'colors'  => [ 'r', 'g' ],
				'agree'   => '1',
				'size'    => '',
			],
			$values
		);

		$missing = Validator::collect( $schema, [], $ctx );
		$this->assertSame(
			[
				'name'    => '',
				'message' => '',
				'ref'     => 'campaign-1',
				'colors'  => [],
				'agree'   => '',
				'size'    => '',
			],
			$missing
		);

		$scalar = Validator::collect(
			$schema,
			[
				'colors' => 'g',
				'agree'  => '0',
			],
			$ctx
		);
		$this->assertSame( [ 'g' ], $scalar['colors'], 'A scalar for a multi-value field is a one-item list.' );
		$this->assertSame( '', $scalar['agree'] );
	}

	/**
	 * Values are text: tags are kept, control characters and bad UTF-8 are removed.
	 *
	 * @return void
	 */
	public function test_collect_keeps_text_as_text() {
		$schema = $this->schema( [ $this->field( 'text', [ 'name' => 't' ] ) ] );
		$ctx    = $this->ctx( $schema );

		$values = Validator::collect( $schema, [ 't' => '<script>alert(1)</script> & "q" ' . "\x07\x08caf\xC3\xA9 \xFF\xFE ok" ], $ctx );
		$this->assertSame( '<script>alert(1)</script> & "q" café  ok', $values['t'] );

		$this->assertSame( [ 't' => '123' ], Validator::collect( $schema, [ 't' => 123 ], $ctx ) );
		$this->assertSame( [ 't' => '' ], Validator::collect( $schema, [ 't' => true ], $ctx ) );
		$this->assertSame( [ 't' => '' ], Validator::collect( $schema, [ 't' => [ 'a' ] ], $ctx ), 'An array for a single value is dropped.' );
	}

	/**
	 * Hidden fields are recomputed from the source and the user, never from the post (C-3.10).
	 *
	 * @return void
	 */
	public function test_hidden_field_is_recomputed() {
		$schema = $this->schema(
			[
				$this->field(
					'hidden',
					[
						'name'  => 'uid',
						'value' => 'u{user:id}',
					]
				),
			]
		);
		$ctx    = new Submission_Context( $schema, null, [ 'user' => new WP_User( self::factory()->user->create() ) ] );
		$values = Validator::collect( $schema, [ 'uid' => 'u999' ], $ctx );
		$this->assertSame( 'u' . $ctx->user_id(), $values['uid'] );

		$guest = $this->ctx( $schema );
		$this->assertSame( [ 'uid' => 'u' ], Validator::collect( $schema, [ 'uid' => 'u999' ], $guest ) );

		// Hidden fields are not validated.
		$this->assertSame( [], Validator::validate( $schema, [ 'uid' => 'whatever' ], $guest ) );
	}

	/**
	 * validate() gives one message per failing field, keyed by name.
	 *
	 * @return void
	 */
	public function test_validate_payload() {
		$schema = $this->schema(
			[
				$this->field(
					'text',
					[
						'name'     => 'name',
						'required' => true,
					]
				),
				$this->field(
					'email',
					[
						'name'     => 'email',
						'required' => true,
					]
				),
				$this->field( 'text', [ 'name' => 'ok' ] ),
			]
		);
		$errors = Validator::validate(
			$schema,
			[
				'name'  => '',
				'email' => 'nope',
				'ok'    => 'fine',
			],
			$this->ctx( $schema )
		);
		$this->assertSame( [ 'name', 'email' ], array_keys( $errors ) );
		$this->assertSame( Validator::message( 'required' ), $errors['name'] );
		$this->assertSame( Validator::message( 'email' ), $errors['email'] );
	}

	/**
	 * Error messages never contain what the visitor typed.
	 *
	 * @return void
	 */
	public function test_messages_do_not_echo_input() {
		$schema = $this->schema(
			[
				$this->field( 'email', [ 'name' => 'email' ] ),
				$this->field(
					'number',
					[
						'name' => 'n',
						'min'  => '5',
					]
				),
				$this->field(
					'text',
					[
						'name'      => 't',
						'maxLength' => 3,
					]
				),
			]
		);
		$secret = 'SECRET-<b>value</b>';
		$errors = Validator::validate(
			$schema,
			[
				'email' => $secret,
				'n'     => $secret,
				't'     => $secret,
			],
			$this->ctx( $schema )
		);
		$this->assertCount( 3, $errors );
		foreach ( $errors as $message ) {
			$this->assertStringNotContainsString( 'SECRET', $message );
		}
		$this->assertSame( 'Please enter a number that is at least 5.', Validator::message( 'number_min', [ 'min' => '5' ] ) );
	}

	/**
	 * The message map is complete and has the placeholders the client needs.
	 *
	 * @return void
	 */
	public function test_messages_map() {
		$messages = Validator::messages();
		foreach ( [ 'required', 'email', 'url', 'number', 'number_min', 'number_max', 'number_step', 'date', 'date_min', 'date_max', 'max_length', 'min_length', 'pattern', 'option', 'min_choices', 'max_choices', 'consent', 'invalid' ] as $key ) {
			$this->assertArrayHasKey( $key, $messages );
			$this->assertNotSame( '', $messages[ $key ] );
		}
		$this->assertStringContainsString( '{min}', $messages['min_length'] );
		$this->assertStringContainsString( '{max}', $messages['max_length'] );
		$this->assertStringContainsString( '{step}', $messages['number_step'] );
		$this->assertSame( Validator::message( 'invalid' ), Validator::message( 'no-such-key' ) );
	}

	/**
	 * The swiftforms_validate_field filter can add, keep and ignore.
	 *
	 * @return void
	 */
	public function test_validate_field_filter() {
		$schema = $this->schema(
			[
				$this->field( 'text', [ 'name' => 'code' ] ),
				$this->field( 'email', [ 'name' => 'email' ] ),
				$this->field( 'hidden', [ 'name' => 'h' ] ),
			]
		);
		$calls  = [];
		add_filter(
			'swiftforms_validate_field',
			static function ( $error, $value, $field ) use ( &$calls ) {
				$calls[] = $field['name'];
				if ( 'code' === $field['name'] && 'bad' === $value ) {
					return 'Code rejected.';
				}
				if ( 'email' === $field['name'] ) {
					return [ 'not a string' ];
				}
				return $error;
			},
			10,
			4
		);

		$errors = Validator::validate(
			$schema,
			[
				'code'  => 'bad',
				'email' => 'x',
				'h'     => '',
			],
			$this->ctx( $schema )
		);
		$this->assertSame( [ 'code' => 'Code rejected.' ], $errors, 'A non-string result is ignored, so the invalid email would have failed only through the built-in rule... which the filter replaced.' );
		$this->assertSame( [ 'code', 'email' ], $calls, 'Hidden fields are skipped.' );

		remove_all_filters( 'swiftforms_validate_field' );
		add_filter(
			'swiftforms_validate_field',
			static function ( $error ) {
				return $error;
			}
		);
		$errors = Validator::validate(
			$schema,
			[
				'code'  => 'ok',
				'email' => 'x',
			],
			$this->ctx( $schema )
		);
		$this->assertSame( [ 'email' ], array_keys( $errors ), 'A filter that returns the error keeps it.' );
	}

	/**
	 * The pattern log rows carry no visitor data.
	 *
	 * @return void
	 */
	public function test_log_rows_carry_no_values() {
		$this->check(
			$this->field(
				'text',
				[
					'name'    => 'code',
					'pattern' => '(a+)+$',
				]
			),
			str_repeat( 'a', 2000 ) . 'SECRETVALUE!'
		);
		foreach ( $this->rows( 'log' ) as $row ) {
			$this->assertStringNotContainsString( 'SECRETVALUE', $row['message'] . $row['context'] );
		}
		$this->assertNotEmpty( Database::table( 'log' ) );
	}
}
