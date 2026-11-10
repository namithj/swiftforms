<?php
/**
 * Tests for SwiftForms\Confirmation.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Confirmation;
use SwiftForms\Submission_Context;

/**
 * Class ConfirmationTest
 *
 * @group submission
 */
class ConfirmationTest extends SF_Submission_Test_Case {

	/**
	 * A context for the current form.
	 *
	 * @param array $values Values.
	 * @return Submission_Context
	 */
	private function ctx( array $values = [] ) {
		return new Submission_Context(
			$this->schema(),
			null,
			[
				'values'   => $values + [
					'name'    => 'Ada',
					'email'   => 'ada@example.com',
					'message' => '',
				],
				'entry_id' => 77,
			]
		);
	}

	/**
	 * The confirmation blocks are rendered, with escaped tags.
	 *
	 * @return void
	 */
	public function test_message_from_blocks() {
		$this->make_form( $this->default_inner( '<!-- wp:paragraph --><p>Thanks, {field:name}! We wrote to {field:email}. Ref {entry:id}.</p><!-- /wp:paragraph -->' ) );

		$result = Confirmation::build( $this->ctx( [ 'name' => '<script>x</script>' ] ) );
		$this->assertSame( 'message', $result['type'] );
		$this->assertStringContainsString( '<p>Thanks, &lt;script&gt;x&lt;/script&gt;! We wrote to ada@example.com. Ref .</p>', $this->norm( $result['html'] ) );
		$this->assertStringNotContainsString( '<script>', $result['html'] );
		$this->assertStringNotContainsString( '77', $result['html'], 'A guest never sees the entry number.' );
	}

	/**
	 * With no confirmation text the default message is used.
	 *
	 * @return void
	 */
	public function test_default_message() {
		$this->make_form( $this->default_inner( '' ) );
		$result = Confirmation::build( $this->ctx() );
		$this->assertSame(
			[
				'type' => 'message',
				'html' => '<p>Thank you. Your message has been sent.</p>',
			],
			$result
		);

		$this->set_settings( [ 'general' => [ 'default_success_message' => 'Got it, <b>thanks</b><script src="https://evil.example/x.js"></script> {field:name}' ] ] );
		$result = Confirmation::build( $this->ctx() );
		$this->assertSame( '<p>Got it, <b>thanks</b> Ada</p>', $result['html'], 'kses applies, tags are merged.' );
	}

	/**
	 * A redirect to a published page.
	 *
	 * @return void
	 */
	public function test_redirect_to_a_page() {
		$thanks = $this->make_post( 'Thanks' );
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => $thanks,
			]
		);

		$this->assertSame(
			[
				'type' => 'redirect',
				'url'  => get_permalink( $thanks ),
			],
			Confirmation::build( $this->ctx() )
		);
	}

	/**
	 * A redirect page that is a draft, private, trashed, password-protected or gone shows the message instead.
	 *
	 * @return void
	 */
	public function test_redirect_page_not_usable() {
		foreach ( [ 'draft', 'private', 'trash', 'future' ] as $status ) {
			$args = [ 'post_status' => $status ];
			if ( 'future' === $status ) {
				$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			}
			$page = $this->make_post( 'x', $args );
			$this->make_form(
				null,
				[
					'confirmationType' => 'redirect',
					'redirectPageId'   => $page,
				]
			);
			$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'], $status );
		}

		$protected = $this->make_post( 'x', [ 'post_password' => 'secret' ] );
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => $protected,
			]
		);
		$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'], 'password' );

		$gone = $this->make_post( 'x' );
		wp_delete_post( $gone, true );
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => $gone,
			]
		);
		$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'], 'deleted' );

		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => 0,
				'redirectUrl'      => '',
			]
		);
		$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'], 'nothing to go to' );
	}

	/**
	 * A redirect URL with tags in the query only.
	 *
	 * @return void
	 */
	public function test_redirect_url() {
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectUrl'      => 'https://example.com/thanks?n={field:name}&id={entry:id}',
			]
		);
		$this->assertSame(
			[
				'type' => 'redirect',
				'url'  => 'https://example.com/thanks?n=Ada%20%26%20co&id=',
			],
			Confirmation::build( $this->ctx( [ 'name' => 'Ada & co' ] ) )
		);
	}

	/**
	 * A redirect URL that is not allowed shows the message.
	 *
	 * @return void
	 */
	public function test_redirect_url_refused() {
		foreach ( [ 'https://{field:name}/x', '{field:email}', 'javascript:alert(1)', 'https://example.com/{field:name}', 'ftp://example.com/' ] as $bad ) {
			$this->make_form(
				null,
				[
					'confirmationType' => 'redirect',
					'redirectUrl'      => $bad,
				]
			);
			$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'], $bad );
		}
	}

	/**
	 * A page wins over a URL; an unusable page does not fall through to the URL.
	 *
	 * @return void
	 */
	public function test_page_wins_over_url() {
		$thanks = $this->make_post( 'Thanks' );
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => $thanks,
				'redirectUrl'      => 'https://example.com/other',
			]
		);
		$this->assertSame( get_permalink( $thanks ), Confirmation::build( $this->ctx() )['url'] );

		$draft = $this->make_post( 'x', [ 'post_status' => 'draft' ] );
		$this->make_form(
			null,
			[
				'confirmationType' => 'redirect',
				'redirectPageId'   => $draft,
				'redirectUrl'      => 'https://example.com/other',
			]
		);
		$this->assertSame( 'message', Confirmation::build( $this->ctx() )['type'] );
	}

	/**
	 * Building the confirmation leaves the context alone.
	 *
	 * @return void
	 */
	public function test_context_is_not_changed() {
		$this->make_form();
		$ctx = $this->ctx();
		Confirmation::build( $ctx );
		$this->assertSame( 77, $ctx->entry_id );
	}
}
