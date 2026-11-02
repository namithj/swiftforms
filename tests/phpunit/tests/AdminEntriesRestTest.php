<?php
/**
 * Tests for the Entries REST routes.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-admin-test-case.php';

use SwiftForms\Database;
use SwiftForms\Entries_Repository;
use SwiftForms\Entry_Presenter;

/**
 * Class AdminEntriesRestTest
 *
 * @group admin
 * @group security
 */
class AdminEntriesRestTest extends SF_Admin_Test_Case {

	/**
	 * Every route answers a guest with 401 and a user without the capability with 403.
	 *
	 * @return void
	 */
	public function test_guest_and_subscriber_are_refused() {
		$id     = $this->add_entry();
		$routes = [
			[ 'GET', '/entries' ],
			[ 'GET', '/entries/' . $id ],
			[ 'PATCH', '/entries/' . $id ],
			[ 'DELETE', '/entries/' . $id ],
			[ 'GET', '/forms' ],
		];
		foreach ( $routes as $route ) {
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->call( $route[0], $route[1], [ 'starred' => true ] )->get_status(), implode( ' ', $route ) . ' as guest' );
			$this->be_role( 'subscriber' );
			$this->assertSame( 403, $this->call( $route[0], $route[1], [ 'starred' => true ] )->get_status(), implode( ' ', $route ) . ' as subscriber' );
		}
		$this->assertNotNull( Entries_Repository::get( $id ), 'Nothing was deleted.' );
	}

	/**
	 * A custom role granted through the capability map gets exactly what it was granted.
	 *
	 * @return void
	 */
	public function test_custom_role_through_capability_map() {
		$id = $this->add_entry();
		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );

		$this->be_role( 'contributor' );
		$this->assertSame( 200, $this->call( 'GET', '/entries' )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/entries/' . $id )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/forms' )->get_status() );
		$this->assertSame( 403, $this->call( 'PATCH', '/entries/' . $id, [ 'starred' => true ] )->get_status(), 'edit_entries is still manage_options.' );
		$this->assertSame( 403, $this->call( 'DELETE', '/entries/' . $id )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/swiftforms-cmf/v1/settings/swiftforms' ) )->get_status(), 'manage_settings is still manage_options (the settings route is cassette-cmf\'s).' );
	}

	/**
	 * Newest first, spam and trash hidden by default, filters, page headers and the page-size enum.
	 *
	 * @return void
	 */
	public function test_list_filters_pagination_and_order() {
		$old    = $this->add_entry( [ 'name' => 'Old' ], [ 'created_gmt' => '2030-01-01 10:00:00' ] );
		$new    = $this->add_entry( [ 'name' => 'New' ], [ 'created_gmt' => '2030-01-03 10:00:00' ] );
		$mid    = $this->add_entry( [ 'name' => 'Mid' ], [ 'created_gmt' => '2030-01-02 10:00:00' ], 'read' );
		$spam   = $this->add_entry( [ 'name' => 'Spam' ], [ 'status' => 'spam' ] );
		$trash  = $this->add_entry( [ 'name' => 'Trash' ], [], 'trash' );
		$first  = $this->form_id;
		$second = $this->make_form();
		unset( $second );
		$other = $this->add_entry( [ 'name' => 'Other' ], [ 'created_gmt' => '2030-01-04 10:00:00' ] );
		$this->be_admin();

		$all = $this->call( 'GET', '/entries' );
		$this->assertSame( 200, $all->get_status() );
		$this->assertSame( [ $other, $new, $mid, $old ], wp_list_pluck( $all->get_data(), 'id' ), 'Newest first; spam and trash hidden.' );
		$this->assertSame( '4', $all->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $all->get_headers()['X-WP-TotalPages'] );

		$form = $this->call( 'GET', '/entries', [ 'form' => $first ] );
		$this->assertSame( [ $new, $mid, $old ], wp_list_pluck( $form->get_data(), 'id' ) );
		$this->assertSame(
			[ $spam ],
			wp_list_pluck(
				$this->call(
					'GET',
					'/entries',
					[
						'form'   => $first,
						'status' => 'spam',
					]
				)->get_data(),
				'id'
			)
		);
		$this->assertSame( [ $trash ], wp_list_pluck( $this->call( 'GET', '/entries', [ 'status' => 'trash' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $mid ], wp_list_pluck( $this->call( 'GET', '/entries', [ 'status' => 'read' ] )->get_data(), 'id' ) );

		$this->assertSame( 400, $this->call( 'GET', '/entries', [ 'per_page' => 7 ] )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/entries', [ 'status' => 'bogus' ] )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/entries', [ 'form' => 'not-a-uuid' ] )->get_status() );
		$this->assertSame( [], $this->call( 'GET', '/entries', [ 'page' => 2 ] )->get_data(), 'Past the last page is empty.' );
	}

	/**
	 * Paging with the real page sizes and the page headers.
	 *
	 * @return void
	 */
	public function test_pagination_headers() {
		for ( $i = 1; $i <= 45; $i++ ) {
			$this->add_entry( [ 'name' => 'N' . $i ], [ 'created_gmt' => gmdate( 'Y-m-d H:i:s', 1900000000 + $i ) ] );
		}
		$this->be_admin();

		$page1 = $this->call( 'GET', '/entries' );
		$this->assertCount( 20, $page1->get_data() );
		$this->assertSame( '45', $page1->get_headers()['X-WP-Total'] );
		$this->assertSame( '3', $page1->get_headers()['X-WP-TotalPages'] );
		$page3 = $this->call( 'GET', '/entries', [ 'page' => 3 ] );
		$this->assertCount( 5, $page3->get_data() );
		$this->assertStringContainsString( 'N1', $page3->get_data()[4]['summary'], 'The oldest entry is last.' );
		$page50 = $this->call( 'GET', '/entries', [ 'per_page' => 50 ] );
		$this->assertCount( 45, $page50->get_data() );
		$this->assertSame( '1', $page50->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * The summary is the first two non-empty values in schema order, cut short, and plain text.
	 *
	 * @return void
	 */
	public function test_summary_and_item_shape() {
		$this->add_entry(
			[
				'name'    => '',
				'email'   => 'ada@example.com',
				'message' => str_repeat( 'x', 300 ),
			]
		);
		$this->be_admin();

		$item = $this->call( 'GET', '/entries' )->get_data()[0];
		$this->assertSame( 'ada@example.com · ' . str_repeat( 'x', 79 ) . '…', $item['summary'] );
		$this->assertSame( $this->form_id, $item['form_id'] );
		$this->assertSame( 'new', $item['status'] );
		$this->assertFalse( $item['starred'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['date_gmt'] );
		$this->assertNotSame( '', $item['date_display'] );
		$this->assertArrayHasKey( 'form_title', $item );
		$this->assertArrayNotHasKey( 'ip', $item );
	}

	/**
	 * The detail uses the labels and types of the snapshot the entry was validated against.
	 *
	 * @return void
	 */
	public function test_detail_uses_the_entrys_snapshot_after_the_form_changed() {
		$id = $this->add_entry();

		// The author renames "Name" to "Full name" and drops the message field.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = (int) substr( $this->source, 5 );
		$inner   = $this->field_markup(
			'text',
			[
				'name'  => 'name',
				'label' => 'Full name',
			]
		)
			. $this->field_markup(
				'email',
				[
					'name'  => 'email',
					'label' => 'Email',
				]
			)
			. '<!-- wp:swiftforms/submit /-->';
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->form_markup( $this->form_id, $inner ),
			]
		);
		\SwiftForms\Form_Index::flush_cache();
		$this->assertNotSame( $this->schema()->hash, \SwiftForms\Entries_Repository::get( $id )['schema_hash'], 'The form really changed.' );

		$detail = $this->call( 'GET', '/entries/' . $id )->get_data();
		$labels = wp_list_pluck( $detail['fields'], 'label', 'name' );
		$this->assertSame(
			[
				'name'    => 'Name',
				'email'   => 'Email',
				'message' => 'Message',
			],
			$labels,
			'Old labels, old field list.'
		);
		$this->assertSame( 'ada@example.com', $detail['fields'][1]['values'][0]['value'] );
		$this->assertTrue( $detail['fields'][1]['values'][0]['is_email'] );
	}

	/**
	 * A value is a link only when it is an http or https URL; an email only when it is a valid address in an email field.
	 *
	 * @return void
	 */
	public function test_link_flags() {
		$bad = $this->add_entry(
			[
				'name'    => 'javascript:alert(1)',
				'email'   => 'ada@example.com',
				'message' => "\"><svg onload=alert(1)>\u{202E}",
			]
		);
		$ok  = $this->add_entry( [ 'name' => 'https://example.com/a?b=c' ] );
		$this->be_admin();

		$fields = $this->call( 'GET', '/entries/' . $bad )->get_data()['fields'];
		$this->assertFalse( $fields[0]['values'][0]['is_link'] );
		$this->assertSame( 'javascript:alert(1)', $fields[0]['values'][0]['value'], 'Values pass through unchanged: the client renders them as text.' );
		$this->assertFalse( $fields[2]['values'][0]['is_link'] );
		$this->assertTrue( $this->call( 'GET', '/entries/' . $ok )->get_data()['fields'][0]['values'][0]['is_link'] );
	}

	/**
	 * IP and user agent are in the detail only when stored; the source title only for a post the user can read.
	 *
	 * @return void
	 */
	public function test_detail_extras_and_source_gating() {
		$plain = $this->add_entry();
		global $wpdb;
		$wpdb->update( Database::table( 'entries' ), [ 'user_agent' => 'Agent/1.0' ], [ 'id' => $plain ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'entries' ) . ' SET ip = UNHEX(%s) WHERE id = %d', bin2hex( inet_pton( '203.0.113.9' ) ), $plain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test setup.
		$none = $this->add_entry();

		$this->be_admin();
		$detail = $this->call( 'GET', '/entries/' . $plain )->get_data();
		$this->assertSame( '203.0.113.9', $detail['ip'] );
		$this->assertSame( 'Agent/1.0', $detail['user_agent'] );
		$this->assertSame( get_the_title( (int) substr( $this->source, 5 ) ), $detail['source']['title'] );
		$this->assertArrayNotHasKey( 'ip', $this->call( 'GET', '/entries/' . $none )->get_data() );
		$this->assertArrayNotHasKey( 'user_agent', $this->call( 'GET', '/entries/' . $none )->get_data() );

		// A private page: a contributor with view_entries cannot read it, so no title and no link.
		wp_update_post(
			[
				'ID'          => (int) substr( $this->source, 5 ),
				'post_status' => 'private',
			]
		);
		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$this->assertNull( $this->call( 'GET', '/entries/' . $none )->get_data()['source'] );
		$this->be_admin();
		$this->assertNotNull( $this->call( 'GET', '/entries/' . $none )->get_data()['source'] );
	}

	/**
	 * PATCH changes status and star; DELETE removes the entry with values and meta.
	 *
	 * @return void
	 */
	public function test_patch_and_delete() {
		global $wpdb;
		$id = $this->add_entry();
		$this->be_admin();

		$read = $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'read' ] );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'read', $read->get_data()['status'] );
		$this->assertSame( 'new', $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'new' ] )->get_data()['status'], 'Unread.' );
		$this->assertTrue( $this->call( 'PATCH', '/entries/' . $id, [ 'starred' => true ] )->get_data()['starred'] );
		$this->assertSame( 'spam', $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'spam' ] )->get_data()['status'] );
		$this->assertSame( [], $this->call( 'GET', '/entries' )->get_data(), 'Spam is hidden from the default list.' );
		$this->assertSame( 'new', $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'new' ] )->get_data()['status'], 'Not spam.' );
		$this->assertSame( 'trash', $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'trash' ] )->get_data()['status'] );
		$this->assertSame( 'new', $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'new' ] )->get_data()['status'], 'Restore.' );
		$this->assertSame( 400, $this->call( 'PATCH', '/entries/' . $id, [ 'status' => 'bogus' ] )->get_status() );
		$this->assertSame( 400, $this->call( 'PATCH', '/entries/' . $id, [] )->get_status() );
		$this->assertSame( 404, $this->call( 'PATCH', '/entries/999999', [ 'starred' => true ] )->get_status() );

		$wpdb->insert(
			Database::table( 'entry_meta' ),
			[
				'entry_id'   => $id,
				'meta_key'   => 'k',
				'meta_value' => 'v',
			]
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$other = $this->add_entry();
		$gone  = $this->call( 'DELETE', '/entries/' . $id );
		$this->assertSame( 200, $gone->get_status() );
		$this->assertNull( Entries_Repository::get( $id ) );
		$this->assertSame( [], Entries_Repository::values( $id ) );
		$this->assertSame( [], $wpdb->get_results( 'SELECT * FROM ' . Database::table( 'entry_meta' ) . ' WHERE entry_id = ' . (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
		$this->assertNotNull( Entries_Repository::get( $other ), 'Only that entry went.' );
		$this->assertSame( 404, $this->call( 'DELETE', '/entries/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/entries/' . $id )->get_status() );
	}

	/**
	 * The forms list: minimal fields and counts per status.
	 *
	 * @return void
	 */
	public function test_forms_list() {
		$this->add_entry();
		$this->add_entry( [], [], 'read' );
		$this->add_entry( [], [ 'status' => 'spam' ] );
		$this->be_admin();

		$forms = $this->call( 'GET', '/forms' )->get_data();
		$mine  = array_values(
			array_filter(
				$forms,
				function ( $form ) {
					return $form['form_id'] === $this->form_id;
				}
			)
		);
		$this->assertCount( 1, $mine );
		$this->assertSame( [ 'form_id', 'title', 'counts' ], array_keys( $mine[0] ) );
		$this->assertSame(
			[
				'new'   => 1,
				'read'  => 1,
				'spam'  => 1,
				'trash' => 0,
			],
			$mine[0]['counts']
		);
	}

	/**
	 * A list of 20 costs at most four queries and uses form_status_date, also with 5,000 entries.
	 *
	 * @return void
	 */
	public function test_list_query_count_and_explain_on_5000_entries() {
		global $wpdb;
		$schema = $this->schema();
		$table  = Database::table( 'entries' );

		$rows = [];
		for ( $i = 0; $i < 5000; $i++ ) {
			$rows[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', $this->form_id, $schema->hash, $this->source, 0 === $i % 10 ? 'spam' : 'new', gmdate( 'Y-m-d H:i:s', 1800000000 + $i ) );
			if ( 500 === count( $rows ) ) {
				$wpdb->query( 'INSERT INTO ' . $table . ' (form_id, schema_hash, source, status, created_gmt) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test setup.
				$rows = [];
			}
		}
		$this->be_admin();
		Entry_Presenter::flush();

		$queries = [];
		$spy     = static function ( $query ) use ( &$queries ) {
			if ( 0 === stripos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, 'swiftforms_' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$response = $this->call(
			'GET',
			'/entries',
			[
				'form'   => $this->form_id,
				'status' => 'new',
			]
		);
		$default  = $this->call( 'GET', '/entries', [ 'form' => $this->form_id ] );
		remove_filter( 'query', $spy );

		$this->assertCount( 20, $response->get_data() );
		$this->assertSame( '4500', $response->get_headers()['X-WP-Total'] );
		$this->assertCount( 20, $default->get_data() );
		$this->assertLessThanOrEqual( 8, count( $queries ), 'Four queries for each of the two lists: ' . implode( "\n", $queries ) );

		$pages = array_values(
			array_filter(
				$queries,
				static function ( $query ) {
					return false !== strpos( $query, 'ORDER BY created_gmt DESC' );
				}
			)
		);
		$this->assertCount( 2, $pages );
		foreach ( $pages as $page_query ) {
			$plan = $wpdb->get_row( 'EXPLAIN ' . $page_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test: the page query as it ran.
			$this->assertSame( 'form_status_date', $plan['key'], 'EXPLAIN uses form_status_date: ' . wp_json_encode( $plan ) );
		}
	}
}
