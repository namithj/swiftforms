<?php
/**
 * REST routes of the Entries screen.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Entries
 *
 * swiftforms/v1/entries (list), entries/{id} (read, change, delete) and forms (counts for the filters). Every
 * permission callback asks Access::can() for the capability the action needs: a guest gets 401, a logged-in
 * user without the capability 403. Responses are never cached.
 */
class Rest_Entries {

	/**
	 * Page sizes the list accepts.
	 */
	const PER_PAGE = [ 20, 50, 100 ];

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			Rest_Public::REST_NAMESPACE,
			'/entries',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_entries' ],
				'permission_callback' => [ self::class, 'can_view' ],
				'args'                => [
					'form'     => [
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_form' ],
					],
					'status'   => [
						'type' => 'string',
						'enum' => Entries_Repository::STATUSES,
					],
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => 20,
						'enum'    => self::PER_PAGE,
					],
				],
			]
		);

		register_rest_route(
			Rest_Public::REST_NAMESPACE,
			'/entries/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'read_entry' ],
					'permission_callback' => [ self::class, 'can_view' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ self::class, 'change_entry' ],
					'permission_callback' => [ self::class, 'can_edit' ],
					'args'                => [
						'status'  => [
							'type' => 'string',
							'enum' => Entries_Repository::STATUSES,
						],
						'starred' => [
							'type' => 'boolean',
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ self::class, 'delete_entry' ],
					'permission_callback' => [ self::class, 'can_delete' ],
				],
			]
		);

		register_rest_route(
			Rest_Public::REST_NAMESPACE,
			'/forms',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_forms' ],
				'permission_callback' => [ self::class, 'can_view' ],
			]
		);
	}

	/**
	 * Permission: view entries.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_view() {
		return self::require_cap( 'swiftforms_view_entries' );
	}

	/**
	 * Permission: edit entries.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_edit() {
		return self::require_cap( 'swiftforms_edit_entries' );
	}

	/**
	 * Permission: delete entries.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_delete() {
		return self::require_cap( 'swiftforms_delete_entries' );
	}

	/**
	 * The permission answer for a capability: true, or 401 for a guest and 403 for a user without it.
	 *
	 * @param string $cap SwiftForms capability.
	 * @return true|\WP_Error
	 */
	public static function require_cap( $cap ) {
		if ( Access::can( $cap ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'swiftforms' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Validate the form filter: empty or an effective formId.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_form( $value ) {
		return is_string( $value ) && ( '' === $value || Uuid::is_valid( $value ) );
	}

	/**
	 * GET /entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_entries( \WP_REST_Request $request ) {
		$per_page = (int) $request['per_page'];
		$page     = Entries_Repository::query(
			[
				'form'     => (string) $request['form'],
				'status'   => (string) $request['status'],
				'page'     => (int) $request['page'],
				'per_page' => $per_page,
			]
		);

		$response = new \WP_REST_Response( Entry_Presenter::list_items( $page ) );
		$response->header( 'X-WP-Total', (string) $page['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $page['total'] / max( 1, $per_page ) ) );
		Rest_Public::no_store( $response );
		return $response;
	}

	/**
	 * GET /entries/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read_entry( \WP_REST_Request $request ) {
		$entry = Entries_Repository::get( (int) $request['id'] );
		if ( null === $entry ) {
			return self::not_found();
		}
		$response = new \WP_REST_Response( Entry_Presenter::detail( $entry ) );
		Rest_Public::no_store( $response );
		return $response;
	}

	/**
	 * PATCH /entries/{id}: status and star.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function change_entry( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$changes = [];
		if ( null !== $request->get_param( 'status' ) ) {
			$changes['status'] = (string) $request->get_param( 'status' );
		}
		if ( null !== $request->get_param( 'starred' ) ) {
			$changes['starred'] = filter_var( $request->get_param( 'starred' ), FILTER_VALIDATE_BOOLEAN );
		}
		if ( ! $changes ) {
			return new \WP_Error( 'rest_missing_callback_param', __( 'Send a status or starred.', 'swiftforms' ), [ 'status' => 400 ] );
		}
		if ( ! Entries_Repository::update( $id, $changes ) ) {
			return self::not_found();
		}
		return self::read_entry( $request );
	}

	/**
	 * DELETE /entries/{id}: permanent, with values and meta.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_entry( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Entries_Repository::delete( $id ) ) {
			return self::not_found();
		}
		$response = new \WP_REST_Response(
			[
				'deleted' => true,
				'id'      => $id,
			]
		);
		Rest_Public::no_store( $response );
		return $response;
	}

	/**
	 * GET /forms: the filter list. Minimal on purpose; later releases add to it.
	 *
	 * @return \WP_REST_Response
	 */
	public static function list_forms() {
		global $wpdb;

		$counts = Entries_Repository::counts_by_form();
		$table  = esc_sql( Database::table( 'forms' ) );
		// sf-allow-unprepared: no input.
		$rows = $wpdb->get_results( "SELECT form_id, title FROM {$table} ORDER BY title ASC, form_id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; no variable input.

		$items = [];
		$known = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$known[ $row['form_id'] ] = true;
			$items[]                  = self::form_item( (string) $row['form_id'], (string) $row['title'], $counts[ $row['form_id'] ] ?? [] );
		}
		foreach ( $counts as $form_id => $by_status ) {
			if ( ! isset( $known[ $form_id ] ) ) {
				$items[] = self::form_item( (string) $form_id, '', $by_status );
			}
		}

		$response = new \WP_REST_Response( $items );
		Rest_Public::no_store( $response );
		return $response;
	}

	/**
	 * One item of the forms list.
	 *
	 * @param string          $form_id   Effective formId.
	 * @param string          $title     Form title.
	 * @param array<string,int> $by_status Counts per status.
	 * @return array<string, mixed>
	 */
	private static function form_item( $form_id, $title, array $by_status ) {
		$counts = [];
		foreach ( Entries_Repository::STATUSES as $status ) {
			$counts[ $status ] = $by_status[ $status ] ?? 0;
		}
		return [
			'form_id' => $form_id,
			'title'   => $title,
			'counts'  => $counts,
		];
	}

	/**
	 * The 404 answer.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'swiftforms_entry_not_found', __( 'No such entry.', 'swiftforms' ), [ 'status' => 404 ] );
	}
}
