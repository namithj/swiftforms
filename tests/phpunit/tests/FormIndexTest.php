<?php
/**
 * Tests for SwiftForms\Form_Index: effective IDs, liveness and refresh.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-resolver-test-case.php';

use SwiftForms\Form_Index;
use SwiftForms\Source_Edges;
use SwiftForms\Uuid;

/**
 * Class FormIndexTest
 *
 * @group index
 * @group resolver
 */
class FormIndexTest extends SF_Resolver_Test_Case {

	/**
	 * A form block with one field, for content that only needs to hold a form.
	 *
	 * @param string $id Stored formId (empty for none).
	 * @return string
	 */
	private function form( $id ) {
		return $this->form_markup( $id, $this->field_markup( 'text', [ 'name' => 'who' ] ) );
	}

	/**
	 * Run code with the save hooks of the index detached (stored data written behind the plugin's back).
	 *
	 * @param callable $callback Code to run.
	 * @return mixed
	 */
	private function without_index_hooks( callable $callback ) {
		remove_action( 'wp_after_insert_post', [ Form_Index::class, 'on_after_insert_post' ], 20 );
		remove_action( 'transition_post_status', [ Form_Index::class, 'on_transition_post_status' ], 20 );
		try {
			return $callback();
		} finally {
			add_action( 'wp_after_insert_post', [ Form_Index::class, 'on_after_insert_post' ], 20, 2 );
			add_action( 'transition_post_status', [ Form_Index::class, 'on_transition_post_status' ], 20, 3 );
		}
	}

