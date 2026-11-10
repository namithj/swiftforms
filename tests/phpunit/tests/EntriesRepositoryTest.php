<?php
/**
 * Tests for SwiftForms\Entries_Repository.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Entries_Repository;
use SwiftForms\Field_Types;
use SwiftForms\Form_Schema;
use SwiftForms\Submission_Context;

/**
 * Class EntriesRepositoryTest
 *
 * @group submission
 */
class EntriesRepositoryTest extends SF_Storage_Test_Case {

	const FORM = '11111111-1111-4111-8111-111111111111';

	/**
	 * A context.
	 *
	 * @param array $values Values.
	 * @param array $extra  Context properties.
	 * @return Submission_Context
	 */
	private function ctx( array $values, array $extra = [] ) {
		$fields = [];
		foreach ( array_keys( $values ) as $name ) {
			$fields[] = array_merge( [ 'type' => 'text' ], Field_Types::defaults( 'text' ), [ 'name' => $name ] );
		}
		$schema = new Form_Schema(
			[
				'form_id' => self::FORM,
				'source'  => 'post:5',
				'fields'  => $fields,
			]
		);
		return new Submission_Context( $schema, null, array_merge( [ 'values' => $values ], $extra ) );
	}

	/**
	 * Row and values, and what is left out.
	 *
	 * @return void
	 */
	public function test_insert_and_get() {
		Clock::freeze( 1900000000 );
		$ctx = $this->ctx(
			[
				'name'   => 'Ada',
				'note'   => '',
				'colors' => [ 'red', 'a,b|c', 'blue' ],
				'none'   => [],
			],
			[ 'created_gmt' => gmdate( 'Y-m-d H:i:s', 1900000000 ) ]
		);

		$id = Entries_Repository::insert( $ctx );
		$this->assertGreaterThan( 0, $id );

		$entry = Entries_Repository::get( $id );
		$this->assertSame( $id, $entry['id'] );
		$this->assertSame( self::FORM, $entry['form_id'] );
		$this->assertSame( $ctx->validation_schema->hash, $entry['schema_hash'] );
		$this->assertSame( 'post:5', $entry['source'] );
		$this->assertSame( 'new', $entry['status'] );
		$this->assertSame( 0, $entry['starred'] );
		$this->assertSame( 0, $entry['user_id'] );
		$this->assertNull( $entry['ip'] );
		$this->assertNull( $entry['user_agent'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1900000000 ), $entry['created_gmt'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1900000000 ), $entry['updated_gmt'] );

		$this->assertSame(
			[
				'colors' => [ 'red', 'a,b|c', 'blue' ],
				'name'   => [ 'Ada' ],
			],
			$entry['values'],
			'Empty values store no row; a list is one row per value in order.'
		);

		$rows = $this->rows( 'entry_values' );
		$this->assertCount( 4, $rows );
		$colors = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'colors' === $row['field'];
				}
			)
		);
		$this->assertSame( [ '0', '1', '2' ], array_column( $colors, 'idx' ) );
	}

	/**
	 * A spam status is kept; a user ID too.
	 *
	 * @return void
	 */
	public function test_status_and_user() {
		$user_id = self::factory()->user->create();
		$id      = Entries_Repository::insert(
			$this->ctx(
				[ 'a' => 'b' ],
				[
					'status' => 'spam',
					'user'   => new WP_User( $user_id ),
				]
			)
		);
		$entry   = Entries_Repository::get( $id );
		$this->assertSame( 'spam', $entry['status'] );
		$this->assertSame( $user_id, $entry['user_id'] );

		$other = Entries_Repository::insert( $this->ctx( [ 'a' => 'b' ], [ 'status' => 'nonsense' ] ) );
		$this->assertSame( 'new', Entries_Repository::get( $other )['status'], 'Only new and spam can be written.' );
	}

	/**
	 * IP and user agent are stored only when the settings say so.
	 *
	 * @return void
	 */
	public function test_ip_and_user_agent_are_opt_in() {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0\r\n (Test) " . str_repeat( 'x', 400 );

		$off = Entries_Repository::get( Entries_Repository::insert( $this->ctx( [ 'a' => 'b' ] ) ) );
		$this->assertNull( $off['ip'] );
		$this->assertNull( $off['user_agent'] );

		$this->set_settings(
			[
				'general' => [
					'store_ip'         => true,
					'store_user_agent' => true,
				],
			]
		);
		$on = Entries_Repository::get( Entries_Repository::insert( $this->ctx( [ 'a' => 'b' ] ) ) );
		$this->assertSame( '203.0.113.7', $on['ip'] );
		$this->assertSame( 255, mb_strlen( $on['user_agent'] ) );
		$this->assertStringStartsWith( 'Mozilla/5.0  (Test) xxx', $on['user_agent'] );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT HEX(ip) FROM `' . SwiftForms\Database::table( 'entries' ) . '` WHERE id = %d', $on['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 'CB007107', $raw, 'Four bytes: inet_pton() of the IPv4 address.' );

		$_SERVER['REMOTE_ADDR'] = '2001:db8::7';
		$v6                     = Entries_Repository::get( Entries_Repository::insert( $this->ctx( [ 'a' => 'b' ] ) ) );
		$this->assertSame( '2001:db8::7', $v6['ip'], 'IPv6 fits the 16 bytes.' );

		$_SERVER['REMOTE_ADDR'] = 'not an address';
		$none                   = Entries_Repository::get( Entries_Repository::insert( $this->ctx( [ 'a' => 'b' ] ) ) );
		$this->assertNull( $none['ip'] );
	}

	/**
	 * Values with quotes, percent signs and emoji survive the prepared insert.
	 *
	 * @return void
	 */
	public function test_awkward_values_round_trip() {
		$values = [
			'a' => "It's 100% \"fine\" \\ \u{1F600} <b>%s %d</b>",
			'b' => str_repeat( 'long ', 3000 ),
		];
		$id     = Entries_Repository::insert( $this->ctx( $values ) );
		$this->assertSame(
			[
				'a' => [ $values['a'] ],
				'b' => [ $values['b'] ],
			],
			Entries_Repository::get( $id )['values']
		);
	}

	/**
	 * Many values are inserted in chunks.
	 *
	 * @return void
	 */
	public function test_many_values() {
		$values = [];
		for ( $i = 0; $i < 250; $i++ ) {
			$values[ 'f' . $i ] = 'v' . $i;
		}
		$id = Entries_Repository::insert( $this->ctx( $values ) );
		$this->assertCount( 250, Entries_Repository::get( $id )['values'] );
	}

	/**
	 * A missing entry is null.
	 *
	 * @return void
	 */
	public function test_get_missing() {
		$this->assertNull( Entries_Repository::get( 999999 ) );
		$this->assertNull( Entries_Repository::get( 0 ) );
		$this->assertNull( Entries_Repository::get( -1 ) );
	}
}
