<?php
/**
 * Tests for SwiftForms\Source_Ref.
 *
 * @package SwiftForms
 */

use SwiftForms\Source_Ref;

/**
 * Class SourceRefTest
 *
 * @group resolver
 */
class SourceRefTest extends WP_UnitTestCase {

	/**
	 * Every valid kind parses and formats back to the same string.
	 *
	 * @return void
	 */
	public function test_round_trips() {
		$refs = [
			'post:123',
			'post:1',
			'template:twentytwentyfive//single',
			'template:my-theme.child//page-about_us',
			'template_part:twentytwentyfive//header',
			'pattern:twentytwentyfive/hero',
			'pattern:my-pattern',
			'pattern:acme/sub/deep.name',
			'widget:block-3',
		];
		foreach ( $refs as $ref ) {
			$parsed = Source_Ref::parse( $ref );
			$this->assertNotNull( $parsed, $ref );
			$this->assertTrue( Source_Ref::is_valid( $ref ) );
			$this->assertSame( $ref, $parsed['type'] . ':' . $parsed['id'], $ref );
		}

		$this->assertSame( 'post:123', Source_Ref::post( 123 ) );
		$this->assertSame( 'template:t//s', Source_Ref::template( 't', 's' ) );
		$this->assertSame( 'template_part:t//s', Source_Ref::template_part( 't', 's' ) );
		$this->assertSame( 'pattern:a/b', Source_Ref::pattern( 'a/b' ) );
		$this->assertSame( 'widget:block-9', Source_Ref::widget( 9 ) );
		$this->assertSame( 'template:t//s', Source_Ref::from_template_id( 'template', 't//s' ) );
	}

	/**
	 * Parsed parts.
	 *
	 * @return void
	 */
	public function test_parsed_parts() {
		$this->assertSame( 77, Source_Ref::parse( 'post:77' )['post_id'] );
		$template = Source_Ref::parse( 'template:abc//def' );
		$this->assertSame( 'abc', $template['theme'] );
		$this->assertSame( 'def', $template['slug'] );
		$this->assertSame( 'abc//def', $template['id'] );
		$this->assertSame( 5, Source_Ref::parse( 'widget:block-5' )['number'] );
	}

	/**
	 * Anything that is not exactly one of the five shapes is rejected.
	 *
	 * @return void
	 */
	public function test_rejects() {
		$bad = [
			'',
			'post:',
			'post:0',
			'post:012',
			'post:-1',
			'post:1.5',
			'post:1 ',
			"post:1\n",
			' post:1',
			'POST:1',
			'post:99999999999999999999',
			'page:1',
			'template:theme',
			'template:theme//',
			'template://slug',
			'template:a//b//c',
			'template:a b//c',
			'template:a/b//c',
			'template_part:t/s',
			'pattern:',
			'pattern:/x',
			'pattern:a//b',
			'pattern:a b',
			'widget:block-0',
			'widget:block-',
			'widget:text-2',
			'widget:block-01',
			'post:1;DROP',
			str_repeat( 'a', 300 ),
			'pattern:' . str_repeat( 'a', 200 ),
		];
		foreach ( $bad as $ref ) {
			$this->assertNull( Source_Ref::parse( $ref ), wp_json_encode( $ref ) );
			$this->assertFalse( Source_Ref::is_valid( $ref ) );
		}
		foreach ( [ null, 5, [], true ] as $ref ) {
			$this->assertNull( Source_Ref::parse( $ref ) );
		}
		$this->assertNull( Source_Ref::post( 0 ) );
		$this->assertNull( Source_Ref::template( 'a b', 'c' ) );
		$this->assertNull( Source_Ref::widget( 0 ) );
		$this->assertNull( Source_Ref::from_template_id( 'pattern', 'a//b' ) );
	}
}
