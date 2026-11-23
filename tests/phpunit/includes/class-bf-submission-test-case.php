<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper: the class name follows BF_Submission_Test_Case.
/**
 * Base class for the submission pipeline tests (REST and no-JS).
 *
 * Creates a published page with a form, builds valid control fields the way the view module would
 * (a real render token and a browser-measured time) and posts to the REST route in-process.
 *
 * @package BlockForms
 */

require_once __DIR__ . '/class-bf-render-test-case.php';
require_once __DIR__ . '/class-bf-test-redirect.php';
require_once __DIR__ . '/class-bf-test-die.php';

use BlockForms\Form_Index;
use BlockForms\Render_Token;
use BlockForms\Schema_Resolver;

/**
 * Class BF_Submission_Test_Case
 */
abstract class BF_Submission_Test_Case extends BF_Render_Test_Case {

	/**
	 * Source of the form made by make_form().
	 *
	 * @var string
	 */
	protected $source = '';

	/**
	 * Effective formId of the form made by make_form().
	 *
	 * @var string
	 */
	protected $form_id = self::ID_A;

	/**
	 * Start as a guest on a clean REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wp_rest_server;
		parent::set_up();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh server so routes register again.
		rest_get_server();
	}

	/**
	 * Forget the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- See set_up().
		unset( $_POST, $_GET['bf_result'] );
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * Name (required), email (required) and message fields, then submit and confirmation.
	 *
	 * @param string $confirmation Inner markup of the confirmation block.
	 * @return string
	 */
	protected function default_inner( $confirmation = '<!-- wp:paragraph --><p>Thanks!</p><!-- /wp:paragraph -->' ) {
		return $this->field_markup(
			'text',
			[
				'name'     => 'name',
				'label'    => 'Name',
				'required' => true,
			]
		)
			. $this->field_markup(
				'email',
				[
					'name'     => 'email',
					'label'    => 'Email',
					'required' => true,
				]
			)
			. $this->field_markup(
				'textarea',
				[
					'name'  => 'message',
					'label' => 'Message',
				]
			)
			. '<!-- wp:blockforms/submit /-->'
			. '<!-- wp:blockforms/confirmation -->' . $confirmation . '<!-- /wp:blockforms/confirmation -->';
	}

	/**
	 * Publish a page with a form (a fresh formId) and remember its source and ID.
	 *
	 * @param string|null $inner      Inner markup (default_inner() when null).
	 * @param array       $form_attrs Form attributes.
	 * @param array       $post_args  Extra post fields.
	 * @return int Post ID.
	 */
	protected function make_form( $inner = null, array $form_attrs = [], array $post_args = [] ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// A fresh stored ID per call: a second page with the same ID would hold a copy, with a derived ID.
		$form_id = wp_generate_uuid4();
		$post_id = $this->make_post( $this->form_markup( $form_id, null === $inner ? $this->default_inner() : $inner, $form_attrs ), $post_args );
		wp_set_current_user( 0 );

		$this->source  = 'post:' . $post_id;
		$this->form_id = $form_id;
		Form_Index::flush_cache();
		return $post_id;
	}

	/**
	 * The current schema of the form.
	 *
	 * @return \BlockForms\Form_Schema
	 */
	protected function schema() {
		$schema = Schema_Resolver::resolve( $this->form_id, $this->source );
		$this->assertNotNull( $schema, 'The form resolves.' );
		return $schema;
	}

	/**
	 * Valid control fields, as the view module sends them.
	 *
	 * @param array $override Values to replace (null removes a key).
	 * @return array
	 */
	protected function controls( array $override = [] ) {
		$controls = [
			'_bf_form'   => $this->form_id,
			'_bf_source' => $this->source,
			'_bf_token'  => Render_Token::issue( $this->form_id, $this->source, $this->schema()->hash ),
			'_bf_hp'     => '',
			'_bf_t'      => 'js.5000',
			'_bf_v'      => BLOCKFORMS_VERSION,
		];
		foreach ( $override as $key => $value ) {
			if ( null === $value ) {
				unset( $controls[ $key ] );
			} else {
				$controls[ $key ] = $value;
			}
		}
		return $controls;
	}

	/**
	 * Valid field values for the default form.
	 *
	 * @param array $override Values to replace.
	 * @return array
	 */
	protected function good( array $override = [] ) {
		return array_merge(
			[
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there',
			],
			$override
		);
	}

	/**
	 * POST a JSON submission to the REST route.
	 *
	 * @param array $bf       Field values.
	 * @param array $controls Control overrides (see controls()).
	 * @return WP_REST_Response
	 */
	protected function submit( array $bf, array $controls = [] ) {
		$request = new WP_REST_Request( 'POST', '/blockforms/v1/submit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( $this->controls( $controls ), [ 'bf' => $bf ] ) ) );
		return rest_do_request( $request );
	}

	/**
	 * Make wp_redirect() throw BF_Test_Redirect and wp_die() throw BF_Test_Die for the rest of the test.
	 *
	 * @return void
	 */
	protected function capture_exits() {
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				$e           = new BF_Test_Redirect( 'redirect' );
				$e->location = $location;
				$e->status   = $status;
				throw $e;
			},
			10,
			2
		);
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title, $args ) {
					$e         = new BF_Test_Die( is_string( $message ) ? $message : 'die' );
					$e->status = isset( $args['response'] ) ? (int) $args['response'] : 500;
					throw $e;
				};
			}
		);
	}

	/**
	 * Markup without the wp-block-paragraph class newer cores add.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	protected function norm( $html ) {
		return str_replace( ' class="wp-block-paragraph"', '', (string) $html );
	}

	/**
	 * Entry rows.
	 *
	 * @return array
	 */
	protected function entries() {
		return $this->rows( 'entries' );
	}
}
