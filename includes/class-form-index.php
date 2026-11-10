<?php
/**
 * Form index.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Index
 *
 * The swiftforms_forms table (ADR-003): one row per effective formId, with its
 * source, title, liveness, current schema hash and where it shows. It answers
 * "is this (formId, source) pair a live location?" with one indexed query
 * (C-3.11) and implements the effective formId rules of C-2.7.
 *
 * Status values (the column default is an empty string; these are the definition):
 *   live      The form can be submitted: its source is a published and viewable post
 *             (password-protected counts; P4 refuses it without the cookie), a
 *             wp_block published and shown by a live source, a template or part of the
 *             active theme, a registered pattern referenced by core/pattern from an active
 *             template or part, or a widget block in an active sidebar.
 *   not_live  The row is known but nothing shows it: a draft, private, scheduled or trashed
 *             post, a wp_block nobody shows, a template of an inactive theme, a pattern that
 *             is only registered, a widget in the inactive area.
 *   deleted   The source or the form block is gone. The row stays: old entries need the title
 *             and locations, and the ID must never be handed out again.
 *
 * Locations (JSON): {"ordinal":n, "kind":"owner|copy|missing|dup", "places":[refs]}. "places"
 * are the live root sources that show the form (the source itself for a post, template, part
 * or widget), empty when not live.
 *
 * Effective IDs (C-2.7), decided once and then kept:
 *   - A missing or invalid stored ID gives uuid_v5( "missing|{ref}|{ordinal}" ). Ordinals are
 *     1-based positions among the source's own form blocks.
 *   - The first block with a stored ID in a source uses the row for (stored ID, source) when
 *     there is one: a mapping, once written, is never changed.
 *   - Otherwise, if a row with form_id = stored ID already belongs to another source, that source
 *     is the owner and this one is a copy: uuid_v5( "{stored ID}|{ref}" ). If not, this source is the
 *     owner and the stored ID is its effective ID. The owner is whoever got the ID first, and a
 *     later, even older, source never takes it over while the owner still contains the form. When the
 *     owner's row is "deleted" (the form block was removed, or the source deleted; not merely
 *     unpublished or trashed) the next source that contains the ID takes the row over: form_id stays,
 *     so entries stay together, and source, locations and status follow. If the old source later
 *     contains the ID again it is a copy. Cut and paste of a form from page A to page B thus keeps
 *     its ID. "Earliest published" is honoured at the
 *     moments many sources are indexed at once (rebuild(), and a walk through nested sources):
 *     they go in order of post_date_gmt, then post ID, with published posts first, then other
 *     posts (drafts have no publish date), then every other source (templates, parts, patterns and
 *     widgets have none).
 *   - A later block with the same stored ID in the same source gets uuid_v5( "dup|{stored}|{ref}|{ordinal}" ).
 */
class Form_Index {

	/**
	 * Object cache group. Keys include the group's last_changed value, bumped on every write.
	 */
	const CACHE_GROUP = 'swiftforms_forms';

	const STATUS_LIVE     = 'live';
	const STATUS_NOT_LIVE = 'not_live';
	const STATUS_DELETED  = 'deleted';

	/**
	 * Substrings that mark content worth parsing: a form, or a block that can pull one in.
	 */
	const MARKERS = [ '<!-- wp:swiftforms/form', '<!-- wp:block ', '<!-- wp:pattern', '<!-- wp:template-part' ];

	/**
	 * Most places listed per form.
	 */
	const MAX_PLACES = 50;

	/**
	 * Posts handled per batch by rebuild().
	 */
	const BATCH = 50;

	/**
	 * How many schema hashes a form's history keeps.
	 */
	const HASH_HISTORY = 25;

	/**
	 * Sources already indexed lazily during this request.
	 *
	 * @var array<string, bool>
	 */
	private static $ensured = [];

	/**
	 * True while rebuild() runs: rows keep their status until the single liveness pass at the end.
	 *
	 * @var bool
	 */
	private static $rebuilding = false;

	/**
	 * Last saved state handled per source during this request (a save fires several hooks).
	 *
	 * @var array<string, string>
	 */
	private static $handled = [];

	/**
	 * References of posts about to be deleted, by post ID (terms are gone by deleted_post).
	 *
	 * @var array<int, string>
	 */
	private static $deleting = [];

	/**
	 * Hook the refresh triggers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_after_insert_post', [ self::class, 'on_after_insert_post' ], 20, 2 );
		add_action( 'transition_post_status', [ self::class, 'on_transition_post_status' ], 20, 3 );
		add_action( 'before_delete_post', [ self::class, 'on_before_delete_post' ], 10, 2 );
		add_action( 'deleted_post', [ self::class, 'on_deleted_post' ] );
		add_action( 'switch_theme', [ self::class, 'reindex_templates' ] );
		add_action( 'upgrader_process_complete', [ self::class, 'on_upgrader_complete' ], 10, 2 );
		foreach ( [ 'widget_block', 'sidebars_widgets' ] as $option ) {
			add_action( 'add_option_' . $option, [ self::class, 'reindex_widgets' ] );
			add_action( 'update_option_' . $option, [ self::class, 'reindex_widgets' ] );
		}
		add_action( 'swiftforms_db_version_changed', [ self::class, 'rebuild_when_ready' ] );
	}

	/**
	 * Rebuild now, or once WordPress has finished loading.
	 *
	 * The migration runs on init at priority 1, before custom post types and patterns are
	 * registered, so a rebuild right then would miss them. wp_loaded comes after all of it.
	 *
	 * @return void
	 */
	public static function rebuild_when_ready() {
		if ( did_action( 'wp_loaded' ) ) {
			self::rebuild();
		} elseif ( ! has_action( 'wp_loaded', [ self::class, 'rebuild' ] ) ) {
			add_action( 'wp_loaded', [ self::class, 'rebuild' ] );
		}
	}

