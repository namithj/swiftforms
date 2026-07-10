<?php
/**
 * Uninstall cleanup.
 *
 * By default SwiftForms leaves all forms and submissions behind on uninstall
 * (deleting the plugin is not the same as saying "delete my leads"). Site
 * owners opt in from Forms → Settings → Advanced, or programmatically from a
 * must-use plugin (regular plugins don't run during uninstall):
 *
 *     add_filter( 'swiftforms_uninstall_delete_data', '__return_true' );
 *
 * @package SwiftForms
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'swiftforms_daily_cleanup' );

$swiftforms_settings = get_option( 'swiftforms_settings' );
$swiftforms_delete   = is_array( $swiftforms_settings ) && ! empty( $swiftforms_settings['uninstallDeleteData'] );

if ( ! apply_filters( 'swiftforms_uninstall_delete_data', $swiftforms_delete ) ) {
	return;
}

foreach ( array( 'swiftforms_form', 'swiftform_entry' ) as $swiftforms_post_type ) {
	// Bounded batches so a large site can't exhaust memory building one huge
	// ID array; each pass re-queries page one after deleting.
	do {
		$swiftforms_post_ids = get_posts(
			array(
				'fields'         => 'ids',
				'post_status'    => 'any',
				'post_type'      => $swiftforms_post_type,
				'posts_per_page' => 100,
			)
		);

		foreach ( $swiftforms_post_ids as $swiftforms_post_id ) {
			wp_delete_post( $swiftforms_post_id, true );
		}
	} while ( ! empty( $swiftforms_post_ids ) );
}

$swiftforms_uploads    = wp_upload_dir();
$swiftforms_upload_dir = trailingslashit( $swiftforms_uploads['basedir'] ) . 'swiftforms';

if ( is_dir( $swiftforms_upload_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $swiftforms_upload_dir, true );
	}
}

delete_option( 'swiftforms_db_version' );
delete_option( 'swiftforms_settings' );
delete_transient( 'swiftforms_unread_count' );
