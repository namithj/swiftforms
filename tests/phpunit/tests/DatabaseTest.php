<?php
/**
 * Tests for SwiftForms\Database.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Database;

/**
 * Class DatabaseTest
 *
 * @group database
 */
class DatabaseTest extends SF_Storage_Test_Case {

	/**
	 * Column => "type NULL|NOT NULL", per table: ADR-003.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function expected_columns() {
		return [
			'forms'        => [
				'form_id'     => 'char(36) NOT NULL',
				'stored_id'   => 'char(36) NOT NULL',
				'source'      => 'varchar(191) NOT NULL',
				'title'       => 'text NOT NULL',
				'status'      => 'varchar(20) NOT NULL',
				'locations'   => 'longtext NOT NULL',
				'schema_hash' => 'char(64) NOT NULL',
				'created_gmt' => 'datetime NOT NULL',
				'updated_gmt' => 'datetime NOT NULL',
			],
			'schemas'      => [
				'schema_hash' => 'char(64) NOT NULL',
				'form_id'     => 'char(36) NOT NULL',
				'schema'      => 'longtext NOT NULL',
				'created_gmt' => 'datetime NOT NULL',
			],
			'entries'      => [
				'id'          => 'bigint unsigned NOT NULL',
				'form_id'     => 'char(36) NOT NULL',
				'schema_hash' => 'char(64) NOT NULL',
				'source'      => 'varchar(191) NOT NULL',
				'status'      => 'varchar(20) NOT NULL',
				'starred'     => 'tinyint NOT NULL',
				'user_id'     => 'bigint unsigned NOT NULL',
				'ip'          => 'varbinary(16) NULL',
				'user_agent'  => 'varchar(255) NULL',
				'created_gmt' => 'datetime NOT NULL',
				'updated_gmt' => 'datetime NOT NULL',
			],
			'entry_values' => [
				'entry_id' => 'bigint unsigned NOT NULL',
				'field'    => 'varchar(160) NOT NULL',
				'idx'      => 'smallint unsigned NOT NULL',
				'value'    => 'longtext NOT NULL',
			],
			'entry_meta'   => [
				'meta_id'     => 'bigint unsigned NOT NULL',
				'entry_id'    => 'bigint unsigned NOT NULL',
				'meta_key'    => 'varchar(64) NOT NULL',
				'meta_value'  => 'longtext NOT NULL',
				'user_id'     => 'bigint unsigned NOT NULL',
				'created_gmt' => 'datetime NOT NULL',
			],
			'log'          => [
				'id'          => 'bigint unsigned NOT NULL',
				'type'        => 'varchar(20) NOT NULL',
				'ref'         => 'varchar(100) NOT NULL',
				'level'       => 'varchar(10) NOT NULL',
				'message'     => 'text NOT NULL',
				'context'     => 'longtext NOT NULL',
				'created_gmt' => 'datetime NOT NULL',
			],
		];
	}

	/**
	 * Index name => columns (with "(n)" for a prefix), per table: ADR-003.
	 *
	 * @return array<string, array<string, string[]>>
	 */
	private function expected_indexes() {
		return [
			'forms'        => [
				'PRIMARY'   => [ 'form_id' ],
				'source'    => [ 'source' ],
				'stored_id' => [ 'stored_id' ],
			],
			'schemas'      => [
				'PRIMARY' => [ 'schema_hash' ],
				'form_id' => [ 'form_id' ],
			],
			'entries'      => [
				'PRIMARY'          => [ 'id' ],
				'form_status_date' => [ 'form_id', 'status', 'created_gmt' ],
				'created_gmt'      => [ 'created_gmt' ],
			],
			'entry_values' => [
				'PRIMARY'      => [ 'entry_id', 'field', 'idx' ],
				'value_prefix' => [ 'value(150)' ],
			],
			'entry_meta'   => [
				'PRIMARY'   => [ 'meta_id' ],
				'entry_key' => [ 'entry_id', 'meta_key' ],
			],
			'log'          => [
				'PRIMARY'     => [ 'id' ],
				'created_gmt' => [ 'created_gmt' ],
			],
		];
	}

