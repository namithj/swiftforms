<?php
/**
 * Tests for SwiftForms\Form_Id_Writer.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-resolver-test-case.php';

use SwiftForms\Form_Id_Writer;
use SwiftForms\Form_Index;
use SwiftForms\Uuid;

/**
 * Class FormIdWriterTest
 *
 * @group index
 * @group resolver
 */
class FormIdWriterTest extends SF_Resolver_Test_Case {

	/**
	 * Save as a user who may post unfiltered HTML, so kses does not touch the fixtures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Save new content in a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Raw content.
	 * @return void
	 */
	private function save( $post_id, $content ) {
		$result = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => $content,
				]
			),
			true
		);
		$this->assertIsInt( $result );
	}

	/**
	 * Number of error rows in the diagnostics log.
	 *
	 * @return int
	 */
	private function error_rows() {
		return count(
			array_filter(
				$this->rows( 'log' ),
				static function ( $row ) {
					return 'error' === $row['type'] && 'form-id' === $row['ref'];
				}
			)
		);
	}

	/**
	 * A copy's stored ID is replaced by its effective ID on the next save, and nothing else in the content changes.
	 *
	 * @return void
	 */
	public function test_copy_is_rewritten_and_owner_is_not() {
		$content = '<!-- wp:paragraph --><p>Intro "quoted" é 日本 \\ slash</p><!-- /wp:paragraph -->' . "\n"
			. $this->form_markup(
				self::ID_A,
				$this->field_markup(
					'text',
					[
						'name'  => 'who',
						'label' => 'Name "x" é',
					]
				),
				[ 'title' => 'Tëst' ]
			) . "\n"
			. '<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->';

		$owner = $this->make_post( $content );
		$copy  = $this->make_post( $content );
		$this->assertSame( $content, $this->stored_content( $copy ), 'New inserts are not rewritten (no ID yet).' );

		$expected_id = Form_Index::copy_id( self::ID_A, 'post:' . $copy );
		$this->assertSame( $expected_id, Form_Index::ensure( self::ID_A, 'post:' . $copy, 1 ), 'Render already uses the effective ID.' );

		$this->save( $copy, $content );
		$this->assertSame( str_replace( '"formId":"' . self::ID_A . '"', '"formId":"' . $expected_id . '"', $content ), $this->stored_content( $copy ) );

		$this->save( $owner, $content . ' ' );
		$this->assertSame( $content . ' ', $this->stored_content( $owner ), 'The owner\'s stored ID is never rewritten.' );

		// Saving again changes nothing more, and render and save agree.
		$stored = $this->stored_content( $copy );
		$this->save( $copy, $stored );
		$this->assertSame( $stored, $this->stored_content( $copy ) );
		$this->assertSame( $expected_id, Form_Index::ensure( $expected_id, 'post:' . $copy, 1 ) );
		$this->assertSame( 'live', $this->row( $expected_id )['status'] );
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'post:' . $owner, 1 ) );
	}

	/**
	 * A block without a formId gets one as its first attribute, in every delimiter shape.
	 *
	 * @return void
	 */
	public function test_missing_id_is_inserted() {
		$cases = [
			'plain'        => [ '<!-- wp:swiftforms/form --><!-- /wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"%s"} --><!-- /wp:swiftforms/form -->' ],
			'attributes'   => [ '<!-- wp:swiftforms/form {"title":"T","ajax":false} --><!-- /wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"%s","title":"T","ajax":false} --><!-- /wp:swiftforms/form -->' ],
			'empty object' => [ '<!-- wp:swiftforms/form {} --><!-- /wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"%s"} --><!-- /wp:swiftforms/form -->' ],
			'self closing' => [ '<!-- wp:swiftforms/form /-->', '<!-- wp:swiftforms/form {"formId":"%s"} /-->' ],
			'self + attrs' => [ '<!-- wp:swiftforms/form {"title":"T"} /-->', '<!-- wp:swiftforms/form {"formId":"%s","title":"T"} /-->' ],
			'empty id'     => [ '<!-- wp:swiftforms/form {"formId":""} --><!-- /wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"%s"} --><!-- /wp:swiftforms/form -->' ],
			'invalid id'   => [ '<!-- wp:swiftforms/form {"formId":"abc","title":"T"} --><!-- /wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"%s","title":"T"} --><!-- /wp:swiftforms/form -->' ],
		];
		foreach ( $cases as $label => $case ) {
			$page = $this->make_post( 'before ' . $case[0] . ' after' );
			$this->save( $page, 'before ' . $case[0] . ' after' );

			$id = Uuid::v5( 'missing|post:' . $page . '|1' );
			$this->assertSame( 'before ' . sprintf( $case[1], $id ) . ' after', $this->stored_content( $page ), $label );
			$this->assertSame( $id, Form_Index::ensure( $id, 'post:' . $page, 1 ), $label );
		}
	}

	/**
	 * Two blocks without IDs get their own, by position; the same stored ID twice gives the first block the ID and the second a dup ID.
	 *
	 * @return void
	 */
	public function test_several_blocks_in_one_source() {
		$page = $this->make_post( $this->form_markup( '' ) . $this->form_markup( '' ) );
		$this->save( $page, $this->form_markup( '' ) . $this->form_markup( '' ) );
		$ref = 'post:' . $page;
		$this->assertSame(
			$this->form_markup( Uuid::v5( 'missing|' . $ref . '|1' ) ) . $this->form_markup( Uuid::v5( 'missing|' . $ref . '|2' ) ),
			$this->stored_content( $page )
		);

		$twice = $this->make_post( 'x' );
		$ref   = 'post:' . $twice;
		$this->save( $twice, $this->form_markup( self::ID_A ) . $this->form_markup( self::ID_B ) . $this->form_markup( self::ID_A ) );
		$this->assertSame(
			$this->form_markup( self::ID_A ) . $this->form_markup( self::ID_B ) . $this->form_markup( Uuid::v5( 'dup|' . self::ID_A . '|' . $ref . '|3' ) ),
			$this->stored_content( $twice )
		);
		$this->assertSame( 'live', $this->row( self::ID_A )['status'] );
	}

	/**
	 * A delimiter where the replacement is not safe (two matches) is left alone and logged once.
	 *
	 * @return void
	 */
	public function test_two_matches_leave_content_untouched_and_log() {
		$this->make_post( $this->form_markup( self::ID_A ) );
		$tricky = '<!-- wp:swiftforms/form {"formId":"' . self::ID_A . '","formId":"' . self::ID_A . '"} --><!-- /wp:swiftforms/form -->';
		$copy   = $this->make_post( 'seed' );

		$this->assertSame( 0, $this->error_rows() );
		$this->save( $copy, $tricky );
		$this->assertSame( $tricky, $this->stored_content( $copy ) );
		$this->assertSame( 1, $this->error_rows() );

		// A non-string formId is not touched either.
		$odd = '<!-- wp:swiftforms/form {"formId":5} --><!-- /wp:swiftforms/form -->';
		$this->save( $copy, $odd );
		$this->assertSame( $odd, $this->stored_content( $copy ) );
		$this->assertSame( 2, $this->error_rows() );
	}

	/**
	 * Revisions, autosaves, navigation posts, posts without an ID and content without forms are skipped.
	 *
	 * @return void
	 */
	public function test_skipped_posts() {
		$content = '<!-- wp:swiftforms/form --><!-- /wp:swiftforms/form -->';
		$base    = [
			'post_content' => wp_slash( $content ),
			'post_type'    => 'page',
			'post_status'  => 'publish',
		];

		$this->assertSame( $base, Form_Id_Writer::filter( $base, [ 'ID' => 0 ] ), 'No ID yet.' );
		$this->assertSame( $base, Form_Id_Writer::filter( $base, [] ) );
		$revision = array_merge(
			$base,
			[
				'post_type'   => 'revision',
				'post_status' => 'inherit',
			]
		);
		$this->assertSame( $revision, Form_Id_Writer::filter( $revision, [ 'ID' => 5 ] ) );
		$autosave = array_merge( $base, [ 'post_status' => 'inherit' ] );
		$this->assertSame( $autosave, Form_Id_Writer::filter( $autosave, [ 'ID' => 5 ] ) );
		$nav = array_merge( $base, [ 'post_type' => 'wp_navigation' ] );
		$this->assertSame( $nav, Form_Id_Writer::filter( $nav, [ 'ID' => 5 ] ) );
		$plain = array_merge( $base, [ 'post_content' => wp_slash( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) ] );
		$this->assertSame( $plain, Form_Id_Writer::filter( $plain, [ 'ID' => 5 ] ) );
		$this->assertSame( 'not data', Form_Id_Writer::filter( 'not data', [ 'ID' => 5 ] ) );
		$this->assertSame( 0, $this->error_rows() );
	}

	/**
	 * Content that is slashed on the way in keeps quotes, backslashes and Unicode in the form's attributes exactly.
	 *
	 * @return void
	 */
	public function test_slashed_content_survives() {
		$this->make_post( $this->form_markup( self::ID_A ) );
		$attrs   = [
			'title' => 'He said "hi" \\ back é 日本 ☃',
			'x'     => "line\nbreak",
		];
		$content = '<!-- wp:html --><p onclick="a(\'b\')">"q" \\ é</p><!-- /wp:html -->' . $this->form_markup( self::ID_A, '', $attrs );
		$copy    = $this->make_post( 'seed' );
		$this->save( $copy, $content );

		$copy_id = Form_Index::copy_id( self::ID_A, 'post:' . $copy );
		$stored  = $this->stored_content( $copy );
		$this->assertSame( str_replace( '"formId":"' . self::ID_A . '"', '"formId":"' . $copy_id . '"', $content ), $stored );
		$blocks = parse_blocks( $stored );
		$this->assertSame( $attrs['title'], $blocks[1]['attrs']['title'] );
		$this->assertSame( $copy_id, $blocks[1]['attrs']['formId'] );
	}

	/**
	 * A database template saved for the active theme goes through the same filter, under its template reference.
	 *
	 * @return void
	 */
	public function test_template_posts() {
		$this->make_theme( 'sf-w3' );
		$this->make_post( $this->form_markup( self::ID_A ) );
		$template = $this->make_template_post( 'wp_template', 'sf-w3', 'single', 'seed' );

		$this->save( $template, $this->form_markup( self::ID_A ) . $this->form_markup( '' ) );

		$copy_id = Form_Index::copy_id( self::ID_A, 'template:sf-w3//single' );
		$this->assertSame(
			$this->form_markup( $copy_id ) . $this->form_markup( Uuid::v5( 'missing|template:sf-w3//single|2' ) ),
			$this->stored_content( $template )
		);
		$this->assertTrue( SwiftForms\Form_Index::is_live( $copy_id, 'template:sf-w3//single' ) );
	}

	/**
	 * The rewrite is a pure function of content and index: the helper can be used without a post.
	 *
	 * @return void
	 */
	public function test_rewrite_returns_null_when_nothing_changes() {
		$page = $this->make_post( $this->form_markup( self::ID_A ) );
		$this->assertNull( Form_Id_Writer::rewrite( $this->form_markup( self::ID_A ), 'post:' . $page ) );
		$this->assertNull( Form_Id_Writer::rewrite( 'no forms', 'post:' . $page ) );
		$this->assertNotNull( Form_Id_Writer::rewrite( $this->form_markup( '' ), 'post:' . $page ) );
	}
}