	/**
	 * Write queries (insert, update, delete, replace) run while a callback executes.
	 *
	 * @param callable $callback Code to run.
	 * @return string[]
	 */
	private function writes( callable $callback ) {
		$writes = [];
		$filter = static function ( $sql ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		$callback();
		remove_filter( 'query', $filter );
		return $writes;
	}

	/**
	 * Status of an index row.
	 *
	 * @param string $form_id Effective formId.
	 * @return string|null
	 */
	private function status( $form_id ) {
		$row = $this->row( $form_id );
		return null === $row ? null : $row['status'];
	}

	/**
	 * Forget everything the index knows (rows and edges), as on a site that never ran the plugin.
	 *
	 * @return void
	 */
	private function empty_index() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . SwiftForms\Database::table( 'forms' ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		Source_Edges::reset();
		Form_Index::flush_cache();
	}

	// ---------------------------------------------------------------------
	// Effective IDs.
	// ---------------------------------------------------------------------

	/**
	 * The first source with an ID owns it; the stored ID is its effective ID.
	 *
	 * @return void
	 */
	public function test_owner_keeps_the_stored_id() {
		$page = $this->make_post( $this->form( self::ID_A ), [ 'post_title' => 'Contact us' ] );

		$row = $this->row( self::ID_A );
		$this->assertSame( 'post:' . $page, $row['source'] );
		$this->assertSame( self::ID_A, $row['stored_id'] );
		$this->assertSame( 'Contact us', $row['title'] );
		$this->assertSame( 'live', $row['status'] );
		$this->assertSame( 64, strlen( $row['schema_hash'] ) );
		$this->assertSame(
			[
				'ordinal' => 1,
				'kind'    => 'owner',
				'places'  => [ 'post:' . $page ],
				'hashes'  => [ $row['schema_hash'] ],
			],
			json_decode( $row['locations'], true )
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'post:' . $page ) );
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'post:' . $page, 1 ) );
		$this->assertNotNull( SwiftForms\Schema_Store::get( $row['schema_hash'] ) );
	}

	/**
	 * The form's own title attribute wins over the page title.
	 *
	 * @return void
	 */
	public function test_title_attribute_wins() {
		$this->make_post( $this->form_markup( self::ID_A, '', [ 'title' => 'Newsletter' ] ), [ 'post_title' => 'Home' ] );
		$this->assertSame( 'Newsletter', $this->row( self::ID_A )['title'] );
	}

	/**
	 * A second source with the same stored ID is a copy: uuid_v5 of the stored ID and its own source.
	 *
	 * @return void
	 */
	public function test_copy_gets_a_derived_id() {
		$original = $this->make_post( $this->form( self::ID_A ) );
		$copy     = $this->make_post( $this->form( self::ID_A ) );

		$copy_id = Uuid::v5( self::ID_A . '|post:' . $copy );
		$this->assertSame( $copy_id, Form_Index::copy_id( self::ID_A, 'post:' . $copy ) );
		$this->assertNotSame( self::ID_A, $copy_id );

		$this->assertSame( 'post:' . $original, $this->row( self::ID_A )['source'] );
		$row = $this->row( $copy_id );
		$this->assertSame( 'post:' . $copy, $row['source'] );
		$this->assertSame( self::ID_A, $row['stored_id'] );
		$this->assertSame( 'copy', json_decode( $row['locations'], true )['kind'] );

		$this->assertSame( $copy_id, Form_Index::ensure( self::ID_A, 'post:' . $copy, 1 ) );
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'post:' . $original, 1 ) );
		$this->assertTrue( Form_Index::is_live( $copy_id, 'post:' . $copy ) );
		$this->assertFalse( Form_Index::is_live( $copy_id, 'post:' . $original ), 'A pair must match its own source.' );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'post:' . $copy ) );
	}

	/**
	 * A block without a usable ID uses the "missing" ID of its source and position.
	 *
	 * @return void
	 */
	public function test_missing_id() {
		$page = $this->make_post( $this->form( '' ) . $this->form( 'not-a-uuid' ) );
		$ref  = 'post:' . $page;

		$first  = Uuid::v5( 'missing|' . $ref . '|1' );
		$second = Uuid::v5( 'missing|' . $ref . '|2' );
		$this->assertSame( 'live', $this->status( $first ) );
		$this->assertSame( 'live', $this->status( $second ) );
		$this->assertSame( '', $this->row( $first )['stored_id'] );
		$this->assertSame( $first, Form_Index::ensure( '', $ref, 1 ) );
		$this->assertSame( $second, Form_Index::ensure( 'not-a-uuid', $ref, 2 ) );
		$this->assertSame( $second, Form_Index::ensure( null, $ref, 2 ) );
	}

	/**
	 * The same stored ID twice in one source: the first keeps it, later blocks get "dup" IDs.
	 *
	 * @return void
	 */
	public function test_duplicate_in_one_source() {
		$page = $this->make_post( $this->form( self::ID_A ) . $this->form( self::ID_B ) . $this->form( self::ID_A ) . $this->form( self::ID_A ) );
		$ref  = 'post:' . $page;

		$dup3 = Uuid::v5( 'dup|' . self::ID_A . '|' . $ref . '|3' );
		$dup4 = Uuid::v5( 'dup|' . self::ID_A . '|' . $ref . '|4' );

		$this->assertSame( 'live', $this->status( self::ID_A ) );
		$this->assertSame( 'live', $this->status( self::ID_B ) );
		$this->assertSame( 'live', $this->status( $dup3 ) );
		$this->assertSame( 'live', $this->status( $dup4 ) );

		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, $ref, 1 ) );
		$this->assertSame( self::ID_B, Form_Index::ensure( self::ID_B, $ref, 2 ) );
		$this->assertSame( $dup3, Form_Index::ensure( self::ID_A, $ref, 3 ) );
		$this->assertSame( $dup4, Form_Index::ensure( self::ID_A, $ref, 4 ) );
	}

	/**
	 * Mappings never change once written, and an owner is never displaced, even by an older source that shows up later.
	 *
	 * @return void
	 */
	public function test_mappings_are_stable() {
		$newer = $this->make_post(
			$this->form( self::ID_A ),
			[
				'post_date'     => '2026-06-02 10:00:00',
				'post_date_gmt' => '2026-06-02 10:00:00',
			]
		);
		$older = $this->make_post(
			$this->form( self::ID_A ),
			[
				'post_date'     => '2026-01-01 10:00:00',
				'post_date_gmt' => '2026-01-01 10:00:00',
			]
		);

		$copy_id = Form_Index::copy_id( self::ID_A, 'post:' . $older );
		$this->assertSame( 'post:' . $newer, $this->row( self::ID_A )['source'], 'The first to be indexed keeps the ID.' );
		$this->assertSame( 'post:' . $older, $this->row( $copy_id )['source'], 'The newcomer is a copy, although it is older.' );

		// Saving again, with edits, changes nothing.
		wp_update_post(
			wp_slash(
				[
					'ID'         => $older,
					'post_title' => 'Edited',
				]
			)
		);
		wp_update_post(
			wp_slash(
				[
					'ID'         => $newer,
					'post_title' => 'Edited too',
				]
			)
		);
		$this->assertSame( 'post:' . $newer, $this->row( self::ID_A )['source'] );
		$this->assertSame( 'post:' . $older, $this->row( $copy_id )['source'] );

		// The owner drops the form: its row stays (deleted) and the copy keeps its own ID.
		wp_update_post(
			wp_slash(
				[
					'ID'           => $newer,
					'post_content' => 'no form any more',
				]
			)
		);
		$this->assertSame( 'deleted', $this->status( self::ID_A ) );
		$this->assertSame( 'live', $this->status( $copy_id ) );
		$this->assertSame( $copy_id, Form_Index::ensure( $copy_id, 'post:' . $older, 1 ), 'The copy was rewritten on save and keeps its own ID.' );

		// And the owner gets it back, live, with the same ID.
		wp_update_post(
			wp_slash(
				[
					'ID'           => $newer,
					'post_content' => $this->form( self::ID_A ),
				]
			)
		);
		$this->assertSame( 'live', $this->status( self::ID_A ) );
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'post:' . $newer, 1 ) );
	}

	/**
	 * A form moved from page A to page B keeps its ID: the deleted owner's row passes to B.
	 *
	 * @return void
	 */
	public function test_deleted_owner_hands_over_to_the_next_source() {
		$a = $this->make_post( $this->form( self::ID_A ) );
		$b = $this->make_post( 'empty' );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $a,
					'post_content' => 'moved away',
				]
			)
		);
		$this->assertSame( 'deleted', $this->status( self::ID_A ) );

		wp_update_post(
			wp_slash(
				[
					'ID'           => $b,
					'post_content' => $this->form( self::ID_A ),
				]
			)
		);
		$row = $this->row( self::ID_A );
		$this->assertSame( 'post:' . $b, $row['source'] );
		$this->assertSame( 'live', $row['status'] );
		$this->assertSame( 'owner', json_decode( $row['locations'], true )['kind'] );
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'post:' . $b, 1 ) );
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'post:' . $b ) );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'post:' . $a ) );
		$this->assertStringContainsString( self::ID_A, $this->stored_content( $b ), 'The owner is not rewritten.' );

		// The old page gets the form back (a revision restore): now it is a copy.
		wp_update_post(
			wp_slash(
				[
					'ID'           => $a,
					'post_content' => $this->form( self::ID_A ),
				]
			)
		);
		$copy_id = Form_Index::copy_id( self::ID_A, 'post:' . $a );
		$this->assertSame( 'post:' . $b, $this->row( self::ID_A )['source'] );
		$this->assertSame( 'post:' . $a, $this->row( $copy_id )['source'] );
		$this->assertSame( $copy_id, Form_Index::ensure( self::ID_A, 'post:' . $a, 1 ) );
	}

	/**
	 * A trashed owner still contains the form: a paste elsewhere is a copy. A permanently deleted owner hands over.
	 *
	 * @return void
	 */
	public function test_trashed_owner_keeps_the_id_deleted_owner_gives_it_up() {
		$a = $this->make_post( $this->form( self::ID_A ) );
		wp_trash_post( $a );
		$this->assertSame( 'not_live', $this->status( self::ID_A ) );

		$b = $this->make_post( $this->form( self::ID_A ) );
		$this->assertSame( 'post:' . $a, $this->row( self::ID_A )['source'] );
		$this->assertSame( 'post:' . $b, $this->row( Form_Index::copy_id( self::ID_A, 'post:' . $b ) )['source'] );

		$c = $this->make_post( $this->form( self::ID_B ) );
		wp_delete_post( $c, true );
		$this->assertSame( 'deleted', $this->status( self::ID_B ) );
		$d = $this->make_post( $this->form( self::ID_B ) );
		$this->assertSame( 'post:' . $d, $this->row( self::ID_B )['source'] );
		$this->assertTrue( Form_Index::is_live( self::ID_B, 'post:' . $d ) );
	}

	/**
	 * When many sources are indexed at once the earliest published claims the ID, whatever their post IDs.
	 *
	 * @return void
	 */
	public function test_rebuild_ranks_owners() {
		$late = null;
		$soon = null;
		$this->make_theme( 'sf-i1', [ 'templates/single.html' => $this->form( self::ID_A ) ] );
		$this->empty_index(); // Switching theme indexed the template already; start from nothing.
		$this->without_index_hooks(
			function () use ( &$late, &$soon ) {
				$late = $this->make_post(
					$this->form( self::ID_A ),
					[
						'post_date'     => '2026-09-01 10:00:00',
						'post_date_gmt' => '2026-09-01 10:00:00',
					]
				);
				$soon = $this->make_post(
					$this->form( self::ID_A ),
					[
						'post_date'     => '2026-02-01 10:00:00',
						'post_date_gmt' => '2026-02-01 10:00:00',
					]
				);
			}
		);
		$this->assertLessThan( $soon, $late, 'The later page has the lower ID.' );

		Form_Index::rebuild();

		$this->assertSame( 'post:' . $soon, $this->row( self::ID_A )['source'], 'Earliest post_date_gmt wins.' );
		$this->assertSame( 'post:' . $late, $this->row( Form_Index::copy_id( self::ID_A, 'post:' . $late ) )['source'] );
		$template_copy = Form_Index::copy_id( self::ID_A, 'template:sf-i1//single' );
		$this->assertSame( 'template:sf-i1//single', $this->row( $template_copy )['source'], 'Posts rank before templates.' );
	}

	/**
	 * Posts with the same date rank by post ID.
	 *
	 * @return void
	 */
	public function test_rank_orders_by_date_then_id() {
		$a = $this->make_post(
			'x',
			[
				'post_date'     => '2026-03-01 10:00:00',
				'post_date_gmt' => '2026-03-01 10:00:00',
			]
		);
		$b = $this->make_post(
			'x',
			[
				'post_date'     => '2026-03-01 10:00:00',
				'post_date_gmt' => '2026-03-01 10:00:00',
			]
		);
		$c = $this->make_post(
			'x',
			[
				'post_date'     => '2026-01-01 10:00:00',
				'post_date_gmt' => '2026-01-01 10:00:00',
			]
		);

		$this->assertSame(
			[ 'post:' . $c, 'post:' . $a, 'post:' . $b, 'pattern:z/z', 'template:t//a', 'widget:block-1' ],
			Form_Index::rank( [ 'widget:block-1', 'post:' . $b, 'template:t//a', 'post:' . $a, 'pattern:z/z', 'post:' . $c ] )
		);
	}

	/**
	 * Render and save agree: ensure() before any save gives the ID the save-time index would.
	 *
	 * @return void
	 */
	public function test_ensure_matches_the_indexed_id_and_indexes_lazily() {
		$id  = $this->without_index_hooks(
			function () {
				$this->make_post( $this->form( self::ID_A ) );
				return $this->make_post( $this->form( self::ID_A ) . $this->form( '' ) );
			}
		);
		$ref = 'post:' . $id;
		$this->assertNull( $this->row( self::ID_A ) );

		$writes = $this->writes(
			function () use ( $ref, &$ensured ) {
				$ensured = [
					Form_Index::ensure( self::ID_A, $ref, 1 ),
					Form_Index::ensure( '', $ref, 2 ),
				];
			}
		);
		$this->assertNotEmpty( $writes, 'An unknown source is indexed on first render.' );

		// The first source is not indexed either, so this one claimed the ID. Render and index agree.
		$this->assertSame( self::ID_A, $ensured[0] );
		$this->assertSame( Uuid::v5( 'missing|' . $ref . '|2' ), $ensured[1] );
		$this->assertSame( 'live', $this->status( $ensured[0] ) );

		Form_Index::flush_cache();
		$again = $this->writes(
			function () use ( $ref ) {
				Form_Index::ensure( self::ID_A, $ref, 1 );
				Form_Index::ensure( '', $ref, 2 );
				Form_Index::index_source( $ref );
			}
		);
		$this->assertSame( [], $again, 'Nothing changed, nothing is written.' );
	}

	/**
	 * Saving a page without changes writes nothing to the index, and a page without forms leaves no trace.
	 *
	 * @return void
	 */
	public function test_unchanged_saves_do_not_write() {
		global $wpdb;

		$page  = $this->make_post( $this->form( self::ID_A ) );
		$plain = $this->make_post( '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->' );

		Form_Index::flush_cache();
		$writes       = $this->writes(
			function () use ( $page, $plain ) {
				wp_update_post(
					wp_slash(
						[
							'ID'           => $page,
							'post_content' => $this->form( self::ID_A ),
							'post_excerpt' => 'Same form, new excerpt',
						]
					)
				);
				wp_update_post(
					wp_slash(
						[
							'ID'         => $plain,
							'post_title' => 'Plain, retitled',
						]
					)
				);
			}
		);
		$index_writes = array_filter(
			$writes,
			static function ( $sql ) {
				return false !== strpos( $sql, 'swiftforms_' );
			}
		);
		$this->assertSame( [], array_values( $index_writes ), 'An unchanged form, and a page with no form, write nothing to the index.' );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . SwiftForms\Database::table( 'forms' ) . '` WHERE source = "post:' . $plain . '"' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Revisions and autosaves are not sources.
	 *
	 * @return void
	 */
	public function test_revisions_are_not_indexed() {
		global $wpdb;

		$page = $this->make_post( $this->form( self::ID_A ) );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $page,
					'post_content' => $this->form( self::ID_A ) . '<!-- wp:paragraph --><p>2</p><!-- /wp:paragraph -->',
				]
			)
		);

		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . SwiftForms\Database::table( 'forms' ) . '`' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	// ---------------------------------------------------------------------
	// Liveness.
	// ---------------------------------------------------------------------

	/**
	 * Every post state: only published (and password-protected) are live.
	 *
	 * @return void
	 */
	public function test_post_states() {
		$states = [
			'publish' => [ [], 'live' ],
			'draft'   => [ [ 'post_status' => 'draft' ], 'not_live' ],
			'pending' => [ [ 'post_status' => 'pending' ], 'not_live' ],
			'private' => [ [ 'post_status' => 'private' ], 'not_live' ],
			'future'  => [
				[
					'post_status'   => 'future',
					'post_date'     => '2036-01-01 10:00:00',
					'post_date_gmt' => '2036-01-01 10:00:00',
				],
				'not_live',
			],
			'trash'   => [ [ 'post_status' => 'trash' ], 'not_live' ],
			'locked'  => [ [ 'post_password' => 'secret' ], 'live' ],
		];
		$n      = 0;
		foreach ( $states as $label => $state ) {
			$id   = sprintf( '%08d-0000-4000-8000-000000000000', ++$n );
			$page = $this->make_post( $this->form( $id ), $state[0] );
			$this->assertSame( $state[1], $this->status( $id ), $label );
			$this->assertSame( 'live' === $state[1], Form_Index::is_live( $id, 'post:' . $page ), $label );
		}
	}

	/**
	 * Publishing, unpublishing, trashing, untrashing and deleting move the status, and the cached answer follows.
	 *
	 * @return void
	 */
	public function test_status_changes_and_cache() {
		global $wpdb;

		$page = $this->make_post( $this->form( self::ID_A ), [ 'post_status' => 'draft' ] );
		$ref  = 'post:' . $page;
		$this->assertFalse( Form_Index::is_live( self::ID_A, $ref ) );

		wp_publish_post( $page );
		$this->assertTrue( Form_Index::is_live( self::ID_A, $ref ), 'wp_publish_post() fires transition_post_status only.' );

		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'private',
				]
			)
		);
		$this->assertFalse( Form_Index::is_live( self::ID_A, $ref ) );

		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'publish',
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, $ref ) );

		wp_trash_post( $page );
		$this->assertFalse( Form_Index::is_live( self::ID_A, $ref ) );
		$this->assertSame( 'not_live', $this->status( self::ID_A ) );

		wp_untrash_post( $page );
		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'publish',
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, $ref ) );

		wp_delete_post( $page, true );
		$this->assertFalse( Form_Index::is_live( self::ID_A, $ref ) );
		$this->assertSame( 'deleted', $this->status( self::ID_A ), 'The row stays, marked deleted.' );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . SwiftForms\Database::table( 'forms' ) . '`' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A synced pattern is live only while a live source shows it.
	 *
	 * @return void
	 */
	public function test_synced_pattern_liveness() {
		$synced = $this->make_post( $this->form( self::ID_A ), [ 'post_type' => 'wp_block' ] );
		$sref   = 'post:' . $synced;
		$this->assertSame( 'not_live', $this->status( self::ID_A ), 'Nobody shows it yet.' );
		$this->assertFalse( Form_Index::is_live( self::ID_A, $sref ) );

		$draft_page = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->', [ 'post_status' => 'draft' ] );
		$this->assertSame( 'not_live', $this->status( self::ID_A ), 'Only a draft page shows it.' );

		$page = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' );
		$this->assertSame( 'live', $this->status( self::ID_A ) );
		$this->assertTrue( Form_Index::is_live( self::ID_A, $sref ) );
		$this->assertSame( [ 'post:' . $page ], json_decode( $this->row( self::ID_A )['locations'], true )['places'] );

		// A second live page adds a place.
		$second = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' );
		$places = json_decode( $this->row( self::ID_A )['locations'], true )['places'];
		$this->assertEqualsCanonicalizing( [ 'post:' . $page, 'post:' . $second ], $places );

		// Unpublishing the pages one by one moves the status when the last one goes.
		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'draft',
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, $sref ) );
		wp_update_post(
			wp_slash(
				[
					'ID'          => $second,
					'post_status' => 'private',
				]
			)
		);
		$this->assertFalse( Form_Index::is_live( self::ID_A, $sref ) );
		$this->assertSame( 'not_live', $this->status( self::ID_A ) );

		wp_update_post(
			wp_slash(
				[
					'ID'          => $draft_page,
					'post_status' => 'publish',
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, $sref ) );

		// The synced pattern itself unpublished (draft): not live, whoever shows it.
		wp_update_post(
			wp_slash(
				[
					'ID'          => $synced,
					'post_status' => 'draft',
				]
			)
		);
		$this->assertFalse( Form_Index::is_live( self::ID_A, $sref ) );
		wp_update_post(
			wp_slash(
				[
					'ID'          => $synced,
					'post_status' => 'publish',
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, $sref ) );

		// The page stops referencing it.
		wp_update_post(
			wp_slash(
				[
					'ID'           => $draft_page,
					'post_content' => 'gone',
				]
			)
		);
		$this->assertFalse( Form_Index::is_live( self::ID_A, $sref ) );
	}

	/**
	 * A form added to a synced pattern that pages already show is live at once (the edges were recorded earlier).
	 *
	 * @return void
	 */
	public function test_form_added_to_an_already_placed_synced_pattern() {
		$synced = $this->make_post( 'no form yet', [ 'post_type' => 'wp_block' ] );
		$this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' );

		wp_update_post(
			wp_slash(
				[
					'ID'           => $synced,
					'post_content' => $this->form( self::ID_A ),
				]
			)
		);
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'post:' . $synced ) );
	}

	/**
	 * Nesting works up to five levels below the page.
	 *
	 * @return void
	 */
	public function test_nested_synced_pattern_depth() {
		$ids   = [];
		$ids[] = $this->make_post( $this->form( self::ID_A ), [ 'post_type' => 'wp_block' ] );
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->make_post( '<!-- wp:block {"ref":' . $ids[ $i - 1 ] . '} /-->', [ 'post_type' => 'wp_block' ] );
		}
		// ids[0] holds the form; ids[5] is five levels above it. A page showing ids[5] is six levels up: too deep.
		$this->make_post( '<!-- wp:block {"ref":' . $ids[5] . '} /-->' );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'post:' . $ids[0] ), 'Six levels: not a location.' );

		$page = $this->make_post( '<!-- wp:block {"ref":' . $ids[4] . '} /-->' );
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'post:' . $ids[0] ), 'Five levels from the page: live.' );
		$this->assertNotNull( $page );
	}

	/**
	 * Template parts and templates of the active theme are live; other themes' are not.
	 *
	 * @return void
	 */
	public function test_templates_and_parts() {
		$this->make_theme(
			'sf-i2',
			[
				'templates/single.html' => $this->form( self::ID_A ),
				'parts/footer.html'     => $this->form( self::ID_B ),
			]
		);
		Form_Index::rebuild();

		$this->assertTrue( Form_Index::is_live( self::ID_A, 'template:sf-i2//single' ) );
		$this->assertTrue( Form_Index::is_live( self::ID_B, 'template_part:sf-i2//footer' ) );

		// A database customisation of the template replaces the file for rendering; its form is live too.
		$this->make_template_post( 'wp_template', 'sf-i2', 'page', $this->form( self::ID_C ) );
		$this->assertTrue( Form_Index::is_live( self::ID_C, 'template:sf-i2//page' ) );

		// Another theme's template is never a source.
		$this->make_template_post( 'wp_template', 'sf-old', 'single', $this->form( '88888888-8888-4888-8888-888888888888' ) );
		$this->assertFalse( Form_Index::is_live( '88888888-8888-4888-8888-888888888888', 'template:sf-old//single' ) );
		$this->assertNull( $this->row( '88888888-8888-4888-8888-888888888888' ) );
	}

	/**
	 * Switching theme turns the old theme's template forms not live and the new theme's live.
	 *
	 * @return void
	 */
	public function test_switch_theme() {
		$this->make_theme( 'sf-i3a', [ 'templates/single.html' => $this->form( self::ID_A ) ] );
		$this->make_theme( 'sf-i3b', [ 'templates/single.html' => $this->form( self::ID_B ) ], false );
		Form_Index::rebuild();
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'template:sf-i3a//single' ) );
		$this->assertNull( $this->row( self::ID_B ) );

		switch_theme( 'sf-i3b' );

		$this->assertFalse( Form_Index::is_live( self::ID_A, 'template:sf-i3a//single' ) );
		$this->assertSame( 'not_live', $this->status( self::ID_A ), 'Kept, not deleted: the theme may come back.' );
		$this->assertTrue( Form_Index::is_live( self::ID_B, 'template:sf-i3b//single' ) );

		switch_theme( 'sf-i3a' );
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'template:sf-i3a//single' ) );
		$this->assertFalse( Form_Index::is_live( self::ID_B, 'template:sf-i3b//single' ) );
	}

	/**
	 * A registered pattern is not a location until an active template or part references it (mutation 15).
	 *
	 * @return void
	 */
	public function test_pattern_liveness() {
		$this->register_pattern( 'sf/contact', $this->form( self::ID_A ) );
		$this->make_theme( 'sf-i4', [ 'templates/single.html' => '<!-- wp:paragraph --><p>nothing</p><!-- /wp:paragraph -->' ] );
		Form_Index::rebuild();

		// Merely registered: no row at all.
		$this->assertNull( $this->row( self::ID_A ) );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'pattern:sf/contact' ) );

		// Rendered inside a page (core/pattern in a post): indexed lazily, still not live.
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, 'pattern:sf/contact', 1 ) );
		$this->assertSame( 'not_live', $this->status( self::ID_A ) );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'pattern:sf/contact' ) );
		$page = $this->make_post( '<!-- wp:pattern {"slug":"sf/contact"} /-->' );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'pattern:sf/contact' ), 'A post showing a pattern does not make it a location.' );

		// An active template that references it does.
		$this->make_template_post( 'wp_template', 'sf-i4', 'single', '<!-- wp:pattern {"slug":"sf/contact"} /-->' );
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'pattern:sf/contact' ) );
		$this->assertSame( [ 'template:sf-i4//single' ], json_decode( $this->row( self::ID_A )['locations'], true )['places'] );
		$this->assertNotNull( $page );

		// The template stops referencing it.
		$tpl = get_posts(
			[
				'post_type'   => 'wp_template',
				'name'        => 'single',
				'post_status' => 'any',
			]
		);
		wp_update_post(
			wp_slash(
				[
					'ID'           => $tpl[0]->ID,
					'post_content' => 'nothing',
				]
			)
		);
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'pattern:sf/contact' ) );
	}

	/**
	 * A pattern referenced from a theme-file template part is live too.
	 *
	 * @return void
	 */
	public function test_pattern_referenced_from_a_file_part() {
		$this->register_pattern( 'sf/inpart', $this->form( self::ID_A ) );
		$this->make_theme( 'sf-i5', [ 'parts/header.html' => '<!-- wp:pattern {"slug":"sf/inpart"} /-->' ] );
		Form_Index::rebuild();

		$this->assertTrue( Form_Index::is_live( self::ID_A, 'pattern:sf/inpart' ) );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'template_part:sf-i5//header' ), 'The form lives in the pattern, not in the part.' );
	}

	/**
	 * Widgets: live in an active sidebar, not live in the inactive area, and placement changes move the status.
	 *
	 * @return void
	 */
	public function test_widgets() {
		$this->place_widget( 5, $this->form( self::ID_A ), 'sf-sidebar' );
		$this->place_widget( 6, $this->form( self::ID_B ), 'wp_inactive_widgets' );

		$this->assertTrue( Form_Index::is_live( self::ID_A, 'widget:block-5' ) );
		$this->assertFalse( Form_Index::is_live( self::ID_B, 'widget:block-6' ) );
		$this->assertSame( 'not_live', $this->status( self::ID_B ) );

		update_option(
			'sidebars_widgets',
			[
				'sf-sidebar'          => [ 'block-5', 'block-6' ],
				'wp_inactive_widgets' => [],
				'array_version'       => 3,
			]
		);
		$GLOBALS['_wp_sidebars_widgets'] = null;
		$this->assertTrue( Form_Index::is_live( self::ID_B, 'widget:block-6' ) );

		// Removing the widget deletes its forms.
		update_option( 'widget_block', [ '_multiwidget' => 1 ] );
		$this->assertFalse( Form_Index::is_live( self::ID_A, 'widget:block-5' ) );
		$this->assertSame( 'deleted', $this->status( self::ID_A ) );
	}

	/**
	 * Garbage IDs cost at most one query each, repeats cost none, and nothing gets cached for malformed input.
	 *
	 * @return void
	 */
	public function test_garbage_form_id_flood() {
		global $wpdb;

		$page = $this->make_post( $this->form( self::ID_A ) );
		$ref  = 'post:' . $page;
		Form_Index::flush_cache();

		$before = $wpdb->num_queries;
		foreach ( [ '', 'x', str_repeat( 'A', 5000 ), self::ID_A . "'; DROP TABLE x;--", 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', null, [] ] as $garbage ) {
			$this->assertFalse( Form_Index::is_live( $garbage, $ref ) );
		}
		foreach ( [ '', 'post:x', str_repeat( 'a', 500 ), null ] as $bad_ref ) {
			$this->assertFalse( Form_Index::is_live( self::ID_A, $bad_ref ) );
		}
		$this->assertSame( 0, $wpdb->num_queries - $before, 'Malformed values never reach the database.' );

		$before = $wpdb->num_queries;
		$random = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$random[] = wp_generate_uuid4();
		}
		foreach ( $random as $id ) {
			$this->assertFalse( Form_Index::is_live( $id, $ref ) );
		}
		$this->assertLessThanOrEqual( 50, $wpdb->num_queries - $before, 'One query per unknown ID.' );

		$before = $wpdb->num_queries;
		foreach ( $random as $id ) {
			$this->assertFalse( Form_Index::is_live( $id, $ref ) );
		}
		$this->assertSame( 0, $wpdb->num_queries - $before, 'Answers are cached.' );

		$before = $wpdb->num_queries;
		$this->assertTrue( Form_Index::is_live( self::ID_A, $ref ) );
		$this->assertTrue( Form_Index::is_live( self::ID_A, $ref ) );
		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );
	}

	/**
	 * Rebuilding from stored content (as the user-config loader and a version change do) finds everything and keeps mappings.
	 *
	 * @return void
	 */
	public function test_rebuild_from_raw_content() {
		$this->make_theme( 'sf-i6', [ 'templates/single.html' => $this->form( self::ID_C ) ] );
		$synced = null;
		$page   = null;
		$copy   = null;
		$this->without_index_hooks(
			function () use ( &$synced, &$page, &$copy ) {
				$synced = $this->make_post( $this->form( self::ID_B ), [ 'post_type' => 'wp_block' ] );
				$page   = $this->make_post( $this->form( self::ID_A ) . '<!-- wp:block {"ref":' . $synced . '} /-->' );
				$copy   = $this->make_post( $this->form( self::ID_A ), [ 'post_status' => 'draft' ] );
			}
		);
		$this->assertNull( $this->row( self::ID_A ) );

		Form_Index::rebuild();

		$this->assertSame( 'live', $this->status( self::ID_A ) );
		$this->assertSame( 'live', $this->status( self::ID_B ) );
		$this->assertSame( 'live', $this->status( self::ID_C ) );
		$copy_id = Form_Index::copy_id( self::ID_A, 'post:' . $copy );
		$this->assertSame( 'not_live', $this->status( $copy_id ) );

		$snapshot = [ $this->row( self::ID_A ), $this->row( self::ID_B ), $this->row( $copy_id ) ];
		$writes   = $this->writes(
			function () {
				Form_Index::rebuild();
			}
		);
		$this->assertSame( $snapshot, [ $this->row( self::ID_A ), $this->row( self::ID_B ), $this->row( $copy_id ) ], 'A second rebuild changes no row.' );
		$this->assertSame(
			[],
			array_values(
				array_filter(
					$writes,
					static function ( $sql ) {
						return false !== strpos( $sql, 'swiftforms_forms' ) || false !== strpos( $sql, 'swiftforms_schemas' );
					}
				)
			),
			'A second rebuild writes nothing to the index tables.'
		);
	}

	/**
	 * A rebuild notices sources that vanished behind its back.
	 *
	 * @return void
	 */
	public function test_rebuild_marks_vanished_sources() {
		$page = $this->make_post( $this->form( self::ID_A ) );
		$this->without_index_hooks(
			static function () use ( $page ) {
				global $wpdb;
				$wpdb->delete( $wpdb->posts, [ 'ID' => $page ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $page );
			}
		);
		$this->assertSame( 'live', $this->status( self::ID_A ), 'The hooks were off: the index still believes it.' );

		Form_Index::rebuild();
		$this->assertSame( 'deleted', $this->status( self::ID_A ) );
	}

	/**
	 * The activation and version-change triggers call the rebuild.
	 *
	 * @return void
	 */
	public function test_triggers_are_wired() {
		$this->assertSame( 10, has_action( 'swiftforms_db_version_changed', [ Form_Index::class, 'rebuild_when_ready' ] ) );
		$this->assertSame( 20, has_action( 'wp_after_insert_post', [ Form_Index::class, 'on_after_insert_post' ] ) );
		$this->assertSame( 20, has_action( 'transition_post_status', [ Form_Index::class, 'on_transition_post_status' ] ) );
		$this->assertSame( 10, has_action( 'deleted_post', [ Form_Index::class, 'on_deleted_post' ] ) );
		$this->assertSame( 10, has_action( 'switch_theme', [ Form_Index::class, 'reindex_templates' ] ) );
		$this->assertSame( 10, has_action( 'upgrader_process_complete', [ Form_Index::class, 'on_upgrader_complete' ] ) );
		$this->assertSame( 10, has_action( 'update_option_widget_block', [ Form_Index::class, 'reindex_widgets' ] ) );
		$this->assertSame( 10, has_action( 'update_option_sidebars_widgets', [ Form_Index::class, 'reindex_widgets' ] ) );

		$page = $this->without_index_hooks(
			function () {
				return $this->make_post( $this->form( self::ID_A ) );
			}
		);
		do_action( 'swiftforms_db_version_changed', 0, 1 );
		$this->assertTrue( Form_Index::is_live( self::ID_A, 'post:' . $page ) );
	}

	/**
	 * Without a usable source, ensure() answers by the same rules and touches nothing.
	 *
	 * @return void
	 */
	public function test_ensure_without_a_source() {
		global $wpdb;

		$before = $wpdb->num_queries;
		$this->assertSame( self::ID_A, Form_Index::ensure( self::ID_A, null, 1 ) );
		$this->assertSame( Uuid::v5( 'missing||2' ), Form_Index::ensure( '', null, 2 ) );
		$this->assertSame( self::ID_B, Form_Index::ensure( self::ID_B, 'not a ref', 1 ) );
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * Edges can be inspected: a page shows a synced pattern.
	 *
	 * @return void
	 */
	public function test_edges_are_recorded() {
		$synced = $this->make_post( $this->form( self::ID_A ), [ 'post_type' => 'wp_block' ] );
		$page   = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' );

		Source_Edges::flush_cache();
		$this->assertSame( [ 'post:' . $synced ], Source_Edges::get( 'post:' . $page ) );
		$this->assertSame( [ 'post:' . $page ], Source_Edges::parents( 'post:' . $synced ) );
	}
}
