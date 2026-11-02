<?php
/**
 * Tests for SwiftForms\Merge_Tags.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Field_Types;
use SwiftForms\Form_Schema;
use SwiftForms\Merge_Tags;
use SwiftForms\Submission_Context;

/**
 * Class MergeTagsTest
 *
 * @group submission
 * @group security
 */
class MergeTagsTest extends SF_Storage_Test_Case {

	const FORM = '11111111-1111-4111-8111-111111111111';

	/**
	 * A context with an email, a name, a message and a checkbox group.
	 *
	 * @param array $values Values (default: Ada).
	 * @param array $extra  Context properties.
	 * @return Submission_Context
	 */
	private function ctx( array $values = [], array $extra = [] ) {
		$values += [
			'name'    => 'Ada <b>Lovelace</b> & "co"',
			'email'   => 'ada@example.com',
			'message' => "line one\nline two",
			'colors'  => [ 'red', 'blue' ],
		];

		$types  = [
			'name'    => 'text',
			'email'   => 'email',
			'message' => 'textarea',
			'colors'  => 'checkboxes',
		];
		$fields = [];
		foreach ( $types as $name => $type ) {
			$fields[] = array_merge(
				[ 'type' => $type ],
				Field_Types::defaults( $type ),
				[
					'name'  => $name,
					'label' => ucfirst( $name ),
				]
			);
		}
		$schema = new Form_Schema(
			[
				'form_id'    => self::FORM,
				'source'     => 'post:5',
				'attributes' => array_merge( Field_Types::form_defaults(), [ 'title' => 'Contact "us"' ] ),
				'fields'     => $fields,
			]
		);

		return new Submission_Context(
			$schema,
			null,
			array_merge(
				[
					'values'      => $values,
					'entry_id'    => 42,
					'page_url'    => 'https://example.org/contact/?a=1&b=2',
					'page_title'  => 'Contact <us>',
					'created_gmt' => '2026-11-02 10:30:00',
				],
				$extra
			)
		);
	}

	/**
	 * A user.
	 *
	 * @return WP_User
	 */
	private function user() {
		return new WP_User(
			self::factory()->user->create(
				[
					'user_email'   => 'member@example.com',
					'display_name' => 'Mem & "ber"',
				]
			)
		);
	}

	/**
	 * Every built-in tag in plain mode.
	 *
	 * @return void
	 */
	public function test_every_tag_in_plain_mode() {
		update_option( 'blogname', 'My &amp; Site' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'UTC' );

		$user = $this->user();
		$ctx  = $this->ctx( [], [ 'user' => $user ] );

		$expect = [
			'{field:name}'        => 'Ada <b>Lovelace</b> & "co"',
			'{field:email}'       => 'ada@example.com',
			'{field:message}'     => "line one\nline two",
			'{field:colors}'      => 'red, blue',
			'{form:title}'        => 'Contact "us"',
			'{form:id}'           => self::FORM,
			'{entry:id}'          => '42',
			'{entry:date}'        => '2026-11-02 10:30',
			'{site:name}'         => 'My & Site',
			'{site:url}'          => home_url(),
			'{page:url}'          => 'https://example.org/contact/?a=1&b=2',
			'{page:title}'        => 'Contact <us>',
			'{user:id}'           => (string) $user->ID,
			'{user:email}'        => 'member@example.com',
			'{user:display_name}' => $user->display_name,
		];
		foreach ( $expect as $tag => $value ) {
			$this->assertSame( $value, Merge_Tags::replace( $tag, $ctx, 'plain' ), $tag );
		}
		$this->assertSame( 'Hi Ada, 42', Merge_Tags::replace( 'Hi {field:name}, {entry:id}', $this->ctx( [ 'name' => 'Ada' ] ), 'plain' ) );
	}

	/**
	 * A guest has no user tags.
	 *
	 * @return void
	 */
	public function test_guest_user_tags_are_empty() {
		$this->assertSame( '[][][]', Merge_Tags::replace( '[{user:id}][{user:email}][{user:display_name}]', $this->ctx(), 'plain' ) );
	}

	/**
	 * Unknown tags are left exactly as written; so are fields the form does not have.
	 *
	 * @return void
	 */
	public function test_unknown_tags_are_verbatim() {
		$template = '{nope} {field:missing} {FIELD:name} { field:name } {site} {form:} {a:b:c} {{field:email}} {';
		$this->assertSame( '{nope} {field:missing} {FIELD:name} { field:name } {site} {form:} {a:b:c} {ada@example.com} {', Merge_Tags::replace( $template, $this->ctx(), 'plain' ) );
	}

