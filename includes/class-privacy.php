<?php
/**
 * Core privacy tools: exporter, eraser and policy text.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Privacy
 *
 * Entries belong to a person when one of their email-type fields holds the address (the type comes from the
 * schema snapshot the entry was validated against, so a field that was an email field then still counts after the
 * form changed) or when the entry's user_id is the user with that address. The exporter and the eraser are
 * registered while privacy.exporter and privacy.eraser are on.
 */
class Privacy {

	/**
	 * Exporter and eraser ID.
	 */
	const ID = 'swiftforms-entries';

	/**
	 * Entries exported or erased per call.
	 */
	const PER_PAGE = 50;

	/**
	 * Hook the tools.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
		add_action( 'admin_init', [ self::class, 'add_policy_text' ] );
	}

	/**
	 * Register the exporter when it is on.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public static function register_exporter( $exporters ) {
		if ( true === Settings::get_value( 'privacy.exporter', true ) && is_array( $exporters ) ) {
			$exporters[ self::ID ] = [
				'exporter_friendly_name' => __( 'SwiftForms entries', 'swiftforms' ),
				'callback'               => [ self::class, 'export' ],
			];
		}
		return $exporters;
	}

	/**
	 * Register the eraser when it is on.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public static function register_eraser( $erasers ) {
		if ( true === Settings::get_value( 'privacy.eraser', true ) && is_array( $erasers ) ) {
			$erasers[ self::ID ] = [
				'eraser_friendly_name' => __( 'SwiftForms entries', 'swiftforms' ),
				'callback'             => [ self::class, 'erase' ],
			];
		}
		return $erasers;
	}

	/**
	 * Core exporter callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 * @return array{data:array<int, array<string, mixed>>, done:bool}
	 */
	public static function export( $email, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$ids   = self::entry_ids( (string) $email );
		$slice = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$data = [];
		foreach ( $slice as $id ) {
			$entry = Entries_Repository::get( $id );
			if ( null === $entry ) {
				continue;
			}
			$data[] = [
				'group_id'    => self::ID,
				'group_label' => __( 'SwiftForms entries', 'swiftforms' ),
				'item_id'     => 'swiftforms-entry-' . $id,
				'data'        => self::export_rows( $entry ),
			];
		}

		return [
			'data' => $data,
			'done' => count( $ids ) <= $page * self::PER_PAGE,
		];
	}

