<?php
/**
 * Tests for SwiftForms\Block_Walker.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-resolver-test-case.php';

use SwiftForms\Block_Walker;

/**
 * Class BlockWalkerTest
 *
 * @group resolver
 */
class BlockWalkerTest extends SF_Resolver_Test_Case {

	/**
	 * Sources and ordinals of the forms a walk found, as "source#ordinal".
	 *
	 * @param array $walk Result of Block_Walker::walk().
	 * @return string[]
	 */
	private function found( array $walk ) {
		$found = [];
		foreach ( $walk['forms'] as $form ) {
			$found[] = $form['source'] . '#' . $form['ordinal'];
		}
		sort( $found );
		return $found;
	}

	/**
	 * Forms are found in document order at any depth, with 1-based ordinals.
	 *
	 * @return void
	 */
	public function test_forms_in_document_order() {
		$markup = '<!-- wp:group --><div>'
			. $this->form_markup( self::ID_A )
			. '<!-- wp:columns --><!-- wp:column -->'
			. $this->form_markup( self::ID_B )
			. '<!-- /wp:column --><!-- /wp:columns -->'
			. '</div><!-- /wp:group -->'
			. $this->form_markup( self::ID_C );

		$forms = Block_Walker::forms( parse_blocks( $markup ) );
		$this->assertCount( 3, $forms );
		$this->assertSame( [ 1, 2, 3 ], wp_list_pluck( $forms, 'ordinal' ) );
		$this->assertSame(
			[ self::ID_A, self::ID_B, self::ID_C ],
			array_map(
				static function ( $form ) {
					return $form['block']['attrs']['formId'];
				},
				$forms
			)
		);
	}

