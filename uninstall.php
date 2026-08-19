<?php
/**
 * Uninstall cleanup.
 *
 * By default SwiftForms leaves all forms and entries behind on uninstall
 * (deleting the plugin is not the same as saying "delete my leads"). Site
 * owners opt in from SwiftForms → Settings → Advanced, or programmatically:
 *
 *     add_filter( 'smartlogix_swiftforms_uninstall_delete_data', '__return_true' );
 *
 * @package SwiftForms
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'smartlogix_swiftforms_retention_cleanup' );

$smartlogix_swiftforms_delete = ! empty( get_option( 'smartlogix_swiftforms_settings_uninstallDeleteData' ) );

if ( ! apply_filters( 'smartlogix_swiftforms_uninstall_delete_data', $smartlogix_swiftforms_delete ) ) {
	return;
}

foreach ( array( 'smartlogix_swf_form', 'smartlogix_swf_entry' ) as $smartlogix_swiftforms_post_type ) {
	// Bounded batches so a large site can't exhaust memory building one huge
	// ID array; each pass re-queries page one after deleting.
	do {
		$smartlogix_swiftforms_post_ids = get_posts(
			array(
				'fields'         => 'ids',
				'post_status'    => 'any',
				'post_type'      => $smartlogix_swiftforms_post_type,
				'posts_per_page' => 100,
			)
		);

		foreach ( $smartlogix_swiftforms_post_ids as $smartlogix_swiftforms_post_id ) {
			wp_delete_post( $smartlogix_swiftforms_post_id, true );
		}
	} while ( ! empty( $smartlogix_swiftforms_post_ids ) );
}

global $wpdb;

// Raw query, not get_terms()/wp_delete_term(): uninstall.php runs without the
// plugin's own `init` hook ever firing, so `smartlogix_swf_entry_form` was never
// registered as a taxonomy in this request.
$wpdb->query(
	$wpdb->prepare(
		"DELETE t, tt FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s",
		'smartlogix_swf_entry_form'
	)
);

$smartlogix_swiftforms_upload_dir = trailingslashit( dirname( ABSPATH ) ) . 'swiftforms-uploads';

if ( is_dir( $smartlogix_swiftforms_upload_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $smartlogix_swiftforms_upload_dir, true );
	}
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'smartlogix_swiftforms_settings_' ) . '%'
	)
);
