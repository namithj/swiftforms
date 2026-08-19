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
		// and Design\DesignSystem's `swf_settings_schema` filter feed into.
		$this->page = swf()->container()->get( 'global_settings_page' );
	}

	public function tear_down(): void {
		foreach ( $this->seedable_option_names() as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	public function test_seed_defaults_populates_every_field_with_its_schema_default(): void {
		$this->page->seed_defaults();

		$this->assertSame( '1', get_option( 'swf_settings_saveEntriesDefault' ) );
		$this->assertSame( 587, get_option( 'swf_settings_smtpPort' ) );
		$this->assertSame( 'tls', get_option( 'swf_settings_smtpEncryption' ) );
		$this->assertSame( 5, get_option( 'swf_settings_rateLimitMaxRequests' ) );
	}

	public function test_seed_defaults_includes_design_tab_fields_contributed_via_the_filter(): void {
		$this->page->seed_defaults();

		$this->assertSame( 'default', get_option( 'swf_settings_designSkin' ) );
		$this->assertSame( '#2563eb', get_option( 'swf_settings_designAccent' ) );
	}

	public function test_seed_defaults_does_not_overwrite_an_existing_value(): void {
		update_option( 'swf_settings_smtpPort', 2525 );

		$this->page->seed_defaults();

		$this->assertSame( 2525, get_option( 'swf_settings_smtpPort' ) );
	}

	public function test_global_settings_get_reads_the_seeded_value_back(): void {
		$this->page->seed_defaults();

		$this->assertSame( 587, GlobalSettings::instance()->get( 'smtpPort', 0 ) );
	}

	public function test_global_settings_get_falls_back_when_the_option_does_not_exist(): void {
		$this->assertSame( 'fallback', GlobalSettings::instance()->get( 'not_a_real_setting', 'fallback' ) );
	}

	/**
	 * @return string[]
	 */
	private function seedable_option_names(): array {
		return array(
			'swf_settings_saveEntriesDefault',
			'swf_settings_defaultAdminRecipients',
			'swf_settings_smtpEnabled',
			'swf_settings_smtpHost',
			'swf_settings_smtpPort',
			'swf_settings_smtpEncryption',
			'swf_settings_smtpUsername',
			'swf_settings_smtpPassword',
			'swf_settings_smtpFromEmail',
			'swf_settings_smtpFromName',
			'swf_settings_rateLimitMaxRequests',
			'swf_settings_rateLimitWindowSeconds',
			'swf_settings_minSubmitSeconds',
			'swf_settings_akismetEnabled',
			'swf_settings_turnstileSiteKey',
			'swf_settings_turnstileSecretKey',
			'swf_settings_designSkin',
			'swf_settings_designAccent',
			'swf_settings_designFieldBg',
			'swf_settings_designRadius',
			'swf_settings_designLabelPosition',
			'swf_settings_uninstallDeleteData',
		);
	}
}
