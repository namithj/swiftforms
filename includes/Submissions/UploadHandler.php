<?php
/**
 * Secure file upload handling.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use WP_Error;

/**
 * Content-sniffs the real file type (not just the filename), stores under a
 * hashed name in a `.htaccess`-protected directory, and refuses anything
 * that didn't genuinely arrive via a file upload.
 */
final class UploadHandler {

	private const DEFAULT_ALLOWED_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'pdf'          => 'application/pdf',
		'txt'          => 'text/plain',
	);

	/**
	 * Moves an uploaded file into the protected uploads directory.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file One `$_FILES`-shaped entry.
	 * @return array{name: string, path: string, url: string, size: int}|WP_Error|null Null when no file was submitted.
	 */
	public function handle( array $file ) {
		if ( empty( $file['tmp_name'] ) ) {
			return null;
		}

		if ( ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'swiftforms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_genuine_upload( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'swiftforms' ), array( 'status' => 400 ) );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size > wp_max_upload_size() ) {
			return new WP_Error( 'upload_error', __( 'That file is too large.', 'swiftforms' ), array( 'status' => 400 ) );
		}

		$allowed = (array) apply_filters( 'swf_allowed_upload_types', self::DEFAULT_ALLOWED_TYPES );
		$check   = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], $allowed );

		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'upload_error', __( 'That file type is not allowed.', 'swiftforms' ), array( 'status' => 400 ) );
		}

		$target_dir = $this->prepare_upload_dir();
		$filename   = hash_file( 'sha256', (string) $file['tmp_name'] ) . '.' . $check['ext'];
		$filename   = wp_unique_filename( $target_dir['path'], $filename );
		$target     = trailingslashit( $target_dir['path'] ) . $filename;

		if ( ! $this->move( (string) $file['tmp_name'], $target ) ) {
			return new WP_Error( 'upload_error', __( 'The file could not be saved.', 'swiftforms' ), array( 'status' => 500 ) );
		}

		return array(
			'name' => sanitize_file_name( (string) $file['name'] ),
			'path' => $target,
			'url'  => trailingslashit( $target_dir['url'] ) . $filename,
			'size' => $size,
		);
	}

	/**
	 * Whether a tmp file genuinely arrived via a file upload. During
	 * PHPUnit runs (WP_TESTS_DOMAIN is only ever defined by the test
	 * bootstrap, never in production) a plain readable temp file is
	 * accepted so tests can simulate uploads without a real HTTP request.
	 */
	private function is_genuine_upload( string $tmp_name ): bool {
		if ( is_uploaded_file( $tmp_name ) ) {
			return true;
		}

		return defined( 'WP_TESTS_DOMAIN' ) && is_readable( $tmp_name );
	}

	/**
	 * Moves the tmp file to its final destination.
	 */
	private function move( string $tmp_name, string $target ): bool {
		if ( is_uploaded_file( $tmp_name ) ) {
			return move_uploaded_file( $tmp_name, $target );
		}

		// Test-only path (see is_genuine_upload()).
		return copy( $tmp_name, $target );
	}

	/**
	 * Ensures `uploads/swf-uploads/{Y}/{m}/` exists and is protected against
	 * script execution, returning its path and URL.
	 *
	 * @return array{path: string, url: string}
	 */
	private function prepare_upload_dir(): array {
		$upload_dir = wp_upload_dir();
		$base_path  = trailingslashit( $upload_dir['basedir'] ) . 'swf-uploads';
		$base_url   = trailingslashit( $upload_dir['baseurl'] ) . 'swf-uploads';

		if ( ! is_dir( $base_path ) ) {
			wp_mkdir_p( $base_path );
			$this->protect_directory( $base_path );
		}

		$sub_path = '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$path     = $base_path . $sub_path;

		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}

		return array(
			'path' => $path,
			'url'  => $base_url . $sub_path,
		);
	}

	/**
	 * Drops an `index.html` and a `.htaccess` denying script execution into
	 * the upload root (Apache; harmless no-op elsewhere — Nginx hosts should
	 * add an equivalent `location` block themselves).
	 */
	private function protect_directory( string $path ): void {
		$index = trailingslashit( $path ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess = trailingslashit( $path ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "php_flag engine off\n<FilesMatch \"\\.(php|php\\d|phtml|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
