<?php
/**
 * Tests for SwiftForms\Settings (C-5).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';
require_once dirname( __DIR__ ) . '/includes/class-sf-test-future-settings.php';

use SwiftForms\Install_Marker;
use SwiftForms\Settings;

/**
 * Class SettingsTest
 *
 * @group settings
 */
class SettingsTest extends SF_Storage_Test_Case {

	/**
	 * The exact C-5 defaults.
	 *
	 * @return void
	 */
	public function test_defaults_are_exactly_c5() {
		$this->assertSame(
			[
				'_v'       => 1,
				'general'  => [
					'store_entries'           => true,
					'store_ip'                => false,
					'store_user_agent'        => false,
					'retention_days'          => 0,
					'load_styles'             => true,
					'default_success_message' => 'Thank you. Your message has been sent.',
				],
				'email'    => [
					'from_name'               => '',
					'from_email'              => '',
					'notification_to'         => '',
					'html_template'           => true,
					'logo_id'                 => 0,
					'accent_color'            => '',
					'footer_text'             => '',
					'visitor_mail_daily_cap'  => 100,
					'visitor_mail_hourly_cap' => 200,
				],
				'spam'     => [
					'honeypot'              => true,
					'min_seconds'           => 3,
					'js_token'              => true,
					'rate_limit_per_minute' => 5,
					'blocked_words'         => '',
					'spam_action'           => 'store',
				],
				'privacy'  => [
					'exporter' => true,
					'eraser'   => true,
				],
				'advanced' => [
					'delete_on_uninstall' => false,
					'debug_log'           => false,
				],
			],
			Settings::defaults()
		);
		$this->assertSame( Settings::defaults(), Settings::defaults( '1.0.0' ), 'No default differs for new installs in 1.0.' );
	}

	/**
	 * The success-message default is translatable.
	 *
	 * @return void
	 */
	public function test_success_message_is_translated() {
		add_filter(
			'gettext_swiftforms',
			static function ( $translation, $text ) {
				return 'Thank you. Your message has been sent.' === $text ? 'Danke.' : $translation;
			},
			10,
			2
		);
		$this->assertSame( 'Danke.', Settings::defaults()['general']['default_success_message'] );
	}

	/**
	 * Every key is one option, swiftforms_{section}_{key}, and none collides with another SwiftForms option.
	 *
	 * @return void
	 */
	public function test_option_names() {
		$expected = [
			'swiftforms_general_store_entries',
			'swiftforms_general_store_ip',
			'swiftforms_general_store_user_agent',
			'swiftforms_general_retention_days',
			'swiftforms_general_load_styles',
			'swiftforms_general_default_success_message',
			'swiftforms_email_from_name',
			'swiftforms_email_from_email',
			'swiftforms_email_notification_to',
			'swiftforms_email_html_template',
			'swiftforms_email_logo_id',
			'swiftforms_email_accent_color',
			'swiftforms_email_footer_text',
			'swiftforms_email_visitor_mail_daily_cap',
			'swiftforms_email_visitor_mail_hourly_cap',
			'swiftforms_spam_honeypot',
			'swiftforms_spam_min_seconds',
			'swiftforms_spam_js_token',
			'swiftforms_spam_rate_limit_per_minute',
			'swiftforms_spam_blocked_words',
			'swiftforms_spam_spam_action',
			'swiftforms_privacy_exporter',
			'swiftforms_privacy_eraser',
			'swiftforms_advanced_delete_on_uninstall',
			'swiftforms_advanced_debug_log',
		];
		$names    = array_map( [ Settings::class, 'option_name' ], Settings::paths() );
		$this->assertSame( $expected, $names );

		$others = [ 'swiftforms_installed', 'swiftforms_db_version', 'swiftforms_backups', 'swiftforms_migration_lock', 'swiftforms_source_edges', 'swiftforms_recent_clients', 'swiftforms_mail_cap_notice', 'swiftforms_secrets', 'swiftforms_settings' ];
		$this->assertSame( [], array_intersect( $names, $others ), 'No settings option is another SwiftForms option.' );
		foreach ( $others as $name ) {
			$this->assertNull( Settings::path_of( $name ), $name . ' is not a settings option.' );
		}
		foreach ( Settings::paths() as $path ) {
			$this->assertSame( $path, Settings::path_of( Settings::option_name( $path ) ) );
		}
		$this->assertNull( Settings::path_of( 'swiftforms_general_unknown' ) );
		$this->assertNull( Settings::path_of( 'swiftforms_general' ) );
	}

