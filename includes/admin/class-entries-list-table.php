<?php
/**
 * The Entries list table.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Entries_List_Table
 *
 * A WP_List_Table like Posts or Comments: status views with counts, a form filter, a sortable date, pagination with a
 * Screen Options page size and row actions. No bulk actions and no checkboxes in 1.0. The page of entries comes from
 * Entries_Repository::query() and Entry_Presenter::list_items() (four queries); the counts for the views and the forms of
 * the filter come from one more, and the titles of the filter's forms from one or three.
 *
 * Loaded on the Entries screen only (Entries_Screen::load()), after wp-admin/includes/class-wp-list-table.php.
 */
class Entries_List_Table extends \WP_List_Table {

	/**
	 * The views: slug => [ repository status, only starred ].
	 */
	const VIEWS = [
		'all'     => [ '', false ],
		'unread'  => [ 'new', false ],
		'read'    => [ 'read', false ],
		'starred' => [ '', true ],
		'spam'    => [ 'spam', false ],
		'trash'   => [ 'trash', false ],
	];

	/**
	 * The current view slug.
	 *
	 * @var string
	 */
	private $view = 'all';

	/**
	 * The form filter (effective formId or '').
	 *
	 * @var string
	 */
	private $form = '';

	/**
	 * Sort direction of the date: 'asc' or 'desc'.
	 *
	 * @var string
	 */
	private $order = 'desc';

	/**
	 * Entry counts per form and status, with stars.
	 *
	 * @var array<string, array<string, array{total:int, starred:int}>>
	 */
	private $counts = [];

	/**
	 * Titles of the forms that have entries.
	 *
	 * @var array<string, string>
	 */
	private $form_titles = [];

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Read the request, the counts and one page of entries.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->read_request();

		$this->counts      = Entries_Repository::counts_with_stars();
		$this->form_titles = Entry_Presenter::form_titles( array_keys( $this->counts ) );

		$per_page = $this->per_page();
		$args     = [
			'form'     => $this->form,
			'status'   => self::VIEWS[ $this->view ][0],
			'starred'  => self::VIEWS[ $this->view ][1],
			'order'    => $this->order,
			'page'     => $this->get_pagenum(),
			'per_page' => $per_page,
		];
		$page     = Entries_Repository::query( $args );

		// A page past the end (a bookmarked link after deleting entries): show the last one.
		$last = max( 1, (int) ceil( $page['total'] / $per_page ) );
		if ( $page['total'] > 0 && ! $page['rows'] ) {
			$args['page']      = $last;
			$page              = Entries_Repository::query( $args );
			$_REQUEST['paged'] = $last;
		}

