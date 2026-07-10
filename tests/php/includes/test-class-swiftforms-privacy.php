<?php
/**
 * Tests for privacy tooling and data lifecycle.
 */

declare(strict_types=1);

class SwiftForms_Privacy_Test extends WP_UnitTestCase {
	private SwiftForms_Privacy $privacy;

	public function set_up(): void {
		parent::set_up();

		( new SwiftForms_CPTs() )->register();
		$this->privacy = new SwiftForms_Privacy();
	}

	private function create_submission( array $fields, int $form_id = 0, string $post_date = '' ): int {
		$args = array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE );

		if ( '' !== $post_date ) {
			$args['post_date']     = $post_date;
			$args['post_date_gmt'] = $post_date;
		}

		$submission_id = self::factory()->post->create( $args );

		foreach ( $fields as $slug => $value ) {
			update_post_meta( $submission_id, '_sf_field_' . $slug, $value );
		}

		if ( $form_id > 0 ) {
			update_post_meta( $submission_id, '_sf_form_id', $form_id );
		}

		return $submission_id;
	}

	public function test_register_adds_exporter_and_eraser(): void {
		$this->privacy->register();

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'swiftforms', $exporters );
		$this->assertArrayHasKey( 'swiftforms', $erasers );
	}

	public function test_register_schedules_daily_cleanup(): void {
		wp_clear_scheduled_hook( SwiftForms_Privacy::CLEANUP_HOOK );

		$this->privacy->register();

		$this->assertNotFalse( wp_next_scheduled( SwiftForms_Privacy::CLEANUP_HOOK ) );
	}

	public function test_export_personal_data_finds_submissions_by_email(): void {
		$matching = $this->create_submission(
			array(
				'email' => 'person@example.com',
				'name'  => 'Taylor',
			)
		);
		$this->create_submission( array( 'email' => 'other@example.com' ) );

		$result = $this->privacy->export_personal_data( 'person@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'swiftform-entry-' . $matching, $result['data'][0]['item_id'] );

		$values = wp_list_pluck( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Taylor', $values['Name'] );
	}

	public function test_erase_personal_data_deletes_matching_submissions(): void {
		$matching = $this->create_submission( array( 'email' => 'person@example.com' ) );
		$other    = $this->create_submission( array( 'email' => 'other@example.com' ) );

		$result = $this->privacy->erase_personal_data( 'person@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['items_removed'] );
		$this->assertNull( get_post( $matching ) );
		$this->assertNotNull( get_post( $other ) );
	}

	public function test_cleanup_deletes_only_expired_submissions_for_forms_with_retention(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'retentionDays' => 30 ) )
		);

		$expired = $this->create_submission(
			array( 'email' => 'old@example.com' ),
			$form_id,
			gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS )
		);
		$fresh   = $this->create_submission( array( 'email' => 'new@example.com' ), $form_id );

		// A submission for a form with no retention limit must never expire.
		$unlimited_form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		$unlimited         = $this->create_submission(
			array( 'email' => 'keep@example.com' ),
			$unlimited_form_id,
			gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS )
		);

		$this->privacy->cleanup_expired_submissions();

		$this->assertNull( get_post( $expired ) );
		$this->assertNotNull( get_post( $fresh ) );
		$this->assertNotNull( get_post( $unlimited ) );
	}

	public function test_cleanup_deletes_more_than_one_batch(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'retentionDays' => 30 ) )
		);

		// One more than the 100-per-batch page size forces a second pass.
		$expired_ids = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$expired_ids[] = self::factory()->post->create(
				array(
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
					'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
					'post_status'   => 'private',
					'post_type'     => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
					'meta_input'    => array( '_sf_form_id' => $form_id ),
				)
			);
		}

		$this->privacy->cleanup_expired_submissions();

		foreach ( $expired_ids as $expired_id ) {
			$this->assertNull( get_post( $expired_id ) );
		}
	}
}
