<?php
/**
 * The Entries screen: the list table, the single-entry screen and the actions on an entry.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Entries_Screen
 *
 * Forms → Entries is a standard WordPress list table (Entries_List_Table) and, behind a link, one screen per entry
 * (admin.php?page=swiftforms&action=view&entry=ID) laid out like an edit screen. The controller is hooked to
 * "load-{hook}" so an action runs before any output: every action is a GET link with a nonce of its own
 * (swiftforms-{action}-entry_{id}), asks Access::can() for the capability it needs, changes the entry through
 * Entries_Repository and redirects back with a "message" query argument that becomes a dismissible notice.
 * Trash and spam notices carry an Undo link.
 *
 * Visitor values reach the page only through escaping (esc_html, esc_url, esc_attr) after Entry_Presenter::visible_text().
 * Opening a new entry marks it as read when the user may edit entries: the same as most form plugins do, so the unread
 * count means "not opened yet". An action's redirect (message in the URL) never marks anything read.
 */
class Entries_Screen {

	/**
	 * The page (menu slug).
	 */
	const PAGE = 'swiftforms';

	/**
	 * The user option behind "Number of items per page".
	 */
	const PER_PAGE_OPTION = 'swiftforms_entries_per_page';

	/**
	 * Entries per page unless the user chose otherwise.
	 */
	const PER_PAGE_DEFAULT = 20;

	/**
	 * Most entries per page (the repository's own limit).
	 */
	const PER_PAGE_MAX = 100;

	/**
	 * The actions on an entry: the capability each needs.
	 */
	const ACTIONS = [
		'read'    => 'swiftforms_edit_entries',
		'unread'  => 'swiftforms_edit_entries',
		'star'    => 'swiftforms_edit_entries',
		'unstar'  => 'swiftforms_edit_entries',
		'spam'    => 'swiftforms_edit_entries',
		'unspam'  => 'swiftforms_edit_entries',
		'trash'   => 'swiftforms_edit_entries',
		'untrash' => 'swiftforms_edit_entries',
		'delete'  => 'swiftforms_delete_entries',
	];

	/**
	 * The list table of this request, built on load so Screen Options can list its columns.
	 *
	 * @var Entries_List_Table|null
	 */
	private static $table = null;

	/**
	 * The entry of the single-entry screen, as Entries_Repository::get() returns it.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $entry = null;

	/**
	 * Hook what does not depend on the screen being shown.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, [ self::class, 'save_per_page' ], 10, 3 );
		add_filter( 'removable_query_args', [ self::class, 'removable_query_args' ] );
	}

	/**
	 * Validate the "Number of items per page" screen option.
	 *
	 * @param mixed  $keep   The value to keep (false: nothing).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return int
	 */
	public static function save_per_page( $keep, $option, $value ) {
		unset( $keep, $option );
		return max( 1, min( self::PER_PAGE_MAX, (int) $value ) );
	}

	/**
	 * Query arguments WordPress may strip from the address bar after a notice.
	 *
	 * @param string[] $args Argument names.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : [];
		$args[] = 'sf_undo';
		$args[] = 'sf_to';
		return $args;
	}

	/**
	 * The "load-{hook}" callback: run an action, or prepare the list or the entry screen.
	 *
	 * @return void
	 */
	public static function load() {
		self::$table = null;
		self::$entry = null;

		$action = self::request_action();

		if ( isset( self::ACTIONS[ $action ] ) ) {
			self::run_action( $action );
			return;
		}

		if ( 'view' === $action ) {
			self::load_view();
			return;
		}

		self::load_list();
	}

	/**
	 * The page callback.
	 *
	 * @return void
	 */
	public static function render() {
		if ( null !== self::$entry ) {
			self::render_view( self::$entry );
			return;
		}
		self::render_list();
	}

