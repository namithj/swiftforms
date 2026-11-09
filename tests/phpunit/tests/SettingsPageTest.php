<?php
/**
 * Tests for the Settings screen: the cassette-cmf settings page and its REST route (P10).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Settings;
use SwiftForms\Settings_Page;
use SwiftForms\Vendor\PedalCMS\CassetteCMF\Core\Manager;

/**
 * Class SettingsPageTest
 *
 * @group settings
 * @group admin
 */
class SettingsPageTest extends SF_Storage_Test_Case {

	const ROUTE = '/swiftforms-cmf/v1/settings/swiftforms';

	/**
	 * A clean REST server, a clean menu and an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wp_rest_server, $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages;
		parent::set_up();
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh REST server and admin menu state for the test.
		$wp_rest_server    = null;
		$menu              = [];
		$submenu           = [];
		$admin_page_hooks  = [];
		$_registered_pages = [];
		$_parent_pages     = [];
		// phpcs:enable
		$this->clear_options();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Forget the REST server and the screen.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- See set_up().
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * The registered page object of cassette-cmf.
	 *
	 * @return \SwiftForms\Vendor\PedalCMS\CassetteCMF\Settings\Settings_Page
	 */
	private function page() {
		$page = Manager::init()->get_new_settings_handler()->get_page( Settings_Page::PAGE_ID );
		$this->assertNotNull( $page, 'The page is registered.' );
		return $page;
	}

