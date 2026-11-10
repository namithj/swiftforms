<?php
/**
 * Custom tables.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * Table names and the dbDelta schema (ADR-003). The tables are per site: names
 * use the current blog's prefix, so on multisite every site has its own six.
 *
 * Column and index changes are additive only (C-4.1): a later release adds a
 * numbered migration (see Migrations) that calls install() again.
 */
class Database {

	/**
	 * Table name stem shared by every SwiftForms table.
	 */
	const PREFIX = 'swiftforms_';

	/**
	 * Table names without the WordPress prefix, in creation order.
	 */
	const TABLES = [ 'forms', 'schemas', 'entries', 'entry_values', 'entry_meta', 'log' ];

	/**
	 * Full table name for the current site.
	 *
	 * @param string $name One of self::TABLES (for example "entries").
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . self::PREFIX . preg_replace( '/[^a-z_]/', '', (string) $name );
	}

	/**
	 * Full names of the six tables, for the current site or for another one.
	 *
	 * @param int|null $blog_id Site ID, or null for the current site.
	 * @return string[]
	 */
	public static function tables( $blog_id = null ) {
		global $wpdb;
		$prefix = null === $blog_id ? $wpdb->prefix : $wpdb->get_blog_prefix( (int) $blog_id );
		$names  = [];
		foreach ( self::TABLES as $name ) {
			$names[] = $prefix . self::PREFIX . $name;
		}
		return $names;
	}

	/**
	 * Create or update the tables with dbDelta. Safe to run again: a second run changes nothing.
	 *
	 * @return string[] Messages from dbDelta (empty when nothing had to change).
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( self::schema() );
	}

	/**
	 * Names of the tables that do not exist on the current site.
	 *
	 * @return string[]
	 */
	public static function missing() {
		global $wpdb;

		$missing  = [];
		$suppress = $wpdb->suppress_errors();
		foreach ( self::tables() as $table ) {
			$table = esc_sql( $table );
			// DESCRIBE (not SHOW TABLES) so the check also sees the temporary tables the test suite creates (sf-allow-unprepared: a fixed list of table names).
			if ( ! $wpdb->get_results( 'DESCRIBE `' . $table . '`' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name built from $wpdb->prefix and a fixed list.
				$missing[] = $table;
			}
		}
		$wpdb->suppress_errors( $suppress );

		return $missing;
	}

	/**
	 * The CREATE TABLE statements, formatted the way dbDelta needs them.
	 *
	 * dbDelta wants one column per line, two spaces after PRIMARY KEY and no
	 * trailing comma. Index prefixes keep every key under 767 bytes (old
	 * InnoDB row formats, utf8mb4): source VARCHAR(191) is 764 bytes, the
	 * value index uses the first 150 characters (600 bytes), the entry_values
	 * primary key is 8 + 640 + 2 bytes. Dates follow core's own schema
	 * (NOT NULL with a zero default); every writer sets them explicitly.
	 *
	 * Column types are lowercase: WordPress 6.8's dbDelta compares the type text
	 * case-sensitively with what DESCRIBE returns, and would "change" every column
	 * on every run otherwise. Integer columns carry the display width core uses (bigint(20), smallint(5)):
	 * MariaDB still reports it, and without it dbDelta would "change" those columns
	 * on every run. MySQL 8.0.17+ drops the width and dbDelta ignores that difference.
	 *
	 * @return string[]
	 */
	private static function schema() {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$tables  = [];

		$table    = self::table( 'forms' );
		$tables[] = "CREATE TABLE {$table} (
	form_id char(36) NOT NULL,
	stored_id char(36) NOT NULL DEFAULT '',
	source varchar(191) NOT NULL DEFAULT '',
	title text NOT NULL,
	status varchar(20) NOT NULL DEFAULT '',
	locations longtext NOT NULL,
	schema_hash char(64) NOT NULL DEFAULT '',
	created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (form_id),
	KEY source (source),
	KEY stored_id (stored_id)
) {$collate};";

		$table    = self::table( 'schemas' );
		$tables[] = "CREATE TABLE {$table} (
	schema_hash char(64) NOT NULL,
	form_id char(36) NOT NULL DEFAULT '',
	`schema` longtext NOT NULL,
	created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (schema_hash),
	KEY form_id (form_id)
) {$collate};";

		$table    = self::table( 'entries' );
		$tables[] = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	form_id char(36) NOT NULL DEFAULT '',
	schema_hash char(64) NOT NULL DEFAULT '',
	source varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'new',
	starred tinyint(1) NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	ip varbinary(16) DEFAULT NULL,
	user_agent varchar(255) DEFAULT NULL,
	created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY form_status_date (form_id,status,created_gmt),
	KEY created_gmt (created_gmt)
) {$collate};";

		$table    = self::table( 'entry_values' );
		$tables[] = "CREATE TABLE {$table} (
	entry_id bigint(20) unsigned NOT NULL,
	field varchar(160) NOT NULL,
	idx smallint(5) unsigned NOT NULL DEFAULT 0,
	value longtext NOT NULL,
	PRIMARY KEY  (entry_id,field,idx),
	KEY value_prefix (value(150))
) {$collate};";

		$table    = self::table( 'entry_meta' );
		$tables[] = "CREATE TABLE {$table} (
	meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
	meta_key varchar(64) NOT NULL DEFAULT '',
	meta_value longtext NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (meta_id),
	KEY entry_key (entry_id,meta_key)
) {$collate};";

		// The log is diagnostics only and never holds user-created data (C-4.5). KEY created_gmt serves the 30-day prune.
		$table    = self::table( 'log' );
		$tables[] = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	type varchar(20) NOT NULL DEFAULT '',
	ref varchar(100) NOT NULL DEFAULT '',
	level varchar(10) NOT NULL DEFAULT 'info',
	message text NOT NULL,
	context longtext NOT NULL,
	created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY created_gmt (created_gmt)
) {$collate};";

		return $tables;
	}
}
