<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Test helper: the class name follows SF_Resolver_Test_Case, and tests apply core's the_content filter.
/**
 * Base class for the block render tests.
 *
 * Renders form markup the way a visitor's page does: a published page, a front-end
 * request for it, the_content. Resets the per-request state of the renderers.
 *
 * @package SwiftForms
 */

require_once __DIR__ . '/class-sf-resolver-test-case.php';

use SwiftForms\Form_Renderer;
use SwiftForms\Form_State;

/**
 * Class SF_Render_Test_Case
 */
abstract class SF_Render_Test_Case extends SF_Resolver_Test_Case {

	/**
	 * Start from an empty renderer state.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Form_Renderer::reset();
		Form_State::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Leave no renderer state behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		Form_Renderer::reset();
		Form_State::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Render block markup as the content of a published page and return the output.
	 *
	 * @param string $markup Block markup (a form, or anything).
	 * @param int    $post_id Out: the page ID.
	 * @param array  $args    Extra post fields.
	 * @return string
	 */
	protected function render_page( $markup, &$post_id = 0, array $args = [] ) {
		$post_id = $this->make_post( $markup, $args );
		$this->go_to( get_permalink( $post_id ) );
		wp_set_current_user( 0 );
		$html = $this->render_current();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		return $html;
	}

	/**
	 * The content of the post of the current request, filtered like a page view.
	 *
	 * @return string
	 */
	protected function render_current() {
		$post = get_queried_object();
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();
		return $html;
	}

	/**
	 * Render a form with the given inner markup and attributes.
	 *
	 * @param string $inner Inner block markup.
	 * @param array  $attrs Form attributes (formId defaults to ID_A).
	 * @return string
	 */
	protected function render_form( $inner, array $attrs = [] ) {
		return $this->render_page( $this->form_markup( self::ID_A, $inner, $attrs ) );
	}

	/**
	 * Render one field in a form and return the field's own markup (the form around it removed).
	 *
	 * @param string $type  Field type.
	 * @param array  $attrs Field attributes.
	 * @param array  $form  Form attributes.
	 * @return string
	 */
	protected function render_field( $type, array $attrs = [], array $form = [] ) {
		$html  = $this->render_form( $this->field_markup( $type, $attrs ), $form );
		$start = strpos( $html, 'hidden></div>' );
		$end   = strpos( $html, '<input type="hidden" name="_sf_form"' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		return substr( $html, $start + strlen( 'hidden></div>' ), $end - $start - strlen( 'hidden></div>' ) );
	}
}
