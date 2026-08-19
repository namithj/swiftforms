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
}
