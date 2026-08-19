<?php
/**
 * Storage for `swf_entry` posts.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Entries;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Submissions\UploadHandler;

/**
 * One post per entry, tagged with its source form via the
 * `swf_entry_form` taxonomy, plus one `swf_field_{slug}` meta row per
 * submitted field — deliberately unprefixed with `_` so WordPress's own
 * Custom Fields metabox displays them on the entry's edit screen, with no
 * bespoke viewer needed.
 */
final class EntryRepository implements Registrable {

	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'delete_uploads_for_entry' ) );
	}

	/**
	 * Creates an entry post, tags it with its form, and saves its field meta.
	 *
	 * @param int                                                                          $form_id Source form post id.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields  Validated, schema-enforced fields.
	 * @return int The new entry post id, or 0 on failure.
	 */
	public function create( int $form_id, array $fields ): int {
		$entry_id = wp_insert_post(
			array(
				'post_type'   => PostTypes::ENTRY_POST_TYPE,
				'post_status' => 'private',
				'post_title'  => __( 'Submission', 'swiftforms' ),
			),
			true
		);

		if ( is_wp_error( $entry_id ) || ! $entry_id ) {
			return 0;
		}

		wp_set_object_terms( $entry_id, PostTypes::entry_term_for_form( $form_id ), PostTypes::ENTRY_FORM_TAXONOMY );

		foreach ( $fields as $field ) {
			$this->save_field_meta( $entry_id, $field );
		}

		wp_update_post(
			array(
				'ID'         => $entry_id,
				/* translators: %d: entry id. */
				'post_title' => sprintf( __( 'Submission #%d', 'swiftforms' ), $entry_id ),
			)
		);

		/**
		 * Fires after a new entry has been fully saved.
		 *
		 * @param int $entry_id Entry post id.
		 * @param int $form_id  Source form post id.
		 */
		do_action( 'swf_entry_saved', $entry_id, $form_id );

		return $entry_id;
	}

	/**
	 * Saves one field's meta, including consent's statement/timestamp and
	 * file uploads' name/path/size.
	 *
	 * @param array{slug: string, type: string, value: mixed, attributes: array<string, mixed>} $field One field.
	 */
	private function save_field_meta( int $entry_id, array $field ): void {
		$key = 'swf_field_' . $field['slug'];

		update_post_meta( $entry_id, $key, $field['value'] );

		if ( 'consent' === $field['type'] && '' !== trim( (string) $field['value'] ) ) {
			update_post_meta( $entry_id, $key . '_statement', (string) ( $field['attributes']['statementText'] ?? '' ) );
			update_post_meta( $entry_id, $key . '_accepted_at', current_time( 'mysql' ) );
		}
	}

	/**
	 * Deletes any uploaded files attached to an entry when it's deleted.
	 */
	public function delete_uploads_for_entry( int $post_id ): void {
		if ( PostTypes::ENTRY_POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( get_post_meta( $post_id ) as $key => $meta_values ) {
			if ( ! str_starts_with( $key, 'swf_field_' ) ) {
				continue;
			}

			$value = maybe_unserialize( $meta_values[0] ?? '' );

			if ( is_array( $value ) && ! empty( $value['path'] ) && UploadHandler::is_managed_file( (string) $value['path'] ) ) {
				wp_delete_file( $value['path'] );
			}
		}
	}
}
