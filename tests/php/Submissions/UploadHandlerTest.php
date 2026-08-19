<?php
/**
 * Tests for SwiftForms\Submissions\UploadHandler.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\UploadHandler;
use SwiftForms\Tests\TestCase;

final class UploadHandlerTest extends TestCase {

	private array $temp_files = array();

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tear_down();
	}

	private function temp_file( string $content ): string {
		$path               = wp_tempnam( 'swf-upload-test' );
		$this->temp_files[] = $path;
		file_put_contents( $path, $content );

		return $path;
	}

	public function test_no_file_returns_null(): void {
		$handler = new UploadHandler();

		$this->assertNull( $handler->handle( array() ) );
	}

	public function test_a_disallowed_file_type_is_rejected(): void {
		$path = $this->temp_file( '<?php echo "not really php, but has the wrong extension"; ?>' );

		$handler = new UploadHandler();
		$result  = $handler->handle(
			array(
				'name'     => 'evil.php',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $path ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_an_allowed_text_file_is_accepted_and_moved(): void {
		$path = $this->temp_file( "Hello, this is a plain text attachment.\n" );

		$handler = new UploadHandler();
		$result  = $handler->handle(
			array(
				'name'     => 'notes.txt',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $path ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringEndsWith( '.txt', $result['path'] );
		$this->assertFileExists( $result['path'] );
		$this->assertStringContainsString( 'swf-uploads', $result['path'] );

		unlink( $result['path'] );
	}

	public function test_a_file_over_the_max_upload_size_is_rejected(): void {
		$path = $this->temp_file( 'small file' );

		$handler = new UploadHandler();
		$result  = $handler->handle(
			array(
				'name'     => 'notes.txt',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => wp_max_upload_size() + 1,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_the_allowed_types_filter_can_restrict_further(): void {
		add_filter( 'swf_allowed_upload_types', fn() => array( 'pdf' => 'application/pdf' ) );

		$path = $this->temp_file( "Hello, this is a plain text attachment.\n" );

		$handler = new UploadHandler();
		$result  = $handler->handle(
			array(
				'name'     => 'notes.txt',
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'size'     => filesize( $path ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
