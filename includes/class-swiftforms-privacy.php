<?php
/**
 * Privacy and data lifecycle: GDPR export/erase integration and retention cleanup.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * GDPR personal data export/erase handlers and retention cleanup.
 */
class SwiftForms_Privacy {
	/**
	 * Cron hook for the daily retention cleanup.
	 */
	public const CLEANUP_HOOK = 'swiftforms_daily_cleanup';

	/**
	 * Submissions processed per exporter/eraser batch.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Wires privacy tooling and the retention cron into WordPress.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired_submissions' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Registers the submissions exporter with core's privacy tools.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['swiftforms'] = array(
			'callback'               => array( $this, 'export_personal_data' ),
			'exporter_friendly_name' => __( 'SwiftForms Submissions', 'swiftforms' ),
		);

		return $exporters;
	}

	/**
	 * Registers the submissions eraser with core's privacy tools.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['swiftforms'] = array(
			'callback'             => array( $this, 'erase_personal_data' ),
			'eraser_friendly_name' => __( 'SwiftForms Submissions', 'swiftforms' ),
		);

		return $erasers;
	}

	/**
	 * Exports every submission that stored the given email address in a field.
	 *
	 * @param string $email Email address being exported.
	 * @param int    $page  1-based batch number, incremented by core per pass.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export_personal_data( string $email, int $page = 1 ): array {
		$submission_ids = $this->find_submissions_by_email( $email, max( 1, $page ) );
		$cpts           = new SwiftForms_CPTs();
		$data           = array();

		foreach ( $submission_ids as $submission_id ) {
			$items = array(
				array(
					'name'  => __( 'Submission ID', 'swiftforms' ),
					'value' => (string) $submission_id,
				),
				array(
					'name'  => __( 'Submitted', 'swiftforms' ),
					'value' => (string) get_the_date( 'Y-m-d H:i:s', $submission_id ),
				),
			);

			foreach ( $cpts->get_submission_field_values( $submission_id ) as $slug => $value ) {
				$items[] = array(
					'name'  => ucwords( str_replace( array( '_', '-' ), ' ', $slug ) ),
					'value' => $value,
				);
			}

			$data[] = array(
				'data'        => $items,
				'group_id'    => 'swiftforms_submissions',
				'group_label' => __( 'Form Submissions', 'swiftforms' ),
				'item_id'     => 'swiftform-entry-' . $submission_id,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $submission_ids ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Permanently deletes every submission that stored the given email address.
	 *
	 * Always queries batch 1: each pass deletes what it finds, so the
	 * remaining matches shift down into the first page.
	 *
	 * @param string $email Email address being erased.
	 * @param int    $page  Unused batch number from core.
	 *
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public function erase_personal_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature fixed by the WP eraser callback contract.
		$submission_ids = $this->find_submissions_by_email( $email, 1 );
		$removed        = 0;

		foreach ( $submission_ids as $submission_id ) {
			// Force delete also removes uploaded files via the
			// before_delete_post cleanup in SwiftForms_CPTs.
			if ( wp_delete_post( $submission_id, true ) ) {
				++$removed;
			}
		}

		return array(
			'done'           => count( $submission_ids ) < self::BATCH_SIZE,
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
		);
	}

	/**
	 * Deletes submissions older than each form's configured retention window.
	 *
	 * Runs daily via WP-Cron. Forms with retentionDays of 0 (the default)
	 * keep their submissions forever.
	 */
	public function cleanup_expired_submissions(): void {
		$form_ids = get_posts(
			array(
				'fields'         => 'ids',
				'post_status'    => 'any',
				'post_type'      => SwiftForms_CPTs::FORM_POST_TYPE,
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded daily cron sweep over form posts, which number in the dozens.
			)
		);

		foreach ( $form_ids as $form_id ) {
			$settings = SwiftForms_CPTs::get_form_settings( (int) $form_id );
			$days     = (int) ( $settings['retentionDays'] ?? 0 );

			if ( $days <= 0 ) {
				continue;
			}

			// Delete in bounded batches: a site with years of expired entries
			// must not build one giant ID array in memory. Each pass re-runs
			// the query (deleting shifts the remainder into page one), and
			// the iteration cap keeps a pathological backlog from pinning the
			// cron worker — the daily schedule finishes the rest tomorrow.
			$batch_query = array(
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
						'column' => 'post_date_gmt',
					),
				),
				'fields'         => 'ids',
				'meta_key'       => '_sf_form_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $form_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'    => 'any',
				'post_type'      => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
				'posts_per_page' => 100,
			);

			for ( $iteration = 0; $iteration < 50; $iteration++ ) {
				$expired_ids = get_posts( $batch_query );

				if ( empty( $expired_ids ) ) {
					break;
				}

				foreach ( $expired_ids as $submission_id ) {
					wp_delete_post( (int) $submission_id, true );
				}
			}
		}
	}

	/**
	 * Finds submission IDs holding the given email in any `_sf_field_*` meta value.
	 *
	 * @param string $email Email address to match exactly.
	 * @param int    $page  1-based batch number.
	 *
	 * @return int[]
	 */
	private function find_submissions_by_email( string $email, int $page ): array {
		if ( '' === trim( $email ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'compare'     => '=',
						'compare_key' => 'LIKE',
						'key'         => '_sf_field_',
						'value'       => $email,
					),
				),
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'paged'          => $page,
				'post_status'    => 'any',
				'post_type'      => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
				'posts_per_page' => self::BATCH_SIZE,
			)
		);

		return array_map( 'intval', $ids );
	}
}