	/**
	 * Call the cassette-cmf route.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $values Values to post (POST) .
	 * @return WP_REST_Response
	 */
	private function call( $method, array $values = [] ) {
		$request = new WP_REST_Request( $method, self::ROUTE );
		if ( 'GET' !== $method ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( [ 'values' => $values ] ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * The values GET returns.
	 *
	 * @return array<string, mixed>
	 */
	private function values() {
		$response = $this->call( 'GET' );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['values'];
	}

	/**
	 * Settings written by the tests that count writes.
	 *
	 * @return array{update:int,add:int}
	 */
	private function counter() {
		return [
			'update' => 0,
			'add'    => 0,
		];
	}

	/**
	 * The page: id, slug, parent, capability, render mode, no schema version; cassette-cmf booted with its own identifiers.
	 *
	 * @return void
	 */
	public function test_page_registration() {
		$page = $this->page();

		$this->assertSame( 'swiftforms', $page->get_page_id() );
		$this->assertSame( 'swiftforms-settings', $page->get_config( 'menu_slug' ) );
		$this->assertSame( 'swiftforms', $page->get_config( 'parent_slug' ) );
		$this->assertSame( 'swiftforms_manage_settings', $page->get_config( 'capability' ) );
		$this->assertSame( 'react', $page->get_render_mode() );
		$this->assertTrue( $page->is_rest_enabled() );
		$this->assertNull( $page->get_config( 'schema_version' ), 'No schema_version: cassette-cmf writes nothing besides the field options.' );

		$this->assertSame( 'swiftforms-cmf', Manager::asset_handle() );
		$this->assertSame( 'SwiftFormsCMFFields', Manager::js_global() );
		$this->assertSame( 'swiftforms-cmf/v1', Manager::rest_namespace() );
	}

	/**
	 * Every setting but delete_on_uninstall (1.1.0) is a field of the page: option name, type and default.
	 *
	 * @return void
	 */
	public function test_every_setting_is_a_field_with_its_default() {
		$handler = Manager::init()->get_new_settings_handler();
		$fields  = $handler->get_fields( Settings_Page::PAGE_ID );
		$expect  = [
			'general_store_entries'           => 'checkbox',
			'general_store_ip'                => 'checkbox',
			'general_store_user_agent'        => 'checkbox',
			'general_retention_days'          => 'number',
			'general_load_styles'             => 'checkbox',
			'general_default_success_message' => 'textarea',
			'email_from_name'                 => 'text',
			'email_from_email'                => 'text',
			'email_notification_to'           => 'text',
			'email_html_template'             => 'checkbox',
			'email_logo_id'                   => 'upload',
			'email_accent_color'              => 'color',
			'email_footer_text'               => 'textarea',
			'email_visitor_mail_daily_cap'    => 'number',
			'email_visitor_mail_hourly_cap'   => 'number',
			'spam_honeypot'                   => 'checkbox',
			'spam_min_seconds'                => 'number',
			'spam_js_token'                   => 'checkbox',
			'spam_rate_limit_per_minute'      => 'number',
			'spam_blocked_words'              => 'textarea',
			'spam_spam_action'                => 'select',
			'privacy_exporter'                => 'checkbox',
			'privacy_eraser'                  => 'checkbox',
			'advanced_debug_log'              => 'checkbox',
		];

		$found = [];
		foreach ( $fields as $name => $field ) {
			if ( ! $field->get_config( 'default' ) && 'custom_html' === $field->get_type() ) {
				continue;
			}
			if ( in_array( $field->get_type(), [ 'metabox', 'tabs', 'custom_html' ], true ) ) {
				continue;
			}
			$found[ $name ] = $field->get_type();
			$path           = preg_replace( '/_/', '.', $name, 1 );
			// The logo field shows '' (not 0) so the upload control does not offer "Remove logo"; Settings keeps 0.
			$expected = 'email_logo_id' === $name ? '' : Settings::defaults( SwiftForms\Install_Marker::installed_version() )[ explode( '.', $path )[0] ][ explode( '.', $path, 2 )[1] ];
			$this->assertSame( $expected, $field->get_config( 'default' ), $name . ' default' );
			$this->assertSame( 'swiftforms_' . $name, $field->get_option_name( Settings_Page::PAGE_ID ), $name . ' option' );
		}
		ksort( $expect );
		ksort( $found );
		$this->assertSame( $expect, $found );

		$option_names = array_map( [ Settings::class, 'option_name' ], Settings::paths() );
		$this->assertSame(
			[ 'swiftforms_advanced_delete_on_uninstall' ],
			array_values(
				array_diff(
					$option_names,
					array_map(
						static function ( $name ) {
							return 'swiftforms_' . $name;
						},
						array_keys( $found )
					)
				)
			),
			'delete_on_uninstall stays off the page until 1.1.0.'
		);
	}

	/**
	 * The page hangs under Forms (slug "swiftforms") as "Settings", behind the mapped capability.
	 *
	 * @return void
	 */
	public function test_menu_entry() {
		global $submenu;

		add_menu_page( 'Entries', 'Forms', 'swiftforms_view_entries', 'swiftforms', '__return_empty_string' );
		$page = $this->page();
		$prop = new ReflectionProperty( $page, 'registered' );
		$prop->setAccessible( true );
		$prop->setValue( $page, false );
		Manager::init()->get_new_settings_handler()->register_pages();

		$slugs = [];
		foreach ( $submenu['swiftforms'] as $item ) {
			if ( 'swiftforms-settings' === $item[2] ) {
				$slugs[] = [ $item[0], $item[1] ];
			}
		}
		$this->assertSame( [ [ 'Settings', 'swiftforms_manage_settings' ] ], $slugs );
		$this->assertSame( get_plugin_page_hookname( 'swiftforms-settings', 'swiftforms' ), $page->get_hook_suffix() );
		$this->assertStringContainsString( 'swiftforms', $page->get_hook_suffix(), 'The screen id contains "swiftforms" (Mail_Notice relies on it).' );
		$this->assertStringContainsString( 'admin.php?page=swiftforms-settings', menu_page_url( 'swiftforms-settings', false ) );
	}

	/**
	 * The route is cassette-cmf's; SwiftForms has no settings route of its own.
	 *
	 * @return void
	 */
	public function test_route_namespace() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/swiftforms-cmf/v1/settings/(?P<page>swiftforms)', $routes );
		$this->assertArrayNotHasKey( '/swiftforms/v1/settings', $routes );
	}

	/**
	 * GET shows the defaults for absent options (in the REST shapes), nothing is written.
	 *
	 * @return void
	 */
	public function test_get_defaults_without_options() {
		$writes = $this->counter();
		$this->count_settings_writes( $writes );

		$values = $this->values();

		$this->assertTrue( $values['general_store_entries'] );
		$this->assertFalse( $values['general_store_ip'] );
		$this->assertSame( 0, $values['general_retention_days'] );
		$this->assertSame( 'Thank you. Your message has been sent.', $values['general_default_success_message'] );
		$this->assertSame( '', $values['email_from_email'] );
		$this->assertSame( '', $values['email_logo_id'] );
		$this->assertSame( '', $values['email_accent_color'] );
		$this->assertSame( 100, $values['email_visitor_mail_daily_cap'] );
		$this->assertSame( 'store', $values['spam_spam_action'] );
		$this->assertArrayNotHasKey( 'advanced_delete_on_uninstall', $values );
		$this->assertCount( 24, $values );
		$this->assertSame( [ 0, 0 ], [ $writes['update'], $writes['add'] ] );
		$this->assertSame( [], Settings::stored_options() );
	}

	/**
	 * Scenario S8, absent: posting the GET payload writes nothing and every option stays absent.
	 *
	 * @return void
	 */
	public function test_noop_post_with_absent_options() {
		$writes = $this->counter();
		$added  = 0;
		$this->count_settings_writes( $writes );
		add_action(
			'added_option',
			static function ( $name ) use ( &$added ) {
				if ( null !== Settings::path_of( $name ) ) {
					++$added;
				}
			}
		);

		$values   = $this->values();
		$response = $this->call( 'POST', $values );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $values, $response->get_data()['values'] );
		$this->assertSame( [ 0, 0, 0 ], [ $writes['update'], $writes['add'], $added ] );
		$this->assertSame( [], Settings::stored_options(), 'Absent options stay absent.' );
		$this->assertNull( get_option( 'cassette_cmf_schema_version_swiftforms', null ), 'cassette-cmf writes nothing else.' );
	}

	/**
	 * Scenario S8, present: posting the GET payload keeps every stored byte, in every stored shape.
	 *
	 * @return void
	 */
	public function test_noop_post_with_present_options() {
		$this->clear_options();
		update_option( 'swiftforms_general_store_entries', '0' );
		update_option( 'swiftforms_general_store_ip', true );             // Stored as "1".
		update_option( 'swiftforms_general_retention_days', 30 );        // Stored as "30".
		update_option( 'swiftforms_general_default_success_message', '<script>x</script> unclean, saved by an older tool' );
		update_option( 'swiftforms_email_notification_to', 'a@example.org, b@example.org' );
		update_option( 'swiftforms_email_logo_id', '' );
		update_option( 'swiftforms_email_accent_color', '#12F' );
		update_option( 'swiftforms_email_footer_text', '<b>Hi</b>' );
		update_option( 'swiftforms_spam_min_seconds', '3' );             // Equals the default but is present.
		update_option( 'swiftforms_spam_spam_action', 'discard' );
		update_option( 'swiftforms_spam_blocked_words', " foo\nbar " );
		$before = Settings::stored_options();
		$this->assertSame( '1', $before['swiftforms_general_store_ip'] );

		$writes = $this->counter();
		$this->count_settings_writes( $writes );

		$values   = $this->values();
		$response = $this->call( 'POST', $values );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 0, 0 ], [ $writes['update'], $writes['add'] ], 'No update_option() call at all.' );
		$this->assertSame( $before, Settings::stored_options() );
		$this->assertSame( '<script>x</script> unclean, saved by an older tool', $this->raw_option( 'swiftforms_general_default_success_message' ) );
	}

	/**
	 * A corrupt stored option shows as the default and a post of what GET returned changes nothing (config 18).
	 *
	 * @return void
	 */
	public function test_noop_post_with_corrupt_options() {
		$this->clear_options();
		update_option( 'swiftforms_general_store_entries', 'banana' );
		update_option( 'swiftforms_general_retention_days', 'thirty' );
		update_option( 'swiftforms_spam_spam_action', 'delete' );
		update_option( 'swiftforms_email_notification_to', [ 'a@example.org' ] );
		update_option( 'swiftforms_email_accent_color', 'red' );
		$before = Settings::stored_options();
		$writes = $this->counter();
		$this->count_settings_writes( $writes );

		$values = $this->values();
		$this->assertTrue( $values['general_store_entries'] );
		$this->assertSame( 0, $values['general_retention_days'] );
		$this->assertSame( 'store', $values['spam_spam_action'] );
		$this->assertSame( '', $values['email_notification_to'] );

		$this->assertSame( 200, $this->call( 'POST', $values )->get_status() );
		$this->assertSame( [ 0, 0 ], [ $writes['update'], $writes['add'] ] );
		$this->assertSame( $before, Settings::stored_options() );
	}

	/**
	 * A change writes exactly the changed option, in the shape cassette-cmf stores, and the reader sees it at once.
	 *
	 * @return void
	 */
	public function test_a_change_writes_one_option() {
		$writes = $this->counter();
		$this->count_settings_writes( $writes );
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ), 'Primes the request cache.' );

		$response = $this->call(
			'POST',
			[
				'spam_honeypot'          => false,
				'spam_min_seconds'       => 7,
				'email_from_name'        => 'Acme',
				'spam_js_token'          => true, // The default, absent: not written.
				'general_retention_days' => 30,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'swiftforms_email_from_name'        => 'Acme',
				'swiftforms_general_retention_days' => '30',
				'swiftforms_spam_honeypot'          => '0',
				'swiftforms_spam_min_seconds'       => '7',
			],
			Settings::stored_options()
		);
		$this->assertFalse( Settings::get_value( 'spam.honeypot' ), 'The cache is flushed.' );
		$this->assertSame( 7, Settings::get_value( 'spam.min_seconds' ) );
		$this->assertSame( 30, Settings::get_value( 'general.retention_days' ) );
		$this->assertSame( 'Acme', Settings::get_value( 'email.from_name' ) );
		$this->assertTrue( Settings::get_value( 'spam.js_token' ) );
		$this->assertFalse( $response->get_data()['values']['spam_honeypot'] );

		// Back to the default: the option is present, so the change is written.
		$this->call( 'POST', [ 'spam_honeypot' => true ] );
		$this->assertSame( '1', $this->raw_option( 'swiftforms_spam_honeypot' ) );
		$this->assertTrue( Settings::get_value( 'spam.honeypot' ) );

		// The same value again writes nothing.
		$writes = $this->counter();
		$this->count_settings_writes( $writes );
		$this->call(
			'POST',
			[
				'spam_honeypot'    => true,
				'spam_min_seconds' => 7,
			]
		);
		$this->assertSame( [ 0, 0 ], [ $writes['update'], $writes['add'] ] );
	}

	/**
	 * Each of the validation rules answers 400 with the field's message, and one bad field stops the whole save.
	 *
	 * @return void
	 */
	public function test_validation_answers_400() {
		$attachment = self::factory()->attachment->create(
			[
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Not an image',
			]
		);
		$bad        = [
			'email_from_email'             => 'not an email',
			'email_notification_to'        => 'a@example.org, nope',
			'email_accent_color'           => 'red',
			'email_logo_id'                => $attachment,
			'spam_spam_action'             => 'delete',
			'general_retention_days'       => 99999,
			'spam_min_seconds'             => -1,
			'email_visitor_mail_daily_cap' => 'many',
			'spam_blocked_words'           => str_repeat( 'x', 20001 ),
			'general_store_entries'        => 'maybe',
		];

		foreach ( $bad as $name => $value ) {
			$response = $this->call(
				'POST',
				[
					'spam_honeypot' => false, // Valid, must not be written when another field is refused.
					$name           => $value,
				]
			);
			$this->assertSame( 400, $response->get_status(), $name );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $name );
			$this->assertArrayHasKey( $name, $response->get_data()['data']['params'], $name );
			$this->assertNotSame( '', $response->get_data()['data']['params'][ $name ] );
			$this->assertSame( [], Settings::stored_options(), $name . ' stops the whole save.' );
		}

		$response = $this->call( 'POST', [ 'email_from_email' => 'not an email' ] );
		$this->assertSame( 'Enter a valid email address, or leave it empty.', $response->get_data()['data']['params']['email_from_email'] );
		$response = $this->call( 'POST', [ 'email_notification_to' => 'a@example.org, nope' ] );
		$this->assertSame( 'Enter valid email addresses separated by commas.', $response->get_data()['data']['params']['email_notification_to'] );
		$response = $this->call( 'POST', [ 'email_accent_color' => 'red' ] );
		$this->assertSame( 'Enter a colour such as #1d4ed8, or leave it empty.', $response->get_data()['data']['params']['email_accent_color'] );
		$response = $this->call( 'POST', [ 'email_logo_id' => $attachment ] );
		$this->assertSame( 'Choose an image from the media library.', $response->get_data()['data']['params']['email_logo_id'] );

		$response = $this->call( 'POST', [ 'swiftforms_secret_thing' => 'x' ] );
		$this->assertSame( 400, $response->get_status(), 'An unknown key is refused.' );
		$response = $this->call( 'POST', [ 'advanced_delete_on_uninstall' => true ] );
		$this->assertSame( 400, $response->get_status(), 'delete_on_uninstall is not on the page.' );
	}

	/**
	 * Valid values of every special kind are accepted and stored as given.
	 *
	 * @return void
	 */
	public function test_valid_values_are_stored() {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertTrue( wp_attachment_is_image( $image ) );

		$response = $this->call(
			'POST',
			[
				'email_from_email'       => 'hello@example.org',
				'email_notification_to'  => 'a@example.org, b@example.org',
				'email_accent_color'     => '#1D4ED8',
				'email_logo_id'          => $image,
				'spam_spam_action'       => 'discard',
				'spam_blocked_words'     => "foo\nbar baz",
				'general_retention_days' => '2.9', // A whole number setting drops the fraction.
				'general_store_entries'  => false,
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'hello@example.org', Settings::get_value( 'email.from_email' ) );
		$this->assertSame( 'a@example.org, b@example.org', Settings::get_value( 'email.notification_to' ) );
		$this->assertSame( '#1D4ED8', Settings::get_value( 'email.accent_color' ) );
		$this->assertSame( $image, Settings::get_value( 'email.logo_id' ) );
		$this->assertSame( 'discard', Settings::get_value( 'spam.spam_action' ) );
		$this->assertSame( "foo\nbar baz", Settings::get_value( 'spam.blocked_words' ), 'Line breaks are kept.' );
		$this->assertSame( 2, Settings::get_value( 'general.retention_days' ) );
		$this->assertFalse( Settings::get_value( 'general.store_entries' ) );
		$this->assertSame( '0', $this->raw_option( 'swiftforms_general_store_entries' ) );

		// Clearing the optional ones works and an empty colour or address is valid.
		$this->assertSame(
			200,
			$this->call(
				'POST',
				[
					'email_logo_id'      => null,
					'email_accent_color' => '',
					'email_from_email'   => '',
				]
			)->get_status()
		);
		$this->assertSame( 0, Settings::get_value( 'email.logo_id' ) );
		$this->assertSame( '', Settings::get_value( 'email.accent_color' ) );
		$this->assertSame( '', Settings::get_value( 'email.from_email' ) );
	}

	/**
	 * The HTML keys keep links and basic formatting and lose scripts and event handlers, whoever saves.
	 *
	 * @return void
	 */
	public function test_html_keys_are_cleaned_with_kses() {
		$this->call(
			'POST',
			[
				'email_footer_text'               => '<b>Hi</b> <a href="https://example.org" onclick="x()">link</a><script>alert(1)</script>',
				'general_default_success_message' => "Thanks <i>a lot</i>\n<iframe src=\"https://evil.example\"></iframe>",
			]
		);

		$this->assertSame( '<b>Hi</b> <a href="https://example.org">link</a>alert(1)', Settings::get_value( 'email.footer_text' ) );
		$this->assertSame( "Thanks <i>a lot</i>\n", Settings::get_value( 'general.default_success_message' ) );
	}

	/**
	 * Multisite: a site administrator without unfiltered_html gets the same cleaning.
	 *
	 * @group multisite
	 *
	 * @return void
	 */
	public function test_html_keys_are_cleaned_for_a_site_admin_without_unfiltered_html() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run composer test:multisite.' );
		}
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		add_user_to_blog( $blog_id, $user_id, 'administrator' );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertTrue( current_user_can( 'swiftforms_manage_settings' ) );

		$response = $this->call( 'POST', [ 'email_footer_text' => '<b>Hi</b><script>alert(1)</script><img src=x onerror=alert(2)>' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '<script', $this->raw_option( 'swiftforms_email_footer_text' ) );
		$this->assertStringNotContainsString( 'onerror', $this->raw_option( 'swiftforms_email_footer_text' ) );
		restore_current_blog();
	}

	/**
	 * Who may use the route: guests 401, other roles 403, administrators and a role mapped to swiftforms_manage_settings 200.
	 *
	 * @return void
	 */
	public function test_route_permissions() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET' )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', [ 'spam_honeypot' => false ] )->get_status() );

		foreach ( [ 'subscriber', 'author', 'editor' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertSame( 403, $this->call( 'GET' )->get_status(), $role );
			$this->assertSame( 403, $this->call( 'POST', [ 'spam_honeypot' => false ] )->get_status(), $role );
		}
		$this->assertSame( [], Settings::stored_options() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, $this->call( 'GET' )->get_status() );

		$this->map_settings_cap( 'edit_others_posts' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->call( 'GET' )->get_status(), 'A role mapped to the capability may.' );
		$this->assertSame( 200, $this->call( 'POST', [ 'spam_honeypot' => false ] )->get_status() );
		$this->assertSame( '0', $this->raw_option( 'swiftforms_spam_honeypot' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->call( 'GET' )->get_status(), 'Another role still may not.' );
	}

	/**
	 * Map swiftforms_manage_settings to another capability for the rest of the test.
	 *
	 * @param string $cap Primitive capability.
	 * @return void
	 */
	private function map_settings_cap( $cap ) {
		add_filter(
			'swiftforms_capability_map',
			static function ( $map ) use ( $cap ) {
				return array_merge( $map, [ 'swiftforms_manage_settings' => $cap ] );
			}
		);
	}

	/**
	 * The OPTIONS request answers the schema to administrators only.
	 *
	 * @return void
	 */
	public function test_schema_is_for_managers() {
		$schema = $this->call( 'OPTIONS' )->get_data()['schema'];
		$this->assertArrayHasKey( 'spam_honeypot', $schema['properties']['values']['properties'] ?? $schema['properties'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$schema = $this->call( 'OPTIONS' )->get_data()['schema'];
		$this->assertArrayNotHasKey( 'properties', $schema );
	}

	/**
	 * The page's assets load on its screen only; the front end gets none of cassette-cmf's.
	 *
	 * @return void
	 */
	public function test_assets_only_on_the_settings_screen() {
		add_menu_page( 'Entries', 'Forms', 'swiftforms_view_entries', 'swiftforms', '__return_empty_string' );
		$page = $this->page();
		$prop = new ReflectionProperty( $page, 'registered' );
		$prop->setAccessible( true );
		$prop->setValue( $page, false );
		$handler = Manager::init()->get_new_settings_handler();
		$handler->register_pages();
		$hook = $page->get_hook_suffix();

		$ours = static function () {
			return array_values(
				array_filter(
					array_merge( wp_scripts()->queue, wp_styles()->queue ),
					static function ( $handle ) {
						return 0 === strpos( $handle, 'swiftforms-cmf' );
					}
				)
			);
		};

		// Another admin screen.
		set_current_screen( 'edit-post' );
		$handler->enqueue_assets();
		$this->assertSame( [], $ours() );

		// The front end.
		set_current_screen( 'front' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertSame( [], $ours() );

		// The settings screen: the React app and nothing of the classic assets.
		set_current_screen( $hook );
		$handler->enqueue_assets();
		$this->assertContains( 'swiftforms-cmf-settings-app', $ours() );
		$this->assertNotContains( 'swiftforms-cmf', $ours(), 'The classic common script and style are not loaded.' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'swiftforms-cmf-settings-app', 'before' ) );
		$this->assertStringContainsString( 'window.SwiftFormsCMFFieldsSettingsApp', $inline );
		$this->assertSame( plugins_url( 'vendor-prefixed/pedalcms/cassette-cmf/build/settings-app.js', SWIFTFORMS_FILE ), strtok( wp_scripts()->registered['swiftforms-cmf-settings-app']->src, '?' ) );
		$this->assertStringContainsString( '"restPath":"\/swiftforms-cmf\/v1\/settings\/swiftforms"', $inline );

		ob_start();
		$page->get_config( 'callback' )();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="cassette-cmf-settings-swiftforms"', $html );
		$this->assertStringContainsString( 'data-config-global="SwiftFormsCMFFieldsSettingsApp"', $html );
	}

	/**
	 * The built app ships with the library and is found where the page looks for it.
	 *
	 * @return void
	 */
	public function test_the_built_app_exists() {
		$build = \SwiftForms\Vendor\PedalCMS\CassetteCMF\Settings\React_Renderer::build_path();
		foreach ( [ 'settings-app.js', 'settings-app.asset.php', 'settings-app.css', 'settings-app-rtl.css' ] as $file ) {
			$this->assertFileExists( $build . '/' . $file );
		}
		$this->assertStringContainsString( 'vendor-prefixed/pedalcms/cassette-cmf/build', str_replace( '\\', '/', $build ) );
		$this->assertNull( \SwiftForms\Vendor\PedalCMS\CassetteCMF\Settings\React_Renderer::unavailable_reason() );
	}

	/**
	 * The Settings screen is where the mail-limit banner points.
	 *
	 * @return void
	 */
	public function test_the_banner_link_lands_on_the_page() {
		$url = admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG );
		$this->assertStringContainsString( 'admin.php?page=swiftforms-settings', $url );
		$this->assertSame( 'swiftforms-settings', Settings_Page::MENU_SLUG );
	}
}
