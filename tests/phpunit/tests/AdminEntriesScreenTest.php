<?php
/**
 * Tests for the Entries screen: the list table, the single-entry screen and the actions.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-admin-test-case.php';

use SwiftForms\Database;
use SwiftForms\Entries_Repository;
use SwiftForms\Entries_Screen;
use SwiftForms\Entry_Presenter;

/**
 * Class AdminEntriesScreenTest
 *
 * @group admin
 * @group security
 */
class AdminEntriesScreenTest extends SF_Admin_Test_Case {

	/**
	 * The nasty corpus of config 05.
	 */
	const SCRIPT     = '<script>window.__sfxss = "script"</script>';
	const JAVASCRIPT = 'javascript:window.__sfxss="url"';
	const ATTRIBUTE  = '"><svg onload="window.__sfxss=\'svg\'">';

	/**
	 * Capture redirects and dies.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->capture_exits();
	}

	/**
	 * Leave no request behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET                    = [];
		$_REQUEST                = [];
		$_SERVER['HTTP_REFERER'] = '';
		unset( $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
	}

	/**
	 * Load and render the Entries screen for a query string, the way admin.php does.
	 *
	 * @param array<string, string|int> $get Query arguments (page is added).
	 * @return string The page.
	 */
	private function screen( array $get = [] ) {
		$get      = array_merge( [ 'page' => 'swiftforms' ], $get );
		$_GET     = $get;
		$_REQUEST = $get;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The hook suffix of the screen being shown.
		$GLOBALS['hook_suffix'] = 'toplevel_page_swiftforms';
		set_current_screen( 'toplevel_page_swiftforms' );
		Entries_Screen::load();
		ob_start();
		Entries_Screen::render();
		return (string) ob_get_clean();
	}

	/**
	 * Run an action with a nonce (or a given nonce value, or none when false) and return the exit.
	 *
	 * @param string          $action Action.
	 * @param int             $id     Entry ID.
	 * @param array           $extra  More query arguments.
	 * @param string|bool|int $nonce  true: a good nonce; a string: that value; false: none.
	 * @return SF_Test_Redirect|SF_Test_Die
	 */
	private function act( $action, $id, array $extra = [], $nonce = true ) {
		$get = array_merge(
			[
				'action' => $action,
				'entry'  => $id,
			],
			$extra
		);
		if ( true === $nonce ) {
			$get['_wpnonce'] = wp_create_nonce( 'swiftforms-' . $action . '-entry_' . $id );
		} elseif ( false !== $nonce ) {
			$get['_wpnonce'] = $nonce;
		}
		try {
			$this->screen( $get );
		} catch ( SF_Test_Redirect $exit ) {
			return $exit;
		} catch ( SF_Test_Die $exit ) {
			return $exit;
		}
		$this->fail( 'The action neither redirected nor died.' );
	}

	/**
	 * The query arguments of a redirect.
	 *
	 * @param SF_Test_Redirect $exit Redirect.
	 * @return array<string, string>
	 */
	private function query_of( SF_Test_Redirect $exit ) {
		parse_str( (string) wp_parse_url( $exit->location, PHP_URL_QUERY ), $query );
		return $query;
	}

	/**
	 * The entries (rows) in a rendered list, in order, as their IDs.
	 *
	 * @param string $html Page.
	 * @return int[]
	 */
	private function row_ids( $html ) {
		preg_match_all( '/<tr id="entry-(\d+)"/', $html, $found );
		return array_map( 'intval', $found[1] );
	}

	/**
	 * The status of an entry.
	 *
	 * @param int $id Entry ID.
	 * @return string
	 */
	private function status( $id ) {
		return (string) Entries_Repository::get( $id )['status'];
	}

