<?php
/**
 * GDPR personal data export/erase and entry retention.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Registers with WordPress's built-in personal-data exporter/eraser tools
 * (Tools → Export/Erase Personal Data) and runs a daily cron that deletes
 * entries past each form's configured retention period.
 */
final class Privacy implements Registrable {

	public const CLEANUP_HOOK = 'smartlogix_swiftforms_retention_cleanup';

	private const BATCH_SIZE = 50;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'register_policy_content' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired_entries' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * @param array<string, mixed> $exporters Existing exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['swiftforms'] = array(
			'exporter_friendly_name' => __( 'SwiftForms Entries', 'swiftforms' ),
			'callback'               => array( $this, 'export_personal_data' ),

		);

		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers Existing erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['swiftforms'] = array(
			'eraser_friendly_name' => __( 'SwiftForms Entries', 'swiftforms' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}
	public function register_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'SwiftForms', 'swiftforms' ), wp_kses_post( __( '<p>When visitors submit a SwiftForms form, we collect the fields shown in that form and any files they choose to upload. If entry storage is enabled, this information is stored privately on this website for the retention period chosen by the site owner, which may include indefinite retention. A short-lived hash derived from the visitor IP address and form ID may be stored in the WordPress cache to limit abusive submission rates.</p><p>Submission data may be sent to this website’s email or SMTP provider and to webhook destinations configured by the site owner. If enabled, field content and the visitor IP address may be sent to Akismet for spam classification. If Cloudflare Turnstile is enabled, the visitor browser connects to Cloudflare, which processes a verification token and IP address. These providers may process data in other countries under their own terms.</p><p>Site owners should identify the fields collected, purposes, recipients, provider locations, retention periods, and legal basis in their final policy. WordPress personal-data export and erasure tools can locate stored entries by submitted email address. Erasure may be limited where retention is legally required, and data already delivered to email, SMTP, Akismet, Cloudflare, or webhook providers must be handled separately with those recipients.</p>', 'swiftforms' ) ) );
		}
	}


	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export_personal_data( string $email, int $page = 1 ): array {
		$entries = $this->find_entries_by_email( $email, $page );
		$items   = array();

		foreach ( $entries as $entry_id ) {
			$fields = array();

			foreach ( get_post_meta( $entry_id ) as $key => $meta_values ) {
				if ( str_starts_with( $key, 'smartlogix_swiftforms_field_' ) ) {
					$fields[] = array(
						'name'  => substr( $key, strlen( 'smartlogix_swiftforms_field_' ) ),
						'value' => maybe_unserialize( $meta_values[0] ?? '' ),
					);
				}
			}

			$items[] = array(
				'group_id'    => 'swiftforms-entries',
				'group_label' => __( 'SwiftForms Entries', 'swiftforms' ),
				'item_id'     => "swiftforms-entry-{$entry_id}",
				'data'        => $fields,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $entries ) < self::BATCH_SIZE,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_personal_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- deleting a batch shifts the remaining entries to page one.
		$entries = $this->find_entries_by_email( $email, 1 );

		foreach ( $entries as $entry_id ) {
			wp_delete_post( $entry_id, true );
		}

		return array(
			'items_removed'  => (bool) $entries,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $entries ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Deletes entries past their form's configured retention period.
	 */
	public function cleanup_expired_entries(): void {
		$forms = get_posts(
			array(
				'post_type'      => PostTypes::FORM_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $forms as $form_id ) {
			$retention_days = (int) Settings\FormSettings::get( $form_id )['retentionDays'];

			if ( $retention_days <= 0 ) {
				continue;
			}

			$this->delete_entries_older_than( $form_id, $retention_days );
		}
	}

	/**
	 * @return int[] Entry post ids.
	 */
	private function find_entries_by_email( string $email, int $page ): array {
		global $wpdb;

		$offset = ( max( 1, $page ) - 1 ) * self::BATCH_SIZE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_value = %s ORDER BY post_id ASC LIMIT %d OFFSET %d",
				$wpdb->esc_like( 'smartlogix_swiftforms_field_' ) . '%',
				$email,
				self::BATCH_SIZE,
				$offset
			)
		);

		return array_map( 'intval', $ids );
	}

	private function delete_entries_older_than( int $form_id, int $days ): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		for ( $batch = 0; $batch < 50; $batch++ ) {
			$entries = get_posts(
				array(
					'post_type'      => PostTypes::ENTRY_POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'date_query'     => array( array( 'before' => $cutoff ) ),
					'tax_query'      => array(
						array(
							'taxonomy' => PostTypes::ENTRY_FORM_TAXONOMY,
							'field'    => 'slug',
							'terms'    => PostTypes::entry_term_slug( $form_id ),
						),
					),
				)
			);

			if ( ! $entries ) {
				break;
			}

			foreach ( $entries as $entry_id ) {
				wp_delete_post( $entry_id, true );
			}

			if ( count( $entries ) < 100 ) {
				break;
			}
		}
	}
}
