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
					'attributes' => array(),
				),
				array(
					'slug'       => 'email',
					'type'       => 'email',
					'value'      => 'alice@example.com',
					'attributes' => array(),
				),
			)
		);

		$this->assertSame( 'Alice', get_post_meta( $entry_id, 'swf_field_name', true ) );
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

		$this->assertSame( 'I agree to the policy.', get_post_meta( $entry_id, 'swf_field_privacy_consent_statement', true ) );
		$this->assertNotEmpty( get_post_meta( $entry_id, 'swf_field_privacy_consent_accepted_at', true ) );
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
		update_post_meta( $entry_id, 'swf_field_attachment', array( 'path' => $managed ) );
		update_post_meta( $entry_id, 'swf_field_forged', array( 'path' => $outside ) );

		( new EntryRepository() )->delete_uploads_for_entry( $entry_id );

		$this->assertFileDoesNotExist( $managed );
		$this->assertFileExists( $outside );

		wp_delete_file( $outside );
	}
	public function test_create_persists_protected_akismet_spam_state(): void {
		$entry_id = ( new EntryRepository() )->create( $this->create_form(), array(), true );

		$this->assertSame( 'spam', get_post_meta( $entry_id, '_swf_spam_status', true ) );
		$this->assertSame( 'akismet', get_post_meta( $entry_id, '_swf_spam_reason', true ) );
	}
	public function test_spam_bulk_actions_update_only_editable_entries(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$entry_id = ( new EntryRepository() )->create( $this->create_form(), array() );
		$repository = new EntryRepository();

		$this->assertArrayHasKey( 'swf_mark_spam', $repository->bulk_actions( array() ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'swf_mark_spam', array( $entry_id ) );
		$this->assertSame( 'spam', get_post_meta( $entry_id, '_swf_spam_status', true ) );
		$repository->handle_bulk_actions( 'https://example.test/', 'swf_mark_ham', array( $entry_id ) );
		$this->assertSame( 'ham', get_post_meta( $entry_id, '_swf_spam_status', true ) );
	}
}
