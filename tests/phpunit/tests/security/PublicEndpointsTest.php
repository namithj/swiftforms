<?php
/**
 * Tests for the pre-step, the token route and the other public endpoints.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Form_Index;
use SwiftForms\Gate;
use SwiftForms\Rest_Public;
use SwiftForms\Render_Token;
use SwiftForms\Time_Trap;

/**
 * Class PublicEndpointsTest
 *
 * @group submission
 * @group security
 */
class PublicEndpointsTest extends SF_Submission_Test_Case {

	/**
	 * Per-form limit off.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 0 ] ] );
	}

	/**
	 * GET the token route.
	 *
	 * @param string $form_id Form ID.
	 * @param string $source  Source.
	 * @return WP_REST_Response
	 */
	private function token_request( $form_id, $source ) {
		$request = new WP_REST_Request( 'GET', '/swiftforms/v1/token' );
		$request->set_query_params(
			[
				'formId' => $form_id,
				'source' => $source,
			]
		);
		return rest_do_request( $request );
	}

	/**
	 * Count queries by what they touch while a callback runs.
	 *
	 * @param callable $callback Code to run.
	 * @return array{forms:int, other:int, all:int} Queries on the forms table, on tables other than options, and in total.
	 */
	private function count_queries( callable $callback ) {
		global $wpdb;
		$count = [
			'forms' => 0,
			'other' => 0,
			'all'   => 0,
		];
		$forms = $wpdb->prefix . 'swiftforms_forms';
		$spy   = static function ( $query ) use ( &$count, $forms, $wpdb ) {
			++$count['all'];
			if ( false !== strpos( $query, '`' . $forms . '`' ) || false !== strpos( $query, ' ' . $forms . ' ' ) ) {
				++$count['forms'];
			} elseif ( false === strpos( $query, $wpdb->options ) && 0 !== stripos( $query, 'SELECT @@' ) ) {
				++$count['other'];
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$callback();
		remove_filter( 'query', $spy );
		return $count;
	}

	/**
	 * The token route issues a token and a signed time, uncached.
	 *
	 * @return void
	 */
	public function test_token_route() {
		$this->make_form();
		$res = $this->token_request( $this->form_id, $this->source );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( [ 'status', 'token', 't' ], array_keys( $data ) );
		$this->assertSame( 'success', $data['status'] );
		$this->assertSame( 'valid', Render_Token::verify( $data['token'], $this->form_id, $this->source, [ $this->schema()->hash ] )['state'] );
		$this->assertSame( 'signed', Time_Trap::parse( $data['t'], $this->form_id, $this->source )['format'] );

		$headers = $res->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$this->assertArrayHasKey( 'Expires', $headers );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'DELETE', '/swiftforms/v1/token' ) )->get_status() );
	}

	/**
	 * A token fetched here works for a submission.
	 *
	 * @return void
	 */
	public function test_fetched_token_submits() {
		$this->make_form();
		$this->set_settings( [ 'spam' => [ 'min_seconds' => 0 ] ] );
		$data = $this->token_request( $this->form_id, $this->source )->get_data();
		$res  = $this->submit(
			$this->good(),
			[
				'_sf_token' => $data['token'],
				'_sf_t'     => 'js.4000',
			]
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $this->entries() );
	}

	/**
	 * Pairs that are not live answer closed and issue no token and no schema hash.
	 *
	 * @return void
	 */
	public function test_non_live_pairs_are_closed() {
		$cases = [];

		$draft          = $this->make_form( null, [], [ 'post_status' => 'draft' ] );
		$cases['draft'] = [ $this->form_id, $this->source ];

		$this->make_form( null, [], [ 'post_status' => 'private' ] );
		$cases['private'] = [ $this->form_id, $this->source ];

		$this->make_form( null, [], [ 'post_status' => 'pending' ] );
		$cases['pending'] = [ $this->form_id, $this->source ];

		$this->make_form(
			null,
			[],
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			]
		);
		$cases['scheduled'] = [ $this->form_id, $this->source ];

		$trashed = $this->make_form();
		wp_trash_post( (int) substr( $this->source, 5 ) );
		$cases['trashed'] = [ $this->form_id, $this->source ];

		$gone = $this->make_form();
		wp_delete_post( (int) substr( $this->source, 5 ), true );
		$cases['deleted'] = [ $this->form_id, $this->source ];

		// A registered pattern nobody places is not a location (mutation 15).
		$unplaced = wp_generate_uuid4();
		$this->register_pattern( 'sf/unplaced', $this->form_markup( $unplaced, $this->default_inner() ) );
		$cases['registered pattern'] = [ $unplaced, 'pattern:sf/unplaced' ];

		// A synced pattern no live page shows.
		$unplaced_block = wp_generate_uuid4();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$block = $this->make_post( $this->form_markup( $unplaced_block, $this->default_inner() ), [ 'post_type' => 'wp_block' ] );
		wp_set_current_user( 0 );
		$cases['unplaced synced pattern'] = [ $unplaced_block, 'post:' . $block ];

		// A template of a theme that is not active.
		$other = wp_generate_uuid4();
		$this->make_theme( 'sf-live', [], true );
		$this->make_template_post( 'wp_template', 'sf-not-active', 'single', $this->form_markup( $other, $this->default_inner() ) );
		wp_set_current_user( 0 );
		$cases['inactive theme'] = [ $other, 'template:sf-not-active//single' ];

		// A widget in the inactive area.
		$widget = wp_generate_uuid4();
		$this->place_widget( 9, $this->form_markup( $widget, $this->default_inner() ), 'wp_inactive_widgets' );
		$cases['inactive widget'] = [ $widget, 'widget:block-9' ];

		// A live form under the wrong source, garbage, and nothing.
		$this->make_form();
		$live                    = [ $this->form_id, $this->source ];
		$cases['wrong source']   = [ $live[0], 'post:99999' ];
		$cases['unknown form']   = [ wp_generate_uuid4(), $live[1] ];
		$cases['garbage id']     = [ "x'; DROP TABLE y;--", $live[1] ];
		$cases['garbage source'] = [ $live[0], 'post:1; DROP' ];
		$cases['empty']          = [ '', '' ];

		foreach ( $cases as $label => $pair ) {
			$this->form_id = $pair[0];
			$this->source  = $pair[1];

			$res = $this->token_request( $pair[0], $pair[1] );
			$this->assertSame( 403, $res->get_status(), $label . ' (token)' );
			$this->assertSame( [ 'status' => 'closed' ], $res->get_data(), $label . ' (token)' );

			$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body(
				wp_json_encode(
					[
						'_sf_form'   => $pair[0],
						'_sf_source' => $pair[1],
						'_sf_token'  => 'v1.a.' . str_repeat( 'a', 32 ),
						'sf'         => $this->good(),
					]
				)
			);
			$res = rest_do_request( $request );
			$this->assertSame( 403, $res->get_status(), $label . ' (submit)' );
			$this->assertSame( [ 'status' => 'closed' ], $res->get_data(), $label . ' (submit)' );
		}
		$this->assertCount( 0, $this->entries() );
		$this->assertNotNull( $draft );
		$this->assertNotNull( $trashed );
		$this->assertNotNull( $gone );
	}

	/**
	 * A live (formId, source) pair with the form in a draft revision of a published page answers on the published content only.
	 *
	 * @return void
	 */
	public function test_unpublishing_closes_the_form() {
		$page = $this->make_form();
		$this->assertSame( 200, $this->submit( $this->good() )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_update_post(
			[
				'ID'          => $page,
				'post_status' => 'draft',
			]
		);
		wp_set_current_user( 0 );

		$this->assertSame( 403, $this->submit( $this->good( [ 'name' => 'Again' ] ) )->get_status() );
		$this->assertSame( 'closed', $this->submit( $this->good( [ 'name' => 'Again' ] ) )->get_data()['status'] );
	}

	/**
	 * A form moved to another source: the old pair is closed, the owner pair works.
	 *
	 * @return void
	 */
	public function test_form_in_another_post_is_not_reachable_by_the_wrong_source() {
		$this->make_form();
		$first_source = $this->source;
		$first_form   = $this->form_id;
		$this->make_form();

		$res = $this->submit(
			$this->good(),
			[
				'_sf_form'   => $first_form,
				'_sf_source' => $this->source,
			]
		);
		$this->assertSame( 'closed', $res->get_data()['status'] );
		$res = $this->submit(
			$this->good(),
			[
				'_sf_form'   => $first_form,
				'_sf_source' => $first_source,
				'_sf_token'  => Render_Token::issue( $first_form, $first_source, $this->schema_of( $first_form, $first_source )->hash ),
			]
		);
		$this->assertSame( 'success', $res->get_data()['status'] );
	}

	/**
	 * Schema of another pair.
	 *
	 * @param string $form_id Form.
	 * @param string $source  Source.
	 * @return \SwiftForms\Form_Schema
	 */
	private function schema_of( $form_id, $source ) {
		$schema = \SwiftForms\Schema_Resolver::resolve( $form_id, $source );
		$this->assertNotNull( $schema );
		return $schema;
	}

	/**
	 * Garbage costs nothing; an unknown but well-formed pair costs one indexed query on the forms table.
	 *
	 * @return void
	 */
	public function test_garbage_flood_costs_one_indexed_query() {
		$this->make_form();
		Form_Index::flush_cache();
		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 10000;
			}
		);

		// Not even a query for values that cannot be a pair.
		$counts = $this->count_queries(
			function () {
				for ( $i = 0; $i < 20; $i++ ) {
					$this->token_request( 'garbage-' . $i, $this->source );
					$this->token_request( $this->form_id, 'post:x' . $i );
				}
			}
		);
		$this->assertSame( 0, $counts['forms'] );
		$this->assertSame( 0, $counts['other'], 'Nothing but the limiter counters is touched.' );

		// A well-formed pair that does not exist: exactly one query on the forms table, nothing on posts, schemas or entries.
		$ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$ids[] = wp_generate_uuid4();
		}
		$body     = array_merge( $this->controls(), [ 'sf' => $this->good() ] );
		$requests = [];
		foreach ( $ids as $id ) {
			$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( array_merge( $body, [ '_sf_form' => $id ] ) ) );
			$requests[] = $request;
		}
		$counts = $this->count_queries(
			function () use ( $requests ) {
				foreach ( $requests as $request ) {
					$res = rest_do_request( $request );
					$this->assertSame( 'closed', $res->get_data()['status'] );
				}
			}
		);
		$this->assertSame( 25, $counts['forms'], 'One indexed query per request.' );
		$this->assertSame( 0, $counts['other'], 'Not a single query on posts, schemas, entries or users.' );

		// The same garbage again is answered without a second query (the answer is cached).
		$counts = $this->count_queries(
			function () use ( $requests ) {
				foreach ( $requests as $request ) {
					rest_do_request( $request );
				}
			}
		);
		$this->assertSame( 0, $counts['forms'] );
	}

	/**
	 * The coarse throttle: per client, all public routes, before the body is read.
	 *
	 * @return void
	 */
	public function test_coarse_throttle() {
		$this->make_form();
		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 4;
			}
		);

		$this->assertSame( 200, $this->token_request( $this->form_id, $this->source )->get_status() );
		$this->assertSame( 200, $this->token_request( $this->form_id, $this->source )->get_status() );
		$this->assertSame( 403, $this->submit( $this->good(), [ '_sf_form' => wp_generate_uuid4() ] )->get_status() );
		$this->assertSame( 200, $this->submit( $this->good() )->get_status() );

		// The fifth request, whatever it is, is turned away.
		$res = $this->token_request( $this->form_id, $this->source );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( [ 'status' => 'rate_limited' ], $res->get_data() );
		$this->assertArrayHasKey( 'Retry-After', $res->get_headers() );

		// ... before WordPress parses the body: broken JSON still gets 429, not a JSON error.
		$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{not json' );
		$res = rest_do_request( $request );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( [ 'status' => 'rate_limited' ], $res->get_data() );

		// Another client is fine.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
		$this->assertSame( 200, $this->token_request( $this->form_id, $this->source )->get_status() );

		// An IPv6 client is limited by its /64.
		$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb::1';
		for ( $i = 0; $i < 4; $i++ ) {
			$this->token_request( $this->form_id, $this->source );
		}
		$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb:ffff:ffff:ffff:ffff';
		$this->assertSame( 429, $this->token_request( $this->form_id, $this->source )->get_status(), 'Same /64.' );
		$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:cccc::1';
		$this->assertSame( 200, $this->token_request( $this->form_id, $this->source )->get_status(), 'Another /64.' );
	}

	/**
	 * A request spoofing X-Forwarded-For cannot dodge the throttle.
	 *
	 * @return void
	 */
	public function test_spoofed_headers_do_not_dodge_the_throttle() {
		$this->make_form();
		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 2;
			}
		);
		$statuses = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . ( 40 + $i );
			$statuses[]                      = $this->token_request( $this->form_id, $this->source )->get_status();
		}
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->assertSame( [ 200, 200, 429, 429, 429 ], $statuses );
	}

	/**
	 * Size caps: body bytes and number of fields, before anything else.
	 *
	 * @return void
	 */
	public function test_size_caps() {
		$this->make_form();

		// Declared too large: refused before the (broken) body is parsed.
		$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'content-length', (string) ( Gate::MAX_BODY + 1 ) );
		$request->set_body( '{not json' );
		$res = rest_do_request( $request );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( [ 'status' => 'blocked' ], $res->get_data() );

		// Actually too large.
		$big = $this->submit( $this->good( [ 'message' => str_repeat( 'a', Gate::MAX_BODY ) ] ) );
		$this->assertSame( 'blocked', $big->get_data()['status'] );
		$this->assertCount( 0, $this->entries() );

		// At the limit is fine (a value near 256 KB would still fail the 5,000 character rule; here only the size matters).
		$ok = $this->submit( $this->good( [ 'message' => str_repeat( 'a', 5000 ) ] ) );
		$this->assertSame( 200, $ok->get_status() );

		// 500 fields pass the gate, 501 do not.
		$many = [];
		for ( $i = 0; $i < 497; $i++ ) {
			$many[ 'x' . $i ] = '1';
		}
		$this->assertSame( 200, $this->submit( $this->good( [ 'message' => 'm500' ] ) + $many )->get_status(), 'Exactly 500 fields (unknown ones are discarded).' );
		$many['x497'] = '1';
		$res          = $this->submit( $this->good( [ 'message' => 'm501' ] ) + $many );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( [ 'status' => 'blocked' ], $res->get_data() );

		// Nested values count.
		$list = [];
		for ( $i = 0; $i < 501; $i++ ) {
			$list[] = 'v';
		}
		$this->assertSame( 'blocked', $this->submit( $this->good() + [ 'colors' => $list ] )->get_data()['status'] );
		$this->assertSame( 501, Gate::count_fields( [ 'a' => $list ] ) );
		$this->assertSame( 0, Gate::count_fields( 'x' ) );
	}

	/**
	 * Responses never echo what was sent, and never carry paths or SQL.
	 *
	 * @return void
	 */
	public function test_errors_do_not_echo_input() {
		$this->make_form();
		$payload = 'ECHO-ME-<script>alert(1)</script>';
		$cases   = [
			$this->submit(
				[
					'name'  => $payload,
					'email' => $payload,
				]
			),
			$this->submit( $this->good(), [ '_sf_form' => $payload ] ),
			$this->submit( $this->good(), [ '_sf_source' => $payload ] ),
			$this->submit( $this->good(), [ '_sf_token' => $payload ] ),
			$this->submit(
				$this->good(),
				[
					'_sf_t'  => $payload,
					'_sf_hp' => $payload,
				]
			),
			$this->token_request( $payload, $payload ),
		];
		foreach ( $cases as $i => $res ) {
			$json = wp_json_encode( $res->get_data() );
			$this->assertStringNotContainsString( 'ECHO-ME', $json, "case $i" );
			$this->assertStringNotContainsString( ABSPATH, $json );
			$this->assertStringNotContainsString( 'wpdb', $json );
		}
	}

	/**
	 * The REST inventory: the two public routes, exactly, both allow-listed; nothing else under the namespace is public.
	 *
	 * @return void
	 */
	public function test_route_inventory() {
		$routes = rest_get_server()->get_routes( 'swiftforms/v1' );
		$public = [];
		foreach ( $routes as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( '/swiftforms/v1' !== $route && SF_User_Config_Runner::is_public( $handler ) ) { // The namespace index is core's own.
					$public[] = substr( $route, strlen( '/swiftforms/v1' ) );
				}
			}
		}
		sort( $public );
		$this->assertSame( Rest_Public::ROUTES, $public, 'Only the allow-listed routes are public.' );
		$this->assertArrayHasKey( '/swiftforms/v1/submit', $routes );
		$this->assertArrayHasKey( '/swiftforms/v1/token', $routes );
		$this->assertSame( [ 'POST' ], array_keys( $routes['/swiftforms/v1/submit'][0]['methods'] ) );
		$this->assertSame( [ 'GET' ], array_keys( $routes['/swiftforms/v1/token'][0]['methods'] ) );
	}

	/**
	 * A route outside the allow-list cannot be registered as public.
	 *
	 * @return void
	 */
	public function test_unlisted_route_is_refused() {
		$this->setExpectedIncorrectUsage( 'SwiftForms\Rest_Public::register' );
		$this->assertFalse(
			Rest_Public::register(
				'/secret',
				[
					'methods'  => 'GET',
					'callback' => '__return_true',
				]
			)
		);
	}

	/**
	 * The public permission callback (__return_true) appears in one place only. Routes with a capability check
	 * live in the two admin route classes; RouteInventoryTest and the security lint cover those.
	 *
	 * @return void
	 */
	public function test_public_permission_callback_is_in_one_file() {
		$files   = array_merge( glob( dirname( __DIR__, 4 ) . '/includes/*.php' ), glob( dirname( __DIR__, 4 ) . '/includes/*/*.php' ) );
		$public  = [];
		$guarded = [];
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$code = (string) preg_replace( '~^\s*(\*|//).*$~m', '', $code );
			if ( false !== strpos( $code, '__return_true' ) ) {
				$public[] = basename( $file );
			}
			if ( false !== strpos( $code, 'permission_callback' ) ) {
				$guarded[] = basename( $file );
			}
		}
		$this->assertSame( [ 'class-rest-public.php' ], $public );
		$this->assertSame( [ 'class-rest-entries.php', 'class-rest-public.php' ], $guarded );
	}

	/**
	 * The nonce refresh answers logged-in users only.
	 *
	 * @return void
	 */
	public function test_nonce_refresh_is_for_logged_in_users_only() {
		$this->assertFalse( has_action( 'wp_ajax_nopriv_swiftforms_rest_nonce' ), 'No guest handler.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The action is ours.
		$this->assertNotFalse( has_action( 'wp_ajax_swiftforms_rest_nonce' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new RuntimeException( 'die' );
				};
			}
		);
		ob_start();
		try {
			do_action( 'wp_ajax_swiftforms_rest_nonce' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();

		$data = json_decode( $out, true );
		$this->assertIsArray( $data );
		$this->assertSame( [ 'nonce' ], array_keys( $data ) );
		$this->assertNotFalse( wp_verify_nonce( $data['nonce'], 'wp_rest' ) );
	}

	/**
	 * Hooks of the public endpoints run for the right requests only.
	 *
	 * @return void
	 */
	public function test_hooks_are_wired() {
		$this->assertNotFalse( has_action( 'rest_api_init', [ 'SwiftForms\Rest_Submit', 'register' ] ) );
		$this->assertNotFalse( has_action( 'rest_api_init', [ 'SwiftForms\Rest_Token', 'register' ] ) );
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', [ 'SwiftForms\Rest_Public', 'pre_dispatch' ] ) );
		$this->assertNotFalse( has_action( 'template_redirect', [ 'SwiftForms\Nojs_Handler', 'maybe_handle' ] ) );
	}
}