	/**
	 * Views with counts, spam and trash left out of All, and what each view lists.
	 *
	 * @return void
	 */
	public function test_views_and_counts() {
		$unread  = $this->add_entry( [ 'name' => 'Una Unread' ] );
		$read    = $this->add_entry( [ 'name' => 'Rhea Read' ], [], 'read' );
		$starred = $this->add_entry( [ 'name' => 'Stella Starred' ], [], 'read' );
		Entries_Repository::update( $starred, [ 'starred' => true ] );
		$spam  = $this->add_entry( [ 'name' => 'Sam Spam' ], [ 'status' => 'spam' ] );
		$trash = $this->add_entry( [ 'name' => 'Tara Trash' ], [], 'trash' );
		$this->be_admin();

		$html = $this->screen();
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Entries</h1>', $html );
		$this->assertStringContainsString( 'class=\'subsubsub\'', $html );
		$this->assertMatchesRegularExpression( '/class="current" aria-current="page">All <span class="count">\(3\)<\/span>/', $html );
		foreach ( [
			'Unread'  => 1,
			'Read'    => 2,
			'Starred' => 1,
			'Spam'    => 1,
			'Trash'   => 1,
		] as $label => $count ) {
			$this->assertMatchesRegularExpression( '/>' . $label . ' <span class="count">\(' . $count . '\)<\/span>/', $html, $label );
		}
		$this->assertEqualsCanonicalizing( [ $unread, $read, $starred ], $this->row_ids( $html ), 'All lists new and read.' );

		$this->assertSame( [ $unread ], $this->row_ids( $this->screen( [ 'status' => 'unread' ] ) ) );
		$this->assertEqualsCanonicalizing( [ $read, $starred ], $this->row_ids( $this->screen( [ 'status' => 'read' ] ) ) );
		$this->assertSame( [ $starred ], $this->row_ids( $this->screen( [ 'status' => 'starred' ] ) ) );
		$this->assertSame( [ $spam ], $this->row_ids( $this->screen( [ 'status' => 'spam' ] ) ) );
		$this->assertSame( [ $trash ], $this->row_ids( $this->screen( [ 'status' => 'trash' ] ) ) );
		$current = $this->screen( [ 'status' => 'trash' ] );
		$this->assertMatchesRegularExpression( '/class="current" aria-current="page">Trash/', $current );
		$this->assertDoesNotMatchRegularExpression( '/class="current" aria-current="page">All/', $current );
		$this->assertCount( 3, $this->row_ids( $this->screen( [ 'status' => 'nonsense' ] ) ), 'An unknown view is All.' );
	}

	/**
	 * Empty state, and the message of an empty filter.
	 *
	 * @return void
	 */
	public function test_empty_states() {
		$this->be_admin();
		$this->assertStringContainsString( 'No entries yet. Add a Form block to any page.', $this->screen() );

		$this->add_entry();
		$html = $this->screen( [ 'status' => 'spam' ] );
		$this->assertStringContainsString( 'No entries found.', $html );
		$this->assertStringNotContainsString( 'No entries yet.', $html );
	}

	/**
	 * The form filter lists the forms that have entries and narrows the list.
	 *
	 * @return void
	 */
	public function test_form_filter() {
		$first = $this->form_id;
		$a     = $this->add_entry( [ 'name' => 'First Form Person' ] );
		$this->make_form();
		$second = $this->form_id;
		$b      = $this->add_entry( [ 'name' => 'Second Form Person' ] );
		$this->be_admin();

		$html = $this->screen();
		$this->assertStringContainsString( '<option value="' . $first . '"', $html );
		$this->assertStringContainsString( '<option value="' . $second . '"', $html );
		$this->assertStringContainsString( 'name="filter_action"', $html );
		$this->assertEqualsCanonicalizing( [ $a, $b ], $this->row_ids( $html ) );

		$filtered = $this->screen( [ 'form' => $second ] );
		$this->assertSame( [ $b ], $this->row_ids( $filtered ) );
		$this->assertStringContainsString( '<option value="' . $second . '" selected=\'selected\'>', $filtered );
		$this->assertSame( [ $a, $b ], array_reverse( $this->row_ids( $this->screen( [ 'form' => 'not-a-form-id' ] ) ) ), 'An invalid form id is ignored.' );
	}

	/**
	 * Pagination, the per-page screen option and past-the-end pages.
	 *
	 * @return void
	 */
	public function test_pagination_and_per_page() {
		$ids = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->add_entry( [ 'name' => 'Person ' . $i ], [ 'created_gmt' => '2030-01-0' . $i . ' 10:00:00' ] );
		}
		$user = $this->be_admin();

		$this->assertCount( 5, $this->row_ids( $this->screen() ), 'Twenty is the default size.' );

		update_user_meta( $user, Entries_Screen::PER_PAGE_OPTION, 2 );
		$page1 = $this->screen();
		$this->assertSame( [ $ids[4], $ids[3] ], $this->row_ids( $page1 ) );
		$this->assertStringContainsString( 'class="displaying-num">5 items', $page1 );
		$this->assertStringContainsString( 'class=\'total-pages\'>3', $page1 );
		$this->assertSame( [ $ids[2], $ids[1] ], $this->row_ids( $this->screen( [ 'paged' => 2 ] ) ) );
		$this->assertSame( [ $ids[0] ], $this->row_ids( $this->screen( [ 'paged' => 3 ] ) ) );
		$this->assertSame( [ $ids[0] ], $this->row_ids( $this->screen( [ 'paged' => 99 ] ) ), 'A page past the end shows the last page.' );

