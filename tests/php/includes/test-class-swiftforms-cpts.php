<?php
/**
 * Tests for custom post type registration.
 */

declare(strict_types=1);

class SwiftForms_CPTs_Test extends WP_UnitTestCase {
	public function test_register_adds_form_post_type(): void {
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$this->assertNotNull( get_post_type_object( SwiftForms_CPTs::FORM_POST_TYPE ) );
	}

	public function test_register_adds_submission_post_type(): void {
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$this->assertNotNull( get_post_type_object( SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );
	}

	public function test_form_type_supports_expected_editor_features(): void {
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$this->assertTrue( post_type_supports( SwiftForms_CPTs::FORM_POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( SwiftForms_CPTs::FORM_POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( SwiftForms_CPTs::FORM_POST_TYPE, 'thumbnail' ) );
		$this->assertFalse( post_type_supports( SwiftForms_CPTs::FORM_POST_TYPE, 'revisions' ) );
	}

	public function test_form_type_supports_custom_fields_for_rest_meta(): void {
		// Without 'custom-fields' support, WordPress's REST API never exposes
		// or accepts the `meta` field for this post type, so _sf_settings
		// (the Form Experience / Notifications sidebar panel) could never be
		// read or saved through the block editor.
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$this->assertTrue( post_type_supports( SwiftForms_CPTs::FORM_POST_TYPE, 'custom-fields' ) );
	}

	public function test_submission_post_type_is_private(): void {
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$post_type = get_post_type_object( SwiftForms_CPTs::SUBMISSION_POST_TYPE );

		$this->assertFalse( $post_type->publicly_queryable );
		$this->assertTrue( $post_type->show_ui );
	}

	public function test_get_form_settings_returns_defaults_when_meta_is_missing(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		$settings = SwiftForms_CPTs::get_form_settings( $form_id );

		$this->assertSame( 'Send message', $settings['submitLabel'] );
		$this->assertSame( 'Form submitted successfully.', $settings['successMessage'] );
		$this->assertFalse( $settings['enableCaptcha'] );
	}

	public function test_register_form_settings_meta_exposes_rest_schema(): void {
		$cpts = new SwiftForms_CPTs();

		$cpts->register();

		$meta_keys = get_registered_meta_keys( 'post', SwiftForms_CPTs::FORM_POST_TYPE );

		$this->assertArrayHasKey( SwiftForms_CPTs::FORM_SETTINGS_META_KEY, $meta_keys );
		$this->assertSame( 'object', $meta_keys[ SwiftForms_CPTs::FORM_SETTINGS_META_KEY ]['type'] );
		$this->assertIsArray( $meta_keys[ SwiftForms_CPTs::FORM_SETTINGS_META_KEY ]['show_in_rest'] );
	}

	public function test_sanitize_form_settings_normalizes_form_level_values(): void {
		$settings = SwiftForms_CPTs::sanitize_form_settings(
			array(
				'adminRecipients' => " ops@example.org\nowner@example.org ",
				'adminSubject'    => '  New lead {submission_id}  ',
				'enableCaptcha'   => '1',
				'submitLabel'     => '  Send now  ',
				'successMessage'  => '  Thanks for reaching out.  ',
			)
		);

		$this->assertSame( "ops@example.org\nowner@example.org", $settings['adminRecipients'] );
		$this->assertSame( 'New lead {submission_id}', $settings['adminSubject'] );
		$this->assertTrue( $settings['enableCaptcha'] );
		$this->assertSame( 'Send now', $settings['submitLabel'] );
		$this->assertSame( 'Thanks for reaching out.', $settings['successMessage'] );
	}

	public function test_sanitize_form_settings_meta_returns_defaults_for_invalid_values(): void {
		$settings = SwiftForms_CPTs::sanitize_form_settings_meta( 'invalid' );

		$this->assertSame( 'Send message', $settings['submitLabel'] );
		$this->assertFalse( $settings['enableCaptcha'] );
	}

	public function test_filter_submission_columns_adds_form_and_email_columns(): void {
		$cpts = new SwiftForms_CPTs();

		$columns = $cpts->filter_submission_columns(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( 'Form', $columns['swiftforms_form'] );
		$this->assertSame( 'Email', $columns['swiftforms_email'] );
	}

	public function test_render_submission_column_outputs_form_title_and_email(): void {
		$cpts          = new SwiftForms_CPTs();
		$form_id       = self::factory()->post->create(
			array(
				'post_type'  => SwiftForms_CPTs::FORM_POST_TYPE,
				'post_title' => 'Contact Form',
			)
		);
		$submission_id = self::factory()->post->create(
			array(
				'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
			)
		);

		update_post_meta( $submission_id, '_sf_form_id', $form_id );
		update_post_meta( $submission_id, '_sf_field_email', 'person@example.com' );

		ob_start();
		$cpts->render_submission_column( 'swiftforms_form', $submission_id );
		$form_output = trim( (string) ob_get_clean() );

		ob_start();
		$cpts->render_submission_column( 'swiftforms_email', $submission_id );
		$email_output = trim( (string) ob_get_clean() );

		$this->assertSame( 'Contact Form', $form_output );
		$this->assertSame( 'person@example.com', $email_output );
	}

	public function test_build_export_rows_unions_field_columns_across_submissions(): void {
		$cpts = new SwiftForms_CPTs();
		$cpts->register();

		$first = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );
		update_post_meta( $first, '_sf_field_email', 'a@example.com' );

		$second = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );
		update_post_meta( $second, '_sf_field_email', 'b@example.com' );
		update_post_meta( $second, '_sf_field_full_name', 'Second Person' );

		$rows = $cpts->build_export_rows( array( $first, $second ) );

		$header = $rows[0];
		$this->assertSame( array( 'ID', 'Title', 'Date' ), array_slice( $header, 0, 3 ) );
		$this->assertContains( 'Email', $header );
		$this->assertContains( 'Full Name', $header );

		$full_name_index = array_search( 'Full Name', $header, true );
		$this->assertSame( '', $rows[1][ $full_name_index ] );
		$this->assertSame( 'Second Person', $rows[2][ $full_name_index ] );
	}

	public function test_build_export_rows_escapes_formula_injection_in_field_values(): void {
		// A value like "=cmd|'/c calc'!A1" executes as a formula the moment a
		// spreadsheet app opens the exported CSV. Prefixing with an
		// apostrophe forces it to be read as inert text instead.
		$cpts = new SwiftForms_CPTs();
		$cpts->register();

		$submission_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );
		update_post_meta( $submission_id, '_sf_field_comment', '=SUM(1+1)' );

		$rows = $cpts->build_export_rows( array( $submission_id ) );

		$header        = $rows[0];
		$comment_index = array_search( 'Comment', $header, true );

		$this->assertSame( "'=SUM(1+1)", $rows[1][ $comment_index ] );
	}

	public function test_get_form_field_schema_derives_fields_from_blocks(): void {
		$content = '<!-- wp:swiftforms/text-field {"label":"Name","slug":"full_name","required":true} /-->'
			. "\n" . '<!-- wp:core/group --><div class="wp-block-group">'
			. '<!-- wp:swiftforms/select-field {"label":"Topic","slug":"topic","options":"Sales\nSupport"} /-->'
			. '</div><!-- /wp:core/group -->'
			. "\n" . '<!-- wp:swiftforms/number-field {"slug":"guests","min":"1","max":"10","step":"1"} /-->'
			. "\n" . '<!-- wp:swiftforms/textarea-field {"slug":"notes"} /-->';

		$form_id = self::factory()->post->create(
			array(
				// wp_insert_post unslashes, and the select options carry a
				// JSON "\n" escape that must survive into the stored content.
				'post_content' => wp_slash( $content ),
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertSame( array( 'full_name', 'topic', 'guests', 'notes' ), array_keys( $schema ) );
		$this->assertSame( 'text', $schema['full_name']['type'] );
		$this->assertTrue( $schema['full_name']['required'] );
		$this->assertSame( 'select', $schema['topic']['type'] );
		$this->assertSame( "Sales\nSupport", $schema['topic']['options'] );
		$this->assertSame( '1', $schema['guests']['min'] );
		$this->assertSame( '10', $schema['guests']['max'] );
		// The textarea block saves data-field-type="text", so its schema type
		// must match what the frontend actually submits.
		$this->assertSame( 'text', $schema['notes']['type'] );
	}

	public function test_get_form_field_schema_uses_default_slug_when_attribute_is_omitted(): void {
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/email-field /-->',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertArrayHasKey( 'email', $schema );
		$this->assertSame( 'email', $schema['email']['type'] );
	}

	public function test_get_form_field_schema_returns_empty_for_invalid_form(): void {
		$this->assertSame( array(), SwiftForms_CPTs::get_form_field_schema( 987654 ) );
	}

	public function test_sanitize_form_settings_handles_new_lifecycle_and_integration_keys(): void {
		$settings = SwiftForms_CPTs::sanitize_form_settings(
			array(
				'autoresponderField' => 'Work Email!',
				'redirectUrl'        => 'https://example.org/thanks',
				'retentionDays'      => '-5',
				'webhookUrl'         => 'javascript:alert(1)',
			)
		);

		$this->assertSame( 'workemail', $settings['autoresponderField'] );
		$this->assertSame( 'https://example.org/thanks', $settings['redirectUrl'] );
		$this->assertSame( 0, $settings['retentionDays'] );
		$this->assertSame( '', $settings['webhookUrl'] );
	}

	public function test_sanitize_form_settings_whitelists_save_entries_mode(): void {
		$this->assertSame( 'disabled', SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'disabled' ) )['saveEntries'] );
		$this->assertSame( 'enabled', SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'enabled' ) )['saveEntries'] );
		$this->assertSame( 'default', SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'sometimes' ) )['saveEntries'] );
		$this->assertSame( 'default', SwiftForms_CPTs::sanitize_form_settings( array() )['saveEntries'] );
	}

	public function test_duplicate_form_copies_content_and_settings_as_draft(): void {
		$cpts    = new SwiftForms_CPTs();
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/text-field {"slug":"full_name"} /-->',
				'post_status'  => 'publish',
				'post_title'   => 'Contact',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);
		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'submitLabel' => 'Send it' ) )
		);

		$copy_id = $cpts->duplicate_form( $form_id );

		$this->assertIsInt( $copy_id );
		$copy = get_post( $copy_id );
		$this->assertSame( 'Contact (Copy)', $copy->post_title );
		$this->assertSame( 'draft', $copy->post_status );
		$this->assertStringContainsString( 'swiftforms/text-field', $copy->post_content );
		$this->assertSame( 'Send it', SwiftForms_CPTs::get_form_settings( $copy_id )['submitLabel'] );
	}

	public function test_duplicate_form_rejects_non_form_posts(): void {
		$cpts    = new SwiftForms_CPTs();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertWPError( $cpts->duplicate_form( $page_id ) );
	}

	public function test_delete_submission_uploads_removes_stored_file(): void {
		$cpts = new SwiftForms_CPTs();
		$cpts->register();

		$uploads    = wp_upload_dir();
		$target_dir = trailingslashit( $uploads['basedir'] ) . 'swiftforms/2026/07';
		wp_mkdir_p( $target_dir );
		$file_path = $target_dir . '/test-upload.txt';
		file_put_contents( $file_path, 'sample upload' );

		$submission_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );
		update_post_meta( $submission_id, '_sf_field_attachment', $file_path );

		wp_delete_post( $submission_id, true );

		$this->assertFileDoesNotExist( $file_path );
	}

	public function test_get_form_field_schema_includes_sanitized_conditions(): void {
		$content = '<!-- wp:swiftforms/select-field {"label":"Topic","slug":"topic","options":"support\nsales"} /-->'
			. "\n" . '<!-- wp:swiftforms/text-field {"label":"Details","slug":"details","conditions":{"enabled":true,"action":"show","groups":[[{"field":"topic","operator":"equals","value":"support"},{"field":"topic","operator":"bogus_operator","value":"x"}]]}} /-->';

		$form_id = self::factory()->post->create(
			array(
				'post_content' => wp_slash( $content ),
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertSame( array(), $schema['topic']['conditions'] );
		$this->assertSame( 'show', $schema['details']['conditions']['action'] );
		$this->assertCount( 1, $schema['details']['conditions']['groups'][0], 'Unknown operators must be dropped during sanitization.' );
		$this->assertSame(
			array(
				'field'    => 'topic',
				'operator' => 'equals',
				'value'    => 'support',
			),
			$schema['details']['conditions']['groups'][0][0]
		);
	}

	public function test_get_form_field_schema_collects_fields_inside_step_block(): void {
		$content = '<!-- wp:swiftforms/step {"title":"Step one"} --><div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="Step one">'
			. '<!-- wp:swiftforms/text-field {"label":"Name","slug":"full_name","required":true} /-->'
			. '</div><!-- /wp:swiftforms/step -->'
			. '<!-- wp:swiftforms/step {"title":"Step two"} --><div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="Step two">'
			. '<!-- wp:swiftforms/date-field {"label":"Date","slug":"event_date","min":"2026-01-01"} /-->'
			. '</div><!-- /wp:swiftforms/step -->';

		$form_id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertSame( array( 'full_name', 'event_date' ), array_keys( $schema ) );
		$this->assertTrue( $schema['full_name']['required'] );
		$this->assertSame( 'date', $schema['event_date']['type'] );
		$this->assertSame( '2026-01-01', $schema['event_date']['min'] );
	}

	public function test_get_form_field_schema_maps_radio_and_hidden_types(): void {
		$content = '<!-- wp:swiftforms/radio-field {"label":"Meal","slug":"meal","options":"Veggie|v\nMeat|m"} /-->'
			. "\n" . '<!-- wp:swiftforms/hidden-field {"slug":"campaign","value":"spring"} /-->';

		$form_id = self::factory()->post->create(
			array(
				'post_content' => wp_slash( $content ),
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertSame( 'radio', $schema['meal']['type'] );
		$this->assertSame( "Veggie|v\nMeat|m", $schema['meal']['options'] );
		$this->assertSame( 'hidden', $schema['campaign']['type'] );
	}

	public function test_form_settings_include_enable_turnstile(): void {
		$this->assertFalse( SwiftForms_CPTs::get_default_form_settings()['enableTurnstile'] );
		$this->assertTrue( SwiftForms_CPTs::sanitize_form_settings( array( 'enableTurnstile' => '1' ) )['enableTurnstile'] );

		$schema = ( new SwiftForms_CPTs() )->get_form_settings_meta_schema();
		$this->assertArrayHasKey( 'enableTurnstile', $schema['properties'], 'Missing REST schema entries silently drop editor saves.' );
	}

	public function test_unread_bubble_uses_cached_count_and_invalidates_on_new_entry(): void {
		global $menu;

		$cpts          = new SwiftForms_CPTs();
		$previous_menu = $menu;
		$menu          = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the admin menu for the bubble under test.
			array( 'Submissions', 'edit_posts', 'edit.php?post_type=' . SwiftForms_CPTs::SUBMISSION_POST_TYPE ),
		);

		self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_type'   => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
				'meta_input'  => array( '_sf_unread' => 1 ),
			)
		);

		$cpts->add_unread_count_bubble();
		$this->assertStringContainsString( 'pending-count">1<', $menu[0][0] );
		$this->assertSame( 1, (int) get_transient( 'swiftforms_unread_count' ) );

		// A new live submission invalidates the cache.
		$submissions = new SwiftForms_Submissions();
		$submissions->create_submission_post( array( 'form_id' => 0 ) );
		$this->assertFalse( get_transient( 'swiftforms_unread_count' ) );

		$menu = $previous_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the simulated menu.
		delete_transient( 'swiftforms_unread_count' );
	}

	public function test_field_value_search_respects_result_limit_filter(): void {
		$cpts = new SwiftForms_CPTs();
		$cpts->register();

		for ( $i = 0; $i < 3; $i++ ) {
			$submission_id = self::factory()->post->create(
				array(
					'post_status' => 'private',
					'post_type'   => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
				)
			);
			update_post_meta( $submission_id, '_sf_field_topic', 'needle-value' );
		}

		add_filter( 'swiftforms_search_results_limit', static fn (): int => 2 );

		$query = new WP_Query();
		$query->init();
		$query->set( 'post_type', SwiftForms_CPTs::SUBMISSION_POST_TYPE );
		$query->set( 's', 'needle-value' );

		// The hook only acts on the admin main query.
		$previous_main_query     = $GLOBALS['wp_the_query'];
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the admin main query under test.
		set_current_screen( 'edit-' . SwiftForms_CPTs::SUBMISSION_POST_TYPE );

		$cpts->apply_field_value_search( $query );

		set_current_screen( 'front' );
		$GLOBALS['wp_the_query'] = $previous_main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the main query.

		remove_all_filters( 'swiftforms_search_results_limit' );

		$matched = (array) $query->get( 'post__in' );
		$this->assertCount( 2, $matched, 'The meta search must honor the result limit filter.' );
	}
}
