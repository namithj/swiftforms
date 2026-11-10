<?php
/**
 * Entry storage.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Entries_Repository
 *
 * Reads and writes the swiftforms_entries and swiftforms_entry_values tables (ADR-003, C-4). Every query goes
 * through $wpdb->prepare() or $wpdb->insert(). It also lists, filters, updates and deletes entries for the admin screens.
 *
 * What insert() stores:
 * - one entries row: form_id (the effective formId), schema_hash (of the schema the fields were validated
 *   against, C-3.8), source, status (new or spam), starred 0, user_id, UTC dates from Clock, the IP address
 *   (inet_pton, VARBINARY(16)) only when general.store_ip is on and the user agent (at most 255 characters)
 *   only when general.store_user_agent is on;
 * - entry_values rows: a single value is one row at idx 0, a multi-value field one row per value at idx 0..n
 *   (C-4.3). An EMPTY value (an optional field left blank, an unticked consent box, a list with no choice)
 *   stores NO row. The entry detail is drawn from the schema snapshot, which lists every field, so a missing
 *   row reads as blank; the table stays small and "has a value" queries stay simple.
 */
class Entries_Repository {

	/**
	 * Entry values inserted per query.
	 */
	const CHUNK = 100;

	/**
	 * Store a submission.
	 *
	 * @param Submission_Context $ctx Context (values, validation schema, status, user, created time).
	 * @return int The entry ID, or 0 when the entry could not be written.
	 */
	public static function insert( Submission_Context $ctx ) {
		global $wpdb;

		$now     = '' !== $ctx->created_gmt ? $ctx->created_gmt : Clock::gmt_mysql();
		$row     = [
			'form_id'     => $ctx->form->form_id,
			'schema_hash' => $ctx->validation_schema->hash,
			'source'      => $ctx->source,
			'status'      => 'spam' === $ctx->status ? 'spam' : 'new',
			'starred'     => 0,
			'user_id'     => $ctx->user_id(),
			'created_gmt' => $now,
			'updated_gmt' => $now,
		];
		$formats = [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ];

		if ( true === Settings::get_value( 'general.store_user_agent', false ) ) {
			$agent = self::user_agent();
			if ( '' !== $agent ) {
				$row['user_agent'] = $agent;
				$formats[]         = '%s';
			}
		}

		$table = Database::table( 'entries' );
		if ( false === $wpdb->insert( $table, $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			Log::add( 'error', 'error', 'An entry could not be written.', [ 'form' => $ctx->form->form_id ], $ctx->form->form_id );
			return 0;
		}
		$entry_id = (int) $wpdb->insert_id;
		if ( $entry_id <= 0 ) {
			return 0;
		}

		if ( true === Settings::get_value( 'general.store_ip', false ) ) {
			$packed = @inet_pton( Client_Ip::address() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on invalid text; the address is validated already.
			if ( false !== $packed ) {
				$esc = esc_sql( $table );
				$wpdb->query( $wpdb->prepare( "UPDATE {$esc} SET ip = UNHEX(%s) WHERE id = %d", bin2hex( $packed ), $entry_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
			}
		}

		if ( ! self::insert_values( $entry_id, $ctx->values ) ) {
			Log::add( 'error', 'error', 'The values of an entry could not be written completely.', [ 'entry' => $entry_id ], $ctx->form->form_id );
		}

		return $entry_id;
	}

	/**
	 * An entry with its values.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, mixed>|null Columns (ip as a readable address or null), and "values": field name => list of strings in idx order. The schema decides whether a field is single or multi-value. Null when there is no such entry.
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}

		$table = esc_sql( Database::table( 'entries' ) );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT *, HEX(ip) AS ip_hex FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		if ( ! is_array( $row ) ) {
			return null;
		}

		$ip_hex = (string) $row['ip_hex'];
		unset( $row['ip_hex'] );
		$row['ip'] = '' === $ip_hex ? null : (string) inet_ntop( (string) hex2bin( $ip_hex ) );

		foreach ( [ 'id', 'starred', 'user_id' ] as $int_column ) {
			$row[ $int_column ] = (int) $row[ $int_column ];
		}
		$row['values'] = self::values( $id );

		return $row;
	}

	/**
	 * The values of an entry.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, string[]> Field name => values in idx order.
	 */
	public static function values( $id ) {
		global $wpdb;

		$table = esc_sql( Database::table( 'entry_values' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT field, value FROM {$table} WHERE entry_id = %d ORDER BY field ASC, idx ASC", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.

		$values = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$values[ (string) $row['field'] ][] = (string) $row['value'];
		}
		return $values;
	}

	/**
	 * The statuses an entry can have. "new" and "read" are the unread/read pair; spam and trash hide an entry.
	 */
	const STATUSES = [ 'new', 'read', 'spam', 'trash' ];

	/**
	 * Characters of a value fetched for a list summary.
	 */
	const SUMMARY_CHARS = 300;

	/**
	 * Most entries deleted per query.
	 */
	const DELETE_BATCH = 500;

	/**
	 * One page of entries, newest first unless "order" says otherwise.
	 *
	 * Three queries (the presenter adds a fourth for titles and snapshots): the count and the page
	 * (both served by form_status_date when a form is chosen), then one batched values query. No filter on the
	 * status means every entry that is neither spam nor trash.
	 *
	 * @param array<string, mixed> $args form (effective formId or ''), status (one of STATUSES or ''), starred (true: only starred entries), order ('asc' or 'desc', default 'desc'), page (1-based), per_page.
	 * @return array{total:int, rows:array<int, array<string, mixed>>, values:array<int, array<string, string[]>>} Rows hold the entry columns without ip and user agent. Values are cut to SUMMARY_CHARS characters.
	 */
	public static function query( array $args ) {
		global $wpdb;

		$form = isset( $args['form'] ) && is_string( $args['form'] ) ? $args['form'] : '';
		if ( '' !== $form && ! Uuid::is_valid( $form ) ) {
			return [
				'total'  => 0,
				'rows'   => [],
				'values' => [],
			];
		}
		$status   = isset( $args['status'] ) && is_string( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : '';
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$where = [];
		if ( '' !== $form ) {
			$where[] = $wpdb->prepare( 'form_id = %s', $form );
		}
		if ( '' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		} else {
			$where[] = $wpdb->prepare( 'status IN (%s, %s)', 'new', 'read' );
		}
		if ( ! empty( $args['starred'] ) ) {
			$where[] = $wpdb->prepare( 'starred = %d', 1 );
		}
		$ascending = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] );
		$table     = Database::table( 'entries' );

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ', $table ) . implode( ' AND ', $where ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; every fragment is prepared.
		if ( 0 === $total ) {
			return [
				'total'  => 0,
				'rows'   => [],
				'values' => [],
			];
		}

		// With a form chosen, form_status_date is the index to use: left alone, the optimiser may walk the whole created_gmt index
		// when the default "new or read" filter matches most rows, which is slow for a small form among many.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; every fragment is prepared.
			$wpdb->prepare( 'SELECT id, form_id, schema_hash, source, status, starred, user_id, created_gmt, updated_gmt FROM %i', $table )
				. ( '' !== $form ? ' FORCE INDEX (form_status_date)' : '' )
				. ' WHERE ' . implode( ' AND ', $where )
				. ( $ascending ? ' ORDER BY created_gmt ASC, id ASC' : ' ORDER BY created_gmt DESC, id DESC' )
				. $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : [];

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['id']      = (int) $row['id'];
			$rows[ $i ]['starred'] = (int) $row['starred'];
			$rows[ $i ]['user_id'] = (int) $row['user_id'];
		}

		return [
			'total'  => $total,
			'rows'   => $rows,
			'values' => self::values_for( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ), self::SUMMARY_CHARS ),
		];
	}

	/**
	 * The values of several entries in one query.
	 *
	 * @param int[] $ids   Entry IDs.
	 * @param int   $chars Characters kept of each value (0 keeps all).
	 * @return array<int, array<string, string[]>> Entry ID => field name => values in idx order.
	 */
	public static function values_for( array $ids, $chars = 0 ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}

		$table  = esc_sql( Database::table( 'entry_values' ) );
		$column = $chars > 0 ? 'LEFT(value, ' . (int) $chars . ')' : 'value';
		$marks  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, field, {$column} AS value FROM {$table} WHERE entry_id IN ({$marks}) ORDER BY entry_id ASC, field ASC, idx ASC", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; the column is a fixed expression and the IDs are placeholders.

		$values = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$values[ (int) $row['entry_id'] ][ (string) $row['field'] ][] = (string) $row['value'];
		}
		return $values;
	}

