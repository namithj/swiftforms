<?php
/**
 * Uninstall cleanup.
 *
 * By default SwiftForms leaves all forms and entries behind on uninstall
 * (deleting the plugin is not the same as saying "delete my leads"). Site
 * owners opt in from SwiftForms → Settings → Advanced, or programmatically:
 *
 *     add_filter( 'swf_uninstall_delete_data', '__return_true' );
 *
 * @package SwiftForms
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'swf_retention_cleanup' );

$swf_delete = ! empty( get_option( 'swf_settings_uninstallDeleteData' ) );

if ( ! apply_filters( 'swf_uninstall_delete_data', $swf_delete ) ) {
	return;
}

foreach ( array( 'swf_form', 'swf_entry' ) as $swf_post_type ) {
	// Bounded batches so a large site can't exhaust memory building one huge
	// ID array; each pass re-queries page one after deleting.
	do {
		$swf_post_ids = get_posts(
			array(
				'fields'         => 'ids',
				'post_status'    => 'any',
				'post_type'      => $swf_post_type,
				'posts_per_page' => 100,
			)
		);

		foreach ( $swf_post_ids as $swf_post_id ) {
			wp_delete_post( $swf_post_id, true );
		}
	} while ( ! empty( $swf_post_ids ) );
}

global $wpdb;

// Raw query, not get_terms()/wp_delete_term(): uninstall.php runs without the
// plugin's own `init` hook ever firing, so `swf_entry_form` was never
// registered as a taxonomy in this request.
$wpdb->query(
	$wpdb->prepare(
		"DELETE t, tt FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
		'swf_entry_form'
	)
);

$swf_uploads    = wp_upload_dir();
$swf_upload_dir = trailingslashit( $swf_uploads['basedir'] ) . 'swf-uploads';

if ( is_dir( $swf_upload_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $swf_upload_dir, true );
	}
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'swf_settings_' ) . '%'
	)
);