	/**
	 * Core eraser callback. Deletes up to PER_PAGE entries per call; the rest comes on the next call.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page (unused: the erased entries are gone, so each call starts at the top).
	 * @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool}
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$email   = (string) $email;
		$ids     = self::entry_ids( $email );
		$removed = Entries_Repository::delete_many( array_slice( $ids, 0, self::PER_PAGE ) );
		$done    = count( $ids ) <= self::PER_PAGE;

		$log_rows = 0;
		if ( $done ) {
			$log_rows = self::erase_mail_log( $email );
		}

		$messages = [];
		if ( $removed > 0 ) {
			/* translators: %d: number of entries. */
			$messages[] = sprintf( _n( 'Removed %d SwiftForms entry.', 'Removed %d SwiftForms entries.', $removed, 'swiftforms' ), $removed );
		}
		if ( $log_rows > 0 ) {
			/* translators: %d: number of rows. */
			$messages[] = sprintf( _n( 'Removed %d row from the SwiftForms mail log.', 'Removed %d rows from the SwiftForms mail log.', $log_rows, 'swiftforms' ), $log_rows );
		}

		return [
			'items_removed'  => $removed > 0 || $log_rows > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * IDs of the entries that belong to an address, oldest first.
	 *
	 * Reads entry_values by exact value (the value_prefix index), then keeps the entries where the field is an
	 * email field in the entry's own snapshot; adds the entries of the user with that address.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public static function entry_ids( $email ) {
		global $wpdb;

		$email = trim( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return [];
		}

		$values  = esc_sql( Database::table( 'entry_values' ) );
		$entries = esc_sql( Database::table( 'entries' ) );

		$matches = $wpdb->get_results( $wpdb->prepare( "SELECT v.entry_id, v.field, e.schema_hash FROM {$values} v INNER JOIN {$entries} e ON e.id = v.entry_id WHERE v.value = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables; names built from $wpdb->prefix.

		$ids = [];
		foreach ( is_array( $matches ) ? $matches : [] as $match ) {
			$schema = Schema_Store::get( (string) $match['schema_hash'] );
			$field  = $schema ? $schema->field( (string) $match['field'] ) : null;
			// Fail safe: without a snapshot (corrupt data, C-4.4) or the field in it, an exact match of the address is personal data.
			if ( null === $field || ( is_array( $field ) && 'email' === ( $field['type'] ?? '' ) ) ) {
				$ids[] = (int) $match['entry_id'];
			}
		}

		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$by_user = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$entries} WHERE user_id = %d", (int) $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
			$ids     = array_merge( $ids, array_map( 'intval', is_array( $by_user ) ? $by_user : [] ) );
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Delete the mail log rows whose recipients include an address.
	 *
	 * @param string $email Email address.
	 * @return int Rows deleted.
	 */
	private static function erase_mail_log( $email ) {
		global $wpdb;

		$table = esc_sql( Database::table( 'log' ) );
		$like  = '%' . $wpdb->esc_like( $email ) . '%';

		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, context FROM {$table} WHERE type = 'mail' AND (context LIKE %s OR message LIKE %s)", $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.

		$delete = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$context = json_decode( (string) $row['context'], true );
			if ( is_array( $context ) && self::lists_address( $context, $email ) ) {
				$delete[] = (int) $row['id'];
			}
		}

		$deleted = 0;
		if ( $delete ) {
			$marks   = implode( ', ', array_fill( 0, count( $delete ), '%d' ) );
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$marks})", $delete ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Own table; IDs are placeholders.
		}
		$wpdb->suppress_errors( $suppress );

		return $deleted;
	}

	/**
	 * Whether any string in a decoded log context is, or is a comma list holding, the address (not a longer address that contains it).
	 *
	 * @param mixed  $node  Decoded JSON.
	 * @param string $email Address.
	 * @return bool
	 */
	private static function lists_address( $node, $email ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $child ) {
				if ( self::lists_address( $child, $email ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_string( $node ) ) {
			return false;
		}
		foreach ( explode( ',', $node ) as $part ) {
			$part = trim( $part );
			if ( 1 === preg_match( '/<([^>]+)>$/', $part, $found ) ) {
				$part = $found[1];
			}
			if ( 0 === strcasecmp( $part, $email ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The name/value rows of one exported entry: every field with its label, then what else is stored about it.
	 *
	 * @param array<string, mixed> $entry Entries_Repository::get().
	 * @return array<int, array{name:string, value:string}>
	 */
	private static function export_rows( array $entry ) {
		$schema = Schema_Store::get( (string) $entry['schema_hash'] );
		$form   = Form_Index::get_row( (string) $entry['form_id'] );

		$rows = [
			[
				'name'  => __( 'Form', 'swiftforms' ),
				'value' => is_array( $form ) ? (string) $form['title'] : '',
			],
			[
				'name'  => __( 'Submitted (UTC)', 'swiftforms' ),
				'value' => (string) $entry['created_gmt'],
			],
		];
		foreach ( Entry_Presenter::fields( $schema, $entry['values'] ) as $field ) {
			$rows[] = [
				'name'  => (string) $field['label'],
				'value' => implode( ', ', wp_list_pluck( $field['values'], 'value' ) ),
			];
		}
		if ( ! empty( $entry['ip'] ) ) {
			$rows[] = [
				'name'  => __( 'IP address', 'swiftforms' ),
				'value' => (string) $entry['ip'],
			];
		}
		if ( ! empty( $entry['user_agent'] ) ) {
			$rows[] = [
				'name'  => __( 'User agent', 'swiftforms' ),
				'value' => (string) $entry['user_agent'],
			];
		}
		return $rows;
	}

	/**
	 * Suggest text for the site's privacy policy.
	 *
	 * @return void
	 */
	public static function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'SwiftForms', wp_kses_post( wpautop( self::policy_text() ) ) );
	}

	/**
	 * The suggested policy text.
	 *
	 * @return string
	 */
	public static function policy_text() {
		$days = Settings::get_value( 'general.retention_days', 0 );

		$text  = '<h2>' . __( 'Contact forms (SwiftForms)', 'swiftforms' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . __( 'Suggested text. Review it and adjust it to how you use your forms.', 'swiftforms' ) . '</p>';
		$text .= '<p>' . __( 'When you send a form on this site, we store what you typed in each field together with the date and time and the page the form was on. If you are logged in, your user account is stored with it. Your IP address and your browser\'s user agent are not stored unless the site owner has turned that on.', 'swiftforms' ) . '</p>';
		if ( is_int( $days ) && $days > 0 ) {
			/* translators: %d: number of days. */
			$text .= '<p>' . sprintf( _n( 'Entries are deleted automatically %d day after they are received.', 'Entries are deleted automatically %d days after they are received.', $days, 'swiftforms' ), $days ) . '</p>';
		} else {
			$text .= '<p>' . __( 'Entries are kept until the site owner deletes them.', 'swiftforms' ) . '</p>';
		}
		$text .= '<p>' . __( 'The form can email a notification of your message to the site owner. Delivery records (the time and the recipients, never your message) are kept for 30 days.', 'swiftforms' ) . '</p>';
		$text .= '<p>' . __( 'To protect the form against spam, the form uses a hidden field and a timing check, and your requests are counted for a short time using a hashed form of your IP address. No data is sent to an external service.', 'swiftforms' ) . '</p>';
		$text .= '<p>' . __( 'You can ask the site owner for a copy of the entries that contain your email address, or to erase them.', 'swiftforms' ) . '</p>';
		return $text;
	}
}
