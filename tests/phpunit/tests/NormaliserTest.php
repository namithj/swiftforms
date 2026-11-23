<?php
require_once dirname( __DIR__, 2 ) . '/user-configs/normaliser.php';

/**
 * The user-config normaliser: one test per documented rule, plus idempotence.
 *
 * The rules are listed in the docblock of BF_Normaliser.
 */
class BlockForms_Normaliser_Test extends PHPUnit\Framework\TestCase {

	public function test_html_line_endings() {
		$this->assertSame( "<p>a</p>\n<p>b</p>", BF_Normaliser::html( "<p>a</p>\r\n<p>b</p>" ) );
		$this->assertSame( "a\nb", BF_Normaliser::html( "a\rb" ) );
	}

	public function test_html_collapses_whitespace_between_tags_only() {
		$this->assertSame( "<div>\n<p>a  b</p>\n</div>", BF_Normaliser::html( "<div>\n\t  <p>a  b</p>\n\n   </div>" ) );
	}

	public function test_html_strips_ver_query_argument() {
		$this->assertSame( '<script src="http://example.org/a.js"></script>', BF_Normaliser::html( '<script src="http://example.org/a.js?ver=7.1.4"></script>' ) );
		$this->assertSame( '<link href="http://example.org/a.css?x=1">', BF_Normaliser::html( '<link href="http://example.org/a.css?x=1&#038;ver=1.2.3">' ) );
		$this->assertSame( '<link href="http://example.org/a.css?x=1&amp;y=2">', BF_Normaliser::html( '<link href="http://example.org/a.css?ver=9&amp;x=1&amp;y=2">' ) );
		$this->assertSame( '<a href="?server=1">', BF_Normaliser::html( '<a href="?server=1">' ), 'Only the ver argument is removed.' );
	}

	public function test_html_hashed_layout_classes() {
		$this->assertSame( 'wp-block-group wp-container-{HASH}', BF_Normaliser::html( 'wp-block-group wp-container-61a3b2c1' ) );
		$this->assertSame( 'wp-container-{HASH} is-layout-flow', BF_Normaliser::html( 'wp-container-core-group-is-layout-9f0d2b3c is-layout-flow' ) );
		$this->assertSame( 'wp-elements-{HASH}', BF_Normaliser::html( 'wp-elements-0123456789abcdef0123456789abcdef' ) );
		$this->assertSame( 'wp-block-group-is-layout-flow', BF_Normaliser::html( 'wp-block-group-is-layout-flow' ), 'Stable classes are kept.' );
		$this->assertSame( '<style id="wp-block-library-css">', BF_Normaliser::html( '<style id="wp-block-library-css">' ), 'Handle ids are kept.' );
	}

	public function test_html_nonces() {
		$this->assertSame( '<a href="/x?_wpnonce={NONCE}">', BF_Normaliser::html( '<a href="/x?_wpnonce=0a1b2c3d4e">' ) );
		$this->assertSame( '<input type="hidden" name="_wpnonce" value="{NONCE}">', BF_Normaliser::html( '<input type="hidden" name="_wpnonce" value="0a1b2c3d4e">' ) );
		$this->assertSame( '<input value="{NONCE}" name="_wpnonce">', BF_Normaliser::html( '<input value="0a1b2c3d4e" name="_wpnonce">' ) );
		$this->assertSame( '<div data-nonce="{NONCE}">', BF_Normaliser::html( '<div data-nonce="0a1b2c3d4e">' ) );
		$this->assertSame( '<div data-other="0a1b2c3d4e">', BF_Normaliser::html( '<div data-other="0a1b2c3d4e">' ), 'Only nonce attributes change.' );
	}

	public function test_html_tokens() {
		$this->assertSame( '<input value="{TOKEN}">', BF_Normaliser::html( '<input value="v1.lq3x9k.0123456789abcdef0123456789abcdef">' ) );
		$this->assertSame( 'v2.lq3x9k.0123456789abcdef0123456789abcdef', BF_Normaliser::html( 'v2.lq3x9k.0123456789abcdef0123456789abcdef' ), 'Only the v1 shape is a token.' );
	}

