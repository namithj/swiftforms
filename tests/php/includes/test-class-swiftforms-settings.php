<?php
/**
 * Tests for the global settings service.
 */

declare(strict_types=1);

class SwiftForms_Settings_Test extends WP_UnitTestCase {
	private SwiftForms_Settings $settings;

	public function set_up(): void {
		parent::set_up();

		$this->settings = new SwiftForms_Settings();
	}

	public function tear_down(): void {
		delete_option( SwiftForms_Settings::OPTION_KEY );

		parent::tear_down();
	}

	public function test_get_settings_returns_defaults_when_option_missing(): void {
		$settings = SwiftForms_Settings::get_settings();

		$this->assertFalse( $settings['smtpEnabled'] );
		$this->assertSame( 587, $settings['smtpPort'] );
		$this->assertSame( 'tls', $settings['smtpEncryption'] );
		$this->assertSame( 5, $settings['rateLimitMaxRequests'] );
		$this->assertSame( 60, $settings['rateLimitWindowSeconds'] );
		$this->assertTrue( $settings['saveEntriesDefault'] );
	}

	public function test_sanitize_settings_whitelists_encryption_and_clamps_port(): void {
		$settings = SwiftForms_Settings::sanitize_settings(
			array(
				'smtpEncryption' => 'starttls-hack',
				'smtpPort'       => '99999',
			)
		);

		$this->assertSame( 'tls', $settings['smtpEncryption'] );
		$this->assertSame( 65535, $settings['smtpPort'] );
	}

	public function test_sanitize_settings_rejects_invalid_from_email(): void {
		$settings = SwiftForms_Settings::sanitize_settings( array( 'smtpFromEmail' => 'not-an-email' ) );

		$this->assertSame( '', $settings['smtpFromEmail'] );
	}

	public function test_sanitize_settings_enforces_rate_limit_minimums(): void {
		$settings = SwiftForms_Settings::sanitize_settings(
			array(
				'rateLimitMaxRequests'   => '0',
				'rateLimitWindowSeconds' => '1',
			)
		);

		$this->assertSame( 1, $settings['rateLimitMaxRequests'] );
		$this->assertSame( 10, $settings['rateLimitWindowSeconds'] );
	}

	public function test_blank_submitted_password_keeps_stored_password(): void {
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings( array( 'smtpPassword' => 'stored-secret' ) )
		);

		// Saving the settings form with the password field left blank must not
		// wipe the stored secret.
		$settings = SwiftForms_Settings::sanitize_settings(
			array(
				'smtpHost'     => 'smtp.example.com',
				'smtpPassword' => '',
			)
		);

