<?php
/**
 * Authorized downloads for private entry uploads.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Entries;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Submissions\UploadHandler;

/**
 * Adds signed, capability-checked download links for private entry uploads.
 */
final class EntryDownloadController implements Registrable {

	public function register(): void {
		add_action( 'admin_post_swf_download_entry_upload', array( $this, 'download' ) );
		add_filter( 'post_row_actions', array( $this, 'add_download_actions' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post               $post    Current post.
	 * @return array<string, string>
	 */
	public function add_download_actions( array $actions, \WP_Post $post ): array {
		if ( PostTypes::ENTRY_POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		foreach ( get_post_meta( $post->ID ) as $key => $meta_values ) {
			if ( ! str_starts_with( $key, 'swf_field_' ) ) {
				continue;
			}

			$slug = substr( $key, strlen( 'swf_field_' ) );
			$url  = $this->url( $post->ID, $slug );
			if ( '' === $url ) {
				continue;
			}

			$value = maybe_unserialize( $meta_values[0] ?? '' );
			$name  = is_array( $value ) ? (string) ( $value['name'] ?? '' ) : '';
			/* translators: %s: uploaded file name. */
			$actions[ 'swf_download_' . $slug ] = '<a href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Download %s', 'swiftforms' ), $name ) ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Returns a signed download URL when the current user may edit the entry.
	 */
	public function url( int $entry_id, string $field_slug ): string {
		$field_slug = sanitize_key( $field_slug );

		if ( ! current_user_can( 'edit_post', $entry_id ) || ! $this->attachment( $entry_id, $field_slug ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'swf_download_entry_upload',
					'entry_id' => $entry_id,
					'field'    => $field_slug,
				),
				admin_url( 'admin-post.php' )
			),
			'swf_download_entry_upload_' . $entry_id . '_' . $field_slug
		);
	}

	/**
	 * Streams one managed attachment after capability and nonce checks.
	 */
	public function download(): void {
		$entry_id   = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		$field_slug = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';

		if ( ! current_user_can( 'edit_post', $entry_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'swiftforms' ), 403 );
		}

		check_admin_referer( 'swf_download_entry_upload_' . $entry_id . '_' . $field_slug );
		$attachment = $this->attachment( $entry_id, $field_slug );
		if ( ! $attachment ) {
			wp_die( esc_html__( 'The requested file could not be found.', 'swiftforms' ), 404 );
		}

		$size = filesize( $attachment['path'] );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', sanitize_file_name( $attachment['name'] ) ) . '"' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- response must stream the private file.
		readfile( $attachment['path'] );
		exit;
	}

	/**
	 * @return array{name: string, path: string, size?: int}|null
	 */
	private function attachment( int $entry_id, string $field_slug ): ?array {
		if ( PostTypes::ENTRY_POST_TYPE !== get_post_type( $entry_id ) || '' === $field_slug ) {
			return null;
		}

		$value = get_post_meta( $entry_id, 'swf_field_' . $field_slug, true );
		if ( ! is_array( $value ) || empty( $value['path'] ) || empty( $value['name'] ) ) {
			return null;
		}

		$path = (string) $value['path'];

		return UploadHandler::is_managed_file( $path ) && is_readable( $path ) ? $value : null;
	}
}
