<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper: the class name follows SF_Submission_Test_Case.
/**
 * Base class for the admin tests (Entries and Settings REST, privacy, retention).
 *
 * Builds on the submission case: a published page with the default form (name, email, message), entries
 * written through Entries_Repository with the form's real snapshot, and REST calls made in-process.
 *
 * @package SwiftForms
 */

require_once __DIR__ . '/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Entries_Repository;
use SwiftForms\Entry_Presenter;
use SwiftForms\Schema_Store;
use SwiftForms\Submission_Context;

/**
 * Class SF_Admin_Test_Case
 */
abstract class SF_Admin_Test_Case extends SF_Submission_Test_Case {

	/**
	 * Start with a form and no user.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Entry_Presenter::flush();
		$this->make_form();
	}

	/**
	 * Add an entry to the current form.
	 *
	 * @param array<string, mixed> $values  Field values (name, email, message by default).
	 * @param array<string, mixed> $context Submission_Context properties (created_gmt, status, user).
	 * @param string               $status  Status to set after the insert ("new" keeps it).
	 * @return int Entry ID.
	 */
	protected function add_entry( array $values = [], array $context = [], $status = 'new' ) {
		$schema = $this->schema();
		Schema_Store::save( $schema );
		$values = array_merge( $this->good(), $values );
		$ctx    = new Submission_Context( $schema, null, array_merge( [ 'values' => $values ], $context ) );
		$id     = Entries_Repository::insert( $ctx );
		$this->assertGreaterThan( 0, $id );
		if ( 'new' !== $status && 'spam' !== $status ) {
			Entries_Repository::update( $id, [ 'status' => $status ] );
		}
		return $id;
	}

	/**
	 * Call a SwiftForms REST route in-process.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below swiftforms/v1, starting with a slash.
	 * @param array<string, mixed> $params Query (GET) or JSON body (others).
	 * @return WP_REST_Response
	 */
	protected function call( $method, $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, '/swiftforms/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Become an administrator.
	 *
	 * @return int User ID.
	 */
	protected function be_admin() {
		$id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Become a user with a role.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	protected function be_role( $role ) {
		$id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Map the SwiftForms capabilities to primitive capabilities for the rest of the test.
	 *
	 * @param array<string, string> $map SwiftForms capability => primitive capability.
	 * @return void
	 */
	protected function map_caps( array $map ) {
		add_filter(
			'swiftforms_capability_map',
			static function ( $default ) use ( $map ) {
				return array_merge( $default, $map );
			}
		);
	}

	/**
	 * A UTC datetime some days before the frozen "now".
	 *
	 * @param int $days    Days back.
	 * @param int $seconds Extra seconds back.
	 * @return string
	 */
	protected function days_ago( $days, $seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', Clock::now() - $days * DAY_IN_SECONDS - $seconds );
	}
}