		$this->items = Entry_Presenter::list_items( $page );
		$this->set_pagination_args(
			[
				'total_items' => $page['total'],
				'per_page'    => $per_page,
			]
		);
	}

	/**
	 * Entries per page: the Screen Options value, 1 to 100.
	 *
	 * @return int
	 */
	private function per_page() {
		return max( 1, min( Entries_Screen::PER_PAGE_MAX, (int) $this->get_items_per_page( Entries_Screen::PER_PAGE_OPTION, Entries_Screen::PER_PAGE_DEFAULT ) ) );
	}

	/**
	 * Read the view, form and order from the query string (each validated).
	 *
	 * @return void
	 */
	private function read_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and sorting of a read-only list.
		$view = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$form = isset( $_GET['form'] ) && is_string( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		$sort = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		// phpcs:enable

		$this->view  = isset( self::VIEWS[ $view ] ) ? $view : 'all';
		$this->form  = Uuid::is_valid( $form ) ? $form : '';
		$this->order = 'asc' === $sort ? 'asc' : 'desc';
	}

	/**
	 * Hidden fields the filter form keeps: the view and the sort.
	 *
	 * @return array<string, string>
	 */
	public function kept_arguments() {
		$kept = [];
		if ( 'all' !== $this->view ) {
			$kept['status'] = $this->view;
		}
		if ( 'asc' === $this->order ) {
			$kept['orderby'] = 'date';
			$kept['order']   = 'asc';
		}
		return $kept;
	}

	/**
	 * The columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'starred' => '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Starred', 'swiftforms' ) . '</span>',
			'entry'   => esc_html__( 'Entry', 'swiftforms' ),
			'form'    => esc_html__( 'Form', 'swiftforms' ),
			'date'    => esc_html__( 'Date', 'swiftforms' ),
		];
	}

	/**
	 * The date sorts both ways; newest first by default.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	protected function get_sortable_columns() {
		return [
			'date' => [ 'date', true, __( 'Date', 'swiftforms' ), __( 'Table ordered by Date.', 'swiftforms' ), 'desc' ],
		];
	}

	/**
	 * The column that carries the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'entry';
	}

	/**
	 * The views (All, Unread, Read, Starred, Spam, Trash) with counts, over all forms.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$totals = [
			'all'     => 0,
			'unread'  => 0,
			'read'    => 0,
			'starred' => 0,
			'spam'    => 0,
			'trash'   => 0,
		];
		foreach ( $this->counts as $by_status ) {
			foreach ( $by_status as $status => $count ) {
				if ( 'new' === $status || 'read' === $status ) {
					$totals['all']                                   += $count['total'];
					$totals['starred']                               += $count['starred'];
					$totals[ 'new' === $status ? 'unread' : 'read' ] += $count['total'];
				} elseif ( isset( $totals[ $status ] ) ) {
					$totals[ $status ] += $count['total'];
				}
			}
		}

		$labels = [
			'all'     => __( 'All', 'swiftforms' ),
			'unread'  => __( 'Unread', 'swiftforms' ),
			'read'    => __( 'Read', 'swiftforms' ),
			'starred' => __( 'Starred', 'swiftforms' ),
			'spam'    => __( 'Spam', 'swiftforms' ),
			'trash'   => __( 'Trash', 'swiftforms' ),
		];

		$views = [];
		foreach ( $labels as $slug => $label ) {
			$url            = Entries_Screen::list_url( 'all' === $slug ? [] : [ 'status' => $slug ] );
			$current        = $slug === $this->view ? ' class="current" aria-current="page"' : '';
			$views[ $slug ] = '<a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $totals[ $slug ] ) ) . ')</span></a>';
		}
		return $views;
	}

	/**
	 * The form filter above the table.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$titles = $this->form_titles;
		asort( $titles, SORT_NATURAL | SORT_FLAG_CASE );
		?>
		<div class="alignleft actions">
			<label for="swiftforms-filter-form" class="screen-reader-text"><?php esc_html_e( 'Filter by form', 'swiftforms' ); ?></label>
			<select name="form" id="swiftforms-filter-form">
				<option value=""><?php esc_html_e( 'All forms', 'swiftforms' ); ?></option>
				<?php foreach ( $titles as $form_id => $title ) : ?>
					<option value="<?php echo esc_attr( $form_id ); ?>"<?php selected( $this->form, $form_id ); ?>><?php echo esc_html( self::form_label( $title ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'swiftforms' ), '', 'filter_action', false, [ 'id' => 'swiftforms-filter-submit' ] ); ?>
		</div>
		<?php
	}

	/**
	 * The tablenav without the bulk actions nonce (a GET form needs none and 1.0 has no bulk actions).
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'bottom' === $which && ! $this->has_items() ) {
			return;
		}
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			?>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * The message of an empty table.
	 *
	 * @return void
	 */
	public function no_items() {
		$has_any = false;
		foreach ( $this->counts as $by_status ) {
			if ( $by_status ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			esc_html_e( 'No entries yet. Add a Form block to any page.', 'swiftforms' );
			return;
		}
		esc_html_e( 'No entries found.', 'swiftforms' );
	}

	/**
	 * One row, classed by its state so an unread entry reads bold, like an unapproved comment.
	 *
	 * @param array<string, mixed> $item Entry_Presenter::list_items() item.
	 * @return void
	 */
	public function single_row( $item ) {
		$classes = [ 'swiftforms-entry-row', 'swiftforms-entry-' . $item['status'] ];
		if ( 'new' === $item['status'] ) {
			$classes[] = 'unread';
		}
		if ( $item['starred'] ) {
			$classes[] = 'starred';
		}
		echo '<tr id="entry-' . esc_attr( (string) $item['id'] ) . '" class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Starred indicator.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	protected function column_starred( $item ) {
		if ( ! $item['starred'] ) {
			return '';
		}
		return '<span class="dashicons dashicons-star-filled swiftforms-star" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Starred', 'swiftforms' ) . '</span>';
	}

	/**
	 * The two-value summary as a link to the entry.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	protected function column_entry( $item ) {
		$id = (int) $item['id'];
		/* translators: %d: entry number. */
		$text = '' !== $item['summary'] ? Entry_Presenter::visible_text( $item['summary'] ) : sprintf( __( 'Entry #%d', 'swiftforms' ), $id );
		$out  = '<strong><a class="row-title" href="' . esc_url( Entries_Screen::view_url( $id ) ) . '">' . esc_html( $text ) . '</a>';
		if ( 'new' === $item['status'] ) {
			$out .= '<span class="screen-reader-text"> (' . esc_html__( 'unread', 'swiftforms' ) . ')</span>';
		}
		return $out . '</strong>';
	}

	/**
	 * The form, linked to the list filtered by it.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	protected function column_form( $item ) {
		return '<a href="' . esc_url( Entries_Screen::list_url( [ 'form' => (string) $item['form_id'] ] ) ) . '">' . esc_html( self::form_label( (string) $item['form_title'] ) ) . '</a>';
	}

	/**
	 * The date: "5 hours ago" for the last day, else the site's date format; the full time on hover.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	protected function column_date( $item ) {
		$time = strtotime( (string) $item['date_gmt'] );
		if ( false === $time ) {
			return '';
		}
		$now  = Clock::now();
		$diff = $now - $time;
		if ( $diff >= 0 && $diff < DAY_IN_SECONDS ) {
			/* translators: %s: time since the entry, for example "5 hours". */
			$text = sprintf( __( '%s ago', 'swiftforms' ), human_time_diff( $time, $now ) );
		} else {
			$text = wp_date( (string) get_option( 'date_format' ), $time );
		}
		return '<time datetime="' . esc_attr( (string) $item['date_gmt'] ) . '" title="' . esc_attr( (string) $item['date_display'] ) . '">' . esc_html( (string) $text ) . '</time>';
	}

	/**
	 * Anything a subclass or filter adds.
	 *
	 * @param array<string, mixed> $item        Item.
	 * @param string               $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );
		return '';
	}

	/**
	 * Row actions under the primary column: View, then what the entry's status and the user's capabilities allow.
	 *
	 * @param array<string, mixed> $item        Item.
	 * @param string               $column_name Column.
	 * @param string               $primary     Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id      = (int) $item['id'];
		$actions = [
			'view' => '<a href="' . esc_url( Entries_Screen::view_url( $id ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %d: entry number. */ __( 'View entry #%d', 'swiftforms' ), $id ) ) . '">' . esc_html__( 'View', 'swiftforms' ) . '</a>',
		];

		foreach ( Entries_Screen::available_actions( (string) $item['status'], (bool) $item['starred'] ) as $key => $label ) {
			$actions[ $key ] = '<a href="' . esc_url( Entries_Screen::action_url( $key, $id ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: 1: action, 2: entry number. */ __( '%1$s entry #%2$d', 'swiftforms' ), $label, $id ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $this->row_actions( $actions );
	}

	/**
	 * A form's title for display.
	 *
	 * @param string $title Title, possibly empty.
	 * @return string
	 */
	private static function form_label( $title ) {
		return '' !== $title ? Entry_Presenter::visible_text( $title ) : __( '(no title)', 'swiftforms' );
	}
}
