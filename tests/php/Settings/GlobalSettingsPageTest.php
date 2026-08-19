<?php
/**
 * Tests for SwiftForms\Settings\GlobalSettingsPage.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Settings;

use SwiftForms\Settings\GlobalSettings;
use SwiftForms\Settings\GlobalSettingsPage;
use SwiftForms\Tests\TestCase;

final class GlobalSettingsPageTest extends TestCase {

	private GlobalSettingsPage $page;

	public function set_up(): void {
		parent::set_up();

		// The plugin is already booted (see tests/bootstrap.php), so this
		// is the real, already-registered instance — same one Cassette-CMF
		// and Design\DesignSystem's `smartlogix_swiftforms_settings_schema` filter feed into.
		$this->page = smartlogix_swiftforms()->container()->get( 'global_settings_page' );
	}

	public function tear_down(): void {
		foreach ( $this->seedable_option_names() as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	public function test_seed_defaults_populates_every_field_with_its_schema_default(): void {
		$this->page->seed_defaults();

		$this->assertSame( '1', get_option( 'smartlogix_swiftforms_settings_saveEntriesDefault' ) );
		$this->assertSame( 587, get_option( 'smartlogix_swiftforms_settings_smtpPort' ) );
		$this->assertSame( 'tls', get_option( 'smartlogix_swiftforms_settings_smtpEncryption' ) );
		$this->assertSame( 5, get_option( 'smartlogix_swiftforms_settings_rateLimitMaxRequests' ) );
	}

	public function test_seed_defaults_includes_design_tab_fields_contributed_via_the_filter(): void {
		$this->page->seed_defaults();

		$this->assertSame( 'default', get_option( 'smartlogix_swiftforms_settings_designSkin' ) );
		$this->assertSame( '#2563eb', get_option( 'smartlogix_swiftforms_settings_designAccent' ) );
	}

	public function test_seed_defaults_does_not_overwrite_an_existing_value(): void {
		update_option( 'smartlogix_swiftforms_settings_smtpPort', 2525 );

		$this->page->seed_defaults();

		$this->assertSame( 2525, get_option( 'smartlogix_swiftforms_settings_smtpPort' ) );
	}

	public function test_global_settings_get_reads_the_seeded_value_back(): void {
		$this->page->seed_defaults();

		$this->assertSame( 587, GlobalSettings::instance()->get( 'smtpPort', 0 ) );
	}

	public function test_global_settings_get_falls_back_when_the_option_does_not_exist(): void {
		$this->assertSame( 'fallback', GlobalSettings::instance()->get( 'not_a_real_setting', 'fallback' ) );
	}

	public function test_secret_constant_names_use_the_full_plugin_prefix(): void {
		$this->assertSame( 'SMARTLOGIX_SWIFTFORMS_SMTP_PASSWORD', GlobalSettings::constant_for( 'smtpPassword' ) );
		$this->assertSame( 'SMARTLOGIX_SWIFTFORMS_TURNSTILE_SECRET_KEY', GlobalSettings::constant_for( 'turnstileSecretKey' ) );
	}

	public function test_secret_source_reports_configuration_without_returning_the_value(): void {
		update_option( 'smartlogix_swiftforms_settings_smtpPassword', 'do-not-render' );
		$this->assertSame( 'database', GlobalSettings::instance()->secret_source( 'smtpPassword' ) );
	}

	/**
	 * @return string[]
	 */
	private function seedable_option_names(): array {
		return array(
			'smartlogix_swiftforms_settings_saveEntriesDefault',
			'smartlogix_swiftforms_settings_defaultAdminRecipients',
			'smartlogix_swiftforms_settings_smtpEnabled',
			'smartlogix_swiftforms_settings_smtpHost',
			'smartlogix_swiftforms_settings_smtpPort',
			'smartlogix_swiftforms_settings_smtpEncryption',
			'smartlogix_swiftforms_settings_smtpUsername',
			'smartlogix_swiftforms_settings_smtpPassword',
			'smartlogix_swiftforms_settings_smtpFromEmail',
			'smartlogix_swiftforms_settings_smtpFromName',
			'smartlogix_swiftforms_settings_rateLimitMaxRequests',
			'smartlogix_swiftforms_settings_rateLimitWindowSeconds',
			'smartlogix_swiftforms_settings_minSubmitSeconds',
			'smartlogix_swiftforms_settings_akismetEnabled',
			'smartlogix_swiftforms_settings_turnstileSiteKey',
			'smartlogix_swiftforms_settings_turnstileSecretKey',
			'smartlogix_swiftforms_settings_designSkin',
			'smartlogix_swiftforms_settings_designAccent',
			'smartlogix_swiftforms_settings_designFieldBg',
			'smartlogix_swiftforms_settings_designRadius',
			'smartlogix_swiftforms_settings_designLabelPosition',
			'smartlogix_swiftforms_settings_uninstallDeleteData',
		);
	}
}