	/**
	 * Entry counts per form and status.
	 *
	 * @return array<string, array<string, int>> Form ID => status => count (statuses with no entry are absent).
	 */
	public static function counts_by_form() {
		global $wpdb;

		$table = esc_sql( Database::table( 'entries' ) );
		// sf-allow-unprepared: no input.
		$rows = $wpdb->get_results( "SELECT form_id, status, COUNT(*) AS total FROM {$table} GROUP BY form_id, status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; no variable input.

		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (string) $row['form_id'] ][ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Entry counts per form and status, with the number of starred entries, in one query.
	 *
	 * @return array<string, array<string, array{total:int, starred:int}>> Form ID => status => counts (statuses with no entry are absent).
	 */
	public static function counts_with_stars() {
		global $wpdb;

		$table = esc_sql( Database::table( 'entries' ) );
		// sf-allow-unprepared: no input.
		$rows = $wpdb->get_results( "SELECT form_id, status, COUNT(*) AS total, SUM(starred) AS starred FROM {$table} GROUP BY form_id, status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; no variable input.

		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (string) $row['form_id'] ][ (string) $row['status'] ] = [
				'total'   => (int) $row['total'],
				'starred' => (int) $row['starred'],
			];
		}
		return $counts;
	}

	/**
	 * Change the status and/or the star of an entry.
	 *
	 * @param int                  $id      Entry ID.
	 * @param array<string, mixed> $changes "status" (one of STATUSES) and/or "starred" (bool).
	 * @return bool Whether the entry exists (also when nothing needed to change).
	 */
	public static function update( $id, array $changes ) {
		global $wpdb;

		$id      = (int) $id;
		$data    = [];
		$formats = [];
		if ( isset( $changes['status'] ) && in_array( $changes['status'], self::STATUSES, true ) ) {
			$data['status'] = $changes['status'];
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'starred', $changes ) ) {
			$data['starred'] = $changes['starred'] ? 1 : 0;
			$formats[]       = '%d';
		}

		$table = esc_sql( Database::table( 'entries' ) );
		if ( ! $data ) {
			return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		}

		$data['updated_gmt'] = Clock::gmt_mysql();
		$formats[]           = '%s';
		$wpdb->update( Database::table( 'entries' ), $data, [ 'id' => $id ], $formats, [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
	}

	/**
	 * Delete entries for good, with their values and meta.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Number of entries deleted.
	 */
	public static function delete_many( array $ids ) {
		global $wpdb;

		$ids     = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$deleted = 0;
		foreach ( array_chunk( $ids, self::DELETE_BATCH ) as $chunk ) {
			$marks = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( [ 'entry_values', 'entry_meta' ] as $name ) {
				$table = esc_sql( Database::table( $name ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE entry_id IN ({$marks})", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; IDs are placeholders.
			}
			$table    = esc_sql( Database::table( 'entries' ) );
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$marks})", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; IDs are placeholders.
		}
		return $deleted;
	}

	/**
	 * Delete one entry for good.
	 *
	 * @param int $id Entry ID.
	 * @return bool Whether it existed.
	 */
	public static function delete( $id ) {
		return 1 === self::delete_many( [ (int) $id ] );
	}

	/**
	 * IDs of entries created strictly before a moment, oldest first.
	 *
	 * @param string   $cutoff   UTC "Y-m-d H:i:s". An entry created exactly then is kept.
	 * @param string[] $statuses Only these statuses (empty: any).
	 * @param int      $limit    Most IDs returned.
	 * @return int[]
	 */
	public static function ids_before( $cutoff, array $statuses = [], $limit = self::DELETE_BATCH ) {
		global $wpdb;

		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; every fragment is prepared.
			$wpdb->prepare( 'SELECT id FROM %i WHERE created_gmt < %s', Database::table( 'entries' ), (string) $cutoff )
			. ( $statuses ? $wpdb->prepare( ' AND status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses ) : '' )
			. $wpdb->prepare( ' ORDER BY created_gmt ASC, id ASC LIMIT %d', max( 1, (int) $limit ) )
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Write the entry_values rows.
	 *
	 * @param int                            $entry_id Entry ID.
	 * @param array<string, string|string[]> $values   Field name => value or list of values.
	 * @return bool Whether every row was written.
	 */
	private static function insert_values( $entry_id, array $values ) {
		global $wpdb;

		$rows = [];
		foreach ( $values as $name => $value ) {
			$list = is_array( $value ) ? array_values( $value ) : [ $value ];
			foreach ( $list as $idx => $item ) {
				$item = (string) $item;
				if ( '' !== $item ) {
					$rows[] = [ (string) $name, (int) $idx, $item ];
				}
			}
		}

		$ok = true;
		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$args = [ Database::table( 'entry_values' ) ];
			foreach ( $chunk as $row ) {
				array_push( $args, $entry_id, $row[0], $row[1], $row[2] );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (entry_id, field, idx, value) VALUES ' . implode( ', ', array_fill( 0, count( $chunk ), '(%d, %s, %d, %s)' ) ), $args ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; one argument per placeholder.
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * The visitor's user agent, cut to 255 characters.
	 *
	 * @return string
	 */
	private static function user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) || ! is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		$agent = Validator::clean_text( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by clean_text().
		return function_exists( 'mb_substr' ) ? mb_substr( $agent, 0, 255, 'UTF-8' ) : substr( $agent, 0, 255 );
	}
}