		// The option is validated: 1 to 100.
		$this->assertSame( 100, Entries_Screen::save_per_page( false, Entries_Screen::PER_PAGE_OPTION, '5000' ) );
		$this->assertSame( 1, Entries_Screen::save_per_page( false, Entries_Screen::PER_PAGE_OPTION, '-4' ) );
		$this->assertSame( 35, Entries_Screen::save_per_page( false, Entries_Screen::PER_PAGE_OPTION, '35' ) );
		$this->assertNotFalse( has_filter( 'set_screen_option_' . Entries_Screen::PER_PAGE_OPTION, [ Entries_Screen::class, 'save_per_page' ] ) );
	}

	/**
	 * The date sorts both ways, newest first by default.
	 *
	 * @return void
	 */
	public function test_sorting_by_date() {
		$old = $this->add_entry( [ 'name' => 'Old' ], [ 'created_gmt' => '2030-01-01 10:00:00' ] );
		$mid = $this->add_entry( [ 'name' => 'Mid' ], [ 'created_gmt' => '2030-01-02 10:00:00' ] );
		$new = $this->add_entry( [ 'name' => 'New' ], [ 'created_gmt' => '2030-01-03 10:00:00' ] );
		$this->be_admin();

		$default = $this->screen();
		$this->assertSame( [ $new, $mid, $old ], $this->row_ids( $default ) );
		$this->assertMatchesRegularExpression( "/<th[^>]*class='[^']*column-date sorted desc'/", $default );
		$this->assertSame(
			[ $old, $mid, $new ],
			$this->row_ids(
				$this->screen(
					[
						'orderby' => 'date',
						'order'   => 'asc',
					]
				)
			)
		);
		$this->assertSame( [ $new, $mid, $old ], $this->row_ids( $this->screen( [ 'order' => 'sideways' ] ) ) );
	}

	/**
	 * Unread rows are classed and starred rows carry the indicator with screen-reader text.
	 *
	 * @return void
	 */
	public function test_unread_and_starred_markup() {
		$unread  = $this->add_entry( [ 'name' => 'Una Unread' ] );
		$read    = $this->add_entry( [ 'name' => 'Rhea Read' ], [], 'read' );
		$starred = $this->add_entry( [ 'name' => 'Stella Starred' ] );
		Entries_Repository::update( $starred, [ 'starred' => true ] );
		$this->be_admin();

		$html = $this->screen();
		$this->assertMatchesRegularExpression( '/<tr id="entry-' . $unread . '" class="[^"]*\bunread\b/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<tr id="entry-' . $read . '" class="[^"]*\bunread\b/', $html );
		$this->assertMatchesRegularExpression( '/<tr id="entry-' . $starred . '" class="[^"]*\bstarred\b/', $html );
		$row = $this->row_html( $html, $starred );
		$this->assertStringContainsString( 'dashicons-star-filled', $row );
		$this->assertStringContainsString( '<span class="screen-reader-text">Starred</span>', $row );
		$this->assertStringNotContainsString( 'swiftforms-star', $this->row_html( $html, $read ) );
		$this->assertStringContainsString( '<a class="row-title" href="' . esc_url( Entries_Screen::view_url( $unread ) ) . '">Una Unread · ada@example.com</a>', $this->row_html( $html, $unread ) );
	}

	/**
	 * One rendered row.
	 *
	 * @param string $html Page.
	 * @param int    $id   Entry ID.
	 * @return string
	 */
	private function row_html( $html, $id ) {
		$this->assertSame( 1, preg_match( '/<tr id="entry-' . $id . '".*?<\/tr>/s', $html, $found ), 'Row ' . $id );
		return $found[0];
	}

	/**
	 * Row actions follow the entry's status and the user's capabilities.
	 *
	 * @return void
	 */
	public function test_row_actions_per_status_and_capability() {
		$new     = $this->add_entry( [ 'name' => 'Nina New' ] );
		$read    = $this->add_entry( [ 'name' => 'Rob Read' ], [], 'read' );
		$spam    = $this->add_entry( [ 'name' => 'Sue Spam' ], [ 'status' => 'spam' ] );
		$trash   = $this->add_entry( [ 'name' => 'Tim Trash' ], [], 'trash' );
		$starred = $this->add_entry( [ 'name' => 'Star Star' ] );
		Entries_Repository::update( $starred, [ 'starred' => true ] );
		$this->be_admin();

		$actions = function ( $html, $id ) {
			preg_match_all( '/<a href="[^"]*(?:\?|&#038;)action=([a-z]+)[^"]*"/', $this->row_html( $html, $id ), $found );
			return array_values( array_unique( $found[1] ) );
		};

		$all = $this->screen();
		$this->assertSame( [ 'view', 'read', 'star', 'spam', 'trash' ], $actions( $all, $new ) );
		$this->assertSame( [ 'view', 'unread', 'star', 'spam', 'trash' ], $actions( $all, $read ) );
		$this->assertContains( 'unstar', $actions( $all, $starred ) );
		$this->assertSame( [ 'view', 'unspam', 'trash' ], $actions( $this->screen( [ 'status' => 'spam' ] ), $spam ) );
		$this->assertSame( [ 'view', 'untrash', 'delete' ], $actions( $this->screen( [ 'status' => 'trash' ] ), $trash ) );

		// View only: no action link carries a nonce.
		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$view_only = $this->screen();
		$this->assertSame( [ 'view' ], $actions( $view_only, $new ) );
		$this->assertStringNotContainsString( '_wpnonce', $view_only );

		// Edit but not delete: the Trash view offers Restore only.
		$this->map_caps(
			[
				'swiftforms_view_entries'   => 'edit_posts',
				'swiftforms_edit_entries'   => 'edit_posts',
				'swiftforms_delete_entries' => 'do_not_allow',
			]
		);
		$this->assertSame( [ 'view', 'untrash' ], $actions( $this->screen( [ 'status' => 'trash' ] ), $trash ) );
	}

	/**
	 * Every action changes the entry, redirects to the list with a message and keeps the list's view.
	 *
	 * @return void
	 */
	public function test_actions_change_the_entry_and_redirect_with_a_message() {
		$this->be_admin();
		$cases = [
			// action, status before, starred before, status after, starred after.
			[ 'read', 'new', false, 'read', false ],
			[ 'unread', 'read', false, 'new', false ],
			[ 'star', 'new', false, 'new', true ],
			[ 'unstar', 'new', true, 'new', false ],
			[ 'spam', 'new', false, 'spam', false ],
			[ 'unspam', 'spam', false, 'new', false ],
			[ 'trash', 'read', false, 'trash', false ],
			[ 'untrash', 'trash', false, 'new', false ],
		];
		foreach ( $cases as $case ) {
			list( $action, $before, $star_before, $after, $star_after ) = $case;
			$id = $this->add_entry( [], [ 'status' => 'spam' === $before ? 'spam' : 'new' ], 'spam' === $before ? 'spam' : $before );
			Entries_Repository::update( $id, [ 'starred' => $star_before ] );
			$this->be_admin();

			$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=swiftforms&status=read&paged=2&junk=1' );
			$exit                    = $this->act( $action, $id );
			$this->assertInstanceOf( SF_Test_Redirect::class, $exit, $action );
			$this->assertSame( 302, $exit->status );
			$query = $this->query_of( $exit );
			$this->assertSame( 'swiftforms', $query['page'], $action );
			$this->assertSame( $action, $query['message'], $action );
			$this->assertSame( 'read', $query['status'], 'The view the user came from is kept: ' . $action );
			$this->assertSame( '2', $query['paged'] );
			$this->assertArrayNotHasKey( 'junk', $query );
			$this->assertArrayNotHasKey( 'action', $query );
			$this->assertSame( 0 === strpos( $exit->location, admin_url( 'admin.php' ) ), true );
			$entry = Entries_Repository::get( $id );
			$this->assertSame( $after, $entry['status'], $action );
			$this->assertSame( $star_after ? 1 : 0, $entry['starred'], $action );
		}

		$id = $this->add_entry( [], [], 'trash' );
		$this->be_admin();
		$exit = $this->act( 'delete', $id );
		$this->assertSame( 'delete', $this->query_of( $exit )['message'] );
		$this->assertNull( Entries_Repository::get( $id ) );
	}

	/**
	 * An action from the single-entry screen returns to it, except trash and delete.
	 *
	 * @return void
	 */
	public function test_actions_from_the_entry_screen() {
		$id = $this->add_entry();
		$this->be_admin();

		$exit  = $this->act( 'star', $id, [ 'from' => 'view' ] );
		$query = $this->query_of( $exit );
		$this->assertSame( 'view', $query['action'] );
		$this->assertSame( (string) $id, $query['entry'] );
		$this->assertSame( 'star', $query['message'] );

		$exit = $this->act( 'trash', $id, [ 'from' => 'view' ] );
		$this->assertArrayNotHasKey( 'action', $this->query_of( $exit ), 'Trash goes back to the list.' );
	}

	/**
	 * No nonce, a wrong nonce and another entry's nonce change nothing.
	 *
	 * @return void
	 */
	public function test_actions_need_their_nonce() {
		$id    = $this->add_entry();
		$other = $this->add_entry();
		$this->be_admin();

		foreach ( [ false, 'badbadbad1', wp_create_nonce( 'swiftforms-trash-entry_' . $other ), wp_create_nonce( 'swiftforms-star-entry_' . $id ) ] as $nonce ) {
			$exit = $this->act( 'trash', $id, [], $nonce );
			$this->assertInstanceOf( SF_Test_Die::class, $exit );
			$this->assertSame( 403, $exit->status );
			$this->assertSame( 'new', $this->status( $id ) );
		}
		$this->assertSame( 'new', $this->status( $other ) );
	}

	/**
	 * Each action asks for its own capability.
	 *
	 * @return void
	 */
	public function test_actions_need_their_capability() {
		$id = $this->add_entry( [], [], 'trash' );

		$this->be_role( 'subscriber' );
		foreach ( [ 'read', 'star', 'trash', 'untrash', 'delete' ] as $action ) {
			$exit = $this->act( $action, $id );
			$this->assertInstanceOf( SF_Test_Die::class, $exit, $action );
			$this->assertSame( 403, $exit->status, $action );
		}
		$this->assertSame( 'trash', $this->status( $id ) );

		// A role that may edit but not delete.
		$this->map_caps( [ 'swiftforms_delete_entries' => 'do_not_allow' ] );
		$this->be_admin();
		$this->assertInstanceOf( SF_Test_Die::class, $this->act( 'delete', $id ) );
		$this->assertNotNull( Entries_Repository::get( $id ) );
		$this->assertInstanceOf( SF_Test_Redirect::class, $this->act( 'untrash', $id ) );
	}

	/**
	 * An action on an entry that is gone says so (404).
	 *
	 * @return void
	 */
	public function test_action_on_a_missing_entry() {
		$this->be_admin();
		$exit = $this->act( 'trash', 987654 );
		$this->assertInstanceOf( SF_Test_Die::class, $exit );
		$this->assertSame( 404, $exit->status );
	}

	/**
	 * The notice after an action, with an Undo link that restores the status the entry had.
	 *
	 * @return void
	 */
	public function test_notice_and_undo() {
		$id = $this->add_entry( [], [], 'read' );
		$this->be_admin();

		$exit  = $this->act( 'trash', $id );
		$query = $this->query_of( $exit );
		$this->assertSame( 'trash', $query['message'] );
		$this->assertSame( (string) $id, $query['sf_undo'] );
		$this->assertSame( 'read', $query['sf_to'] );

		$html = $this->screen(
			[
				'message' => 'trash',
				'sf_undo' => $id,
				'sf_to'   => 'read',
			]
		);
		$this->assertStringContainsString( 'class="notice notice-success is-dismissible"', $html );
		$this->assertStringContainsString( 'Entry moved to the Trash.', $html );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*action=untrash[^"]*sf_to=read[^"]*_wpnonce=[0-9a-f]{10}[^"]*">Undo<\/a>/', $html );

		$exit = $this->act( 'untrash', $id, [ 'sf_to' => 'read' ] );
		$this->assertInstanceOf( SF_Test_Redirect::class, $exit );
		$this->assertSame( 'read', $this->status( $id ) );

		$this->assertStringNotContainsString( 'notice-success', $this->screen( [ 'message' => 'nonsense' ] ) );
		$this->assertStringContainsString( 'Entry marked as read.', $this->screen( [ 'message' => 'read' ] ) );
		$this->assertStringNotContainsString( 'Undo', $this->screen( [ 'message' => 'read' ] ) );

		// No Undo for a user who may not edit.
		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$this->assertStringNotContainsString(
			'Undo',
			$this->screen(
				[
					'message' => 'trash',
					'sf_undo' => $id,
				]
			)
		);
	}

	/**
	 * The single-entry screen shows the labels of the entry's snapshot after the form changed.
	 *
	 * @return void
	 */
	public function test_view_uses_the_snapshot_of_the_entry() {
		$id = $this->add_entry();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$inner = $this->field_markup(
			'text',
			[
				'name'  => 'name',
				'label' => 'Full name',
			]
		)
			. '<!-- wp:swiftforms/submit /-->';
		wp_update_post(
			[
				'ID'           => (int) substr( $this->source, 5 ),
				'post_content' => $this->form_markup( $this->form_id, $inner ),
			]
		);
		\SwiftForms\Form_Index::flush_cache();

		$html = $this->screen(
			[
				'action' => 'view',
				'entry'  => $id,
			]
		);
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">', $html );
		$this->assertStringContainsString( 'Entry #' . $id, $html );
		$this->assertStringContainsString( '<th scope="row">Name</th>', $html, 'The label the entry was saved under.' );
		$this->assertStringContainsString( '<th scope="row">Message</th>', $html );
		$this->assertStringNotContainsString( 'Full name', $html );
		$this->assertStringContainsString( '<td>Ada Lovelace</td>', $html );
		$this->assertStringContainsString( '<a href="mailto:ada@example.com">ada@example.com</a>', $html );
		$this->assertStringContainsString( 'Back to Entries', $html );
		$this->assertStringContainsString( 'id="poststuff"', $html );
		$this->assertStringContainsString( 'class="widefat striped swiftforms-entry-fields"', $html );
		$this->assertStringContainsString( 'swiftforms-print', $html );
	}

	/**
	 * Opening a new entry marks it read, unless the user may not edit or an action just ran.
	 *
	 * @return void
	 */
	public function test_opening_an_entry_marks_it_read() {
		$id = $this->add_entry();
		$this->be_admin();

		// An action's redirect (message set) does not mark it read again.
		$this->screen(
			[
				'action'  => 'view',
				'entry'   => $id,
				'message' => 'unread',
			]
		);
		$this->assertSame( 'new', $this->status( $id ) );

		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$this->screen(
			[
				'action' => 'view',
				'entry'  => $id,
			]
		);
		$this->assertSame( 'new', $this->status( $id ), 'Without the edit capability nothing is written.' );

		$this->be_admin();
		$html = $this->screen(
			[
				'action' => 'view',
				'entry'  => $id,
			]
		);
		$this->assertSame( 'read', $this->status( $id ) );
		$this->assertStringContainsString( '<strong class="swiftforms-entry-status">Read</strong>', $html );

		// Spam and trash keep their status.
		$spam = $this->add_entry( [], [ 'status' => 'spam' ] );
		$this->screen(
			[
				'action' => 'view',
				'entry'  => $spam,
			]
		);
		$this->assertSame( 'spam', $this->status( $spam ) );
	}

	/**
	 * A missing entry is a 404 with a way back.
	 *
	 * @return void
	 */
	public function test_view_of_a_missing_entry() {
		$this->be_admin();
		foreach ( [ 987654, 0 ] as $id ) {
			try {
				$this->screen(
					[
						'action' => 'view',
						'entry'  => $id,
					]
				);
				$this->fail( 'Expected a die.' );
			} catch ( SF_Test_Die $die ) {
				$this->assertSame( 404, $die->status );
			}
		}
	}

	/**
	 * IP and user agent only when stored; the source only for a post the user can read.
	 *
	 * @return void
	 */
	public function test_view_extras_and_source_gating() {
		global $wpdb;
		$plain  = $this->add_entry();
		$stored = $this->add_entry();
		$wpdb->update( Database::table( 'entries' ), [ 'user_agent' => 'Agent/1.0 <b>x</b>' ], [ 'id' => $stored ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'entries' ) . ' SET ip = UNHEX(%s) WHERE id = %d', bin2hex( inet_pton( '203.0.113.9' ) ), $stored ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test setup.
		$this->be_admin();

		$view = function ( $id ) {
			return $this->screen(
				[
					'action' => 'view',
					'entry'  => $id,
				]
			);
		};

		$html = $view( $plain );
		$this->assertStringNotContainsString( 'IP address', $html );
		$this->assertStringNotContainsString( 'Browser:', $html );

		$html = $view( $stored );
		$this->assertStringContainsString( '<strong>203.0.113.9</strong>', $html );
		$this->assertStringContainsString( 'Agent/1.0 &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( 'Sent from:', $html );
		$this->assertStringContainsString( esc_url( (string) get_permalink( (int) substr( $this->source, 5 ) ) ), $html );

		wp_update_post(
			[
				'ID'          => (int) substr( $this->source, 5 ),
				'post_status' => 'private',
			]
		);
		$this->map_caps( [ 'swiftforms_view_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$html = $view( $plain );
		$this->assertStringNotContainsString( 'Sent from:', $html, 'No title or link for a post the user cannot read.' );
		$this->assertStringNotContainsString( 'p=' . (int) substr( $this->source, 5 ), $html );
	}

	/**
	 * Print: the box and the notices are hidden by the stylesheet, the table stays.
	 *
	 * @return void
	 */
	public function test_print_stylesheet_hides_everything_but_the_fields() {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/admin/entries.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$this->assertStringContainsString( '@media print', $css );
		$print = substr( $css, (int) strpos( $css, '@media print' ) );
		foreach ( [ '#adminmenumain', '#wpadminbar', '#wpfooter', '#postbox-container-1', '.notice' ] as $hidden ) {
			$this->assertStringContainsString( $hidden, $print );
		}
		$this->assertStringNotContainsString( 'swiftforms-entry-fields', substr( $print, 0, (int) strpos( $print, '{' ) ), 'The fields table is not in the hidden list.' );
	}

	/**
	 * The nasty corpus is text on the list and on the entry screen: no markup, no link, bidi controls visible.
	 *
	 * @return void
	 */
	public function test_visitor_values_are_text_on_the_list_and_the_entry_screen() {
		$script = $this->add_entry(
			[
				'name'    => self::SCRIPT,
				'email'   => 'nasty@example.com',
				'message' => self::JAVASCRIPT,
			]
		);
		$attr   = $this->add_entry(
			[
				'name'    => self::ATTRIBUTE,
				'email'   => 'nasty2@example.com',
				'message' => "evil\u{202E}gnp.exe",
			]
		);
		$link   = $this->add_entry(
			[
				'name'    => 'Link person',
				'email'   => 'link@example.com',
				'message' => 'https://example.com/path?a=b&c=d',
			]
		);
		$this->be_admin();

		$list = $this->screen();
		foreach ( [ '<script>', 'onload="', '<svg' ] as $markup ) {
			$this->assertStringNotContainsString( $markup, $list, 'List: ' . $markup );
		}
		$this->assertStringContainsString( '&lt;script&gt;window.__sfxss = &quot;script&quot;&lt;/script&gt;', $list );
		$this->assertStringNotContainsString( "\u{202E}", $list );

		foreach ( [ $script, $attr, $link ] as $id ) {
			$view = $this->screen(
				[
					'action' => 'view',
					'entry'  => $id,
				]
			);
			foreach ( [ '<script>', '<svg', 'onload="' ] as $markup ) {
				$this->assertStringNotContainsString( $markup, $view, 'Entry ' . $id . ': ' . $markup );
			}
			$this->assertStringNotContainsString( "\u{202E}", $view );
			$this->assertDoesNotMatchRegularExpression( '/href=["\']javascript:/i', $view );
		}

		// The javascript: value is text in a cell with no link in it; the RTL override is a visible marker.
		$view = $this->screen(
			[
				'action' => 'view',
				'entry'  => $script,
			]
		);
		$this->assertMatchesRegularExpression( '/<th scope="row">Message<\/th>\s*<td>javascript:window\.__sfxss=&quot;url&quot;<\/td>/', $view );
		$view = $this->screen(
			[
				'action' => 'view',
				'entry'  => $attr,
			]
		);
		$this->assertStringContainsString( 'evil[U+202E]gnp.exe', $view );

		// An http(s) value is a link with rel and an escaped href; the validated address is a mailto link.
		$view = $this->screen(
			[
				'action' => 'view',
				'entry'  => $link,
			]
		);
		$this->assertStringContainsString( '<a href="https://example.com/path?a=b&#038;c=d" rel="noopener noreferrer" target="_blank">https://example.com/path?a=b&amp;c=d</a>', $view );
		$this->assertStringContainsString( '<a href="mailto:link@example.com">link@example.com</a>', $view );
	}

	/**
	 * Values: lists, line breaks, empty answers and what may become a link.
	 *
	 * @return void
	 */
	public function test_values_html() {
		$text = static function ( array $values, $link = false, $email = false ) {
			$items = [];
			foreach ( $values as $value ) {
				$items[] = [
					'value'    => $value,
					'is_link'  => $link,
					'is_email' => $email,
				];
			}
			return Entries_Screen::values_html( [ 'values' => $items ] );
		};

		$this->assertSame( '<ul class="swiftforms-values"><li>Red</li><li>Blue &amp; green</li></ul>', $text( [ 'Red', 'Blue & green' ] ) );
		$this->assertSame( "Line one<br />\nLine two", $text( [ "Line one\nLine two" ] ) );
		$this->assertStringContainsString( 'No answer', $text( [] ) );
		$this->assertStringContainsString( 'No answer', $text( [ '' ] ) );
		$this->assertSame( '&lt;i&gt;x&lt;/i&gt;', $text( [ '<i>x</i>' ] ) );
		$this->assertSame( 'a[U+202E]b[U+2066]c', $text( [ "a\u{202E}b\u{2066}c" ] ) );
		$this->assertStringNotContainsString( '<a ', $text( [ 'javascript:alert(1)' ] ), 'Not flagged as a link: text.' );
		// Even if a value were flagged, esc_url drops a scheme that is not http or https.
		$this->assertStringNotContainsString( 'javascript', (string) preg_replace( '/>[^<]*</', '><', $text( [ 'javascript:alert(1)' ], true ) ) );
		$this->assertStringContainsString( 'href="mailto:a@b.co"', $text( [ 'a@b.co' ], false, true ) );
	}

	/**
	 * The bidirectional control characters become visible markers; other text is untouched.
	 *
	 * @return void
	 */
	public function test_visible_text() {
		$this->assertSame( 'evil[U+202E]gnp.exe', Entry_Presenter::visible_text( "evil\u{202E}gnp.exe" ) );
		$this->assertSame( '[U+2066]x[U+2069][U+200E][U+200F][U+061C]', Entry_Presenter::visible_text( "\u{2066}x\u{2069}\u{200E}\u{200F}\u{061C}" ) );
		$this->assertSame( '<script>alert(1)</script> Grüße 你好', Entry_Presenter::visible_text( '<script>alert(1)</script> Grüße 你好' ) );
		$this->assertSame( '', Entry_Presenter::visible_text( null ) );
		$this->assertSame( '', Entry_Presenter::visible_text( 5 ) );
		$this->assertSame( '', Entry_Presenter::visible_text( "a\xB1b" ), 'Invalid UTF-8 gives nothing (wp_check_invalid_utf8).' );
	}

	/**
	 * A page of the list costs the repository and presenter four queries; the whole screen at most seven.
	 *
	 * @return void
	 */
	public function test_query_counts() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->add_entry( [ 'name' => 'Person ' . $i ] );
		}
		$this->be_admin();
		Entry_Presenter::flush();

		$queries = [];
		$spy     = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, 'swiftforms_' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$html = $this->screen( [ 'form' => $this->form_id ] );
		remove_filter( 'query', $spy );
		$this->assertCount( 20, $this->row_ids( $html ) );
		$this->assertLessThanOrEqual( 7, count( $queries ), implode( "\n", $queries ) );

		$queries = [];
		Entry_Presenter::flush();
		add_filter( 'query', $spy );
		Entry_Presenter::list_items( Entries_Repository::query( [ 'form' => $this->form_id ] ) );
		remove_filter( 'query', $spy );
		$this->assertLessThanOrEqual( 4, count( $queries ), implode( "\n", $queries ) );
	}

	/**
	 * The repository sorts both ways and filters starred entries.
	 *
	 * @return void
	 */
	public function test_repository_order_and_starred() {
		$a = $this->add_entry( [], [ 'created_gmt' => '2030-01-01 10:00:00' ] );
		$b = $this->add_entry( [], [ 'created_gmt' => '2030-01-02 10:00:00' ] );
		Entries_Repository::update( $a, [ 'starred' => true ] );

		$this->assertSame( [ $a, $b ], array_map( 'intval', wp_list_pluck( Entries_Repository::query( [ 'order' => 'asc' ] )['rows'], 'id' ) ) );
		$this->assertSame( [ $b, $a ], array_map( 'intval', wp_list_pluck( Entries_Repository::query( [ 'order' => 'DESC' ] )['rows'], 'id' ) ) );
		$this->assertSame( [ $b, $a ], array_map( 'intval', wp_list_pluck( Entries_Repository::query( [ 'order' => 'x; DROP' ] )['rows'], 'id' ) ) );
		$this->assertSame( [ $a ], array_map( 'intval', wp_list_pluck( Entries_Repository::query( [ 'starred' => true ] )['rows'], 'id' ) ) );

		$counts = Entries_Repository::counts_with_stars();
		$this->assertSame(
			[
				'total'   => 2,
				'starred' => 1,
			],
			$counts[ $this->form_id ]['new']
		);
	}

	/**
	 * The form filter shows the title kept in the snapshot of a deleted form.
	 *
	 * @return void
	 */
	public function test_form_titles_for_a_deleted_form() {
		$this->add_entry();
		wp_delete_post( (int) substr( $this->source, 5 ), true );
		\SwiftForms\Form_Index::flush_cache();
		Entry_Presenter::flush();

		$titles = Entry_Presenter::form_titles( [ $this->form_id, 'not-a-uuid' ] );
		$this->assertSame( [ $this->form_id ], array_keys( $titles ) );
		$this->be_admin();
		$this->assertStringContainsString( '<option value="' . $this->form_id . '"', $this->screen() );
	}
	/**
	 * The summary skips consent ("1") and hidden values: they don't say whose entry it is.
	 *
	 * @return void
	 */
	public function test_summary_skips_consent_and_hidden_values() {
		$fields = [];
		foreach ( [
			'ref'   => 'hidden',
			'agree' => 'consent',
			'email' => 'email',
			'name'  => 'text',
		] as $name => $type ) {
			$fields[] = array_merge( [ 'type' => $type ], \SwiftForms\Field_Types::defaults( $type ), [ 'name' => $name ] );
		}
		$schema = new \SwiftForms\Form_Schema(
			[
				'form_id' => '5f990000-0000-4000-8000-000000000001',
				'source'  => 'post:1',
				'fields'  => $fields,
			]
		);
		$values = [
			'ref'   => [ '42' ],
			'agree' => [ '1' ],
			'email' => [ 'jonas@example.com' ],
			'name'  => [ 'Jonas' ],
		];
		$this->assertSame( 'jonas@example.com · Jonas', \SwiftForms\Entry_Presenter::summary( $schema, $values ) );
	}
}