	/**
	 * Styles of the screen.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$path = plugin_dir_path( SWIFTFORMS_FILE ) . 'includes/admin/entries.css';
		wp_enqueue_style( 'swiftforms-admin-entries', plugins_url( 'includes/admin/entries.css', SWIFTFORMS_FILE ), [ 'dashicons' ], is_readable( $path ) ? (string) filemtime( $path ) : SWIFTFORMS_VERSION );
		wp_style_add_data( 'swiftforms-admin-entries', 'rtl', 'replace' );
	}

	/**
	 * URL of the list, with some query arguments (empty values are left out).
	 *
	 * @param array<string, string|int> $args Arguments.
	 * @return string
	 */
	public static function list_url( array $args = [] ) {
		$args = array_filter(
			$args,
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of the screen of one entry.
	 *
	 * @param int $id Entry ID.
	 * @return string
	 */
	public static function view_url( $id ) {
		return add_query_arg(
			[
				'page'   => self::PAGE,
				'action' => 'view',
				'entry'  => (int) $id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of an action on an entry, with its nonce. Not escaped: the caller uses esc_url().
	 *
	 * @param string               $action Action (a key of ACTIONS).
	 * @param int                  $id     Entry ID.
	 * @param array<string, mixed> $extra  More arguments.
	 * @return string
	 */
	public static function action_url( $action, $id, array $extra = [] ) {
		$url = add_query_arg(
			array_merge(
				[
					'page'   => self::PAGE,
					'action' => $action,
					'entry'  => (int) $id,
				],
				$extra
			),
			admin_url( 'admin.php' )
		);
		return add_query_arg( '_wpnonce', wp_create_nonce( self::nonce_action( $action, $id ) ), $url );
	}

	/**
	 * Nonce action of an action on an entry.
	 *
	 * @param string $action Action.
	 * @param int    $id     Entry ID.
	 * @return string
	 */
	private static function nonce_action( $action, $id ) {
		return 'swiftforms-' . $action . '-entry_' . (int) $id;
	}

	/**
	 * The actions an entry offers the current user, in order: key => label.
	 *
	 * @param string $status  Entry status (new, read, spam, trash).
	 * @param bool   $starred Whether it is starred.
	 * @return array<string, string>
	 */
	public static function available_actions( $status, $starred ) {
		$out  = [];
		$edit = Access::can( 'swiftforms_edit_entries' );

		if ( $edit ) {
			if ( 'new' === $status ) {
				$out['read'] = __( 'Mark as read', 'swiftforms' );
			} elseif ( 'read' === $status ) {
				$out['unread'] = __( 'Mark as unread', 'swiftforms' );
			}
			if ( 'new' === $status || 'read' === $status ) {
				$out[ $starred ? 'unstar' : 'star' ] = $starred ? __( 'Unstar', 'swiftforms' ) : __( 'Star', 'swiftforms' );
				$out['spam']                         = __( 'Spam', 'swiftforms' );
			}
			if ( 'spam' === $status ) {
				$out['unspam'] = __( 'Not spam', 'swiftforms' );
			}
			if ( 'trash' === $status ) {
				$out['untrash'] = __( 'Restore', 'swiftforms' );
			} else {
				$out['trash'] = __( 'Trash', 'swiftforms' );
			}
		}
		if ( 'trash' === $status && Access::can( 'swiftforms_delete_entries' ) ) {
			$out['delete'] = __( 'Delete permanently', 'swiftforms' );
		}
		return $out;
	}

	/**
	 * The "action" query argument.
	 *
	 * @return string
	 */
	private static function request_action() {
		return isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; each action checks its nonce.
	}

	/**
	 * The "entry" query argument.
	 *
	 * @return int
	 */
	private static function request_entry() {
		return isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; each action checks its nonce.
	}

	/**
	 * Build the list table and its Screen Options.
	 *
	 * @return void
	 */
	private static function load_list() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-entries-list-table.php';

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Number of items per page:', 'swiftforms' ),
				'default' => self::PER_PAGE_DEFAULT,
				'option'  => self::PER_PAGE_OPTION,
			]
		);

		self::$table = new Entries_List_Table();
		self::help_tab();
		self::$table->prepare_items();
	}

	/**
	 * Load the entry of the single-entry screen, marking it read.
	 *
	 * @return void
	 */
	private static function load_view() {
		$entry = Entries_Repository::get( self::request_entry() );
		if ( null === $entry ) {
			self::not_found();
		}

		// An action's redirect carries a message: opening again after "Mark as unread" must not undo it.
		$after_action = isset( $_GET['message'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		if ( 'new' === $entry['status'] && ! $after_action && Access::can( 'swiftforms_edit_entries' ) ) {
			Entries_Repository::update( $entry['id'], [ 'status' => 'read' ] );
			$entry['status'] = 'read';
		}

		self::$entry = $entry;
		self::help_tab();
		wp_add_inline_script( 'common', 'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".swiftforms-print");if(b){e.preventDefault();window.print();}});' );
	}

	/**
	 * Stop with "no such entry".
	 *
	 * @return void
	 */
	private static function not_found() {
		wp_die(
			esc_html__( 'There is no such entry. It may have been deleted.', 'swiftforms' ),
			esc_html__( 'Entry not found', 'swiftforms' ),
			[
				'response'  => 404,
				'link_url'  => esc_url( self::list_url() ),
				'link_text' => esc_html__( 'Back to Entries', 'swiftforms' ),
			]
		);
	}

	/**
	 * A short help tab.
	 *
	 * @return void
	 */
	private static function help_tab() {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}
		$screen->add_help_tab(
			[
				'id'      => 'swiftforms-entries-overview',
				'title'   => __( 'Overview', 'swiftforms' ),
				'content' => '<p>' . esc_html__( 'Every submission of your forms is listed here, newest first. Opening an entry marks it as read. Entries moved to the Trash stay there until you delete them permanently or the retention period in the privacy settings removes them.', 'swiftforms' ) . '</p>',
			]
		);
	}

	/**
	 * Run an action on an entry, then redirect.
	 *
	 * @param string $action Action (a key of ACTIONS).
	 * @return void
	 */
	private static function run_action( $action ) {
		$id = self::request_entry();

		if ( ! Access::can( self::ACTIONS[ $action ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'swiftforms' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::nonce_action( $action, $id ) );

		$entry = Entries_Repository::get( $id );
		if ( null === $entry ) {
			self::not_found();
		}

		$previous = 'read' === $entry['status'] ? 'read' : 'new';
		$restore  = isset( $_GET['sf_to'] ) && 'read' === $_GET['sf_to'] ? 'read' : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Covered by check_admin_referer() above.
		$extra    = [];

		switch ( $action ) {
			case 'read':
				Entries_Repository::update( $id, [ 'status' => 'read' ] );
				break;
			case 'unread':
				Entries_Repository::update( $id, [ 'status' => 'new' ] );
				break;
			case 'star':
				Entries_Repository::update( $id, [ 'starred' => true ] );
				break;
			case 'unstar':
				Entries_Repository::update( $id, [ 'starred' => false ] );
				break;
			case 'spam':
				Entries_Repository::update( $id, [ 'status' => 'spam' ] );
				$extra = [
					'sf_undo' => $id,
					'sf_to'   => $previous,
				];
				break;
			case 'trash':
				Entries_Repository::update( $id, [ 'status' => 'trash' ] );
				$extra = [
					'sf_undo' => $id,
					'sf_to'   => $previous,
				];
				break;
			case 'unspam':
			case 'untrash':
				Entries_Repository::update( $id, [ 'status' => $restore ] );
				break;
			case 'delete':
				Entries_Repository::delete( $id );
				break;
		}

		$stay = isset( $_GET['from'] ) && 'view' === $_GET['from'] && ! in_array( $action, [ 'trash', 'delete' ], true ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Covered by check_admin_referer() above.
		if ( $stay ) {
			$target = add_query_arg( 'message', $action, self::view_url( $id ) );
		} else {
			$target = add_query_arg( array_merge( [ 'message' => $action ], $extra ), self::list_url( self::back_args() ) );
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * The list arguments (view, form, order, page) of the page the user came from.
	 *
	 * @return array<string, string>
	 */
	private static function back_args() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return [];
		}
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
		if ( ! isset( $query['page'] ) || self::PAGE !== $query['page'] || isset( $query['action'] ) ) {
			return [];
		}
		$args = [];
		foreach ( [ 'status', 'form', 'orderby', 'order', 'paged' ] as $key ) {
			if ( isset( $query[ $key ] ) && is_string( $query[ $key ] ) ) {
				$args[ $key ] = $query[ $key ];
			}
		}
		return $args;
	}

	/**
	 * The notice after an action, from the "message" query argument.
	 *
	 * @return string HTML.
	 */
	private static function notice() {
		$code = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shows a fixed message; nothing is changed.
		$text = [
			'read'    => __( 'Entry marked as read.', 'swiftforms' ),
			'unread'  => __( 'Entry marked as unread.', 'swiftforms' ),
			'star'    => __( 'Entry starred.', 'swiftforms' ),
			'unstar'  => __( 'Star removed.', 'swiftforms' ),
			'spam'    => __( 'Entry marked as spam.', 'swiftforms' ),
			'unspam'  => __( 'Entry marked as not spam.', 'swiftforms' ),
			'trash'   => __( 'Entry moved to the Trash.', 'swiftforms' ),
			'untrash' => __( 'Entry restored from the Trash.', 'swiftforms' ),
			'delete'  => __( 'Entry deleted permanently.', 'swiftforms' ),
		];
		if ( ! isset( $text[ $code ] ) ) {
			return '';
		}

		$undo = '';
		$id   = isset( $_GET['sf_undo'] ) ? absint( wp_unslash( $_GET['sf_undo'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The link it builds has its own nonce.
		if ( $id > 0 && in_array( $code, [ 'trash', 'spam' ], true ) && Access::can( 'swiftforms_edit_entries' ) ) {
			$to   = isset( $_GET['sf_to'] ) && 'read' === $_GET['sf_to'] ? 'read' : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Whitelisted.
			$undo = ' <a href="' . esc_url( self::action_url( 'trash' === $code ? 'untrash' : 'unspam', $id, [ 'sf_to' => $to ] ) ) . '">' . esc_html__( 'Undo', 'swiftforms' ) . '</a>';
		}

		return '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html( $text[ $code ] ) . $undo . '</p></div>';
	}

	/**
	 * Print the list screen.
	 *
	 * @return void
	 */
	private static function render_list() {
		if ( null === self::$table ) {
			return;
		}
		$table = self::$table;
		?>
		<div class="wrap swiftforms-entries">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Entries', 'swiftforms' ); ?></h1>
			<hr class="wp-header-end">
			<?php echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from fixed strings and escaped URLs in notice(). ?>
			<?php $table->views(); ?>
			<form id="swiftforms-entries-filter" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php
				foreach ( $table->kept_arguments() as $name => $value ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				}
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Print the single-entry screen.
	 *
	 * @param array<string, mixed> $entry Entries_Repository::get().
	 * @return void
	 */
	private static function render_view( array $entry ) {
		$detail = Entry_Presenter::detail( $entry );
		$id     = (int) $detail['id'];
		?>
		<div class="wrap swiftforms-entry">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: entry number. */
				echo esc_html( sprintf( __( 'Entry #%d', 'swiftforms' ), $id ) );
				?>
			</h1>
			<hr class="wp-header-end">
			<?php echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from fixed strings and escaped URLs in notice(). ?>
			<p class="swiftforms-back"><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'Back to Entries', 'swiftforms' ); ?></a></p>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<table class="widefat striped swiftforms-entry-fields">
							<caption class="screen-reader-text"><?php esc_html_e( 'Submitted values', 'swiftforms' ); ?></caption>
							<tbody>
								<?php foreach ( $detail['fields'] as $field ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( Entry_Presenter::visible_text( $field['label'] ) ); ?></th>
										<td><?php echo self::values_html( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped in values_html(). ?></td>
									</tr>
								<?php endforeach; ?>
								<?php if ( ! $detail['fields'] ) : ?>
									<tr><td><?php esc_html_e( 'This entry has no values.', 'swiftforms' ); ?></td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php self::render_box( $detail ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The "Entry" box of the single-entry screen.
	 *
	 * @param array<string, mixed> $detail Entry_Presenter::detail().
	 * @return void
	 */
	private static function render_box( array $detail ) {
		$id      = (int) $detail['id'];
		$status  = (string) $detail['status'];
		$starred = (bool) $detail['starred'];
		$labels  = [
			'new'   => __( 'Unread', 'swiftforms' ),
			'read'  => __( 'Read', 'swiftforms' ),
			'spam'  => __( 'Spam', 'swiftforms' ),
			'trash' => __( 'Trash', 'swiftforms' ),
		];
		$actions = self::available_actions( $status, $starred );
		$keys    = array_keys( $actions );
		$title   = '' !== (string) $detail['form_title'] ? Entry_Presenter::visible_text( $detail['form_title'] ) : __( '(no title)', 'swiftforms' );
		?>
		<div id="swiftforms-entry-box" class="postbox">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Entry', 'swiftforms' ); ?></h2></div>
			<div class="inside">
				<div class="submitbox">
					<div id="minor-publishing">
						<div class="misc-pub-section misc-pub-post-status swiftforms-meta-status">
							<?php esc_html_e( 'Status:', 'swiftforms' ); ?>
							<strong class="swiftforms-entry-status"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></strong>
							<?php self::inline_link( $actions, [ 'read', 'unread', 'unspam' ], $id ); ?>
						</div>
						<div class="misc-pub-section <?php echo $starred ? 'swiftforms-meta-starred' : 'swiftforms-meta-unstarred'; ?>">
							<?php esc_html_e( 'Starred:', 'swiftforms' ); ?>
							<strong><?php echo $starred ? esc_html__( 'Yes', 'swiftforms' ) : esc_html__( 'No', 'swiftforms' ); ?></strong>
							<?php self::inline_link( $actions, [ 'star', 'unstar' ], $id ); ?>
						</div>
						<div class="misc-pub-section misc-pub-curtime swiftforms-meta-date">
							<?php esc_html_e( 'Submitted:', 'swiftforms' ); ?>
							<strong><time datetime="<?php echo esc_attr( $detail['date_gmt'] ); ?>"><?php echo esc_html( $detail['date_display'] ); ?></time></strong>
						</div>
						<div class="misc-pub-section swiftforms-meta-form">
							<?php esc_html_e( 'Form:', 'swiftforms' ); ?>
							<strong><a href="<?php echo esc_url( self::list_url( [ 'form' => (string) $detail['form_id'] ] ) ); ?>"><?php echo esc_html( $title ); ?></a></strong>
						</div>
						<?php if ( ! empty( $detail['source'] ) ) : ?>
							<div class="misc-pub-section swiftforms-meta-source">
								<?php esc_html_e( 'Sent from:', 'swiftforms' ); ?>
								<strong>
									<?php if ( '' !== $detail['source']['link'] ) : ?>
										<a href="<?php echo esc_url( $detail['source']['link'] ); ?>"><?php echo esc_html( Entry_Presenter::visible_text( $detail['source']['title'] ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( Entry_Presenter::visible_text( $detail['source']['title'] ) ); ?>
									<?php endif; ?>
								</strong>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $detail['user'] ) ) : ?>
							<div class="misc-pub-section swiftforms-meta-user">
								<?php esc_html_e( 'User:', 'swiftforms' ); ?>
								<strong><?php echo esc_html( Entry_Presenter::visible_text( $detail['user'] ) ); ?></strong>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $detail['ip'] ) ) : ?>
							<div class="misc-pub-section swiftforms-meta-ip">
								<?php esc_html_e( 'IP address:', 'swiftforms' ); ?>
								<strong><?php echo esc_html( Entry_Presenter::visible_text( $detail['ip'] ) ); ?></strong>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $detail['user_agent'] ) ) : ?>
							<div class="misc-pub-section swiftforms-meta-browser swiftforms-user-agent">
								<?php esc_html_e( 'Browser:', 'swiftforms' ); ?>
								<strong><?php echo esc_html( Entry_Presenter::visible_text( $detail['user_agent'] ) ); ?></strong>
							</div>
						<?php endif; ?>
					</div>
					<div id="major-publishing-actions">
						<div id="delete-action">
							<?php
							self::inline_link( $actions, [ 'spam' ], $id );
							foreach ( array_intersect( [ 'trash', 'untrash', 'delete' ], $keys ) as $key ) {
								$class = 'untrash' === $key ? '' : 'submitdelete deletion';
								echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::action_url( $key, $id, [ 'from' => 'view' ] ) ) . '">' . esc_html( $actions[ $key ] ) . '</a> ';
							}
							?>
						</div>
						<div id="publishing-action">
							<button type="button" class="button swiftforms-print"><?php esc_html_e( 'Print', 'swiftforms' ); ?></button>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the first available action of a group as a small link.
	 *
	 * @param array<string, string> $actions Available actions.
	 * @param string[]              $group   Action keys that may stand here.
	 * @param int                   $id      Entry ID.
	 * @return void
	 */
	private static function inline_link( array $actions, array $group, $id ) {
		foreach ( $group as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				echo '<a class="swiftforms-inline-action" href="' . esc_url( self::action_url( $key, $id, [ 'from' => 'view' ] ) ) . '">' . esc_html( $actions[ $key ] ) . '</a> ';
				return;
			}
		}
	}

	/**
	 * The values of one field as escaped HTML: text, a list of values, a link for a validated address or URL.
	 *
	 * @param array<string, mixed> $field One item of Entry_Presenter::fields().
	 * @return string
	 */
	public static function values_html( array $field ) {
		$items = [];
		foreach ( $field['values'] as $item ) {
			$text = Entry_Presenter::visible_text( $item['value'] );
			if ( '' === $text ) {
				continue;
			}
			$html = nl2br( esc_html( $text ) );
			if ( ! empty( $item['is_email'] ) ) {
				$html = '<a href="' . esc_url( 'mailto:' . $item['value'], [ 'mailto' ] ) . '">' . $html . '</a>';
			} elseif ( ! empty( $item['is_link'] ) ) {
				$html = '<a href="' . esc_url( $item['value'], [ 'http', 'https' ] ) . '" rel="noopener noreferrer" target="_blank">' . $html . '</a>';
			}
			$items[] = $html;
		}

		if ( ! $items ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'No answer', 'swiftforms' ) . '</span>';
		}
		if ( 1 === count( $items ) ) {
			return $items[0];
		}
		return '<ul class="swiftforms-values"><li>' . implode( '</li><li>', $items ) . '</li></ul>';
	}
}