	/**
	 * Unknown block names are walked (C-2.8): a form inside a newer wrapper is still found.
	 *
	 * @return void
	 */
	public function test_unknown_wrappers_are_walked() {
		$markup = '<!-- wp:acme/future-wrapper {"x":1} --><!-- wp:acme/inner -->'
			. $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) )
			. '<!-- /wp:acme/inner --><!-- /wp:acme/future-wrapper -->';

		$forms = Block_Walker::forms( parse_blocks( $markup ) );
		$this->assertCount( 1, $forms );

		$seen = [];
		Block_Walker::each_block(
			parse_blocks( $markup ),
			static function ( $block ) use ( &$seen ) {
				$seen[] = $block['blockName'];
			}
		);
		$this->assertSame( [ 'acme/future-wrapper', 'acme/inner', 'swiftforms/form', 'swiftforms/field-text' ], array_values( array_filter( $seen ) ) );
	}

	/**
	 * The references of the three pulling blocks, and nothing for bad attributes.
	 *
	 * @return void
	 */
	public function test_reference_of() {
		$this->make_theme( 'sf-w1' );
		$ref = static function ( $markup ) {
			return Block_Walker::reference_of( parse_blocks( $markup )[0] );
		};

		$this->assertSame( 'post:7', $ref( '<!-- wp:block {"ref":7} /-->' ) );
		$this->assertSame( 'post:7', $ref( '<!-- wp:block {"ref":"7"} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:block {"ref":"seven"} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:block {"ref":0} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:block /-->' ) );
		$this->assertSame( 'template_part:sf-w1//header', $ref( '<!-- wp:template-part {"slug":"header"} /-->' ) );
		$this->assertSame( 'template_part:other//header', $ref( '<!-- wp:template-part {"slug":"header","theme":"other"} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:template-part /-->' ) );
		$this->assertNull( $ref( '<!-- wp:template-part {"slug":"bad slug"} /-->' ) );
		$this->assertSame( 'pattern:sf/hero', $ref( '<!-- wp:pattern {"slug":"sf/hero"} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:pattern {"slug":"sf hero"} /-->' ) );
		$this->assertNull( $ref( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertNull( $ref( '<!-- wp:post-content /-->' ) );
	}

	/**
	 * A form inside a synced pattern belongs to the pattern's post, and the page references it.
	 *
	 * @return void
	 */
	public function test_expansion_attributes_nested_forms_to_their_source() {
		$synced = $this->make_post( $this->form_markup( self::ID_A ), [ 'post_type' => 'wp_block' ] );
		$page   = $this->make_post( $this->form_markup( self::ID_B ) . '<!-- wp:block {"ref":' . $synced . '} /-->' );
		$ref    = 'post:' . $page;

		$walk = Block_Walker::walk( $ref, parse_blocks( $this->stored_content( $page ) ), true );
		$this->assertEqualsCanonicalizing( [ $ref . '#1', 'post:' . $synced . '#1' ], $this->found( $walk ) );
		$this->assertSame( [ [ $ref, 'post:' . $synced ] ], $walk['edges'] );
		$this->assertEqualsCanonicalizing( [ $ref, 'post:' . $synced ], $walk['expanded'] );

		$flat = Block_Walker::walk( $ref, parse_blocks( $this->stored_content( $page ) ), false );
		$this->assertSame( [ $ref . '#1' ], $this->found( $flat ) );
		$this->assertSame( [ [ $ref, 'post:' . $synced ] ], $flat['edges'], 'Edges are listed even without expansion.' );
	}

	/**
	 * A wp_block that is not published is not entered (core renders nothing for it), but the edge is listed.
	 *
	 * @return void
	 */
	public function test_unpublished_synced_pattern_is_not_entered() {
		$synced = $this->make_post(
			$this->form_markup( self::ID_A ),
			[
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
			]
		);
		$page   = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' );

		$walk = Block_Walker::walk( 'post:' . $page, parse_blocks( $this->stored_content( $page ) ), true );
		$this->assertSame( [], $walk['forms'] );
		$this->assertSame( [ [ 'post:' . $page, 'post:' . $synced ] ], $walk['edges'] );
	}

	/**
	 * The same synced pattern shown twice yields its form once.
	 *
	 * @return void
	 */
	public function test_repeated_reference_is_deduplicated() {
		$synced = $this->make_post( $this->form_markup( self::ID_A ), [ 'post_type' => 'wp_block' ] );
		$ref    = '<!-- wp:block {"ref":' . $synced . '} /-->';
		$page   = $this->make_post( $ref . $ref . '<!-- wp:group -->' . $ref . '<!-- /wp:group -->' );

		$walk = Block_Walker::walk( 'post:' . $page, parse_blocks( $this->stored_content( $page ) ), true );
		$this->assertSame( [ 'post:' . $synced . '#1' ], $this->found( $walk ) );
		$this->assertCount( 1, $walk['edges'] );
	}

	/**
	 * Expansion goes five sources deep and stops there.
	 *
	 * @return void
	 */
	public function test_depth_limit_is_five() {
		// W6 holds a form; W1..W5 chain down to it; the page references W1.
		$chain    = [];
		$next     = $this->make_post( $this->form_markup( self::ID_C ), [ 'post_type' => 'wp_block' ] );
		$chain[6] = $next;
		for ( $level = 5; $level >= 1; $level-- ) {
			$inner           = $this->form_markup( 5 === $level ? self::ID_B : '' ) . '<!-- wp:block {"ref":' . $next . '} /-->';
			$next            = $this->make_post( $inner, [ 'post_type' => 'wp_block' ] );
			$chain[ $level ] = $next;
		}
		$page = $this->make_post( '<!-- wp:block {"ref":' . $chain[1] . '} /-->' );

		$walk = Block_Walker::walk( 'post:' . $page, parse_blocks( $this->stored_content( $page ) ), true );
		$this->assertContains( 'post:' . $chain[5] . '#1', $this->found( $walk ), 'The fifth level is entered.' );
		$this->assertNotContains( 'post:' . $chain[6] . '#1', $this->found( $walk ), 'The sixth level is not.' );
		$this->assertContains( [ 'post:' . $chain[5], 'post:' . $chain[6] ], $walk['edges'], 'But the edge to it is listed.' );
		$this->assertContains( 'post:' . $chain[5], $walk['expanded'] );
		$this->assertNotContains( 'post:' . $chain[6], $walk['expanded'] );
		$this->assertSame( Block_Walker::DEPTH_LIMIT, 5 );
	}

	/**
	 * A synced pattern that references itself, and two that reference each other, end the walk.
	 *
	 * @return void
	 */
	public function test_cycles_terminate() {
		$self = $this->make_post( 'placeholder', [ 'post_type' => 'wp_block' ] );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $self,
					'post_content' => $this->form_markup( self::ID_A ) . '<!-- wp:block {"ref":' . $self . '} /-->',
				]
			)
		);
		$walk = Block_Walker::walk( 'post:' . $self, parse_blocks( $this->stored_content( $self ) ), true );
		$this->assertSame( [ 'post:' . $self . '#1' ], $this->found( $walk ) );
		$this->assertSame( [ [ 'post:' . $self, 'post:' . $self ] ], $walk['edges'] );

		$a = $this->make_post( 'placeholder', [ 'post_type' => 'wp_block' ] );
		$b = $this->make_post( $this->form_markup( self::ID_B ) . '<!-- wp:block {"ref":' . $a . '} /-->', [ 'post_type' => 'wp_block' ] );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $a,
					'post_content' => $this->form_markup( self::ID_C ) . '<!-- wp:block {"ref":' . $b . '} /-->',
				]
			)
		);

		$walk = Block_Walker::walk( 'post:' . $a, parse_blocks( $this->stored_content( $a ) ), true );
		$this->assertEqualsCanonicalizing( [ 'post:' . $a . '#1', 'post:' . $b . '#1' ], $this->found( $walk ) );
		$this->assertCount( 2, $walk['edges'] );
	}

	/**
	 * Template parts of the active theme and registered patterns are entered; parts of another theme and unknown patterns are not.
	 *
	 * @return void
	 */
	public function test_template_parts_and_patterns() {
		$this->make_theme( 'sf-w2', [ 'parts/footer.html' => $this->form_markup( self::ID_A ) ] );
		$this->register_pattern( 'sf/contact', $this->form_markup( self::ID_B ) );

		$markup = '<!-- wp:template-part {"slug":"footer","theme":"sf-w2"} /-->'
			. '<!-- wp:template-part {"slug":"footer","theme":"other-theme"} /-->'
			. '<!-- wp:pattern {"slug":"sf/contact"} /-->'
			. '<!-- wp:pattern {"slug":"sf/unknown"} /-->';
		$walk   = Block_Walker::walk( 'template:sf-w2//index', parse_blocks( $markup ), true );

		$this->assertSame( [ 'pattern:sf/contact#1', 'template_part:sf-w2//footer#1' ], $this->found( $walk ) );
		$this->assertCount( 4, $walk['edges'] );
	}

	/**
	 * core/post-content is never expanded: it is the page being rendered.
	 *
	 * @return void
	 */
	public function test_post_content_is_not_followed() {
		$walk = Block_Walker::walk( 'template:t//single', parse_blocks( '<!-- wp:post-content /-->' ), true );
		$this->assertSame( [], $walk['forms'] );
		$this->assertSame( [], $walk['edges'] );
	}
}