	/**
	 * Forget per-request state (tests, and the user-config loader after it rewrites stored data).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$ensured  = [];
		self::$handled  = [];
		self::$deleting = [];
		Source_Edges::flush_cache();
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	// ---------------------------------------------------------------------
	// Reading.
	// ---------------------------------------------------------------------

	/**
	 * Whether a (formId, source) pair is a live location (C-3.11). One indexed query, cached.
	 *
	 * A value that is not a canonical UUID or a valid reference costs nothing.
	 *
	 * @param string $effective_id Effective formId.
	 * @param string $ref          Source reference.
	 * @return bool
	 */
	public static function is_live( $effective_id, $ref ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $effective_id ) || ! Source_Ref::is_valid( $ref ) ) {
			return false;
		}

		$key    = 'live:' . self::last_changed() . ':' . md5( $effective_id . '|' . $ref );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return 1 === $cached;
		}

		$table  = esc_sql( Database::table( 'forms' ) );
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE form_id = %s AND source = %s", $effective_id, $ref ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		$live   = self::STATUS_LIVE === $status;

		wp_cache_set( $key, $live ? 1 : 0, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $live;
	}

	/**
	 * One index row.
	 *
	 * @param string $effective_id Effective formId.
	 * @return array<string, mixed>|null Columns, with "locations" decoded into an array.
	 */
	public static function get_row( $effective_id ) {
		global $wpdb;

		if ( ! Uuid::is_valid( $effective_id ) ) {
			return null;
		}
		$table = esc_sql( Database::table( 'forms' ) );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s", $effective_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * Effective formId for a form block being rendered. Used at render time.
	 *
	 * Reads the index. A source the index does not know (a theme file, SQL-inserted content, a
	 * form added without a save) is indexed once during this request, which is the only write; a
	 * request where nothing changed writes nothing. When the source cannot be indexed (for
	 * example a preview of unsaved content) the ID is still computed by the same rules, just not stored.
	 *
	 * @param mixed  $stored  formId attribute as stored.
	 * @param string $ref     Source reference ( Source_Stack::current() ).
	 * @param int    $ordinal 1-based position among the source's own form blocks ( Source_Stack::next_ordinal() ).
	 * @return string Effective formId.
	 */
	public static function ensure( $stored, $ref, $ordinal ) {
		$stored  = is_string( $stored ) ? $stored : '';
		$ordinal = max( 1, (int) $ordinal );

		if ( ! Source_Ref::is_valid( $ref ) ) {
			// No source to look in: the same rules, nothing read or stored.
			return Uuid::is_valid( $stored ) ? $stored : self::missing_id( (string) $ref, $ordinal );
		}

		$effective = self::lookup( $stored, $ref, $ordinal );
		if ( null === $effective && ! isset( self::$ensured[ $ref ] ) ) {
			self::$ensured[ $ref ] = true;
			self::index_source( $ref );
			$effective = self::lookup( $stored, $ref, $ordinal );
		}
		if ( null !== $effective ) {
			return $effective;
		}

		// Not indexable. Same rules, nothing stored.
		if ( ! Uuid::is_valid( $stored ) ) {
			return self::missing_id( $ref, $ordinal );
		}
		return self::decide( $stored, $ref )['effective'];
	}

	/**
	 * Effective IDs of the form blocks of one source, by the C-2.7 rules, without writing anything.
	 *
	 * @param string $ref   Source reference.
	 * @param array<int, array{ordinal:int, block:array<string, mixed>}> $forms Result of Block_Walker::forms() for the source's own content.
	 * @return array<int, array{ordinal:int, block:array<string, mixed>, stored:string, effective:string, kind:string}> One entry per form. "stored" is empty when the stored ID is missing or invalid.
	 */
	public static function plan( $ref, array $forms ) {
		$plan = [];
		$seen = [];

		foreach ( $forms as $form ) {
			$ordinal = (int) $form['ordinal'];
			$stored  = $form['block']['attrs']['formId'] ?? '';
			$stored  = Uuid::is_valid( $stored ) ? $stored : '';

			if ( '' === $stored ) {
				$effective = self::missing_id( $ref, $ordinal );
				$kind      = 'missing';
			} elseif ( isset( $seen[ $stored ] ) ) {
				$effective = Uuid::v5( 'dup|' . $stored . '|' . $ref . '|' . $ordinal );
				$kind      = 'dup';
			} else {
				$seen[ $stored ] = true;
				$row             = self::find_row( $stored, $ref, 0 );
				if ( null !== $row ) {
					$effective = $row['form_id'];
					$kind      = $row['kind'];
				} else {
					$decision  = self::decide( $stored, $ref );
					$effective = $decision['effective'];
					$kind      = $decision['kind'];
				}
			}

			$plan[] = [
				'ordinal'   => $ordinal,
				'block'     => $form['block'],
				'stored'    => $stored,
				'effective' => $effective,
				'kind'      => $kind,
			];
		}

		return $plan;
	}

	/**
	 * Effective ID of a copy: uuid_v5( "{stored}|{ref}" ) in the SwiftForms namespace (C-2.7).
	 *
	 * @param string $stored Stored formId.
	 * @param string $ref    Source reference of the copy.
	 * @return string
	 */
	public static function copy_id( $stored, $ref ) {
		return Uuid::v5( $stored . '|' . $ref );
	}

	/**
	 * Effective ID of a block without a usable formId.
	 *
	 * @param string $ref     Source reference.
	 * @param int    $ordinal Ordinal.
	 * @return string
	 */
	private static function missing_id( $ref, $ordinal ) {
		return Uuid::v5( 'missing|' . $ref . '|' . $ordinal );
	}

	/**
	 * Owner or copy, for a stored ID that has no row for this source yet.
	 *
	 * @param string $stored Valid stored formId.
	 * @param string $ref    Source reference.
	 * @return array{effective:string, kind:string}
	 */
	private static function decide( $stored, $ref ) {
		$owner = self::get_row( $stored );
		// A deleted owner (its form block or its source is gone) hands the ID, and its entries, to the next source that contains it.
		if ( null !== $owner && $owner['source'] !== $ref && self::STATUS_DELETED !== $owner['status'] ) {
			return [
				'effective' => self::copy_id( $stored, $ref ),
				'kind'      => 'copy',
			];
		}
		return [
			'effective' => $stored,
			'kind'      => 'owner',
		];
	}

	/**
	 * The index row a stored ID already maps to in a source.
	 *
	 * Normally one row. When the source holds the same stored ID in several blocks, rows of
	 * kind "dup" exist next to the first block's row. The first block (pass $ordinal 0) uses
	 * the row that is not a dup; a later block (its own ordinal) uses the dup row recorded with
	 * that ordinal, and falls back to the first block's row.
	 *
	 * @param string $stored  Valid stored formId.
	 * @param string $ref     Source reference.
	 * @param int    $ordinal Ordinal of the block asking, or 0 to ask for the first block's row.
	 * @return array{form_id:string, kind:string, ordinal:int}|null
	 */
	private static function find_row( $stored, $ref, $ordinal ) {
		global $wpdb;

		$key  = 'rows:' . self::last_changed() . ':' . md5( $stored . '|' . $ref );
		$rows = wp_cache_get( $key, self::CACHE_GROUP );
		if ( ! is_array( $rows ) ) {
			$table = esc_sql( Database::table( 'forms' ) );
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT form_id, locations FROM {$table} WHERE source = %s AND stored_id = %s ORDER BY created_gmt, form_id", $ref, $stored ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
			$rows  = [];
			foreach ( (array) $found as $row ) {
				$locations = json_decode( (string) $row['locations'], true );
				$locations = is_array( $locations ) ? $locations : [];
				$rows[]    = [
					'form_id' => (string) $row['form_id'],
					'kind'    => isset( $locations['kind'] ) && is_string( $locations['kind'] ) ? $locations['kind'] : 'owner',
					'ordinal' => isset( $locations['ordinal'] ) ? (int) $locations['ordinal'] : 0,
				];
			}
			wp_cache_set( $key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		$first = null;
		foreach ( $rows as $row ) {
			if ( 'dup' !== $row['kind'] ) {
				$first = $first ?? $row;
			} elseif ( $ordinal > 0 && $row['ordinal'] === $ordinal ) {
				return $row;
			}
		}
		return $first;
	}

	/**
	 * Effective ID for a stored ID and ordinal from the rows alone (no writes), or null when the index does not know the form.
	 *
	 * @param string $stored  Stored formId ('' when missing or invalid).
	 * @param string $ref     Source reference.
	 * @param int    $ordinal Ordinal.
	 * @return string|null
	 */
	private static function lookup( $stored, $ref, $ordinal ) {
		if ( ! Uuid::is_valid( $stored ) ) {
			$effective = self::missing_id( $ref, $ordinal );
			$row       = self::get_row( $effective );
			return ( null !== $row && $row['source'] === $ref ) ? $effective : null;
		}

		$row = self::find_row( $stored, $ref, $ordinal );
		if ( null !== $row ) {
			return $row['form_id'];
		}

		// A form that was rewritten on save carries its own effective ID as the stored one.
		$own = self::get_row( $stored );
		return ( null !== $own && $own['source'] === $ref ) ? $stored : null;
	}

	/**
	 * Current last_changed stamp of the group.
	 *
	 * @return string
	 */
	private static function last_changed() {
		return (string) wp_cache_get_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Turn a table row into an array with decoded locations.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( array $row ) {
		$locations        = json_decode( (string) $row['locations'], true );
		$row['locations'] = is_array( $locations ) ? $locations : [];
		return $row;
	}

	// ---------------------------------------------------------------------
	// Indexing.
	// ---------------------------------------------------------------------

	/**
	 * Index one source: its forms, the forms of everything it pulls in, and liveness.
	 *
	 * Loads the source, walks it with expansion, upserts one row per form (attributed to the
	 * source the form is in), saves each schema snapshot, records the reference edges, then
	 * recomputes liveness for the source and for everything reachable from it. A source that
	 * cannot be loaded is handled by source_gone(). Nothing is written when nothing changed.
	 *
	 * @param string $ref Source reference.
	 * @return void
	 */
	public static function index_source( $ref ) {
		if ( ! Source_Ref::is_valid( $ref ) ) {
			return;
		}

		$loaded = Source_Loader::load( $ref );
		if ( null === $loaded ) {
			self::source_gone( $ref );
			return;
		}

		$walk = Block_Walker::walk( $ref, $loaded['blocks'], true );

		// What this source showed before, so that sources it stops showing are refreshed too.
		$before = Source_Edges::descendants( $ref );

		// Edges first: liveness reads them.
		$targets = [];
		foreach ( $walk['edges'] as $edge ) {
			$targets[ $edge[0] ][] = $edge[1];
		}
		$changed = false;
		foreach ( $walk['expanded'] as $source ) {
			$changed = Source_Edges::set( $source, $targets[ $source ] ?? [] ) || $changed;
		}

		$by_source = [];
		foreach ( $walk['forms'] as $form ) {
			$by_source[ $form['source'] ][] = [
				'ordinal' => $form['ordinal'],
				'block'   => $form['block'],
			];
		}

		$done = [];
		foreach ( self::rank( $walk['expanded'] ) as $source ) {
			if ( self::reconcile_source( $source, $by_source[ $source ] ?? [] ) ) {
				$changed = true;
				self::bump(); // The next source in this walk may read rows this one just wrote.
			}
			$done[] = $source;
		}

		if ( ! self::$rebuilding ) {
			foreach ( array_unique( array_merge( $before, Source_Edges::descendants( $ref ) ) ) as $source ) {
				if ( ! in_array( $source, $done, true ) ) {
					$changed = self::refresh_source( $source ) || $changed;
				}
			}
		}

		if ( $changed ) {
			self::bump();
		}
	}

	/**
	 * Mark a source as gone and recompute what it used to show.
	 *
	 * A post, a widget or a template of the active theme that cannot be loaded is deleted:
	 * its forms become "deleted" and its edges are dropped. A template of another theme and a
	 * pattern that is no longer registered are only unavailable: their forms become "not_live".
	 *
	 * @param string $ref Source reference.
	 * @return void
	 */
	public static function remove_source( $ref ) {
		if ( ! Source_Ref::is_valid( $ref ) ) {
			return;
		}
		self::source_gone( $ref );
	}

	/**
	 * Handle a source that did not load.
	 *
	 * @param string $ref Source reference.
	 * @return void
	 */
	private static function source_gone( $ref ) {
		$parsed  = Source_Ref::parse( $ref );
		$deleted = ! in_array( $parsed['type'], [ 'pattern' ], true )
			&& ! ( isset( $parsed['theme'] ) && ! Source_Loader::is_active_theme_template( $ref ) );

		$descendants = Source_Edges::descendants( $ref );
		$changed     = false;

		if ( self::$rebuilding ) {
			if ( $deleted ) {
				$changed = self::set_source_rows( $ref, self::STATUS_DELETED, [] );
				$changed = Source_Edges::remove( $ref ) || $changed;
			}
			if ( $changed ) {
				self::bump();
			}
			return;
		}

		if ( $deleted ) {
			$changed = self::set_source_rows( $ref, self::STATUS_DELETED, [] );
			$changed = Source_Edges::remove( $ref ) || $changed;
		} else {
			$changed = self::refresh_source( $ref );
		}

		foreach ( $descendants as $source ) {
			$changed = self::refresh_source( $source ) || $changed;
		}

		if ( $changed ) {
			self::bump();
		}
	}

	/**
	 * Bring the rows of one source in line with the forms now in its content.
	 *
	 * @param string $ref   Source reference.
	 * @param array<int, array{ordinal:int, block:array<string, mixed>}> $forms Forms of the source's own content: ordinal and block.
	 * @return bool Whether anything was written.
	 */
	private static function reconcile_source( $ref, array $forms ) {
		global $wpdb;

		$state   = Source_Loader::state( $ref );
		$places  = self::places( $ref );
		$status  = $places ? self::STATUS_LIVE : self::STATUS_NOT_LIVE;
		$changed = false;
		$keep    = [];

		foreach ( self::plan( $ref, $forms ) as $entry ) {
			$schema = Schema_Resolver::build( $entry['block'], $entry['effective'], $entry['stored'], $ref );
			Schema_Store::save( $schema );

			$title = $schema->title();
			if ( '' === $title && null !== $state ) {
				$title = (string) $state['title'];
			}

			$row_status = $status;
			$row_places = $places;
			if ( self::$rebuilding ) {
				// Liveness is settled in one pass at the end of the rebuild; until then rows keep what they had.
				$known      = self::get_row( $entry['effective'] );
				$row_status = null === $known || self::STATUS_DELETED === $known['status'] ? self::STATUS_NOT_LIVE : $known['status'];
				$row_places = null === $known || ! isset( $known['locations']['places'] ) || ! is_array( $known['locations']['places'] ) ? [] : $known['locations']['places'];
			}

			$keep[]  = $entry['effective'];
			$changed = self::upsert(
				[
					'form_id'     => $entry['effective'],
					'stored_id'   => $entry['stored'],
					'source'      => $ref,
					'title'       => $title,
					'status'      => $row_status,
					'locations'   => self::encode_locations( $entry['ordinal'], $entry['kind'], $row_places ),
					'schema_hash' => $schema->hash,
				],
				$schema
			) || $changed;
		}

		// Forms that used to be here and are not any more.
		$table = esc_sql( Database::table( 'forms' ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT form_id FROM {$table} WHERE source = %s AND status <> %s", $ref, self::STATUS_DELETED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		foreach ( array_diff( (array) $ids, $keep ) as $form_id ) {
			$changed = self::write_status( (string) $form_id, self::STATUS_DELETED, [] ) || $changed;
		}

		return $changed;
	}

	/**
	 * Insert or update a row, writing only when something differs.
	 *
	 * @param array<string, string> $row    Columns without the dates.
	 * @param Form_Schema           $schema Schema, for the repair log.
	 * @return bool Whether a write happened.
	 */
	private static function upsert( array $row, Form_Schema $schema ) {
		global $wpdb;

		$table    = Database::table( 'forms' );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE form_id = %s', $row['form_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; name built from $wpdb->prefix.

		$history          = self::next_history( is_array( $existing ) ? $existing : null, $row['schema_hash'] );
		$row['locations'] = self::with_hashes( $row['locations'], $history );

		if ( ! is_array( $existing ) ) {
			$now = Clock::gmt_mysql();
			$ok  = $wpdb->insert(
				$table,
				array_merge(
					$row,
					[
						'created_gmt' => $now,
						'updated_gmt' => $now,
					]
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $ok && $schema->notices ) {
				self::log_repairs( $schema );
			}
			return (bool) $ok;
		}

		$differs = false;
		foreach ( $row as $column => $value ) {
			if ( (string) $existing[ $column ] !== (string) $value ) {
				$differs = true;
				break;
			}
		}
		if ( ! $differs ) {
			return false;
		}

		$ok = $wpdb->update( $table, array_merge( $row, [ 'updated_gmt' => Clock::gmt_mysql() ] ), [ 'form_id' => $row['form_id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok && $schema->notices && $existing['schema_hash'] !== $row['schema_hash'] ) {
			self::log_repairs( $schema );
		}
		return false !== $ok;
	}

	/**
	 * Log that the resolver had to repair names (once per schema change, never per request).
	 *
	 * @param Form_Schema $schema Schema.
	 * @return void
	 */
	private static function log_repairs( Form_Schema $schema ) {
		Log::add(
			'error',
			'warning',
			'Field names were repaired while indexing a form.',
			[
				'source'  => $schema->source,
				'repairs' => count( $schema->notices ),
			],
			$schema->form_id
		);
	}

	/**
	 * Recompute status and places of the existing rows of a source, without reading its content.
	 *
	 * @param string $ref Source reference.
	 * @return bool Whether anything was written.
	 */
	private static function refresh_source( $ref ) {
		global $wpdb;

		$places  = self::places( $ref );
		$status  = $places ? self::STATUS_LIVE : self::STATUS_NOT_LIVE;
		$table   = esc_sql( Database::table( 'forms' ) );
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT form_id FROM {$table} WHERE source = %s AND status <> %s", $ref, self::STATUS_DELETED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		$changed = false;
		foreach ( (array) $ids as $form_id ) {
			$changed = self::write_status( (string) $form_id, $status, $places ) || $changed;
		}
		return $changed;
	}

	/**
	 * Set status and places on every non-deleted row of a source.
	 *
	 * @param string   $ref    Source reference.
	 * @param string   $status New status.
	 * @param string[] $places Places.
	 * @return bool Whether anything was written.
	 */
	private static function set_source_rows( $ref, $status, array $places ) {
		global $wpdb;

		$table   = esc_sql( Database::table( 'forms' ) );
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT form_id FROM {$table} WHERE source = %s AND status <> %s", $ref, $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		$changed = false;
		foreach ( (array) $ids as $form_id ) {
			$changed = self::write_status( (string) $form_id, $status, $places ) || $changed;
		}
		return $changed;
	}

	/**
	 * Write the status and places of one row when they differ.
	 *
	 * @param string   $form_id Effective formId.
	 * @param string   $status  New status.
	 * @param string[] $places  Places.
	 * @return bool Whether a write happened.
	 */
	private static function write_status( $form_id, $status, array $places ) {
		global $wpdb;

		$table = Database::table( 'forms' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT status, locations FROM `' . esc_sql( $table ) . '` WHERE form_id = %s', $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; name built from $wpdb->prefix.
		if ( ! is_array( $row ) ) {
			return false;
		}

		$locations = json_decode( (string) $row['locations'], true );
		$locations = is_array( $locations ) ? $locations : [];
		$json      = self::with_hashes( self::encode_locations( (int) ( $locations['ordinal'] ?? 0 ), (string) ( $locations['kind'] ?? 'owner' ), $places ), self::clean_hashes( $locations['hashes'] ?? [] ) );
		if ( $row['status'] === $status && $row['locations'] === $json ) {
			return false;
		}

		return false !== $wpdb->update(
			$table,
			[
				'status'      => $status,
				'locations'   => $json,
				'updated_gmt' => Clock::gmt_mysql(),
			],
			[ 'form_id' => $form_id ]
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The schema hash history of a form after a (re)index: the new hash first, then the earlier ones, distinct, newest first, at most HASH_HISTORY.
	 *
	 * Rows written before the history existed start from their stored schema_hash.
	 *
	 * @param array<string, mixed>|null $existing Existing row, or null for a new form.
	 * @param string                    $hash     Hash being written.
	 * @return string[]
	 */
	private static function next_history( $existing, $hash ) {
		$history = [];
		if ( is_array( $existing ) ) {
			$locations = json_decode( (string) $existing['locations'], true );
			$history   = self::clean_hashes( is_array( $locations ) ? ( $locations['hashes'] ?? [] ) : [] );
			if ( [] === $history && Schema_Store::is_hash( $existing['schema_hash'] ) ) {
				$history = [ $existing['schema_hash'] ];
			}
		}
		array_unshift( $history, $hash );
		return array_slice( array_values( array_unique( $history ) ), 0, self::HASH_HISTORY );
	}

	/**
	 * Keep only well-formed, distinct hashes.
	 *
	 * @param mixed $hashes Candidate list.
	 * @return string[]
	 */
	private static function clean_hashes( $hashes ) {
		if ( ! is_array( $hashes ) ) {
			return [];
		}
		return array_slice( array_values( array_unique( array_filter( $hashes, [ Schema_Store::class, 'is_hash' ] ) ) ), 0, self::HASH_HISTORY );
	}

	/**
	 * Add the hash history to a locations JSON string.
	 *
	 * @param string   $json   Locations JSON.
	 * @param string[] $hashes History, newest first.
	 * @return string
	 */
	private static function with_hashes( $json, array $hashes ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || [] === $hashes ) {
			return $json;
		}
		$data['hashes'] = array_values( $hashes );
		$out            = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		return false === $out ? $json : $out;
	}

	/**
	 * Schema hashes a form has had, newest first (the current one first). Render tokens name one of them (C-3.8).
	 *
	 * @param string $effective_id Effective formId.
	 * @return string[]
	 */
	public static function hash_history( $effective_id ) {
		$row = self::get_row( $effective_id );
		if ( null === $row ) {
			return [];
		}
		$history = self::clean_hashes( $row['locations']['hashes'] ?? [] );
		if ( Schema_Store::is_hash( $row['schema_hash'] ) && ! in_array( $row['schema_hash'], $history, true ) ) {
			array_unshift( $history, $row['schema_hash'] );
		}
		return $history;
	}

	/**
	 * JSON for the locations column.
	 *
	 * @param int      $ordinal Ordinal of the form block in its source.
	 * @param string   $kind    owner, copy, missing or dup.
	 * @param string[] $places  Live root sources.
	 * @return string
	 */
	private static function encode_locations( $ordinal, $kind, array $places ) {
		$json = wp_json_encode(
			[
				'ordinal' => (int) $ordinal,
				'kind'    => (string) $kind,
				'places'  => array_values( $places ),
			],
			JSON_UNESCAPED_SLASHES
		);
		return false === $json ? '{}' : $json;
	}

	/**
	 * Bump the cache stamp after any write to the table.
	 *
	 * @return void
	 */
	private static function bump() {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	// ---------------------------------------------------------------------
	// Liveness.
	// ---------------------------------------------------------------------

	/**
	 * Live root sources that show a source (C-3.11), empty when it is not live.
	 *
	 * A post, template, template part or widget is its own place when it is open to the public.
	 * A wp_block (published) and a pattern take the places of the live sources that reference them,
	 * through up to Block_Walker::DEPTH_LIMIT levels; a pattern counts only when a template, a
	 * template part or another pattern references it.
	 *
	 * @param string   $ref      Source reference.
	 * @param int      $depth    Levels climbed so far.
	 * @param string[] $visiting References on the way up (cycle guard).
	 * @return string[] Sorted, at most MAX_PLACES.
	 */
	private static function places( $ref, $depth = 0, array $visiting = [] ) {
		$state = Source_Loader::state( $ref );
		if ( null === $state ) {
			return [];
		}

		$needs_parent = false;
		switch ( $state['kind'] ) {
			case 'post':
				if ( 'publish' !== $state['status'] ) {
					return [];
				}
				if ( 'wp_block' === $state['post_type'] ) {
					$needs_parent = true;
					break;
				}
				return $state['viewable'] ? [ $ref ] : [];

			case 'pattern':
				$needs_parent = true;
				break;

			default: // Template, template part, widget.
				return $state['active'] ? [ $ref ] : [];
		}

		$places = [];
		if ( $needs_parent && $depth < Block_Walker::DEPTH_LIMIT ) {
			$visiting[] = $ref;
			foreach ( Source_Edges::parents( $ref ) as $parent ) {
				if ( in_array( $parent, $visiting, true ) ) {
					continue;
				}
				if ( 'pattern' === $state['kind'] ) {
					$parsed = Source_Ref::parse( $parent );
					if ( null === $parsed || ! in_array( $parsed['type'], [ 'template', 'template_part', 'pattern' ], true ) ) {
						continue;
					}
				}
				$places = array_merge( $places, self::places( $parent, $depth + 1, $visiting ) );
			}
		}

		$places = array_values( array_unique( $places ) );
		sort( $places, SORT_STRING );
		return array_slice( $places, 0, self::MAX_PLACES );
	}

	/**
	 * Sort sources the way owners are decided: published posts by post_date_gmt then ID, other posts after them, everything else last (by reference).
	 *
	 * @param string[] $refs Source references.
	 * @return string[]
	 */
	public static function rank( array $refs ) {
		$keyed = [];
		foreach ( $refs as $ref ) {
			$parsed = Source_Ref::parse( $ref );
			$state  = ( null !== $parsed && 'post' === $parsed['type'] ) ? Source_Loader::state( $ref ) : null;
			if ( null === $state ) {
				$keyed[ $ref ] = [ 2, '', 0 ];
				continue;
			}
			$dated         = '' !== $state['date_gmt'] && '0000-00-00 00:00:00' !== $state['date_gmt'];
			$keyed[ $ref ] = [ 'publish' === $state['status'] && $dated ? 0 : 1, $dated ? $state['date_gmt'] : '9999-12-31 23:59:59', (int) $state['post_id'] ];
		}
		uksort(
			$keyed,
			static function ( $a, $b ) use ( $keyed ) {
				$left  = $keyed[ $a ];
				$right = $keyed[ $b ];
				return [ $left[0], $left[1], $left[2], $a ] <=> [ $right[0], $right[1], $right[2], $b ];
			}
		);
		return array_map( 'strval', array_keys( $keyed ) );
	}

	// ---------------------------------------------------------------------
	// Triggers.
	// ---------------------------------------------------------------------

	/**
	 * A post was saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			self::index_post( $post );
		}
	}

	/**
	 * A post changed status (publish, unpublish, trash, untrash, schedule).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post = null ) {
		if ( $new_status !== $old_status && $post instanceof \WP_Post ) {
			self::index_post( $post );
		}
	}

	/**
	 * Remember the reference of a post before core deletes it (its terms go first).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && self::is_source_post( $post ) ) {
			$ref = self::ref_for_post( $post );
			if ( null !== $ref ) {
				self::$deleting[ (int) $post_id ] = $ref;
			}
		}
	}

	/**
	 * A post is gone.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_deleted_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( self::$deleting[ $post_id ] ) ) {
			return;
		}
		$ref = self::$deleting[ $post_id ];
		unset( self::$deleting[ $post_id ] );
		// Core fires deleted_post before it clears its post caches; clear them so the loader sees the deletion.
		clean_post_cache( $post_id );
		// Not remove_source(): deleting a database template can leave the theme's own file.
		self::index_source( $ref );
	}

	/**
	 * Index a post's source when it can matter.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	private static function index_post( \WP_Post $post ) {
		if ( ! self::is_source_post( $post ) ) {
			return;
		}
		$ref = self::ref_for_post( $post );
		if ( null === $ref ) {
			return;
		}

		// One pass per saved state: a save fires several of our hooks with the same data, one after another.
		$signature = $post->post_status . '|' . md5( $post->post_content . '|' . $post->post_title . '|' . $post->post_password );
		if ( ( self::$handled[ $ref ] ?? '' ) === $signature ) {
			return;
		}
		self::$handled[ $ref ] = $signature;

		if ( ! self::relevant( (string) $post->post_content, $ref ) ) {
			return;
		}
		self::index_source( $ref );
	}

	/**
	 * Whether content needs indexing: it has a marker, or the source has rows or edges to update.
	 *
	 * @param string $content Stored content.
	 * @param string $ref     Source reference.
	 * @return bool
	 */
	private static function relevant( $content, $ref ) {
		global $wpdb;

		foreach ( self::MARKERS as $marker ) {
			if ( false !== strpos( $content, $marker ) ) {
				return true;
			}
		}
		if ( Source_Edges::has( $ref ) ) {
			return true;
		}
		$table = esc_sql( Database::table( 'forms' ) );
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$table} WHERE source = %s LIMIT 1", $ref ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
	}

	/**
	 * Whether a post can be a source: a show_in_rest post type, or wp_block, wp_template, wp_template_part. Never revisions, autosaves, auto-drafts or wp_navigation.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function is_source_post( \WP_Post $post ) {
		if ( in_array( $post->post_type, [ 'revision', 'wp_navigation', 'nav_menu_item', 'customize_changeset' ], true ) ) {
			return false;
		}
		if ( in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
			return false;
		}
		if ( in_array( $post->post_type, [ 'wp_block', 'wp_template', 'wp_template_part' ], true ) ) {
			return true;
		}
		$type = get_post_type_object( $post->post_type );
		return $type instanceof \WP_Post_Type && $type->show_in_rest;
	}

	/**
	 * Source reference of a post: template and part posts map to their theme//slug reference, everything else to post:ID.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null Null for a template post without a theme.
	 */
	public static function ref_for_post( \WP_Post $post ) {
		if ( in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			$terms = wp_get_object_terms( (int) $post->ID, 'wp_theme', [ 'fields' => 'names' ] );
			if ( is_wp_error( $terms ) || empty( $terms[0] ) ) {
				return null;
			}
			return 'wp_template' === $post->post_type
				? Source_Ref::template( $terms[0], $post->post_name )
				: Source_Ref::template_part( $terms[0], $post->post_name );
		}
		return Source_Ref::post( (int) $post->ID );
	}

	/**
	 * A theme upgrade finished.
	 *
	 * @param mixed $upgrader Upgrader (unused).
	 * @param array<string, mixed> $options Upgrade details.
	 * @return void
	 */
	public static function on_upgrader_complete( $upgrader, $options = [] ) {
		if ( is_array( $options ) && isset( $options['type'] ) && 'theme' === $options['type'] ) {
			self::reindex_templates();
		}
	}

	/**
	 * Reindex the templates and parts of the active theme (files and database) and every template row already known.
	 *
	 * Rows of templates that belong to another theme become not_live; patterns referenced from the
	 * active templates and parts are indexed through them.
	 *
	 * @return void
	 */
	public static function reindex_templates() {
		$refs = self::known_sources( [ 'template:%', 'template_part:%' ] );
		foreach ( [
			'wp_template'      => 'template',
			'wp_template_part' => 'template_part',
		] as $type => $kind ) {
			foreach ( (array) get_block_templates( [], $type ) as $template ) {
				if ( ! $template instanceof \WP_Block_Template ) {
					continue;
				}
				$ref = Source_Ref::from_template_id( $kind, (string) $template->id );
				if ( null !== $ref && ( self::has_marker( (string) $template->content ) || Source_Edges::has( $ref ) ) ) {
					$refs[] = $ref;
				}
			}
		}
		foreach ( array_unique( $refs ) as $ref ) {
			self::index_source( $ref );
		}
		self::refresh_all();
	}

	/**
	 * Block widgets changed (content or placement).
	 *
	 * @return void
	 */
	public static function reindex_widgets() {
		$refs    = self::known_sources( [ 'widget:%' ] );
		$widgets = get_option( 'widget_block' );
		if ( is_array( $widgets ) ) {
			foreach ( $widgets as $number => $widget ) {
				if ( is_int( $number ) && is_array( $widget ) && isset( $widget['content'] ) && self::has_marker( (string) $widget['content'] ) ) {
					$ref = Source_Ref::widget( $number );
					if ( null !== $ref ) {
						$refs[] = $ref;
					}
				}
			}
		}
		foreach ( array_unique( $refs ) as $ref ) {
			self::index_source( $ref );
		}
	}

	/**
	 * Whether content contains a marker.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	private static function has_marker( $content ) {
		foreach ( self::MARKERS as $marker ) {
			if ( false !== strpos( $content, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sources that already have rows, matching LIKE patterns on the reference.
	 *
	 * @param string[] $patterns LIKE patterns, for example "widget:%".
	 * @return string[]
	 */
	private static function known_sources( array $patterns ) {
		global $wpdb;

		$table = esc_sql( Database::table( 'forms' ) );
		$refs  = [];
		foreach ( $patterns as $pattern ) {
			$refs = array_merge( $refs, (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source FROM {$table} WHERE source LIKE %s", $pattern ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		}
		return array_map( 'strval', $refs );
	}

	/**
	 * Recompute liveness of every source that has rows.
	 *
	 * @return void
	 */
	private static function refresh_all() {
		$changed = false;
		foreach ( self::known_sources( [ '%' ] ) as $ref ) {
			$changed = self::refresh_source( $ref ) || $changed;
		}
		if ( $changed ) {
			self::bump();
		}
	}

	/**
	 * Rebuild the whole index from stored content.
	 *
	 * Used after every schema version change, on activation and by the user-config loader. Idempotent:
	 * mappings that exist are kept. Posts that can matter are found with a LIKE prefilter (a form,
	 * synced pattern, pattern or template part marker), ordered by post_date_gmt and ID so that
	 * the earliest-published source claims a shared stored ID first, and handled in batches. Then
	 * theme templates and parts, block widgets, and finally every source the index knew but the
	 * scan did not find (deleted or unavailable) and a liveness pass.
	 *
	 * @return void
	 */
	public static function rebuild() {
		self::$ensured    = [];
		self::$handled    = [];
		self::$rebuilding = true;
		Source_Edges::begin();

		try {
			self::scan();
		} finally {
			self::$rebuilding = false;
			Source_Edges::commit();
		}

		self::refresh_all();
		self::bump();
	}

	/**
	 * The scan of rebuild(): every source that can hold or pull in a form.
	 *
	 * @return void
	 */
	private static function scan() {
		global $wpdb;

		$conditions = [];
		$args       = [];
		foreach ( self::MARKERS as $marker ) {
			$conditions[] = 'post_content LIKE %s';
			$args[]       = '%' . $wpdb->esc_like( $marker ) . '%';
		}
		$excluded = "'revision', 'nav_menu_item', 'wp_navigation', 'customize_changeset', 'oembed_cache', 'user_request'";
		$ids      = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft', 'inherit') AND post_type NOT IN ({$excluded}) AND (" . implode( ' OR ', $conditions ) . ") ORDER BY (post_status <> 'publish' OR post_date_gmt = '0000-00-00 00:00:00') ASC, post_date_gmt ASC, ID ASC", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Markers are fixed strings, the rest is built from constants.

		$done = [];
		foreach ( array_chunk( array_map( 'intval', (array) $ids ), self::BATCH ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			foreach ( $chunk as $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post instanceof \WP_Post || ! self::is_source_post( $post ) ) {
					continue;
				}
				$ref = self::ref_for_post( $post );
				if ( null !== $ref && ! isset( $done[ $ref ] ) ) {
					$done[ $ref ] = true;
					self::index_source( $ref );
				}
			}
		}

		foreach ( [
			'wp_template'      => 'template',
			'wp_template_part' => 'template_part',
		] as $type => $kind ) {
			foreach ( (array) get_block_templates( [], $type ) as $template ) {
				if ( $template instanceof \WP_Block_Template && self::has_marker( (string) $template->content ) ) {
					$ref = Source_Ref::from_template_id( $kind, (string) $template->id );
					if ( null !== $ref && ! isset( $done[ $ref ] ) ) {
						$done[ $ref ] = true;
						self::index_source( $ref );
					}
				}
			}
		}

		$widgets = get_option( 'widget_block' );
		if ( is_array( $widgets ) ) {
			foreach ( $widgets as $number => $widget ) {
				if ( is_int( $number ) && is_array( $widget ) && isset( $widget['content'] ) && self::has_marker( (string) $widget['content'] ) ) {
					$ref = Source_Ref::widget( $number );
					if ( null !== $ref && ! isset( $done[ $ref ] ) ) {
						$done[ $ref ] = true;
						self::index_source( $ref );
					}
				}
			}
		}

		// Sources the index knew before but the scan did not reach: gone, or no longer showing a form.
		foreach ( self::known_sources( [ '%' ] ) as $ref ) {
			if ( ! isset( $done[ $ref ] ) ) {
				self::index_source( $ref );
			}
		}
	}
}
