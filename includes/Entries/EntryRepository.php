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
		add_filter( 'manage_edit-' . PostTypes::ENTRY_POST_TYPE . '_columns', array( $this, 'entry_columns' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_spam_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_entries_by_spam' ) );
		add_filter( 'bulk_actions-edit-' . PostTypes::ENTRY_POST_TYPE, array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . PostTypes::ENTRY_POST_TYPE, array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'manage_' . PostTypes::ENTRY_POST_TYPE . '_posts_custom_column', array( $this, 'render_entry_column' ), 10, 2 );
	}


	/** @param array<string, string> $columns @return array<string, string> */
	public function entry_columns( array $columns ): array {
		$columns['swf_spam'] = __( 'Spam', 'swiftforms' );

		return $columns;
	}

	public function render_entry_column( string $column, int $entry_id ): void {
		if ( 'swf_spam' === $column ) {
			echo 'spam' === get_post_meta( $entry_id, '_swf_spam_status', true ) ? esc_html__( 'Spam', 'swiftforms' ) : esc_html__( 'Not spam', 'swiftforms' );
		}
	}


	public function render_spam_filter(): void {
		global $typenow;
		if ( PostTypes::ENTRY_POST_TYPE !== $typenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, as in core.
		$selected = isset( $_GET['swf_spam'] ) ? sanitize_key( wp_unslash( (string) $_GET['swf_spam'] ) ) : '';
		echo '<label class="screen-reader-text" for="swf-spam">' . esc_html__( 'Filter by spam status', 'swiftforms' ) . '</label>';
		echo '<select name="swf_spam" id="swf-spam"><option value="">' . esc_html__( 'All entries', 'swiftforms' ) . '</option>';
		echo '<option value="spam"' . selected( $selected, 'spam', false ) . '>' . esc_html__( 'Spam only', 'swiftforms' ) . '</option>';
		echo '<option value="ham"' . selected( $selected, 'ham', false ) . '>' . esc_html__( 'Not spam', 'swiftforms' ) . '</option></select>';
	}

	public function filter_entries_by_spam( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::ENTRY_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, as in core.
		$status = isset( $_GET['swf_spam'] ) ? sanitize_key( wp_unslash( (string) $_GET['swf_spam'] ) ) : '';
		if ( in_array( $status, array( 'spam', 'ham' ), true ) ) {
			$query->set( 'meta_key', '_swf_spam_status' );
			$query->set( 'meta_value', $status );
		}
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function bulk_actions( array $actions ): array {
		$actions['swf_mark_spam'] = __( 'Mark as spam', 'swiftforms' );
		$actions['swf_mark_ham']  = __( 'Mark as not spam', 'swiftforms' );

		return $actions;
	}

	/** @param int[] $entry_ids */
	public function handle_bulk_actions( string $redirect, string $action, array $entry_ids ): string {
		if ( ! in_array( $action, array( 'swf_mark_spam', 'swf_mark_ham' ), true ) ) {
			return $redirect;
		}

		foreach ( $entry_ids as $entry_id ) {
			$entry_id = (int) $entry_id;
			if ( PostTypes::ENTRY_POST_TYPE === get_post_type( $entry_id ) && current_user_can( 'edit_post', $entry_id ) ) {
				update_post_meta( $entry_id, '_swf_spam_status', 'swf_mark_spam' === $action ? 'spam' : 'ham' );
			}
		}

		return $redirect;
	}
	/**
	 * Creates an entry post, tags it with its form, and saves its field meta.
	 *
	 * @param int                                                                          $form_id Source form post id.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields  Validated, schema-enforced fields.
	 * @return int The new entry post id, or 0 on failure.
	 */
	public function create( int $form_id, array $fields, bool $is_spam = false ): int {
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
		update_post_meta( $entry_id, '_swf_spam_status', $is_spam ? 'spam' : 'ham' );
		if ( $is_spam ) {
			update_post_meta( $entry_id, '_swf_spam_reason', 'akismet' );
		}

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
