<?php
/**
 * Storage for `smartlogix_swf_entry` posts.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Entries;

use SwiftForms\Notifications\Webhooks;
use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Submissions\UploadHandler;

/**
 * One post per entry, tagged with its source form via the
 * `smartlogix_swf_entry_form` taxonomy, plus one `smartlogix_swiftforms_field_{slug}` meta row per
 * submitted field. Entry data is displayed through a read-only metabox;
 * protected metadata and filesystem paths never reach the normal editor UI.
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
		add_action( 'add_meta_boxes_' . PostTypes::ENTRY_POST_TYPE, array( $this, 'add_entry_metabox' ) );
		add_action( 'load-post.php', array( $this, 'mark_entry_read_on_view' ) );
		add_action( 'admin_post_smartlogix_swiftforms_export_entry', array( $this, 'export_entry' ) );
	}


	/** @param array<string, string> $columns @return array<string, string> */
	public function entry_columns( array $columns ): array {
		$columns['smartlogix_swiftforms_read']    = __( 'Status', 'swiftforms' );
		$columns['smartlogix_swiftforms_summary'] = __( 'Summary', 'swiftforms' );
		$columns['smartlogix_swiftforms_spam']    = __( 'Spam', 'swiftforms' );

		return $columns;
	}

	public function render_entry_column( string $column, int $entry_id ): void {
		if ( 'smartlogix_swiftforms_read' === $column ) {
			echo 'read' === get_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', true ) ? esc_html__( 'Read', 'swiftforms' ) : '<strong>' . esc_html__( 'Unread', 'swiftforms' ) . '</strong>';
		}

		if ( 'smartlogix_swiftforms_summary' === $column ) {
			foreach ( $this->fields( $entry_id ) as $field ) {
				if ( ! is_array( $field['value'] ) && '' !== trim( (string) $field['value'] ) ) {
					echo esc_html( wp_trim_words( (string) $field['value'], 12 ) );
					break;
				}
			}
		}

		if ( 'smartlogix_swiftforms_spam' === $column ) {
			echo 'spam' === get_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', true ) ? esc_html__( 'Spam', 'swiftforms' ) : esc_html__( 'Not spam', 'swiftforms' );
		}
	}


	public function render_spam_filter(): void {
		global $typenow;
		if ( PostTypes::ENTRY_POST_TYPE !== $typenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, as in core.
		$selected = isset( $_GET['smartlogix_swiftforms_spam'] ) ? sanitize_key( wp_unslash( (string) $_GET['smartlogix_swiftforms_spam'] ) ) : '';
		echo '<label class="screen-reader-text" for="swf-spam">' . esc_html__( 'Filter by spam status', 'swiftforms' ) . '</label>';
		echo '<select name="smartlogix_swiftforms_spam" id="swf-spam"><option value="">' . esc_html__( 'All entries', 'swiftforms' ) . '</option>';
		echo '<option value="spam"' . selected( $selected, 'spam', false ) . '>' . esc_html__( 'Spam only', 'swiftforms' ) . '</option>';
		echo '<option value="ham"' . selected( $selected, 'ham', false ) . '>' . esc_html__( 'Not spam', 'swiftforms' ) . '</option></select>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter.
		$read = isset( $_GET['smartlogix_swiftforms_read'] ) ? sanitize_key( wp_unslash( (string) $_GET['smartlogix_swiftforms_read'] ) ) : '';
		echo '<label class="screen-reader-text" for="swf-read">' . esc_html__( 'Filter by read status', 'swiftforms' ) . '</label>';
		echo '<select name="smartlogix_swiftforms_read" id="swf-read"><option value="">' . esc_html__( 'All read states', 'swiftforms' ) . '</option>';
		echo '<option value="unread"' . selected( $read, 'unread', false ) . '>' . esc_html__( 'Unread', 'swiftforms' ) . '</option>';
		echo '<option value="read"' . selected( $read, 'read', false ) . '>' . esc_html__( 'Read', 'swiftforms' ) . '</option></select>';
	}

	public function filter_entries_by_spam( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::ENTRY_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, as in core.
		$status = isset( $_GET['smartlogix_swiftforms_spam'] ) ? sanitize_key( wp_unslash( (string) $_GET['smartlogix_swiftforms_spam'] ) ) : '';
		if ( in_array( $status, array( 'spam', 'ham' ), true ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_smartlogix_swiftforms_spam_status',
						'value' => $status,
					),
				)
			);
		}

		$meta_query = array_filter( (array) $query->get( 'meta_query' ), 'is_array' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter.
		$read = isset( $_GET['smartlogix_swiftforms_read'] ) ? sanitize_key( wp_unslash( (string) $_GET['smartlogix_swiftforms_read'] ) ) : '';
		if ( in_array( $read, array( 'read', 'unread' ), true ) ) {
			$meta_query[] = array(
				'key'   => '_smartlogix_swiftforms_read_status',
				'value' => $read,
			);
		}

		$search = trim( (string) $query->get( 's' ) );
		if ( '' !== $search ) {
			$query->set( 's', '' );
			$meta_query[] = array(
				'key'         => 'smartlogix_swiftforms_field_',
				'compare_key' => 'LIKE',
				'value'       => $search,
				'compare'     => 'LIKE',
			);
		}
		$query->set( 'meta_query', $meta_query );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function bulk_actions( array $actions ): array {
		$actions['smartlogix_swiftforms_mark_spam']   = __( 'Mark as spam', 'swiftforms' );
		$actions['smartlogix_swiftforms_mark_ham']    = __( 'Mark as not spam', 'swiftforms' );
		$actions['smartlogix_swiftforms_mark_read']   = __( 'Mark as read', 'swiftforms' );
		$actions['smartlogix_swiftforms_mark_unread'] = __( 'Mark as unread', 'swiftforms' );

		return $actions;
	}

	/** @param int[] $entry_ids */
	public function handle_bulk_actions( string $redirect, string $action, array $entry_ids ): string {
		if ( ! in_array( $action, array( 'smartlogix_swiftforms_mark_spam', 'smartlogix_swiftforms_mark_ham', 'smartlogix_swiftforms_mark_read', 'smartlogix_swiftforms_mark_unread' ), true ) ) {
			return $redirect;
		}

		foreach ( $entry_ids as $entry_id ) {
			$entry_id = (int) $entry_id;
			if ( PostTypes::ENTRY_POST_TYPE === get_post_type( $entry_id ) && current_user_can( 'edit_post', $entry_id ) ) {
				if ( str_contains( $action, 'mark_spam' ) || str_contains( $action, 'mark_ham' ) ) {
					update_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', str_contains( $action, 'mark_spam' ) ? 'spam' : 'ham' );
				} else {
					update_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', str_contains( $action, 'mark_unread' ) ? 'unread' : 'read' );
				}
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
		update_post_meta( $entry_id, '_smartlogix_swiftforms_spam_status', $is_spam ? 'spam' : 'ham' );
		update_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', 'unread' );
		if ( $is_spam ) {
			update_post_meta( $entry_id, '_smartlogix_swiftforms_spam_reason', 'akismet' );
		}

		foreach ( $fields as $field ) {
			$this->save_field_meta( $entry_id, $field );
		}
		update_post_meta(
			$entry_id,
			'_smartlogix_swiftforms_field_schema',
			array_column(
				array_map(
					static fn ( array $field ): array => array(
						'label' => (string) ( $field['attributes']['label'] ?? $field['slug'] ),
						'type'  => $field['type'],
						'slug'  => $field['slug'],
					),
					$fields
				),
				null,
				'slug'
			)
		);

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
		do_action( 'smartlogix_swiftforms_entry_saved', $entry_id, $form_id );

		return $entry_id;
	}

	/**
	 * Saves one field's meta, including consent's statement/timestamp and
	 * file uploads' name/path/size.
	 *
	 * @param array{slug: string, type: string, value: mixed, attributes: array<string, mixed>} $field One field.
	 */
	private function save_field_meta( int $entry_id, array $field ): void {
		$key = 'smartlogix_swiftforms_field_' . $field['slug'];

		update_post_meta( $entry_id, $key, $field['value'] );

		if ( 'consent' === $field['type'] && '' !== trim( (string) $field['value'] ) ) {
			update_post_meta( $entry_id, $key . '_statement', (string) ( $field['attributes']['statementText'] ?? '' ) );
			update_post_meta( $entry_id, $key . '_accepted_at', current_time( 'mysql' ) );
		}
	}

	public function add_entry_metabox(): void {
		add_meta_box( 'smartlogix-swiftforms-entry', __( 'Submission details', 'swiftforms' ), array( $this, 'render_entry_metabox' ), PostTypes::ENTRY_POST_TYPE, 'normal', 'high' );
	}

	public function render_entry_metabox( \WP_Post $post ): void {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $this->fields( $post->ID ) as $field ) {
			echo '<tr><th scope="row">' . esc_html( $field['label'] ) . '</th><td>' . wp_kses_post( $this->format_value( $post->ID, $field ) ) . '</td></tr>';
		}
		echo '</tbody></table><p><a class="button" href="' . esc_url( $this->export_url( $post->ID ) ) . '">' . esc_html__( 'Export this entry as CSV', 'swiftforms' ) . '</a></p>';

		$email_status     = sanitize_key( (string) get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_email', true ) );
		$email_status     = '' !== $email_status ? $email_status : 'not_attempted';
		$email_attempts   = absint( get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_email_attempts', true ) );
		$email_error      = sanitize_key( (string) get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_email_error', true ) );
		$webhook_status   = sanitize_key( (string) get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_webhook', true ) );
		$webhook_status   = '' !== $webhook_status ? $webhook_status : 'not_attempted';
		$webhook_attempts = absint( get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_webhook_attempts', true ) );
		$webhook_error    = sanitize_key( (string) get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_webhook_error', true ) );

		echo '<h3>' . esc_html__( 'Delivery status', 'swiftforms' ) . '</h3><table class="widefat striped"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Email', 'swiftforms' ) . '</th><td>' . esc_html( $email_status );
		/* translators: %d: number of delivery attempts. */
		echo ' — ' . esc_html( sprintf( _n( '%d attempt', '%d attempts', $email_attempts, 'swiftforms' ), $email_attempts ) );
		if ( '' !== $email_error ) {
			echo ' (' . esc_html( $email_error ) . ')';
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Webhook', 'swiftforms' ) . '</th><td>' . esc_html( $webhook_status );
		/* translators: %d: number of delivery attempts. */
		echo ' — ' . esc_html( sprintf( _n( '%d attempt', '%d attempts', $webhook_attempts, 'swiftforms' ), $webhook_attempts ) );
		if ( '' !== $webhook_error ) {
			echo ' (' . esc_html( $webhook_error ) . ')';
		}
		$retry_url = Webhooks::retry_url( $post->ID );
		if ( '' !== $retry_url && is_array( get_post_meta( $post->ID, '_smartlogix_swiftforms_delivery_webhook_payload', true ) ) ) {
			echo ' <a class="button button-small" href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Retry webhook', 'swiftforms' ) . '</a>';
		}
		echo '</td></tr></tbody></table>';
	}

	public function mark_entry_read_on_view(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Loading an authorized edit screen only changes presentation state.
		$entry_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $entry_id && PostTypes::ENTRY_POST_TYPE === get_post_type( $entry_id ) && current_user_can( 'edit_post', $entry_id ) ) {
			update_post_meta( $entry_id, '_smartlogix_swiftforms_read_status', 'read' );
		}
	}

	public function export_url( int $entry_id ): string {
		if ( ! current_user_can( 'edit_post', $entry_id ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'smartlogix_swiftforms_export_entry',
					'entry_id' => $entry_id,
				),
				admin_url( 'admin-post.php' )
			),
			'smartlogix_swiftforms_export_entry_' . $entry_id
		);
	}

	public function export_entry(): void {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		if ( ! $entry_id || ! current_user_can( 'edit_post', $entry_id ) ) {
			wp_die( esc_html__( 'You are not allowed to export this entry.', 'swiftforms' ), 403 );
		}
		check_admin_referer( 'smartlogix_swiftforms_export_entry_' . $entry_id );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="swiftforms-entry-' . $entry_id . '.csv"' );
		$stream = fopen( 'php://output', 'w' );
		foreach ( $this->fields( $entry_id ) as $field ) {
			fputcsv( $stream, array( $field['label'], is_array( $field['value'] ) ? (string) ( $field['value']['name'] ?? '' ) : (string) $field['value'] ) );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is a response stream, not a filesystem path.
		exit;
	}

	/** @return array<int, array{slug:string,label:string,type:string,value:mixed}> */
	private function fields( int $entry_id ): array {
		$schema = (array) get_post_meta( $entry_id, '_smartlogix_swiftforms_field_schema', true );
		$fields = array();
		foreach ( $schema as $slug => $definition ) {
			$fields[] = array(
				'slug'  => sanitize_key( (string) $slug ),
				'label' => (string) ( $definition['label'] ?? $slug ),
				'type'  => (string) ( $definition['type'] ?? 'text' ),
				'value' => get_post_meta( $entry_id, 'smartlogix_swiftforms_field_' . sanitize_key( (string) $slug ), true ),
			);
		}
		return $fields;
	}

	/** @param array{slug:string,label:string,type:string,value:mixed} $field */
	private function format_value( int $entry_id, array $field ): string {
		if ( is_array( $field['value'] ) ) {
			$url = ( new EntryDownloadController() )->url( $entry_id, $field['slug'] );
			return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $field['value']['name'] ?? __( 'Download attachment', 'swiftforms' ) ) ) . '</a>' : esc_html__( 'Attachment unavailable', 'swiftforms' );
		}

		return nl2br( esc_html( (string) $field['value'] ) );
	}

	/**
	 * Deletes any uploaded files attached to an entry when it's deleted.
	 */
	public function delete_uploads_for_entry( int $post_id ): void {
		if ( PostTypes::ENTRY_POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( get_post_meta( $post_id ) as $key => $meta_values ) {
			if ( ! str_starts_with( $key, 'smartlogix_swiftforms_field_' ) ) {
				continue;
			}

			$value = maybe_unserialize( $meta_values[0] ?? '' );

			if ( is_array( $value ) && ! empty( $value['path'] ) && UploadHandler::is_managed_file( (string) $value['path'] ) ) {
				wp_delete_file( $value['path'] );
			}
		}
	}
}