	public function test_html_signed_render_times() {
		$this->assertSame( '<input type="hidden" name="_bf_t" value="{T}">', BF_Normaliser::html( '<input type="hidden" name="_bf_t" value="1795082400.0123456789abcdef">' ) );
		$this->assertSame( '<input value="{T}" name="_bf_t">', BF_Normaliser::html( '<input value="1795082400.0123456789abcdef" name="_bf_t">' ) );
		$this->assertSame( '<input name="_bf_t" value="js.10000">', BF_Normaliser::html( '<input name="_bf_t" value="js.10000">' ), 'Only the signed shape is a render time.' );
		$this->assertSame( '<input name="other" value="1795082400.0123456789abcdef">', BF_Normaliser::html( '<input name="other" value="1795082400.0123456789abcdef">' ), 'Only the _bf_t control changes.' );
	}

	public function test_html_result_keys() {
		$this->assertSame( '<a href="http://example.org/contact/?bf_result={RESULT}">', BF_Normaliser::html( '<a href="http://example.org/contact/?bf_result=0123456789abcdef0123456789abcdef">' ) );
		$this->assertSame( 'bf_result=0123456789abcdef', BF_Normaliser::html( 'bf_result=0123456789abcdef' ), 'Only a 32-character key is a result key.' );
	}

	public function test_html_plugin_version_control() {
		$this->assertSame( '<input type="hidden" name="_bf_v" value="{VERSION}">', BF_Normaliser::html( '<input type="hidden" name="_bf_v" value="1.0.0">' ) );
		$this->assertSame( '<input type="hidden" name="_bf_v" value="{VERSION}">', BF_Normaliser::html( '<input type="hidden" name="_bf_v" value="0.1.0-beta.2">' ) );
		$this->assertSame( '<input name="x" value="1.0.0">', BF_Normaliser::html( '<input name="x" value="1.0.0">' ), 'Only the _bf_v control changes.' );
	}

	public function test_html_emoji_image_version() {
		$this->assertSame( '<img src="https://s.w.org/images/core/emoji/{EMOJI}/72x72/1f680.png" alt="x">', BF_Normaliser::html( '<img src="https://s.w.org/images/core/emoji/17.0.2/72x72/1f680.png" alt="x">' ) );
		$this->assertSame( '<img src="https://s.w.org/images/core/emoji/{EMOJI}/svg/1f680.svg">', BF_Normaliser::html( '<img src="https://s.w.org/images/core/emoji/16.0.0/svg/1f680.svg">' ) );
		$this->assertSame( '<img src="https://example.org/emoji/17.0.2/a.png">', BF_Normaliser::html( '<img src="https://example.org/emoji/17.0.2/a.png">' ), 'Only core emoji images change.' );
	}

	public function test_client_state_nonce() {
		$this->assertSame( '{"restUrl":"http://example.org/wp-json/","nonce": "{NONCE}"}', BF_Normaliser::tokens( '{"restUrl":"http://example.org/wp-json/","nonce": "88575b2429"}' ) );
		$this->assertSame( '{"nonce":"{NONCE}"}', BF_Normaliser::html( '{"nonce":"88575b2429"}' ) );
		$this->assertSame( '{"nonce":"short"}', BF_Normaliser::tokens( '{"nonce":"short"}' ), 'Only a ten-digit hex nonce changes.' );
	}

	public function test_tokens_for_text_that_is_not_html() {
		$this->assertSame( '{"token":"{TOKEN}"}', BF_Normaliser::tokens( '{"token":"v1.lq3x9k.0123456789abcdef0123456789abcdef"}' ) );
		$this->assertSame( 'Location: http://example.org/a/?bf_result={RESULT}', BF_Normaliser::tokens( 'Location: http://example.org/a/?bf_result=0123456789abcdef0123456789abcdef' ) );
		$this->assertSame( 'plain 1.0.0', BF_Normaliser::tokens( 'plain 1.0.0' ) );
	}

