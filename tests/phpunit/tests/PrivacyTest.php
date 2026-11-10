<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The core privacy hooks and the plugin's own cron hook constant.
/**
 * Tests for the privacy exporter, eraser and policy text.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-admin-test-case.php';

use SwiftForms\Entries_Repository;
use SwiftForms\Log;
use SwiftForms\Privacy;

/**
 * Class PrivacyTest
 *
 * @group admin
 * @group privacy
 */
class PrivacyTest extends SF_Admin_Test_Case {

	/**
	 * The tools are registered while their settings are on.
	 *
	 * @return void
	 */
	public function test_registration_follows_settings() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
		$this->assertArrayHasKey( Privacy::ID, $exporters );
		$this->assertArrayHasKey( Privacy::ID, $erasers );
		$this->assertTrue( is_callable( $exporters[ Privacy::ID ]['callback'] ) );
		$this->assertTrue( is_callable( $erasers[ Privacy::ID ]['callback'] ) );

		$this->set_settings( [ 'privacy' => [ 'exporter' => false ] ] );
		$this->assertArrayNotHasKey( Privacy::ID, apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( Privacy::ID, apply_filters( 'wp_privacy_personal_data_erasers', [] ) );

		$this->set_settings( [ 'privacy' => [ 'eraser' => false ] ] );
		$this->assertArrayNotHasKey( Privacy::ID, apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	/**
	 * Entries are found by an email-type field only, case-insensitively, and by user.
	 *
	 * @return void
	 */
	public function test_matching() {
		$by_email = $this->add_entry( [ 'email' => 'ada@example.com' ] );
		$by_case  = $this->add_entry( [ 'email' => 'Ada@Example.com' ] );
		$in_text  = $this->add_entry(
			[
				'name'  => 'ada@example.com',
				'email' => 'other@example.com',
			]
		);
		$longer   = $this->add_entry( [ 'email' => 'bigada@example.com' ] );
		$user_id  = self::factory()->user->create( [ 'user_email' => 'ada@example.com' ] );
		$by_user  = $this->add_entry( [ 'email' => 'someone-else@example.com' ], [ 'user' => get_userdata( $user_id ) ] );

		$ids = Privacy::entry_ids( 'ada@example.com' );
		$this->assertSame( [ $by_email, $by_case, $by_user ], $ids );
		$this->assertNotContains( $in_text, $ids, 'A text field holding the address is not an email field.' );
		$this->assertNotContains( $longer, $ids );
		$this->assertSame( [], Privacy::entry_ids( 'not an email' ) );
	}

	/**
	 * The field type comes from the entry's own snapshot, so an old email field still counts after the form changed.
	 *
	 * @return void
	 */
	public function test_type_from_the_entrys_snapshot() {
		$id = $this->add_entry( [ 'email' => 'ada@example.com' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$inner = $this->field_markup(
			'text',
			[
				'name'  => 'email',
				'label' => 'Email',
			]
		) . '<!-- wp:swiftforms/submit /-->';
		wp_update_post(
			[
				'ID'           => (int) substr( $this->source, 5 ),
				'post_content' => $this->form_markup( $this->form_id, $inner ),
			]
		);
		\SwiftForms\Form_Index::flush_cache();
		$now_text = $this->add_entry( [ 'email' => 'ada@example.com' ] );

		$this->assertSame( [ $id ], Privacy::entry_ids( 'ada@example.com' ), 'Only the entry whose snapshot says email.' );
		unset( $now_text );
	}

	/**
	 * Fail safe: an entry whose snapshot row is missing (corrupt data) is still found by an exact address.
	 *
	 * @return void
	 */
	public function test_entry_without_a_snapshot_is_still_found() {
		global $wpdb;
		$id = $this->add_entry( [ 'email' => 'margaret@example.org' ] );
		$wpdb->update( \SwiftForms\Database::table( 'entries' ), [ 'schema_hash' => str_repeat( 'f', 64 ) ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		$this->assertSame( [ $id ], Privacy::entry_ids( 'margaret@example.org' ) );
	}

	/**
	 * The export lists every value with its label, in pages.
	 *
	 * @return void
	 */
	public function test_export() {
		$id = $this->add_entry(
			[
				'name'    => 'Ada',
				'email'   => 'ada@example.com',
				'message' => 'Hi there',
			]
		);

		$result = Privacy::export( 'ada@example.com', 1 );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$item = $result['data'][0];
		$this->assertSame( 'swiftforms-entries', $item['group_id'] );
		$this->assertSame( 'SwiftForms entries', $item['group_label'] );
		$this->assertSame( 'swiftforms-entry-' . $id, $item['item_id'] );
		$pairs = wp_list_pluck( $item['data'], 'value', 'name' );
		$this->assertSame( 'Ada', $pairs['Name'] );
		$this->assertSame( 'ada@example.com', $pairs['Email'] );
		$this->assertSame( 'Hi there', $pairs['Message'] );
		$this->assertArrayNotHasKey( 'IP address', $pairs, 'Not stored, not exported.' );

		$this->assertSame(
			[
				'data' => [],
				'done' => true,
			],
			Privacy::export( 'nobody@example.com', 1 )
		);

		for ( $i = 0; $i < 60; $i++ ) {
			$this->add_entry( [ 'email' => 'ada@example.com' ] );
		}
		$first  = Privacy::export( 'ada@example.com', 1 );
		$second = Privacy::export( 'ada@example.com', 2 );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 50, $first['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 11, $second['data'] );
	}

	/**
	 * Through core's own exporter registry.
	 *
	 * @return void
	 */
	public function test_export_through_core_registry() {
		$this->add_entry( [ 'email' => 'ada@example.com' ] );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
		$result    = call_user_func( $exporters[ Privacy::ID ]['callback'], 'ada@example.com', 1 );
		$this->assertCount( 1, $result['data'] );
	}

	/**
	 * The eraser removes the matching entries with values, meta and the mail log rows that list the address.
	 *
	 * @return void
	 */
	public function test_erase() {
		global $wpdb;
		$mine  = $this->add_entry( [ 'email' => 'ada@example.com' ] );
		$mine2 = $this->add_entry( [ 'email' => 'ada@example.com' ] );
		$other = $this->add_entry( [ 'email' => 'bigada@example.com' ] );
		$wpdb->insert(
			\SwiftForms\Database::table( 'entry_meta' ),
			[
				'entry_id'   => $mine,
				'meta_key'   => 'k',
				'meta_value' => 'v',
			]
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.

		Log::add( 'mail', 'info', 'Sent', [ 'to' => [ 'ada@example.com' ] ] );
		Log::add( 'mail', 'info', 'Sent', [ 'recipients' => 'owner@example.com, Ada@Example.com' ] );
		Log::add( 'mail', 'info', 'Sent', [ 'to' => 'Ada <ada@example.com>' ] );
		Log::add( 'mail', 'info', 'Sent', [ 'to' => [ 'bigada@example.com' ] ] );
		Log::add( 'error', 'error', 'Other', [ 'to' => [ 'ada@example.com' ] ] );

		$result = Privacy::erase( 'ada@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertNull( Entries_Repository::get( $mine ) );
		$this->assertNull( Entries_Repository::get( $mine2 ) );
		$this->assertSame( [], Entries_Repository::values( $mine ) );
		$this->assertNotNull( Entries_Repository::get( $other ), 'Another address stays.' );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \SwiftForms\Database::table( 'entry_meta' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
		$left = $wpdb->get_col( 'SELECT type FROM ' . \SwiftForms\Database::table( 'log' ) . " WHERE type IN ('mail', 'error') ORDER BY id" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
		$this->assertSame( [ 'mail', 'error' ], $left, 'Only the mail row of bigada@example.com and the error row remain.' );

		$again = Privacy::erase( 'ada@example.com', 1 );
		$this->assertFalse( $again['items_removed'] );
		$this->assertTrue( $again['done'] );
	}

	/**
	 * A big erase takes several calls.
	 *
	 * @return void
	 */
	public function test_erase_in_pages() {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->add_entry( [ 'email' => 'ada@example.com' ] );
		}
		$first = Privacy::erase( 'ada@example.com', 1 );
		$this->assertFalse( $first['done'] );
		$second = Privacy::erase( 'ada@example.com', 2 );
		$this->assertTrue( $second['done'] );
		$this->assertSame( [], Privacy::entry_ids( 'ada@example.com' ) );
	}

	/**
	 * The policy text says what is stored and follows the retention setting.
	 *
	 * @return void
	 */
	public function test_policy_text() {
		$text = Privacy::policy_text();
		$this->assertStringContainsString( 'IP address', $text );
		$this->assertStringContainsString( 'until the site owner deletes them', $text );
		$this->set_settings( [ 'general' => [ 'retention_days' => 90 ] ] );
		$this->assertStringContainsString( '90 days', Privacy::policy_text() );
		$this->assertStringContainsString( 'No data is sent to an external service', Privacy::policy_text() );
	}
}