		$this->assertSame( 'stored-secret', $settings['smtpPassword'] );
	}

	public function test_configure_phpmailer_applies_smtp_settings_when_enabled(): void {
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings(
				array(
					'smtpEnabled'    => '1',
					'smtpEncryption' => 'ssl',
					'smtpFromEmail'  => 'forms@example.org',
					'smtpFromName'   => 'SwiftForms Bot',
					'smtpHost'       => 'smtp.example.org',
					'smtpPassword'   => 'secret',
					'smtpPort'       => '465',
					'smtpUsername'   => 'mailer',
				)
			)
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$this->settings->configure_phpmailer( $mailer );

		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp.example.org', $mailer->Host );
		$this->assertSame( 465, $mailer->Port );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
		$this->assertTrue( $mailer->SMTPAuth );
		$this->assertSame( 'mailer', $mailer->Username );
		$this->assertSame( 'secret', $mailer->Password );
		$this->assertSame( 'forms@example.org', $mailer->From );
		$this->assertSame( 'SwiftForms Bot', $mailer->FromName );
	}

	public function test_configure_phpmailer_is_noop_when_disabled(): void {
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings(
				array(
					'smtpEnabled' => '',
					'smtpHost'    => 'smtp.example.org',
				)
			)
		);

		$mailer                    = tests_retrieve_phpmailer_instance();
		$original_mailer_transport = $mailer->Mailer;

		$this->settings->configure_phpmailer( $mailer );

		$this->assertSame( $original_mailer_transport, $mailer->Mailer );
	}

	public function test_rate_limit_filters_return_option_values(): void {
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings(
				array(
					'rateLimitMaxRequests'   => 20,
					'rateLimitWindowSeconds' => 120,
				)
			)
		);

		$this->settings->register();

		$this->assertSame( 20, apply_filters( 'swiftforms_rate_limit_max_requests', 5 ) );
		$this->assertSame( 120, apply_filters( 'swiftforms_rate_limit_window_seconds', 60 ) );

		// Explicit filters at default priority still override the option.
		add_filter( 'swiftforms_rate_limit_max_requests', static fn (): int => 99 );
		$this->assertSame( 99, apply_filters( 'swiftforms_rate_limit_max_requests', 5 ) );
		remove_all_filters( 'swiftforms_rate_limit_max_requests' );
		remove_all_filters( 'swiftforms_rate_limit_window_seconds' );
	}

	public function test_should_save_entries_resolves_tri_state_against_global_default(): void {
		( new SwiftForms_CPTs() )->register();

		$default_form  = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		$enabled_form  = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		$disabled_form = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		update_post_meta( $enabled_form, SwiftForms_CPTs::FORM_SETTINGS_META_KEY, SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'enabled' ) ) );
		update_post_meta( $disabled_form, SwiftForms_CPTs::FORM_SETTINGS_META_KEY, SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'disabled' ) ) );

		// Global default on (the default).
		$this->assertTrue( SwiftForms_Settings::should_save_entries( $default_form ) );
		$this->assertTrue( SwiftForms_Settings::should_save_entries( $enabled_form ) );
		$this->assertFalse( SwiftForms_Settings::should_save_entries( $disabled_form ) );

		// Global default off: 'default' forms follow it, explicit ones don't.
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings( array( 'saveEntriesDefault' => '' ) )
		);

		$this->assertFalse( SwiftForms_Settings::should_save_entries( $default_form ) );
		$this->assertTrue( SwiftForms_Settings::should_save_entries( $enabled_form ) );
		$this->assertFalse( SwiftForms_Settings::should_save_entries( $disabled_form ) );
	}

	public function test_settings_page_is_registered_under_forms_menu(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->settings->register();
		do_action( 'admin_menu' );

		global $submenu;

		$forms_menu = $submenu[ 'edit.php?post_type=' . SwiftForms_CPTs::FORM_POST_TYPE ] ?? array();
		$slugs      = wp_list_pluck( $forms_menu, 2 );

		$this->assertContains( SwiftForms_Settings::PAGE_SLUG, $slugs );
	}

	public function test_sanitize_settings_keeps_stored_turnstile_secret_when_blank(): void {
		update_option( SwiftForms_Settings::OPTION_KEY, array( 'turnstileSecretKey' => 'stored-secret' ) );

		$sanitized = SwiftForms_Settings::sanitize_settings( array( 'turnstileSecretKey' => '' ) );
		$this->assertSame( 'stored-secret', $sanitized['turnstileSecretKey'] );

		$sanitized = SwiftForms_Settings::sanitize_settings( array( 'turnstileSecretKey' => 'new-secret' ) );
		$this->assertSame( 'new-secret', $sanitized['turnstileSecretKey'] );

		delete_option( SwiftForms_Settings::OPTION_KEY );
	}

	public function test_min_submit_seconds_filter_reads_option(): void {
		( new SwiftForms_Settings() )->register();
		update_option( SwiftForms_Settings::OPTION_KEY, array( 'minSubmitSeconds' => 9 ) );

		$this->assertSame( 9, apply_filters( 'swiftforms_min_submit_seconds', 3 ) );

		// Explicit higher-priority filters still win (option feeds priority 5).
		add_filter( 'swiftforms_min_submit_seconds', static fn (): int => 42 );
		$this->assertSame( 42, apply_filters( 'swiftforms_min_submit_seconds', 3 ) );

		remove_all_filters( 'swiftforms_min_submit_seconds' );
		delete_option( SwiftForms_Settings::OPTION_KEY );
	}
}
