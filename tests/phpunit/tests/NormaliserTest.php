<?php
require_once dirname( __DIR__, 2 ) . '/user-configs/normaliser.php';

/**
 * The user-config normaliser: one test per documented rule, plus idempotence.
 *
 * The rules are listed in the docblock of SF_Normaliser.
 */
class SwiftForms_Normaliser_Test extends PHPUnit\Framework\TestCase {

	public function test_html_line_endings() {
		$this->assertSame( "<p>a</p>\n<p>b</p>", SF_Normaliser::html( "<p>a</p>\r\n<p>b</p>" ) );
		$this->assertSame( "a\nb", SF_Normaliser::html( "a\rb" ) );
	}

	public function test_html_collapses_whitespace_between_tags_only() {
		$this->assertSame( "<div>\n<p>a  b</p>\n</div>", SF_Normaliser::html( "<div>\n\t  <p>a  b</p>\n\n   </div>" ) );
	}

	public function test_html_strips_ver_query_argument() {
		$this->assertSame( '<script src="http://example.org/a.js"></script>', SF_Normaliser::html( '<script src="http://example.org/a.js?ver=7.1.4"></script>' ) );
		$this->assertSame( '<link href="http://example.org/a.css?x=1">', SF_Normaliser::html( '<link href="http://example.org/a.css?x=1&#038;ver=1.2.3">' ) );
		$this->assertSame( '<link href="http://example.org/a.css?x=1&amp;y=2">', SF_Normaliser::html( '<link href="http://example.org/a.css?ver=9&amp;x=1&amp;y=2">' ) );
		$this->assertSame( '<a href="?server=1">', SF_Normaliser::html( '<a href="?server=1">' ), 'Only the ver argument is removed.' );
	}

	public function test_html_hashed_layout_classes() {
		$this->assertSame( 'wp-block-group wp-container-{HASH}', SF_Normaliser::html( 'wp-block-group wp-container-61a3b2c1' ) );
		$this->assertSame( 'wp-container-{HASH} is-layout-flow', SF_Normaliser::html( 'wp-container-core-group-is-layout-9f0d2b3c is-layout-flow' ) );
		$this->assertSame( 'wp-elements-{HASH}', SF_Normaliser::html( 'wp-elements-0123456789abcdef0123456789abcdef' ) );
		$this->assertSame( 'wp-block-group-is-layout-flow', SF_Normaliser::html( 'wp-block-group-is-layout-flow' ), 'Stable classes are kept.' );
		$this->assertSame( '<style id="wp-block-library-css">', SF_Normaliser::html( '<style id="wp-block-library-css">' ), 'Handle ids are kept.' );
	}

	public function test_html_nonces() {
		$this->assertSame( '<a href="/x?_wpnonce={NONCE}">', SF_Normaliser::html( '<a href="/x?_wpnonce=0a1b2c3d4e">' ) );
		$this->assertSame( '<input type="hidden" name="_wpnonce" value="{NONCE}">', SF_Normaliser::html( '<input type="hidden" name="_wpnonce" value="0a1b2c3d4e">' ) );
		$this->assertSame( '<input value="{NONCE}" name="_wpnonce">', SF_Normaliser::html( '<input value="0a1b2c3d4e" name="_wpnonce">' ) );
		$this->assertSame( '<div data-nonce="{NONCE}">', SF_Normaliser::html( '<div data-nonce="0a1b2c3d4e">' ) );
		$this->assertSame( '<div data-other="0a1b2c3d4e">', SF_Normaliser::html( '<div data-other="0a1b2c3d4e">' ), 'Only nonce attributes change.' );
	}

	public function test_html_tokens() {
		$this->assertSame( '<input value="{TOKEN}">', SF_Normaliser::html( '<input value="v1.lq3x9k.0123456789abcdef0123456789abcdef">' ) );
		$this->assertSame( 'v2.lq3x9k.0123456789abcdef0123456789abcdef', SF_Normaliser::html( 'v2.lq3x9k.0123456789abcdef0123456789abcdef' ), 'Only the v1 shape is a token.' );
	}

	public function test_html_removes_the_core_paragraph_class() {
		$this->assertSame( '<p>a</p>', SF_Normaliser::html( '<p class="wp-block-paragraph">a</p>' ) );
		$this->assertSame( '<p class="has-large-font-size">a</p>', SF_Normaliser::html( '<p class="has-large-font-size wp-block-paragraph">a</p>' ) );
		$this->assertSame( '<p class="wp-block-paragraph-x">a</p>', SF_Normaliser::html( '<p class="wp-block-paragraph-x">a</p>' ), 'Only the exact class is removed.' );
	}

	public function test_html_keeps_the_test_home_url() {
		$this->assertSame( '<a href="http://example.org/contact/">', SF_Normaliser::html( '<a href="http://example.org/contact/">' ) );
	}

	public function test_html_trims_the_edges() {
		$this->assertSame( '<p>a</p>', SF_Normaliser::html( "\n  <p>a</p>\n\n" ) );
	}

	public function test_json_is_pretty_ordered_and_unescaped() {
		$expected = "{\n    \"z\": \"http://example.org/\",\n    \"a\": \"\u{00FC}\"\n}\n";
		$this->assertSame(
			$expected,
			SF_Normaliser::json(
				[
					'z' => 'http://example.org/',
					'a' => "\u{00FC}",
				]
			)
		);
	}

	public function test_text_trims_trailing_whitespace_per_line() {
		$this->assertSame( "a\n\nb\n", SF_Normaliser::text( "a  \r\n\t\nb \t\n" ) );
	}

	/**
	 * Every rule is idempotent.
	 *
	 * @dataProvider samples
	 *
	 * @param string $input Raw input.
	 */
	public function test_idempotence( $input ) {
		$html = SF_Normaliser::html( $input );
		$this->assertSame( $html, SF_Normaliser::html( $html ) );
		$text = SF_Normaliser::text( $input );
		$this->assertSame( $text, SF_Normaliser::text( $text ) );
	}

	/**
	 * Inputs that touch every rule.
	 *
	 * @return array<string, array{string}>
	 */
	public function samples() {
		return [
			'everything' => [ "<div class=\"wp-container-core-group-is-layout-9f0d2b3c\">\r\n  <a href=\"/x?ver=1&amp;_wpnonce=0a1b2c3d4e\"> </a>\n<input name=\"_wpnonce\" value=\"0a1b2c3d4e\"> <b data-nonce=\"0a1b2c3d4e\">v1.abc.0123456789abcdef0123456789abcdef</b>  \n</div>  " ],
			'ver only'   => [ '<script src="a.js?ver=1.2"></script> <link href="b.css?a=1&ver=2&b=3">' ],
			'paragraph'  => [ '<p class="x wp-block-paragraph">a</p> <p class="wp-block-paragraph">b</p>' ],
			'plain'      => [ "just text  \n\n" ],
		];
	}
}