	/**
	 * Substitution is single-pass: a visitor value that looks like a tag stays literal.
	 *
	 * @return void
	 */
	public function test_single_pass() {
		$nasty = '{user:email} {field:email} {all_fields} {entry:id} {site:name}';
		$ctx   = $this->ctx(
			[
				'name'    => $nasty,
				'message' => '{field:name}',
			],
			[ 'user' => $this->user() ]
		);

		foreach ( [ 'plain', 'html', 'attr', 'header', 'url' ] as $mode ) {
			$out = Merge_Tags::replace( 'X {field:name} / {field:message} Y', $ctx, $mode );
			$this->assertStringNotContainsString( 'member@example.com', $out, $mode );
			$this->assertStringNotContainsString( 'ada@example.com', $out, $mode );
			$this->assertStringNotContainsString( '<table', $out, $mode );
		}
		$this->assertSame( 'X ' . $nasty . ' / {field:name} Y', Merge_Tags::replace( 'X {field:name} / {field:message} Y', $ctx, 'plain' ) );

		// Also inside an HTML document.
		$html = Merge_Tags::replace( '<p>{field:name}</p><a title="{field:name}">x</a>', $ctx, 'html' );
		$this->assertStringContainsString( '<p>{user:email} {field:email} {all_fields} {entry:id} {site:name}</p>', $html );
		$this->assertStringNotContainsString( 'member@example.com', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	/**
	 * The filter's values are also substituted once.
	 *
	 * @return void
	 */
	public function test_filter_values_are_not_rescanned() {
		add_filter(
			'swiftforms_merge_tags',
			static function ( $tags ) {
				$tags['order:ref'] = '{field:email}';
				return $tags;
			}
		);
		$this->assertSame( '{field:email}', Merge_Tags::replace( '{order:ref}', $this->ctx(), 'plain' ) );
	}

	/**
	 * Text and attribute modes escape the value.
	 *
	 * @return void
	 */
	public function test_html_and_attr_modes() {
		$ctx = $this->ctx( [ 'name' => '<script>alert(1)</script> & "q" \'s\' &amp;' ] );
		$this->assertSame( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039; &amp;amp;', Merge_Tags::replace( '{field:name}', $ctx, 'html' ) );
		$this->assertSame( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039; &amp;amp;', Merge_Tags::replace( '{field:name}', $ctx, 'attr' ) );
		$this->assertSame( 'Hello &lt;b&gt;', Merge_Tags::replace( 'Hello {field:name}', $this->ctx( [ 'name' => '<b>' ] ), 'html' ) );
	}

	/**
	 * Plain mode normalises line endings; header mode removes CR and LF.
	 *
	 * @return void
	 */
	public function test_plain_and_header_modes() {
		$ctx = $this->ctx(
			[
				'name'    => "Ada\r\nBcc: evil@example.com\rX-Header: 1\0",
				'message' => "a\r\nb\rc",
			]
		);
		$this->assertSame( "a\nb\nc", Merge_Tags::replace( '{field:message}', $ctx, 'plain' ) );

		$header = Merge_Tags::replace( 'Subject: {field:name}', $ctx, 'header' );
		$this->assertStringNotContainsString( "\r", $header );
		$this->assertStringNotContainsString( "\n", $header );
		$this->assertStringNotContainsString( "\0", $header );
		$this->assertSame( 'Subject: Ada Bcc: evil@example.com X-Header: 1', $header );
	}

	/**
	 * URL mode percent-encodes.
	 *
	 * @return void
	 */
	public function test_url_mode() {
		$ctx = $this->ctx( [ 'name' => 'a b&c=d/é?#' ] );
		$this->assertSame( '?n=a%20b%26c%3Dd%2F%C3%A9%3F%23', Merge_Tags::replace( '?n={field:name}', $ctx, 'url' ) );
	}

	/**
	 * Markup: tags are replaced in text nodes and attribute values.
	 *
	 * @return void
	 */
	public function test_html_document_text_and_attributes() {
		$ctx  = $this->ctx( [ 'name' => '"><img src=x onerror=alert(1)>' ] );
		$html = Merge_Tags::replace( '<p class="hello">Hi {field:name}!</p><img alt="{field:name}" src="https://example.com/a.png"><div data-x=\'{form:title}\'>{form:title}</div>', $ctx, 'html' );

		$this->assertStringContainsString( '<p class="hello">Hi &quot;&gt;&lt;img src=x onerror=alert(1)&gt;!</p>', $html );
		$this->assertStringContainsString( 'alt="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '>Contact &quot;us&quot;</div>', $html );

		$processor = new WP_HTML_Tag_Processor( $html );
		$tags      = [];
		while ( $processor->next_tag() ) {
			$tags[] = $processor->get_tag();
		}
		$this->assertSame( [ 'P', 'IMG', 'DIV' ], $tags, 'No tag came out of a value.' );
	}

	/**
	 * Script, style, textarea and title content, and event attributes, are left alone.
	 *
	 * @return void
	 */
	public function test_script_style_and_event_attributes_are_untouched() {
		$ctx      = $this->ctx();
		$template = '<script>var n = "{field:name}";</script>'
			. '<style>.a:after{content:"{field:name}"}</style>'
			. '<textarea>{field:name}</textarea><title>{field:name}</title>'
			. '<a href="https://example.com/" onclick="alert(\'{field:name}\')" onmouseover="{field:email}" style="background:url({field:name})" srcset="{field:name} 1x">{field:email}</a>';

		$html = Merge_Tags::replace( $template, $ctx, 'html' );
		$this->assertStringContainsString( '<script>var n = "{field:name}";</script>', $html );
		$this->assertStringContainsString( '<style>.a:after{content:"{field:name}"}</style>', $html );
		$this->assertStringContainsString( '<textarea>{field:name}</textarea>', $html );
		$this->assertStringContainsString( '<title>{field:name}</title>', $html );
		$this->assertStringContainsString( 'onclick="alert(\'{field:name}\')"', $html );
		$this->assertStringContainsString( 'onmouseover="{field:email}"', $html );
		$this->assertStringContainsString( 'style="background:url({field:name})"', $html );
		$this->assertStringContainsString( 'srcset="{field:name} 1x"', $html );
		$this->assertStringContainsString( '>ada@example.com</a>', $html, 'The text node still works.' );
	}

	/**
	 * href and src: a whole-value tag needs an http(s) URL; query and fragment tags are encoded; the rest stays.
	 *
	 * @return void
	 */
	public function test_url_attributes() {
		$ctx = $this->ctx(
			[
				'name'    => 'javascript:alert(1)',
				'email'   => 'https://visitor.example/page?x=1&y=2',
				'message' => 'a b&c',
			]
		);

		$cases = [
			'<a href="{field:email}">x</a>'           => '<a href="https://visitor.example/page?x=1&#038;y=2">x</a>',
			'<a href="{field:name}">x</a>'            => '<a href="">x</a>',
			'<a href="https://example.com/?q={field:message}#{field:message}">x</a>' => '<a href="https://example.com/?q=a%20b%26c#a%20b%26c">x</a>',
			'<img src="{field:name}">'                => '<img src="">',
			'<a href="javascript:{field:name}">x</a>' => '<a href="javascript:{field:name}">x</a>',
			'<a href="https://{field:message}.example.com/">x</a>' => '<a href="https://{field:message}.example.com/">x</a>',
			'<a href="/{field:message}?a=1">x</a>'    => '<a href="/{field:message}?a=1">x</a>',
			'<a href="{nope}">x</a>'                  => '<a href="{nope}">x</a>',
			'<a href="{all_fields}">x</a>'            => '<a href="">x</a>',
		];
		foreach ( $cases as $template => $expected ) {
			$out = Merge_Tags::replace( $template, $ctx, 'html' );
			$this->assertSame( str_replace( '&#038;', '&amp;', $expected ), str_replace( '&#038;', '&amp;', $out ), $template );
		}
	}

	/**
	 * {all_fields} as HTML table (escaped), as plain lines, and inside markup.
	 *
	 * @return void
	 */
	public function test_all_fields() {
		$ctx = $this->ctx(
			[
				'name'    => '<b>x</b>',
				'message' => "a\nb",
			]
		);

		$table = Merge_Tags::replace( '{all_fields}', $ctx, 'html' );
		$this->assertStringStartsWith( '<table', $table );
		$this->assertStringContainsString( '<th', $table );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $table );
		$this->assertStringNotContainsString( '<b>x', $table );
		$this->assertMatchesRegularExpression( '#a<br>\s*b#', $table );
		$this->assertStringContainsString( '>Name<', $table );
		$this->assertStringContainsString( 'red, blue', $table );

		$plain = Merge_Tags::replace( '{all_fields}', $ctx, 'plain' );
		$this->assertSame( "Name: <b>x</b>\nEmail: ada@example.com\nMessage: a\nb\nColors: red, blue", $plain );

		$in_doc = Merge_Tags::replace( '<h1>New</h1>{all_fields}<p>{all_fields}</p>', $ctx, 'html' );
		$this->assertSame( 2, substr_count( $in_doc, '<table' ) );
		$this->assertStringNotContainsString( 'sf-all-fields', $in_doc );

		$in_attr = Merge_Tags::replace(
			'<a title="{all_fields}">x</a>',
			$this->ctx(
				[
					'name'    => 'N',
					'message' => 'm',
				]
			),
			'html'
		);
		$this->assertStringNotContainsString( '<table', $in_attr );
		$this->assertStringContainsString( 'Name: N', $in_attr );
	}

	/**
	 * Visitor-addressed mail: values cut to 500 characters, at most 50 fields.
	 *
	 * @return void
	 */
	public function test_visitor_addressed_limits() {
		$ctx = $this->ctx( [ 'message' => str_repeat( 'é', 800 ) ] );

		$long = Merge_Tags::replace( '{field:message}', $ctx, 'plain' );
		$this->assertSame( 800, mb_strlen( $long ) );
		$cut = Merge_Tags::replace( '{field:message}', $ctx, 'plain', true );
		$this->assertSame( 501, mb_strlen( $cut ) );
		$this->assertStringEndsWith( '…', $cut );

		$fields = [];
		$values = [];
		for ( $i = 1; $i <= 60; $i++ ) {
			$fields[]           = array_merge( [ 'type' => 'text' ], Field_Types::defaults( 'text' ), [ 'name' => 'f' . $i ] );
			$values[ 'f' . $i ] = 'v' . $i;
		}
		$schema = new Form_Schema(
			[
				'form_id' => self::FORM,
				'source'  => 'post:5',
				'fields'  => $fields,
			]
		);
		$big    = new Submission_Context( $schema, null, [ 'values' => $values ] );
		$this->assertSame( 60, substr_count( Merge_Tags::replace( '{all_fields}', $big, 'plain' ), "\n" ) + 1 );
		$this->assertSame( 50, substr_count( Merge_Tags::replace( '{all_fields}', $big, 'plain', true ), "\n" ) + 1 );
		$this->assertSame( 50, substr_count( Merge_Tags::replace( '{all_fields}', $big, 'html', true ), '<tr>' ) );
	}

	/**
	 * The filter adds and overrides tags.
	 *
	 * @return void
	 */
	public function test_filter() {
		$seen = [];
		add_filter(
			'swiftforms_merge_tags',
			static function ( $tags, $context, $mode ) use ( &$seen ) {
				$seen[]            = $mode;
				$tags['order:id']  = 'A<1>';
				$tags['site:name'] = 'Override';
				$tags['bad key']   = 'x';
				$tags['arr:x']     = [ 'no' ];
				$tags['num:x']     = 5;
				return $tags;
			},
			10,
			3
		);
		$ctx = $this->ctx();
		$this->assertSame( 'A<1> Override 5 {bad key} {arr:x}', Merge_Tags::replace( '{order:id} {site:name} {num:x} {bad key} {arr:x}', $ctx, 'plain' ) );
		$this->assertSame( 'A&lt;1&gt;', Merge_Tags::replace( '{order:id}', $ctx, 'html' ) );
		$this->assertSame( [ 'plain', 'html' ], $seen );

		remove_all_filters( 'swiftforms_merge_tags' );
		add_filter( 'swiftforms_merge_tags', '__return_null' );
		$this->assertSame( 'Contact "us"', Merge_Tags::replace( '{form:title}', $ctx, 'plain' ), 'A non-array return is ignored.' );
	}

	/**
	 * A template without braces comes back untouched; non-strings are empty.
	 *
	 * @return void
	 */
	public function test_trivial_templates() {
		$ctx = $this->ctx();
		$this->assertSame( '<p>Hi & bye</p>', Merge_Tags::replace( '<p>Hi & bye</p>', $ctx, 'html' ) );
		$this->assertSame( '', Merge_Tags::replace( null, $ctx, 'html' ) );
		$this->assertSame( '', Merge_Tags::replace( '', $ctx, 'html' ) );
		$this->assertSame( 'x ada@example.com', Merge_Tags::replace( 'x {field:email}', $ctx, 'bogus-mode' ), 'An unknown mode is plain.' );
		$this->assertSame( '<p>Hi', Merge_Tags::replace( '<p>Hi', $ctx, 'html' ), 'Broken markup survives.' );
		$this->assertSame( '<p>ada@example.com', Merge_Tags::replace( '<p>{field:email}', $ctx, 'html' ) );
	}

	/**
	 * Redirect URLs: tags only after the "?" or "#", percent-encoded, http(s) only.
	 *
	 * @return void
	 */
	public function test_redirect_url() {
		$ctx = $this->ctx(
			[
				'name'  => 'Ada & Bob/#1',
				'email' => 'ada@example.com',
			]
		);

		$this->assertSame(
			'https://example.com/thanks?name=Ada%20%26%20Bob%2F%231&e=ada%40example.com#hi-Ada%20%26%20Bob%2F%231',
			Merge_Tags::redirect_url( 'https://example.com/thanks?name={field:name}&e={field:email}#hi-{field:name}', $ctx )
		);
		$this->assertSame( 'https://example.com/thanks', Merge_Tags::redirect_url( ' https://example.com/thanks ', $ctx ) );
		$this->assertSame( 'http://example.com/a?b=c', Merge_Tags::redirect_url( 'http://example.com/a?b=c', $ctx ) );
		$this->assertSame( 'https://example.com/#x', Merge_Tags::redirect_url( 'https://example.com/#x', $ctx ) );
	}

	/**
	 * A tag in the scheme, host or path refuses the redirect (no visitor-controlled open redirect).
	 *
	 * @return void
	 */
	public function test_redirect_url_refuses_tags_before_the_query() {
		$ctx = $this->ctx(
			[
				'name'  => 'evil.com',
				'email' => 'https://evil.com',
			]
		);
		foreach ( [
			'{field:email}',
			'{field:email}/thanks',
			'https://{field:name}/thanks',
			'https://example.com/{field:name}',
			'https://example.com/{field:name}?a=1',
			'https://example.com{field:name}',
			'{site:url}/thanks',
			'{field:name}://example.com',
			'https://example.com/{unknown}?a=1',
			'https://exa{field:name}mple.com?q=1',
		] as $bad ) {
			$this->assertNull( Merge_Tags::redirect_url( $bad, $ctx ), $bad );
		}
	}

	/**
	 * Only absolute http(s) URLs come out.
	 *
	 * @return void
	 */
	public function test_redirect_url_scheme_check() {
		$ctx = $this->ctx();
		foreach ( [ 'javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'ftp://example.com/', '//example.com/x', '/relative/path', 'thanks', '', '   ', 'https://', 'http:///x', 'vbscript:x', 'java\nscript:alert(1)?{field:name}', 'javascript:alert(1)//?x={field:name}' ] as $bad ) {
			$this->assertNull( Merge_Tags::redirect_url( $bad, $ctx ), wp_json_encode( $bad ) );
		}
		$this->assertNull( Merge_Tags::redirect_url( null, $ctx ) );
		$this->assertNull( Merge_Tags::redirect_url( [ 'https://example.com' ], $ctx ) );
	}

	/**
	 * A value in the query cannot add parameters or break the URL.
	 *
	 * @return void
	 */
	public function test_redirect_url_values_cannot_inject() {
		$ctx = $this->ctx(
			[
				'name'  => "x&admin=1#frag\r\nLocation: https://evil.com",
				'email' => 'https://evil.com/',
			]
		);
		$url = Merge_Tags::redirect_url( 'https://example.com/ok?n={field:name}&u={field:email}', $ctx );
		$this->assertNotNull( $url );
		$parts = wp_parse_url( $url );
		$this->assertSame( 'example.com', $parts['host'] );
		parse_str( $parts['query'], $query );
		$this->assertSame( [ 'n', 'u' ], array_keys( $query ), 'Exactly the two parameters the author wrote.' );
		$this->assertSame( 'x&admin=1#fragLocation: https://evil.com', $query['n'], 'CR and LF are dropped by esc_url_raw().' );
		$this->assertArrayNotHasKey( 'fragment', $parts );
		$this->assertStringNotContainsString( "\n", $url );
	}

	/**
	 * Recipient tags resolve only to email fields of the same form.
	 *
	 * @return void
	 */
	public function test_recipient_tags() {
		$ctx = $this->ctx(
			[
				'name'  => 'victim@example.com',
				'email' => 'Ada@Example.com',
			]
		);

		$this->assertSame(
			[
				'addresses' => [ 'admin@example.org', 'Ada@Example.com' ],
				'visitor'   => [ 'Ada@Example.com' ],
			],
			Merge_Tags::recipient_tags( 'admin@example.org, {field:email}', $ctx )
		);

		// A text field, an unknown field, and non-field tags give nothing.
		$this->assertSame(
			[
				'addresses' => [],
				'visitor'   => [],
			],
			Merge_Tags::recipient_tags( '{field:name}, {field:missing}, {user:email}, {site:name}, {all_fields}', $ctx )
		);

		// Mixed text around a tag is not an address.
		$this->assertSame(
			[
				'addresses' => [],
				'visitor'   => [],
			],
			Merge_Tags::recipient_tags( 'x{field:email}', $ctx )
		);

		// Duplicates collapse (case-insensitively); the author's own address is not a visitor address.
		$this->assertSame(
			[
				'addresses' => [ 'ada@example.com' ],
				'visitor'   => [ 'Ada@Example.com' ], // The same mailbox also came from a visitor value, so it counts as visitor-addressed.
			],
			Merge_Tags::recipient_tags( 'ada@example.com; {field:email}', $ctx )
		);

		// "Name <address>" written by the author.
		$this->assertSame( [ 'admin@example.org' ], Merge_Tags::recipient_tags( 'Boss <admin@example.org>', $ctx )['addresses'] );

		// The filter does not apply to recipients.
		add_filter(
			'swiftforms_merge_tags',
			static function ( $tags ) {
				$tags['field:name'] = 'attacker@example.com';
				return $tags;
			}
		);
		$this->assertSame( [], Merge_Tags::recipient_tags( '{field:name}', $ctx )['addresses'] );
	}

	/**
	 * A visitor value that is not an address is never a recipient, whatever the field type was.
	 *
	 * @return void
	 */
	public function test_recipient_value_must_be_an_address() {
		$ctx = $this->ctx( [ 'email' => "a@example.com,b@example.com\nBcc: c@example.com" ] );
		$this->assertSame( [], Merge_Tags::recipient_tags( '{field:email}', $ctx )['addresses'] );
		$ctx = $this->ctx( [ 'email' => [ 'a@example.com' ] ] );
		$this->assertSame( [], Merge_Tags::recipient_tags( '{field:email}', $ctx )['addresses'] );
	}

	/**
	 * The email field must be an email field in the schema the value was validated against too (stale schema).
	 *
	 * @return void
	 */
	public function test_recipient_field_must_be_email_in_both_schemas() {
		$ctx = $this->ctx( [ 'name' => 'victim@example.com' ] );
		// The current form has "name" as text. A snapshot that once called it an email field must not make it a recipient.
		$old_fields             = $ctx->validation_schema->fields;
		$old_fields[0]['type']  = 'email';
		$stale                  = new Form_Schema(
			[
				'form_id' => self::FORM,
				'source'  => 'post:5',
				'fields'  => $old_fields,
			]
		);
		$ctx->validation_schema = $stale;
		$this->assertSame( [], Merge_Tags::recipient_tags( '{field:name}', $ctx )['addresses'] );
		unset( $ctx );
		$this->assertNotNull( Clock::now() );
	}
	/**
	 * An untitled form (the inserted default) uses the title the index shows: its source's title.
	 *
	 * @return void
	 */
	public function test_form_title_falls_back_to_the_source_title() {
		$form_id = '7766abde-1be7-4b89-9e61-00862767d945';
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Contact',
				'post_content' => '<!-- wp:swiftforms/form {"formId":"' . $form_id . '"} --><!-- wp:swiftforms/field-email {"label":"Email","name":"email"} /--><!-- /wp:swiftforms/form -->',
			]
		);
		\SwiftForms\Form_Index::index_source( 'post:' . $post_id );
		$schema = \SwiftForms\Schema_Resolver::resolve( $form_id, 'post:' . $post_id );
		$this->assertNotNull( $schema );
		$this->assertSame( '', $schema->title() );

		$ctx = new Submission_Context( $schema, null, [ 'values' => [ 'email' => 'a@example.com' ] ] );
		$this->assertSame( 'New submission: Contact', Merge_Tags::replace( 'New submission: {form:title}', $ctx, 'header' ) );
	}
}
