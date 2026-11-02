<?php
/**
 * Route inventory: every swiftforms/v1 route is either on the public allow-list or guarded by Access::can().
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/includes/class-sf-admin-test-case.php';

use SwiftForms\Access;
use SwiftForms\Rest_Public;

/**
 * Class RouteInventoryTest
 *
 * @group security
 * @group admin
 */
class RouteInventoryTest extends SF_Admin_Test_Case {

	/**
	 * The route entries of the namespace: [ route, method(s), handler ].
	 *
	 * @return array<int, array{0:string, 1:array, 2:array}>
	 */
	private function inventory() {
		$out = [];
		foreach ( rest_get_server()->get_routes( Rest_Public::REST_NAMESPACE ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				$out[] = [ $route, $handler['methods'], $handler ];
			}
		}
		return $out;
	}

	/**
	 * The routes that exist are the routes we know. A new route is a visible change to this list.
	 *
	 * @return void
	 */
	public function test_the_route_list_is_known() {
		$routes = array_keys( rest_get_server()->get_routes( Rest_Public::REST_NAMESPACE ) );
		sort( $routes );
		$this->assertSame(
			[
				'/swiftforms/v1',
				'/swiftforms/v1/entries',
				'/swiftforms/v1/entries/(?P<id>\d+)',
				'/swiftforms/v1/forms',
				'/swiftforms/v1/submit',
				'/swiftforms/v1/token',
			],
			$routes
		);
	}

	/**
	 * Each route not on the allow-list refuses a subscriber and a guest, and accepts an administrator's capability check.
	 *
	 * @return void
	 */
	public function test_every_private_route_checks_access() {
		$public   = array_map(
			static function ( $route ) {
				return '/' . Rest_Public::REST_NAMESPACE . $route;
			},
			Rest_Public::ROUTES
		);
		$public[] = '/swiftforms/v1'; // The namespace index core adds.

		$checked = 0;
		foreach ( $this->inventory() as $entry ) {
			list( $route, , $handler ) = $entry;
			if ( in_array( $route, $public, true ) ) {
				continue;
			}
			$callback = $handler['permission_callback'] ?? null;
			$this->assertTrue( is_callable( $callback ), "$route has a permission callback." );
			$this->assertNotSame( '__return_true', $callback, "$route is not public." );

			wp_set_current_user( 0 );
			$guest = call_user_func( $callback, new WP_REST_Request() );
			$this->assertWPError( $guest, "$route refuses a guest." );
			$this->assertSame( 401, $guest->get_error_data()['status'] );

			$this->be_role( 'subscriber' );
			$subscriber = call_user_func( $callback, new WP_REST_Request() );
			$this->assertWPError( $subscriber, "$route refuses a subscriber." );
			$this->assertSame( 403, $subscriber->get_error_data()['status'] );

			$this->be_admin();
			$this->assertTrue( call_user_func( $callback, new WP_REST_Request() ), "$route admits an administrator." );
			++$checked;
		}
		$this->assertSame( 5, $checked, 'GET entries, GET/PATCH/DELETE entries/{id}, GET forms. The settings route is cassette-cmf\'s (SettingsPageTest).' );
	}

	/**
	 * Every private permission callback runs Access::can(): a custom role granted one capability gets that one only.
	 *
	 * @return void
	 */
	public function test_permission_callbacks_use_access_can() {
		$this->map_caps( [ 'swiftforms_delete_entries' => 'edit_posts' ] );
		$this->be_role( 'contributor' );
		$this->assertTrue( Access::can( 'swiftforms_delete_entries' ) );
		$this->assertTrue( \SwiftForms\Rest_Entries::can_delete() );
		$this->assertWPError( \SwiftForms\Rest_Entries::can_view() );
		$this->assertWPError( \SwiftForms\Rest_Entries::can_edit() );
	}

	/**
	 * No __return_true outside the public route class, and the public class registers only the allow-list.
	 *
	 * @return void
	 */
	public function test_no_return_true_outside_the_allow_list() {
		$root = dirname( __DIR__, 4 ) . '/includes';
		$hits = [];
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.
				if ( is_array( $token ) && T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] && false !== strpos( $token[1], '__return_true' ) ) {
					$hits[] = substr( $file->getPathname(), strlen( $root ) + 1 );
				}
			}
		}
		$this->assertSame( [ 'rest/class-rest-public.php' ], array_values( array_unique( $hits ) ) );

		$this->assertSame( [ '/submit', '/token' ], Rest_Public::ROUTES );
	}

	/**
	 * Public routes are exactly the allow-list.
	 *
	 * @return void
	 */
	public function test_public_routes_are_the_allow_list() {
		$public = [];
		foreach ( $this->inventory() as $entry ) {
			if ( isset( $entry[2]['permission_callback'] ) && '__return_true' === $entry[2]['permission_callback'] ) {
				$public[] = $entry[0];
			}
		}
		sort( $public );
		$this->assertSame( [ '/swiftforms/v1/submit', '/swiftforms/v1/token' ], $public );
	}
}
