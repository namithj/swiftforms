<?php
/**
 * Tests for the Akismet spam integration.
 */

declare(strict_types=1);

class SwiftForms_Spam_Test extends WP_UnitTestCase {
	public function tear_down(): void {
		remove_all_filters( 'swiftforms_akismet_result' );

		parent::tear_down();
	}

	public function test_is_akismet_active_false_without_plugin(): void {
		// The Akismet plugin is not loaded in the test environment.
		$this->assertFalse( SwiftForms_Spam::is_akismet_active() );
	}

	public function test_build_comment_check_payload_maps_fields(): void {
		$payload = SwiftForms_Spam::build_comment_check_payload(
			array(
				'fields' => array(
					array(
						'slug'  => 'name',
						'type'  => 'text',
						'value' => 'Ada Lovelace',
					),
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'ada@example.com',
					),
					array(
						'slug'  => 'message',
						'type'  => 'text',
						'value' => 'Buy cheap widgets',
					),
					array(
						'slug'  => 'upload',
						'type'  => 'file',
						'value' => array( 'name' => 'x.pdf' ),
					),
				),
			)
		);

		$this->assertSame( 'contact-form', $payload['comment_type'] );
		$this->assertSame( 'Ada Lovelace', $payload['comment_author'] );
		$this->assertSame( 'ada@example.com', $payload['comment_author_email'] );
		$this->assertStringContainsString( 'Buy cheap widgets', $payload['comment_content'] );
		$this->assertStringContainsString( 'Ada Lovelace', $payload['comment_content'] );
		$this->assertSame( (string) home_url(), $payload['blog'] );
	}

	public function test_check_result_is_filterable(): void {
		// Without Akismet the raw result is false; the filter must be able to
		// override it (this is also how tests stub spam classification).
		$this->assertFalse( SwiftForms_Spam::check( array() ) );

		add_filter( 'swiftforms_akismet_result', '__return_true' );

		$this->assertTrue( SwiftForms_Spam::check( array() ) );
	}
}
