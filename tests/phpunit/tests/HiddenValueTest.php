<?php
/**
 * Tests for SwiftForms\Hidden_Value.
 *
 * @package SwiftForms
 */

use SwiftForms\Hidden_Value;

/**
 * Class HiddenValueTest
 *
 * @group blocks
 */
class HiddenValueTest extends WP_UnitTestCase {

	/**
	 * Static text is returned as it is, tokens included in other braces are kept.
	 *
	 * @return void
	 */
	public function test_static_and_unknown_text() {
		$this->assertSame( '', Hidden_Value::resolve( '', 'post:1' ) );
		$this->assertSame( 'spring-2026', Hidden_Value::resolve( 'spring-2026', 'post:1' ) );
		$this->assertSame( '{field:email} {post} {POST:ID} {user}', Hidden_Value::resolve( '{field:email} {post} {POST:ID} {user}', 'post:1' ) );
	}

	/**
	 * The four v1 tokens of a post source.
	 *
	 * @return void
	 */
	public function test_tokens_of_a_post_source() {
		$post_id = self::factory()->post->create(
			[
				'post_title' => 'A <i>title</i>',
				'post_name'  => 'a-title',
			]
		);
		$ref     = 'post:' . $post_id;

		$this->assertSame( (string) $post_id, Hidden_Value::resolve( '{post:id}', $ref ) );
		$this->assertSame( 'A title', Hidden_Value::resolve( '{post:title}', $ref ) );
		$this->assertSame( get_permalink( $post_id ), Hidden_Value::resolve( '{post:url}', $ref ) );
		$this->assertSame( '7', Hidden_Value::resolve( '{user:id}', $ref, 7 ) );
		$this->assertSame( '', Hidden_Value::resolve( '{user:id}', $ref, 0 ) );
		$this->assertSame( "#$post_id|A title|7", Hidden_Value::resolve( '#{post:id}|{post:title}|{user:id}', $ref, 7 ) );
	}

	/**
	 * A single pass: a title that looks like a token is not expanded again.
	 *
	 * @return void
	 */
	public function test_single_pass() {
		$post_id = self::factory()->post->create( [ 'post_title' => '{user:id}' ] );
		$this->assertSame( '{user:id} / 5', Hidden_Value::resolve( '{post:title} / {user:id}', 'post:' . $post_id, 5 ) );
	}

	/**
	 * Sources without a post, and posts that do not exist, give empty post tokens.
	 *
	 * @return void
	 */
	public function test_non_post_sources() {
		foreach ( [ 'template:twentytwentyfive//home', 'template_part:twentytwentyfive//footer', 'pattern:swiftforms/contact', 'widget:block-3', '', 'garbage', 'post:999999' ] as $ref ) {
			$this->assertSame( '[]', Hidden_Value::resolve( '[{post:id}{post:title}{post:url}]', $ref ), $ref );
		}
		$this->assertSame( '4', Hidden_Value::resolve( '{user:id}', 'widget:block-3', 4 ), 'The user token does not need a post.' );
	}
}
