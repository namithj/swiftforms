<?php
/**
 * Entries as the admin screens and the REST API show them.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Entry_Presenter
 *
 * Turns stored rows into arrays for the Entries screen. Labels and field types come from the schema snapshot
 * the entry was validated against (C-3.7), never from the current form, so an entry reads the way it was
 * submitted even after the form changed. Every value is plain text: the client renders it as text, and the
 * server only says whether a value may become a link (http or https, or a validated email address).
 */
class Entry_Presenter {

	/**
	 * Characters of one value in a list summary.
	 */
	const SUMMARY_VALUE_CHARS = 80;

	/**
	 * Separator between the values of a summary.
	 */
	const SUMMARY_SEPARATOR = ' · ';

	/**
	 * Forms and snapshots by key, for the request.
	 *
	 * @var array<string, mixed>
	 */
	private static $snapshots = [];

	/**
	 * Present one page of a query() result.
	 *
	 * Exactly one query reads the titles of the forms and the snapshots of the page.
	 *
	 * @param array<string, mixed> $page Result of Entries_Repository::query().
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_items( array $page ) {
		$rows = $page['rows'];
		if ( ! $rows ) {
			return [];
		}

		$lookup = self::forms_and_snapshots( array_values( array_unique( wp_list_pluck( $rows, 'form_id' ) ) ), array_values( array_unique( wp_list_pluck( $rows, 'schema_hash' ) ) ) );

		$items = [];
		foreach ( $rows as $row ) {
			$schema  = $lookup['schemas'][ $row['schema_hash'] ] ?? null;
			$values  = $page['values'][ $row['id'] ] ?? [];
			$items[] = [
				'id'           => $row['id'],
				'form_id'      => $row['form_id'],
				'form_title'   => self::title( $lookup['titles'][ $row['form_id'] ] ?? '', $schema ),
				'date_gmt'     => self::iso( $row['created_gmt'] ),
				'date_display' => self::display( $row['created_gmt'] ),
				'status'       => $row['status'],
				'starred'      => 1 === $row['starred'],
				'summary'      => self::summary( $schema, $values ),
			];
		}
		return $items;
	}

	/**
	 * Show the bidirectional control characters of a visitor value as visible [U+XXXX] markers.
	 *
	 * Embeddings, overrides, isolates and marks could reorder the text around a value, so they are never passed on. The
	 * result is still plain text: the caller escapes it.
	 *
	 * @param mixed $value Anything. Non-strings become an empty string.
	 * @return string
	 */
	public static function visible_text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( $value );
		return (string) preg_replace_callback(
			'/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
			static function ( $match ) {
				$point = function_exists( 'mb_ord' ) ? (int) mb_ord( $match[0], 'UTF-8' ) : 0;
				return sprintf( '[U+%04X]', $point );
			},
			$value
		);
	}

	/**
	 * Titles of forms for a filter: the form index, else the title kept in the snapshot of one of its entries (a deleted form).
	 *
	 * One query for the titles, and two more only when a form is gone.
	 *
	 * @param string[] $form_ids Effective formIds.
	 * @return array<string, string> formId => title ('' when nothing is known).
	 */
	public static function form_titles( array $form_ids ) {
		global $wpdb;

		$form_ids = array_values( array_filter( array_unique( $form_ids ), [ Uuid::class, 'is_valid' ] ) );
		if ( ! $form_ids ) {
			return [];
		}
		$lookup  = self::forms_and_snapshots( $form_ids, [] );
		$titles  = [];
		$missing = [];
		foreach ( $form_ids as $form_id ) {
			$titles[ $form_id ] = $lookup['titles'][ $form_id ] ?? '';
			if ( '' === $titles[ $form_id ] ) {
				$missing[] = $form_id;
			}
		}
		if ( $missing ) {
			$table = esc_sql( Database::table( 'entries' ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT form_id, MAX(schema_hash) AS schema_hash FROM {$table} WHERE form_id IN (" . implode( ', ', array_fill( 0, count( $missing ), '%s' ) ) . ') GROUP BY form_id', $missing ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; every value is a placeholder.
			$rows  = is_array( $rows ) ? $rows : [];
			$known = self::forms_and_snapshots( [], array_map( 'strval', wp_list_pluck( $rows, 'schema_hash' ) ) );
			foreach ( $rows as $row ) {
				$schema = $known['schemas'][ (string) $row['schema_hash'] ] ?? null;
				if ( $schema instanceof Form_Schema ) {
					$titles[ (string) $row['form_id'] ] = $schema->title();
				}
			}
		}
		return $titles;
	}

	/**
	 * The form's title from the index, else the title kept in the entry's snapshot (a deleted form).
	 *
	 * @param string           $indexed Title from the form index ('' when the form is gone).
	 * @param Form_Schema|null $schema  The entry's snapshot.
	 * @return string
	 */
	private static function title( $indexed, $schema ) {
		if ( '' !== $indexed || ! $schema instanceof Form_Schema ) {
			return $indexed;
		}
		return $schema->title();
	}

	/**
	 * Present one entry in full.
	 *
	 * @param array<string, mixed> $entry Entries_Repository::get().
	 * @return array<string, mixed>
	 */
	public static function detail( array $entry ) {
		$lookup = self::forms_and_snapshots( [ (string) $entry['form_id'] ], [ (string) $entry['schema_hash'] ] );
		$schema = $lookup['schemas'][ $entry['schema_hash'] ] ?? null;

		$detail = [
			'id'           => $entry['id'],
			'form_id'      => $entry['form_id'],
			'form_title'   => self::title( $lookup['titles'][ $entry['form_id'] ] ?? '', $schema ),
			'date_gmt'     => self::iso( $entry['created_gmt'] ),
			'date_display' => self::display( $entry['created_gmt'] ),
			'status'       => $entry['status'],
			'starred'      => 1 === $entry['starred'],
			'fields'       => self::fields( $schema, $entry['values'] ),
			'source'       => self::source( (string) $entry['source'] ),
			'user'         => self::user( (int) $entry['user_id'] ),
		];
		if ( ! empty( $entry['ip'] ) ) {
			$detail['ip'] = (string) $entry['ip'];
		}
		if ( isset( $entry['user_agent'] ) && '' !== (string) $entry['user_agent'] ) {
			$detail['user_agent'] = (string) $entry['user_agent'];
		}
		return $detail;
	}

	/**
	 * The fields of an entry in schema order, then values the snapshot does not know.
	 *
	 * @param Form_Schema|null           $schema Snapshot, or null when it is gone.
	 * @param array<string, string[]>    $values Field name => values.
	 * @return array<int, array<string, mixed>> Each: name, label, type, values (list of { value, is_link, is_email }).
	 */
	public static function fields( $schema, array $values ) {
		$out  = [];
		$seen = [];
		foreach ( $schema instanceof Form_Schema ? $schema->fields : [] as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$seen[ $name ] = true;
			$label         = isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $name;
			$out[]         = self::field_row( $name, $label, (string) ( $field['type'] ?? 'text' ), $values[ $name ] ?? [] );
		}
		foreach ( $values as $name => $list ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$out[] = self::field_row( (string) $name, (string) $name, 'text', $list );
			}
		}
		return $out;
	}

	/**
	 * One field of the detail.
	 *
	 * @param string   $name   Field name.
	 * @param string   $label  Label from the snapshot.
	 * @param string   $type   Field type.
	 * @param string[] $values Values.
	 * @return array<string, mixed>
	 */
	private static function field_row( $name, $label, $type, array $values ) {
		$items = [];
		foreach ( $values as $value ) {
			$value   = (string) $value;
			$items[] = [
				'value'    => $value,
				'is_link'  => 'email' !== $type && Validator::is_http_url( $value ),
				'is_email' => 'email' === $type && false !== is_email( $value ),
			];
		}
		return [
			'name'   => $name,
			'label'  => $label,
			'type'   => $type,
			'values' => $items,
		];
	}

	/**
	 * The first two non-empty values in schema order, cut short.
	 *
	 * @param Form_Schema|null        $schema Snapshot.
	 * @param array<string, string[]> $values Field name => values (already cut by the query).
	 * @return string
	 */
	public static function summary( $schema, array $values ) {
		$order = [];
		$skip  = [];
		foreach ( $schema instanceof Form_Schema ? $schema->fields : [] as $field ) {
			if ( isset( $field['name'] ) && is_string( $field['name'] ) ) {
				$order[] = $field['name'];
				// A ticked consent box ("1") and computed hidden values say nothing about whose entry it is.
				if ( in_array( $field['type'] ?? '', [ 'consent', 'hidden' ], true ) ) {
					$skip[] = $field['name'];
				}
			}
		}
		$order = array_merge( $order, array_diff( array_keys( $values ), $order ) );

		$parts = [];
		foreach ( array_diff( $order, $skip ) as $name ) {
			$text = trim( implode( ', ', $values[ $name ] ?? [] ) );
			if ( '' === $text ) {
				continue;
			}
			$parts[] = self::truncate( $text, self::SUMMARY_VALUE_CHARS );
			if ( 2 === count( $parts ) ) {
				break;
			}
		}
		return implode( self::SUMMARY_SEPARATOR, $parts );
	}

	/**
	 * Cut a string to a number of characters, with an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Characters.
	 * @return string
	 */
	private static function truncate( $text, $chars ) {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $chars ) {
			return mb_substr( $text, 0, $chars - 1, 'UTF-8' ) . '…';
		}
		return $text;
	}

	/**
	 * Where the entry was sent from: a title and a link, only for a post the current user may read.
	 *
	 * @param string $ref Source reference.
	 * @return array<string, string>|null
	 */
	private static function source( $ref ) {
		$parsed = Source_Ref::parse( $ref );
		if ( null === $parsed || 'post' !== $parsed['type'] || empty( $parsed['post_id'] ) ) {
			return null;
		}
		$post_id = (int) $parsed['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post_id ) ) {
			return null;
		}
		$link = get_permalink( $post );
		return [
			'title' => get_the_title( $post ),
			'link'  => false === $link ? '' : (string) $link,
		];
	}

	/**
	 * The display name of the user who submitted the entry.
	 *
	 * @param int $user_id User ID.
	 * @return string|null
	 */
	private static function user( $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : null;
	}

	/**
	 * Titles of forms and snapshots in one query.
	 *
	 * @param string[] $form_ids Effective formIds.
	 * @param string[] $hashes   Schema hashes.
	 * @return array{titles:array<string,string>, schemas:array<string,Form_Schema>}
	 */
	private static function forms_and_snapshots( array $form_ids, array $hashes ) {
		global $wpdb;

		$titles   = [];
		$schemas  = [];
		$form_ids = array_values( array_filter( $form_ids, [ Uuid::class, 'is_valid' ] ) );
		$hashes   = array_values( array_filter( $hashes, [ Schema_Store::class, 'is_hash' ] ) );

		$need = array_values(
			array_filter(
				$hashes,
				static function ( $hash ) {
					return ! isset( self::$snapshots[ $hash ] );
				}
			)
		);

		if ( $form_ids || $need ) {
			// Both halves always run; an empty list becomes IN (''), which matches no row (formIds are UUIDs, hashes are hex).
			$in_forms   = $form_ids ? $form_ids : [ '' ];
			$in_schemas = $need ? $need : [ '' ];
			$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own tables.
				$wpdb->prepare(
					'SELECT \'f\' AS kind, form_id AS id, title AS body FROM %i WHERE form_id IN (' . implode( ', ', array_fill( 0, count( $in_forms ), '%s' ) ) . ')'
					. ' UNION ALL SELECT \'s\' AS kind, schema_hash AS id, `schema` AS body FROM %i WHERE schema_hash IN (' . implode( ', ', array_fill( 0, count( $in_schemas ), '%s' ) ) . ')',
					array_merge( [ Database::table( 'forms' ) ], $in_forms, [ Database::table( 'schemas' ) ], $in_schemas )
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( 'f' === $row['kind'] ) {
					$titles[ (string) $row['id'] ] = (string) $row['body'];
				} else {
					self::$snapshots[ (string) $row['id'] ] = Form_Schema::from_snapshot( (string) $row['body'] );
				}
			}
		}

		foreach ( $hashes as $hash ) {
			if ( isset( self::$snapshots[ $hash ] ) && self::$snapshots[ $hash ] instanceof Form_Schema ) {
				$schemas[ $hash ] = self::$snapshots[ $hash ];
			}
		}
		return [
			'titles'  => $titles,
			'schemas' => $schemas,
		];
	}

	/**
	 * Forget the snapshots held for the request (tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$snapshots = [];
	}

	/**
	 * UTC ISO 8601 date of a stored UTC datetime.
	 *
	 * @param string $gmt "Y-m-d H:i:s".
	 * @return string
	 */
	public static function iso( $gmt ) {
		return str_replace( ' ', 'T', (string) $gmt ) . 'Z';
	}

	/**
	 * A stored UTC datetime in the site's time zone and formats.
	 *
	 * @param string $gmt "Y-m-d H:i:s".
	 * @return string
	 */
	public static function display( $gmt ) {
		$time = strtotime( (string) $gmt . ' UTC' );
		if ( false === $time ) {
			return '';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