	public function test_scrub_replaces_literal_aliases_longest_first() {
		$this->assertSame(
			'post:about post:contact',
			BF_Normaliser::scrub(
				'post:12 post:123',
				[
					'post:12'  => 'post:about',
					'post:123' => 'post:contact',
				]
			)
		);
		$this->assertSame(
			'post:contact',
			BF_Normaliser::scrub(
				'post:123',
				[
					'post:12'  => 'post:about',
					'post:123' => 'post:contact',
				]
			),
			'The longer value wins over its own prefix.'
		);
		$this->assertSame( 'unchanged', BF_Normaliser::scrub( 'unchanged', [] ) );
	}

	public function test_html_removes_the_core_paragraph_class() {
		$this->assertSame( '<p>a</p>', BF_Normaliser::html( '<p class="wp-block-paragraph">a</p>' ) );
		$this->assertSame( '<p class="has-large-font-size">a</p>', BF_Normaliser::html( '<p class="has-large-font-size wp-block-paragraph">a</p>' ) );
		$this->assertSame( '<p class="wp-block-paragraph-x">a</p>', BF_Normaliser::html( '<p class="wp-block-paragraph-x">a</p>' ), 'Only the exact class is removed.' );
	}

	public function test_html_removes_the_anchor_of_a_form_element() {
		$this->assertSame( '<form class="blockforms-form a" method="post">', BF_Normaliser::html( '<form class="blockforms-form a" id="hero" method="post">' ) );
		$this->assertSame( '<div class="blockforms-form blockforms-form--submitted">', BF_Normaliser::html( '<div class="blockforms-form blockforms-form--submitted" id="hero">' ) );
		$this->assertSame( '<div class="other" id="hero"><input id="x">', BF_Normaliser::html( '<div class="other" id="hero"><input id="x">' ), 'Other elements keep their id.' );
	}

	public function test_html_keeps_the_test_home_url() {
		$this->assertSame( '<a href="http://example.org/contact/">', BF_Normaliser::html( '<a href="http://example.org/contact/">' ) );
	}

	public function test_html_trims_the_edges() {
		$this->assertSame( '<p>a</p>', BF_Normaliser::html( "\n  <p>a</p>\n\n" ) );
	}

	public function test_json_is_pretty_ordered_and_unescaped() {
		$expected = "{\n    \"z\": \"http://example.org/\",\n    \"a\": \"\u{00FC}\"\n}\n";
		$this->assertSame(
			$expected,
			BF_Normaliser::json(
				[
					'z' => 'http://example.org/',
					'a' => "\u{00FC}",
				]
			)
		);
	}

	public function test_text_trims_trailing_whitespace_per_line() {
		$this->assertSame( "a\n\nb\n", BF_Normaliser::text( "a  \r\n\t\nb \t\n" ) );
	}

	/**
	 * Every rule is idempotent.
	 *
	 * @dataProvider samples
	 *
	 * @param string $input Raw input.
	 */
	public function test_idempotence( $input ) {
		$html = BF_Normaliser::html( $input );
		$this->assertSame( $html, BF_Normaliser::html( $html ) );
		$text = BF_Normaliser::text( $input );
		$this->assertSame( $text, BF_Normaliser::text( $text ) );
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
			'controls'   => [ '<input name="_bf_t" value="1795082400.0123456789abcdef"><input name="_bf_v" value="1.0.0"><a href="?bf_result=0123456789abcdef0123456789abcdef">' ],
			'emoji'      => [ '<img src="https://s.w.org/images/core/emoji/17.0.2/72x72/1f680.png" alt="x"> <p>a</p>' ],
			'plain'      => [ "just text  \n\n" ],
		];
	}
}