	/**
	 * With no option stored, every value is its default and nothing is written.
	 *
	 * @return void
	 */
	public function test_absent_options_read_as_defaults_and_never_write() {
		$writes = [
			'update' => 0,
			'add'    => 0,
		];
		$this->clear_options();
		$this->count_settings_writes( $writes );

		$this->assertSame( Settings::defaults( Install_Marker::installed_version() ), Settings::get() );
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );
		$this->assertSame( 5, Settings::get_value( 'spam.rate_limit_per_minute' ) );
		$this->assertSame( 'fallback', Settings::get_value( 'spam.nothing', 'fallback' ) );
		$this->assertNull( Settings::get_value( 'nothing' ) );
		$this->assertSame( [], Settings::stored_options() );
		$this->assertFalse( Settings::has_stored() );
		$this->assertSame(
			[
				'update' => 0,
				'add'    => 0,
			],
			$writes
		);
	}

	/**
	 * Stored strings come back typed: "1" and "0" are booleans, digits are integers, text stays text.
	 *
	 * @return void
	 */
	public function test_present_options_are_typed() {
		$this->clear_options();
		update_option( 'swiftforms_general_store_entries', '0' );
		update_option( 'swiftforms_general_store_ip', '1' );
		update_option( 'swiftforms_general_retention_days', 30 );
		update_option( 'swiftforms_spam_min_seconds', '0' );
		update_option( 'swiftforms_email_notification_to', 'a@example.org, b@example.org' );
		update_option( 'swiftforms_email_logo_id', 12 );
		update_option( 'swiftforms_email_accent_color', '#12F' );
		update_option( 'swiftforms_spam_spam_action', 'discard' );
		update_option( 'swiftforms_spam_blocked_words', " foo\nbar " );

		$this->assertFalse( Settings::get_value( 'general.store_entries' ) );
		$this->assertTrue( Settings::get_value( 'general.store_ip' ) );
		$this->assertSame( 30, Settings::get_value( 'general.retention_days' ) );
		$this->assertSame( 0, Settings::get_value( 'spam.min_seconds' ) );
		$this->assertSame( 'a@example.org, b@example.org', Settings::get_value( 'email.notification_to' ) );
		$this->assertSame( 12, Settings::get_value( 'email.logo_id' ) );
		$this->assertSame( '#12F', Settings::get_value( 'email.accent_color' ) );
		$this->assertSame( 'discard', Settings::get_value( 'spam.spam_action' ) );
		$this->assertSame( " foo\nbar ", Settings::get_value( 'spam.blocked_words' ), 'A valid value is never trimmed.' );
		$this->assertTrue( Settings::get_value( 'general.load_styles' ), 'The rest keeps its default.' );
		$this->assertSame( 1, Settings::get()['_v'] );
	}

	/**
	 * A wrong-typed or corrupt option reads as the default, per key, never fatally; its neighbours are fine.
	 *
	 * @return void
	 */
	public function test_corrupt_options_read_as_defaults() {
		$this->clear_options();
		$object = new stdClass();
		$cases  = [
			'general.store_entries'           => [ 'yes', 'banana', [ 'x' ], $object, '2', '' ],
			'general.store_ip'                => [ 'true', [ 1 ], 7 ],
			'general.retention_days'          => [ 'thirty', '-5', -5, '1.5', '99999999999999999999', [ 3 ], '' ],
			'general.default_success_message' => [ [ 'x' ], $object, 5 ],
			'email.from_email'                => [ 'not an email', [ 'a@example.org' ], 'a@example.org, b@example.org' ],
			'email.notification_to'           => [ 'a@example.org, nope', [ 'x' ], ',' ],
			'email.logo_id'                   => [ 'a', '', [ 7 ], -3 ],
			'email.accent_color'              => [ 'red', '#12', '#ggg', [ '#fff' ] ],
			'spam.spam_action'                => [ 'delete', '', [ 'store' ], 1 ],
			'spam.min_seconds'                => [ 'soon', -1, [] ],
		];
		foreach ( $cases as $path => $garbage ) {
			$default = Settings::defaults()[ explode( '.', $path )[0] ][ explode( '.', $path )[1] ];
			foreach ( $garbage as $value ) {
				update_option( Settings::option_name( $path ), $value );
				Settings::flush_cache();
				$this->assertSame( $default, Settings::get_value( $path ), $path . ' = ' . wp_json_encode( $value ) );
				$this->assertSame( $default, get_option( Settings::option_name( $path ) ), 'The guard shows the default to every reader of the option.' );
			}
		}

		// One corrupt neighbour does not spoil a valid one.
		update_option( 'swiftforms_spam_honeypot', 'banana' );
		update_option( 'swiftforms_spam_js_token', '0' );
		Settings::flush_cache();
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );
		$this->assertFalse( Settings::get_value( 'spam.js_token' ) );
	}

	/**
	 * The guard changes what readers see, not what is stored.
	 *
	 * @return void
	 */
	public function test_corrupt_bytes_stay_in_the_table() {
		$this->clear_options();
		update_option( 'swiftforms_spam_spam_action', 'delete' );
		$this->assertSame( 'store', get_option( 'swiftforms_spam_spam_action' ) );
		$this->assertSame( 'delete', $this->raw_option( 'swiftforms_spam_spam_action' ) );
		$this->assertSame( [ 'swiftforms_spam_spam_action' => 'delete' ], Settings::stored_options() );
		$this->assertTrue( Settings::has_stored() );
	}

	/**
	 * Options this class does not know are never read, changed or reported.
	 *
	 * @return void
	 */
	public function test_unknown_options_are_ignored() {
		$this->clear_options();
		add_option( 'swiftforms_general_future_key', 'x' );
		add_option( 'swiftforms_future_section_key', 'y' );
		add_option( 'swiftforms_settings', [ 'spam' => [ 'honeypot' => false ] ] );

		$this->assertSame( Settings::defaults( Install_Marker::installed_version() ), Settings::get(), 'The old array option is not read any more.' );
		$this->assertSame( 'x', get_option( 'swiftforms_general_future_key' ) );
		$this->assertSame( 'y', get_option( 'swiftforms_future_section_key' ) );
		$this->assertSame( [ 'swiftforms_general_future_key' => 'x' ], Settings::stored_options(), 'A key of a known section is part of the backup, a foreign section is not.' );
	}

	/**
	 * The request cache follows add, update and delete of a settings option, and only those.
	 *
	 * @return void
	 */
	public function test_cache_is_flushed_when_a_settings_option_changes() {
		$this->clear_options();
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );

		add_option( 'swiftforms_spam_honeypot', '0' );
		$this->assertFalse( Settings::get_value( 'spam.honeypot' ) );

		update_option( 'swiftforms_spam_honeypot', '1' );
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );

		update_option( 'swiftforms_spam_honeypot', '0' );
		$this->assertFalse( Settings::get_value( 'spam.honeypot' ) );

		delete_option( 'swiftforms_spam_honeypot' );
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );
	}

	/**
	 * Reading is idempotent over a fuzz set of stored values: coerce(coerce(x)) is coerce(x), and a valid value is not altered.
	 *
	 * @return void
	 */
	public function test_coerce_is_idempotent() {
		$pool = [ null, true, false, 0, 1, -1, 7, 1.5, '', '0', '1', '7', 'x', 'owner@example.org', '#abc', 'store', 'discard', [], [ 'a' => 1 ], new stdClass() ];
		foreach ( Settings::paths() as $path ) {
			foreach ( $pool as $value ) {
				$once = Settings::coerce( $path, $value );
				if ( null === $once ) {
					continue;
				}
				$this->assertSame( $once, Settings::coerce( $path, $once ), $path );
			}
		}
	}

	/**
	 * C-5.3: a default for new installs is decided only by the installed version.
	 *
	 * @return void
	 */
	public function test_new_install_default_mechanism() {
		$this->assertFalse( Sf_Test_Future_Settings::defaults()['general']['future_flag'], 'No version known: the old default.' );
		$this->assertFalse( Sf_Test_Future_Settings::defaults( '1.0.0' )['general']['future_flag'] );
		$this->assertFalse( Sf_Test_Future_Settings::defaults( '1.1.9' )['general']['future_flag'] );
		$this->assertTrue( Sf_Test_Future_Settings::defaults( '1.2.0' )['general']['future_flag'] );
		$this->assertTrue( Sf_Test_Future_Settings::defaults( '2.0.0' )['general']['future_flag'] );

		// A stored value is valid whatever the install age, so it is never replaced.
		$this->assertFalse( Sf_Test_Future_Settings::coerce( 'general.future_flag', '0', Sf_Test_Future_Settings::defaults( '1.3.0' )['general']['future_flag'] ) );
		$this->assertTrue( Sf_Test_Future_Settings::defaults( '1.3.0' )['general']['future_flag'], 'An absent option takes the new-install default.' );
		$this->assertFalse( Sf_Test_Future_Settings::defaults( '1.0.0' )['general']['future_flag'], 'An absent option on an old install keeps the old behaviour.' );
	}

	/**
	 * The reader asks the install marker, not the settings options, how old the install is.
	 *
	 * @return void
	 */
	public function test_reader_uses_the_install_marker() {
		$this->clear_options();

		add_option(
			Install_Marker::OPTION,
			[
				'version' => '1.2.0',
				'time'    => 1700000000,
			],
			'',
			false
		);
		Settings::flush_cache();
		$this->assertTrue( Sf_Test_Future_Settings::get()['general']['future_flag'] );

		update_option(
			Install_Marker::OPTION,
			[
				'version' => '1.1.0',
				'time'    => 1700000000,
			]
		);
		Settings::flush_cache();
		$this->assertFalse( Sf_Test_Future_Settings::get()['general']['future_flag'] );
	}
}
