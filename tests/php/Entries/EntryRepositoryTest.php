<?php
/**
 * Tests for SwiftForms\Entries\EntryRepository.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Entries;

use SwiftForms\Entries\EntryRepository;
use SwiftForms\PostTypes;
use SwiftForms\Submissions\UploadHandler;
use SwiftForms\Tests\TestCase;

final class EntryRepositoryTest extends TestCase {

	public function test_create_stores_one_meta_row_per_field(): void {
		$form_id = $this->create_form();
		$repo    = new EntryRepository();

		$entry_id = $repo->create(
			$form_id,
			array(
				array(
					'slug'       => 'name',
					'type'       => 'text',
					'value'      => 'Alice',
					'attributes' => array( 'label' => 'Full name' ),
				),
				array(
					'slug'       => 'email',
					'type'       => 'email',
					'value'      => 'alice@example.com',
					'attributes' => array(),
				),
			)
		);

		$this->assertSame( 'Alice', get_post_meta( $entry_id, 'smartlogix_swiftforms_field_name', true ) );
		$this->assertSame( 'unread', get_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', true ) );
		$this->assertSame( 'Full name', get_post_meta( $entry_id, '_smartlogix_swiftforms_field_schema', true )['name']['label'] );
	}

	public function test_create_tags_entry_with_its_form_term(): void {
		$form_id  = $this->create_form();
		$repo     = new EntryRepository();
		$entry_id = $repo->create( $form_id, array() );

		$terms = wp_get_object_terms( $entry_id, PostTypes::ENTRY_FORM_TAXONOMY, array( 'fields' => 'slugs' ) );

		$this->assertSame( array( PostTypes::entry_term_slug( $form_id ) ), $terms );
	}

	public function test_consent_field_stores_statement_and_timestamp(): void {
		$form_id = $this->create_form();
		$repo    = new EntryRepository();

		$entry_id = $repo->create(
			$form_id,
			array(
				array(
					'slug'       => 'privacy_consent',
					'type'       => 'consent',
					'value'      => 'yes',
					'attributes' => array( 'statementText' => 'I agree to the policy.' ),
				),
			)
		);

		$this->assertSame( 'I agree to the policy.', get_post_meta( $entry_id, 'smartlogix_swiftforms_field_privacy_consent_statement', true ) );
		$this->assertNotEmpty( get_post_meta( $entry_id, 'smartlogix_swiftforms_field_privacy_consent_accepted_at', true ) );
	}

	public function test_delete_uploads_for_entry_deletes_only_managed_files(): void {
		$managed_dir = UploadHandler::private_upload_dir();
		wp_mkdir_p( $managed_dir );
		$managed = trailingslashit( $managed_dir ) . 'entry-delete-test.txt';
		file_put_contents( $managed, 'private attachment' );

		$outside = wp_tempnam( 'swf-outside-upload' );
		file_put_contents( $outside, 'not managed by SwiftForms' );

		$entry_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::ENTRY_POST_TYPE,
				'post_status' => 'private',
			)
		);
		update_post_meta( $entry_id, 'smartlogix_swiftforms_field_attachment', array( 'path' => $managed ) );
		update_post_meta( $entry_id, 'smartlogix_swiftforms_field_forged', array( 'path' => $outside ) );

		( new EntryRepository() )->delete_uploads_for_entry( $entry_id );

		$this->assertFileDoesNotExist( $managed );
		$this->assertFileExists( $outside );

		wp_delete_file( $outside );
	}
	public function test_create_persists_protected_akismet_spam_state(): void {
		$entry_id = ( new EntryRepository() )->create( $this->create_form(), array(), true );

		$this->assertSame( 'spam', get_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', true ) );
		$this->assertSame( 'akismet', get_post_meta( $entry_id, '_smartlogix_swiftforms_spam_reason', true ) );
	}
	public function test_spam_bulk_actions_update_only_editable_entries(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$entry_id   = ( new EntryRepository() )->create( $this->create_form(), array() );
		$repository = new EntryRepository();

		$this->assertArrayHasKey( 'smartlogix_swiftforms_mark_spam', $repository->bulk_actions( array() ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'smartlogix_swiftforms_mark_spam', array( $entry_id ) );
		$this->assertSame( 'spam', get_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', true ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'smartlogix_swiftforms_mark_ham', array( $entry_id ) );
		$this->assertSame( 'ham', get_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', true ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'smartlogix_swiftforms_mark_read', array( $entry_id ) );
		$this->assertSame( 'read', get_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', true ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'smartlogix_swiftforms_mark_unread', array( $entry_id ) );
		$this->assertSame( 'unread', get_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', true ) );
	}

	public function test_read_only_metabox_uses_labels_without_exposing_internal_metadata(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$repository = new EntryRepository();
		$entry_id   = $repository->create(
			$this->create_form(),
			array(
				array(
					'slug'       => 'message',
					'type'       => 'textarea',
					'value'      => '<script>alert(1)</script>Hello',
					'attributes' => array( 'label' => 'Your message' ),
				),
			)
		);
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_email', 'failed' );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_email_attempts', 1 );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_email_error', 'mail_failed' );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', 'retrying' );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_attempts', 2 );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook_payload', array( 'private_marker' => 'stored-only' ) );

		ob_start();
		$repository->render_entry_metabox( get_post( $entry_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Your message', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '_smartlogix_swiftforms_field_schema', $html );
		$this->assertStringContainsString( 'smartlogix_swiftforms_export_entry', $html );
		$this->assertStringContainsString( 'Delivery status', $html );
		$this->assertStringContainsString( 'mail_failed', $html );
		$this->assertStringContainsString( 'retrying', $html );
		$this->assertStringContainsString( 'Retry webhook', $html );
		$this->assertStringNotContainsString( 'stored-only', $html );
	}

	public function test_entry_search_targets_only_submitted_field_meta(): void {
		set_current_screen( 'edit-' . PostTypes::ENTRY_POST_TYPE );
		$_GET['smartlogix_swiftforms_read'] = 'unread';
		$query                              = new \WP_Query();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP_Query::is_main_query() compares this test fixture with the global main query.
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'post_type', PostTypes::ENTRY_POST_TYPE );
		$query->set( 's', 'alice@example.com' );

		( new EntryRepository() )->filter_entries_by_spam( $query );
		$meta_query = $query->get( 'meta_query' );

		$this->assertSame( '', $query->get( 's' ) );
		$this->assertSame( 'smartlogix_swiftforms_field_', $meta_query[1]['key'] );
		$this->assertSame( 'LIKE', $meta_query[1]['compare_key'] );
		unset( $_GET['smartlogix_swiftforms_read'] );
		set_current_screen( 'front' );
	}
}
