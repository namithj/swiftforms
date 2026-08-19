<?php
/**
 * Tests for private entry-upload downloads.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Entries;

use SwiftForms\Entries\EntryDownloadController;
use SwiftForms\PostTypes;
use SwiftForms\Submissions\UploadHandler;
use SwiftForms\Tests\TestCase;

final class EntryDownloadControllerTest extends TestCase {

	public function test_download_url_requires_capability_and_managed_file(): void {
		$directory = UploadHandler::private_upload_dir();
		wp_mkdir_p( $directory );
		$path = trailingslashit( $directory ) . 'entry-download-test.txt';
		file_put_contents( $path, 'private attachment' );

		$entry_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::ENTRY_POST_TYPE,
				'post_status' => 'private',
			)
		);
		update_post_meta(
			$entry_id,
			'swf_field_attachment',
			array(
				'name' => 'entry-download-test.txt',
				'path' => $path,
			)
		);

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$url = ( new EntryDownloadController() )->url( $entry_id, 'attachment' );
		$this->assertStringContainsString( 'action=swf_download_entry_upload', $url );
		$this->assertStringContainsString( 'entry_id=' . $entry_id, $url );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertSame( '', ( new EntryDownloadController() )->url( $entry_id, 'attachment' ) );

		wp_set_current_user( $administrator );
		update_post_meta(
			$entry_id,
			'swf_field_forged',
			array(
				'name' => 'forged.txt',
				'path' => wp_tempnam( 'swf-forged' ),
			)
		);
		$this->assertSame( '', ( new EntryDownloadController() )->url( $entry_id, 'forged' ) );

		wp_delete_file( $path );
	}
}
