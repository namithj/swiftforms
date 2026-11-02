<?php
/**
 * Schema snapshots.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema_Store
 *
 * The swiftforms_schemas table: one row per schema hash, written once and kept
 * forever (C-4.4), so an entry stays readable after its form changes or is deleted
 * and a cached page can still be validated against the schema it showed (C-3.8).
 * A row is never updated; two forms that happen to share a field list have different
 * form IDs but one hash, and the first snapshot stays (what is stored is identical for
 * validation purposes, and the form_id column records who wrote it first).
 */
class Schema_Store {

	/**
	 * Object cache group. Snapshots never change, so cached copies never go stale.
	 */
	const CACHE_GROUP = 'swiftforms_schemas';

	/**
	 * Store a snapshot unless its hash is already there.
	 *
	 * @param Form_Schema $schema Resolved schema.
	 * @return bool True when a row was inserted, false when the hash was already stored or the write failed.
	 */
	public static function save( Form_Schema $schema ) {
		global $wpdb;

		if ( ! self::is_hash( $schema->hash ) || self::has( $schema->hash ) ) {
			return false;
		}

		$json = wp_json_encode( $schema->to_snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$table = esc_sql( Database::table( 'schemas' ) );
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (schema_hash, form_id, `schema`, created_gmt) VALUES (%s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
				$schema->hash,
				$schema->form_id,
				$json,
				Clock::gmt_mysql()
			)
		);

		if ( 1 === (int) $rows ) {
			wp_cache_set( $schema->hash, $json, self::CACHE_GROUP );
			return true;
		}
		return false;
	}

	/**
	 * The snapshot for a hash.
	 *
	 * @param string $hash Schema hash.
	 * @return Form_Schema|null Null when the hash is not valid or unknown.
	 */
	public static function get( $hash ) {
		$json = self::get_json( $hash );
		return null === $json ? null : Form_Schema::from_snapshot( $json );
	}

	/**
	 * Hashes of a form's snapshots, newest first. The candidates a render token may name besides the current hash (C-3.8).
	 *
	 * @param string $form_id Effective formId.
	 * @param int    $limit   Most hashes returned.
	 * @return string[]
	 */
	public static function recent_hashes( $form_id, $limit = 25 ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $form_id ) ) {
			return [];
		}

		$table  = esc_sql( Database::table( 'schemas' ) );
		$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT schema_hash FROM {$table} WHERE form_id = %s ORDER BY created_gmt DESC, schema_hash ASC LIMIT %d", $form_id, max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		return is_array( $hashes ) ? array_values( array_filter( $hashes, [ self::class, 'is_hash' ] ) ) : [];
	}

	/**
	 * Whether a hash is stored.
	 *
	 * @param string $hash Schema hash.
	 * @return bool
	 */
	public static function has( $hash ) {
		return null !== self::get_json( $hash );
	}

	/**
	 * Whether a string looks like a schema hash.
	 *
	 * @param mixed $hash Candidate.
	 * @return bool
	 */
	public static function is_hash( $hash ) {
		return is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $hash );
	}

	/**
	 * Raw snapshot JSON for a hash.
	 *
	 * @param string $hash Schema hash.
	 * @return string|null
	 */
	private static function get_json( $hash ) {
		global $wpdb;

		if ( ! self::is_hash( $hash ) ) {
			return null;
		}

		$cached = wp_cache_get( $hash, self::CACHE_GROUP );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$table = esc_sql( Database::table( 'schemas' ) );
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT `schema` FROM {$table} WHERE schema_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		if ( ! is_string( $json ) ) {
			return null;
		}

		wp_cache_set( $hash, $json, self::CACHE_GROUP );
		return $json;
	}
}