	/**
	 * Names follow the site prefix and list exactly six tables.
	 *
	 * @return void
	 */
	public function test_table_names() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'swiftforms_entries', Database::table( 'entries' ) );
		$this->assertSame(
			[ 'forms', 'schemas', 'entries', 'entry_values', 'entry_meta', 'log' ],
			Database::TABLES
		);

		$tables = Database::tables();
		$this->assertCount( 6, $tables );
		foreach ( $tables as $table ) {
			$this->assertStringStartsWith( $wpdb->prefix . 'swiftforms_', $table );
		}
		$this->assertSame( $wpdb->get_blog_prefix( 1 ) . 'swiftforms_log', Database::tables( 1 )[5] );
	}

	/**
	 * install() creates the six tables with exactly the ADR-003 columns.
	 *
	 * @return void
	 */
	public function test_install_creates_adr003_columns() {
		global $wpdb;

		$this->use_scratch_prefix();
		$this->assertCount( 6, Database::missing(), 'The scratch prefix starts without tables.' );

		Database::install();

		$this->assertSame( [], Database::missing() );
		foreach ( $this->expected_columns() as $name => $expected ) {
			$actual = [];
			foreach ( $wpdb->get_results( 'SHOW COLUMNS FROM `' . Database::table( $name ) . '`', ARRAY_A ) as $column ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table.
				// MySQL 8 drops integer display widths that MariaDB keeps; ignore them.
				$type                       = preg_replace( '/\b(tinyint|smallint|bigint)\(\d+\)/', '$1', strtolower( $column['Type'] ) );
				$actual[ $column['Field'] ] = $type . ( 'YES' === $column['Null'] ? ' NULL' : ' NOT NULL' );
			}
			$this->assertSame( $expected, $actual, 'Columns of ' . $name );
		}
	}

	/**
	 * The keys are the ADR-003 keys, and every key stays under 767 bytes.
	 *
	 * @return void
	 */
	public function test_install_creates_adr003_indexes_under_767_bytes() {
		global $wpdb;

		$this->use_scratch_prefix();
		Database::install();

		foreach ( $this->expected_indexes() as $name => $expected ) {
			$table   = Database::table( $name );
			$lengths = [];
			foreach ( $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ) as $column ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table.
				$lengths[ $column['Field'] ] = $column['Type'];
			}

			$actual = [];
			$bytes  = [];
			foreach ( $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ) as $index ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table.
				$key = $index['Key_name'];
				$col = $index['Column_name'];
				if ( ! empty( $index['Sub_part'] ) ) {
					$actual[ $key ][] = $col . '(' . $index['Sub_part'] . ')';
					$bytes[ $key ]    = ( $bytes[ $key ] ?? 0 ) + 4 * (int) $index['Sub_part'];
				} else {
					$actual[ $key ][] = $col;
					$bytes[ $key ]    = ( $bytes[ $key ] ?? 0 ) + $this->index_bytes( $lengths[ $col ] );
				}
			}
			$this->assertSame( $expected, $actual, 'Keys of ' . $name );
			foreach ( $bytes as $key => $total ) {
				$this->assertLessThan( 767, $total, "Key {$key} of {$name} is {$total} bytes." );
			}
		}
	}

	/**
	 * A second install() has nothing to do.
	 *
	 * @return void
	 */
	public function test_install_twice_is_a_noop() {
		global $wpdb;

		$this->use_scratch_prefix();
		$first = Database::install();
		$this->assertNotEmpty( $first, 'The first run reports what it created.' );

		$before = $this->create_statements();
		$second = Database::install();

		$this->assertSame( [], $second, 'dbDelta reports nothing to change.' );
		$this->assertSame( $before, $this->create_statements() );
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	/**
	 * A missing table is reported by name.
	 *
	 * @return void
	 */
	public function test_missing_lists_absent_tables() {
		global $wpdb;

		$this->use_scratch_prefix();
		Database::install();
		$wpdb->query( 'DROP TABLE `' . Database::table( 'schemas' ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table.

		$this->assertSame( [ Database::table( 'schemas' ) ], Database::missing() );
	}

	/**
	 * CREATE TABLE text of the six tables, for comparing two states.
	 *
	 * @return string[]
	 */
	private function create_statements() {
		global $wpdb;

		$out = [];
		foreach ( Database::tables() as $table ) {
			$row   = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table.
			$out[] = $row[1];
		}
		return $out;
	}

	/**
	 * Bytes a whole column takes in an index (utf8mb4: 4 per character).
	 *
	 * @param string $type Column type from SHOW COLUMNS.
	 * @return int
	 */
	private function index_bytes( $type ) {
		if ( preg_match( '/^(?:var)?(?:char|binary)\((\d+)\)/i', $type, $m ) ) {
			return 0 === stripos( $type, 'varbinary' ) || 0 === stripos( $type, 'binary' ) ? (int) $m[1] : 4 * (int) $m[1];
		}
		if ( 0 === stripos( $type, 'bigint' ) ) {
			return 8;
		}
		if ( 0 === stripos( $type, 'smallint' ) ) {
			return 2;
		}
		if ( 0 === stripos( $type, 'datetime' ) ) {
			return 8;
		}
		return 0 === stripos( $type, 'text' ) || 0 === stripos( $type, 'longtext' ) ? 100000 : 8;
	}
}
