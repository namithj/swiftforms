<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Test helper: the class name SF_User_Config_Runner is fixed by the R00 plan; it reads stored state straight from the database on purpose.
/**
 * Runs one user config through the scenario matrix and collects its snapshots.
 *
 * Implemented: the harness self-check ("apply"), S1 (front-end render of every form location), S2 (valid
 * submissions over REST), S3 (invalid submissions), S4 (no-JS posts), S5 (spam and abuse), S6 (entries REST),
 * S7 (no export route in 1.0.0), S8 (settings REST, no write), S9 (privacy exporter and eraser), S10 (schema and
 * registry), S11 (migrations), and the config-specific checks a config lists in "checks". Every other scenario
 * must be listed in the config's "skip" or the run fails: a scenario that is neither implemented nor skipped is
 * a gap in the safety net. S12 and S13 are Playwright-only.
 *
 * The scenarios run in order on one database state: entries made by S2 are what S6 lists, and S9 erases one of
 * them. Throttle and limiter transients are cleared between requests and between scenarios, so no request
 * depends on the clock-free counters of the one before it. IDs that depend on the database (post IDs, entry IDs,
 * derived formIds, user IDs) are replaced by stable labels before anything is written to a snapshot; see
 * clean() and text().
 *
 * @package SwiftForms
 */

require_once __DIR__ . '/class-sf-test-redirect.php';
require_once __DIR__ . '/class-sf-test-die.php';

use SwiftForms\Clock;
use SwiftForms\Block_Walker;
use SwiftForms\Entries_Repository;
use SwiftForms\Form_Renderer;
use SwiftForms\Form_State;
use SwiftForms\Migrations;
use SwiftForms\Nojs_Handler;
use SwiftForms\Render_Token;
use SwiftForms\Schema_Resolver;
use SwiftForms\Settings;
use SwiftForms\Source_Stack;
use SwiftForms\Time_Trap;

/**
 * Scenario runner.
 */
class SF_User_Config_Runner {

	/**
	 * Scenarios in run order. S12 and S13 run in the browser (tests/e2e), not here.
	 */
	const ORDER = [ 'apply', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S14', 'S15', 'S16' ];

	/**
	 * Scenarios this release implements.
	 */
	const IMPLEMENTED = [ 'apply', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11' ];

	/**
	 * Config-specific checks a config may list in "checks", run after S11.
	 */
	const CHECKS = [ 'retention', 'patterns', 'resave', 'entry_details', 'spam_off', 'heavy_history', 'source_states', 'roles', 'global_styles', 'hooks', 'multisite' ];

	/**
	 * The client address and user agent every request is made from.
	 */
	const CLIENT_IP         = '203.0.113.7';
	const CLIENT_USER_AGENT = 'SwiftForms-Regression/1.0';

	/**
	 * Network sites of the "multisite" check whose tables and install marker (frozen version "1.0.0") the runner writes itself.
	 */
	const MULTISITE_SEEDED_SITES = [ 'alpha', 'beta' ];

	/**
	 * The test case, for go_to() and the user factory.
	 *
	 * @var WP_UnitTestCase_Base
	 */
	private $test;

	/**
	 * Snapshot texts produced so far, by path relative to the snapshot root.
	 *
	 * @var array<string, string>
	 */
	private $outputs = [];

	/**
	 * Failure messages.
	 *
	 * @var string[]
	 */
	private $failures = [];

	/**
	 * Number of assertions made.
	 *
	 * @var int
	 */
	private $checks = 0;

	/**
	 * Slug of the config being run.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Name of the variant being run, or an empty string for the base config.
	 *
	 * @var string
	 */
	private $variant = '';

	/**
	 * The config being run.
	 *
	 * @var array
	 */
	private $config = [];

	/**
	 * The forms the index holds after the config was applied, in a stable order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $forms = [];

	/**
	 * Literal text aliases for values that depend on the database: "post:12" => "post:contact" and so on.
	 *
	 * @var array<string, string>
	 */
	private $aliases = [];

	/**
	 * Entry IDs the config itself inserted (stable, never relabelled).
	 *
	 * @var int[]
	 */
	private $fixture_entry_ids = [];

	/**
	 * Entry ID to label, in the order the run meets them.
	 *
	 * @var array<int, string>
	 */
	private $entry_labels = [];

	/**
	 * User ID to label.
	 *
	 * @var array<int, string>
	 */
	private $user_labels = [];

	/**
	 * Highest row IDs already reported by delta().
	 *
	 * @var array<string, int>
	 */
	private $cursor = [];

	/**
	 * Mails already reported by delta().
	 *
	 * @var int
	 */
	private $mail_cursor = 0;

	/**
	 * Constructor.
	 *
	 * @param WP_UnitTestCase_Base $test The running test case (must offer sf_go_to() and sf_factory()).
	 */
	public function __construct( $test ) {
		$this->test = $test;
	}

	/**
	 * Apply a config and run every scenario.
	 *
	 * @param array $config Loaded config.
	 * @return array{outputs: array<string, string>, failures: string[], checks: int}
	 */
	public function run( array $config ) {
		$this->slug   = $config['slug'];
		$this->config = $config;

		$skipped = [];
		// The base config first, then every variant: the same stored data with the variant's options, each through the whole matrix.
		foreach ( array_merge( [ '' ], array_keys( $config['variants'] ) ) as $variant ) {
			$this->variant = (string) $variant;
			if ( '' !== $this->variant && 1 !== preg_match( '/^[a-z0-9-]+$/D', $this->variant ) ) {
				$this->failures[] = 'Variant name "' . $this->variant . '" must be lowercase letters, digits and dashes (it becomes part of a file name).';
				continue;
			}
			SF_User_Config_Loader::apply( $config, $this->variant );
			$this->prepare();

			foreach ( self::ORDER as $scenario ) {
				if ( isset( $config['skip'][ $scenario ] ) ) {
					$skipped[ $scenario ] = $config['skip'][ $scenario ];
					continue;
				}
				if ( ! in_array( $scenario, self::IMPLEMENTED, true ) ) {
					$this->failures[] = 'Scenario ' . $scenario . ' is not implemented; add it or list it in skip.';
					continue;
				}
				$this->begin_scenario();
				call_user_func( [ $this, 'scenario_' . strtolower( $scenario ) ], $config );
			}
			if ( '' === $this->variant ) {
				foreach ( (array) ( $config['checks'] ?? [] ) as $check ) {
					if ( ! in_array( $check, self::CHECKS, true ) ) {
						$this->failures[] = 'Unknown check "' . $check . '" in checks.';
						continue;
					}
					$this->begin_scenario();
					call_user_func( [ $this, 'check_' . $check ], $config );
				}
			}
		}
		$this->variant = '';
		// Removing a skip without implementing the scenario shows up here as a snapshot diff.
		$lines = [];
		foreach ( $skipped as $scenario => $reason ) {
			$lines[] = $scenario . ': ' . $reason;
		}
		$this->output( '_skipped.txt', $lines ? implode( "\n", $lines ) . "\n" : "(none)\n" );

		SF_User_Config_Loader::reset();
		wp_set_current_user( 0 );

		return [
			'outputs'  => $this->outputs,
			'failures' => $this->failures,
			'checks'   => $this->checks,
		];
	}

	/**
	 * Harness self-check: read the stored state back from the database.
	 *
	 * Dumps the site settings the config sets, every swiftforms_* option as the raw serialised bytes and every
	 * post's content, so a change to what a config writes (or to how it is
	 * written) is a diff. Post IDs are left out: they depend on the database.
	 */
	private function scenario_apply() {
		global $wpdb;

		$text = "== site ==\n";
		foreach ( [ 'home', 'siteurl', 'stylesheet', 'template', 'timezone_string', 'permalink_structure', 'WPLANG' ] as $name ) {
			$text .= $name . ' = ' . $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) . "\n";
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', [] );
			$text   .= 'network active plugins = ' . implode(
				', ',
				array_map(
					static function ( $file ) {
						return basename( dirname( $file ) );
					},
					array_keys( $network )
				)
			) . "\n";
		}

		$text .= "\n== options ==\n";
		$rows  = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'swiftforms\\_%' AND option_name <> 'swiftforms_source_edges' ORDER BY option_name", ARRAY_A );
		foreach ( $rows as $row ) {
			$text .= $row['option_name'] . ' = ' . $row['option_value'] . "\n";
		}
		if ( ! $rows ) {
			$text .= "(none)\n";
		}

		$text .= "\n== posts ==\n";
		foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
			$post  = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status, post_name, post_title, post_content FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			$text .= '-- ' . $key . ' (' . $post['post_type'] . '/' . $post['post_status'] . ', slug ' . $post['post_name'] . ', title ' . $post['post_title'] . ")\n" . $post['post_content'] . "\n";
		}
		if ( ! SF_User_Config_Loader::ids() ) {
			$text .= "(none)\n";
		}
		++$this->checks;
		$this->output( 'apply.txt', SF_Normaliser::text( $text ) );
	}

	// ---------------------------------------------------------------------
	// Preparation: the forms of the config, aliases for database IDs, request state.
	// ---------------------------------------------------------------------

	/**
	 * Read the forms the index holds, build the aliases, and note what the config inserted.
	 */
	private function prepare() {
		global $wpdb;

		$this->forms        = [];
		$this->aliases      = [];
		$this->entry_labels = [];
		$this->user_labels  = [];
		$post_keys          = array_flip( SF_User_Config_Loader::ids() );

		foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
			$this->aliases[ 'post:' . $id ] = 'post:' . $key;
		}
		foreach ( SF_User_Config_Loader::user_ids() as $key => $id ) {
			$this->user_labels[ (int) $id ] = '{USER:' . $key . '}';
		}

		$table = SwiftForms\Database::table( 'forms' );
		$rows  = $wpdb->get_results( "SELECT form_id, stored_id, source, title, status, locations FROM {$table}", ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$locations = json_decode( (string) $row['locations'], true );
			$locations = is_array( $locations ) ? $locations : [];
			$post_id   = 1 === preg_match( '/^post:(\d+)$/D', (string) $row['source'], $m ) ? (int) $m[1] : 0;
			$name      = isset( $post_keys[ $post_id ] ) ? $post_keys[ $post_id ] : (string) $row['source'];
			// A template or part stored in the database is named by the config's key for it; one that lives in the theme's files by its reference.
			$template_post = 0;
			if ( 1 === preg_match( '/^(template|template_part):[^\/]+\/\/(.+)$/D', (string) $row['source'], $tm ) ) {
				foreach ( $this->config['posts'] as $configured ) {
					if ( 'wp_' . $tm[1] === ( $configured['post_type'] ?? '' ) && $tm[2] === ( $configured['slug'] ?? $configured['key'] ) ) {
						$name          = (string) $configured['key'];
						$template_post = (int) ( SF_User_Config_Loader::ids()[ $configured['key'] ] ?? 0 );
					}
				}
			}

			// The page that shows the form: its own post when it is a page or post, otherwise the first live place that is one.
			$page_id = 0;
			$places  = isset( $locations['places'] ) && is_array( $locations['places'] ) ? $locations['places'] : [];
			array_unshift( $places, (string) $row['source'] );
			foreach ( $places as $place ) {
				if ( 1 === preg_match( '/^post:(\d+)$/D', (string) $place, $pm ) && in_array( get_post_type( (int) $pm[1] ), [ 'page', 'post' ], true ) ) {
					$page_id = (int) $pm[1];
					break;
				}
			}

			$this->forms[] = [
				'key'       => $name . '#' . (int) ( $locations['ordinal'] ?? 1 ),
				'form_id'   => (string) $row['form_id'],
				'stored_id' => (string) $row['stored_id'],
				'source'    => (string) $row['source'],
				'title'     => (string) $row['title'],
				'status'    => (string) $row['status'],
				'page_id'   => $page_id,
				'render_id' => $page_id > 0 ? $page_id : $template_post,
			];
		}
		usort(
			$this->forms,
			static function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);
		foreach ( $this->forms as $form ) {
			if ( $form['form_id'] !== $form['stored_id'] ) {
				$this->aliases[ $form['form_id'] ]                               = '{FORM:' . $form['key'] . '}';
				$this->aliases[ 'sf-' . substr( $form['form_id'], 0, 8 ) . '-' ] = 'sf-{FORM:' . $form['key'] . '}-';
			}
		}

		$this->fixture_entry_ids = [];
		foreach ( (array) ( $this->config['tables']['swiftforms_entries'] ?? [] ) as $entry ) {
			if ( isset( $entry['id'] ) ) {
				$this->fixture_entry_ids[] = (int) $entry['id'];
			}
		}

		$this->cursor      = [
			'entries' => (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . SwiftForms\Database::table( 'entries' ) ),
			'meta'    => (int) $wpdb->get_var( 'SELECT MAX(meta_id) FROM ' . SwiftForms\Database::table( 'entry_meta' ) ),
			'log'     => (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . SwiftForms\Database::table( 'log' ) ),
		];
		$this->mail_cursor = count( SF_User_Config_Loader::mails() );

		$_SERVER['REMOTE_ADDR']     = self::CLIENT_IP;
		$_SERVER['HTTP_USER_AGENT'] = self::CLIENT_USER_AGENT;
	}

	/**
	 * Start a scenario from a known state: no request counters, no half-rendered form, the frozen clock, a guest.
	 */
	private function begin_scenario() {
		$this->reset_limits( true );
		Form_Renderer::reset();
		Form_State::reset();
		Source_Stack::reset();
		Clock::freeze( SF_User_Config_Loader::FROZEN_TIME );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_GET                      = [];
	}

	/**
	 * Delete the request counters (coarse limiter, per-form limiter, duplicate guard) and, when asked, the visitor-mail limiter.
	 *
	 * @param bool $include_mail Also clear the per-address, daily and hourly mail counters.
	 */
	private function reset_limits( $include_mail = false ) {
		global $wpdb;

		$prefixes = [ 'swiftforms_c_', 'swiftforms_f_', 'swiftforms_d_' ];
		if ( $include_mail ) {
			$prefixes = array_merge( $prefixes, [ 'swiftforms_mla_', 'swiftforms_mld_', 'swiftforms_mlh_' ] );
		}
		foreach ( $prefixes as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $prefix ) . '%' ) );
			foreach ( is_array( $names ) ? $names : [] as $name ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			}
		}
	}

	/**
	 * The administrator the config created (or a new one).
	 *
	 * @return int
	 */
	private function admin_id() {
		$users = SF_User_Config_Loader::user_ids();
		return isset( $users['admin'] ) ? (int) $users['admin'] : (int) $this->test->sf_factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * The forms that are live (can be submitted).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function live_forms() {
		$closed = (array) ( $this->config['expect_closed'] ?? [] );
		return array_values(
			array_filter(
				$this->forms,
				static function ( $form ) use ( $closed ) {
					return 'live' === $form['status'] && ! in_array( $form['key'], $closed, true );
				}
			)
		);
	}

	/**
	 * The payload names a config lists for a form.
	 *
	 * @param array<string, mixed> $form Form.
	 * @param string               $kind valid, invalid or spam.
	 * @return string[]
	 */
	private function payload_names( array $form, $kind ) {
		$names = $this->config['submissions'][ $form['key'] ][ $kind ] ?? [];
		if ( 'valid' === $kind && ! $names ) {
			$this->failures[] = 'Config ' . $this->slug . ' lists no submissions.valid for the live form ' . $form['key'] . '.';
		}
		return (array) $names;
	}

	// ---------------------------------------------------------------------
	// Making text stable: labels for IDs that depend on the database.
	// ---------------------------------------------------------------------

	/**
	 * The label of an entry ID: the ID itself when the config inserted the entry, otherwise {E1}, {E2}... in the order the run meets them.
	 *
	 * @param mixed $id Entry ID.
	 * @return int|string
	 */
	private function entry_label( $id ) {
		$id = (int) $id;
		if ( in_array( $id, $this->fixture_entry_ids, true ) ) {
			return $id;
		}
		if ( ! isset( $this->entry_labels[ $id ] ) ) {
			$this->entry_labels[ $id ] = '{E' . ( count( $this->entry_labels ) + 1 ) . '}';
		}
		return $this->entry_labels[ $id ];
	}

	/**
	 * Text with the database-dependent values replaced: post references, derived formIds, entry references; then tokens and nonces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function text( $text ) {
		$text = SF_Normaliser::scrub( (string) $text, $this->aliases );
		$text = preg_replace_callback(
			'/swiftforms-entry-(\d+)/',
			function ( $m ) {
				return 'swiftforms-entry-' . $this->entry_label( $m[1] );
			},
			$text
		);
		return SF_Normaliser::tokens( $text );
	}

	/**
	 * Data made stable, key by key: IDs under known keys become labels, every string goes through text().
	 *
	 * @param mixed  $value Any value.
	 * @param string $key   The key the value sits under.
	 * @return mixed
	 */
	private function clean( $value, $key = '' ) {
		if ( $value instanceof stdClass ) {
			return (object) $this->clean( (array) $value, $key );
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->clean( $v, is_string( $k ) ? $k : $key );
			}
			return $out;
		}
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) && in_array( $key, [ 'id', 'entryId', 'entry', 'entry_id', 'user_id', 'userId' ], true ) ) {
			$value = (int) $value;
		}
		if ( is_int( $value ) ) {
			if ( in_array( $key, [ 'id', 'entryId', 'entry', 'entry_id' ], true ) ) {
				return $value > 0 ? $this->entry_label( $value ) : $value; // 0 means "no entry" (a form that stores nothing).
			}
			if ( in_array( $key, [ 'user_id', 'userId' ], true ) && $value > 0 ) {
				return $this->user_labels[ $value ] ?? '{USER:?}';
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( '_sf_v' === $key && SWIFTFORMS_VERSION === $value ) {
				return '{VERSION}';
			}
			// Markup SwiftForms renders (a confirmation) goes through the HTML normaliser, which absorbs core-version noise.
			return $this->text( 'html' === $key ? SF_Normaliser::html( $value ) : $value );
		}
		return $value;
	}

	/**
	 * JSON of cleaned data, in the normaliser's format.
	 *
	 * @param mixed $value Data.
	 * @return string
	 */
	private function json( $value ) {
		return SF_Normaliser::json( $this->clean( $value ) );
	}

	/**
	 * A titled block of a snapshot file.
	 *
	 * @param string $title Title.
	 * @param string $body  Body (ends with a newline or is empty).
	 * @return string
	 */
	private function block( $title, $body ) {
		return '== ' . $this->text( $title ) . " ==\n" . ( '' === $body || "\n" === substr( $body, -1 ) ? $body : $body . "\n" ) . "\n";
	}

	// ---------------------------------------------------------------------
	// Requests.
	// ---------------------------------------------------------------------

	/**
	 * The control fields of a submission as the view module sends them: a render token for the form as it is now, the browser-measured time js.10000, an empty honeypot.
	 *
	 * @param array<string, mixed>   $form Form.
	 * @param array<string, mixed>   $over Controls to replace (null removes one).
	 * @return array<string, mixed>
	 */
	private function controls( array $form, array $over = [] ) {
		$schema = Schema_Resolver::resolve( $form['form_id'], $form['source'] );
		if ( null === $schema ) {
			$this->failures[] = 'The form ' . $form['key'] . ' does not resolve from its source.';
			return [];
		}
		$controls = [
			'_sf_form'   => $form['form_id'],
			'_sf_source' => $form['source'],
			'_sf_token'  => Render_Token::issue( $form['form_id'], $form['source'], $schema->hash ),
			'_sf_hp'     => '',
			'_sf_t'      => 'js.10000',
			'_sf_v'      => SWIFTFORMS_VERSION,
			'_sf_i'      => '1',
		];
		foreach ( $over as $name => $value ) {
			if ( null === $value ) {
				unset( $controls[ $name ] );
			} else {
				$controls[ $name ] = $value;
			}
		}
		return $controls;
	}

	/**
	 * Dispatch a REST request in-process, as a guest or as a user.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $route   Route including the namespace.
	 * @param array<string, mixed> $params  Query (GET) or JSON body.
	 * @param int                  $user_id User ID, or 0 for a guest.
	 * @return array{status:int, data:mixed, headers:array<string, string>}
	 */
	private function rest( $method, $route, array $params = [], $user_id = 0 ) {
		wp_set_current_user( (int) $user_id );
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		$response = rest_do_request( $request );
		wp_set_current_user( 0 );

		$server = rest_get_server();
		$data   = $server->response_to_data( $response, false );
		return [
			'status'  => (int) $response->get_status(),
			'data'    => $data,
			'headers' => $response->get_headers(),
		];
	}

	/**
	 * POST a submission to the public route.
	 *
	 * @param array<string, mixed> $form     Form.
	 * @param array<string, mixed> $sf       Field values.
	 * @param array<string, mixed> $controls Control overrides.
	 * @param int                  $user_id  User ID (0 for a guest).
	 * @return array{status:int, data:mixed, headers:array<string, string>}
	 */
	private function submit( array $form, array $sf, array $controls = [], $user_id = 0 ) {
		$this->reset_limits( false );
		return $this->rest( 'POST', '/swiftforms/v1/submit', array_merge( $this->controls( $form, $controls ), [ 'sf' => $sf ] ), $user_id );
	}

	/**
	 * A response as text: status, the headers a visitor-facing route must set, the body.
	 *
	 * @param array{status:int, data:mixed, headers:array<string, string>} $response Response.
	 * @return string
	 */
	private function describe( array $response ) {
		$text = 'status: ' . $response['status'] . "\n";
		foreach ( [ 'Cache-Control', 'Retry-After' ] as $name ) {
			if ( isset( $response['headers'][ $name ] ) ) {
				$text .= strtolower( $name ) . ': ' . $response['headers'][ $name ] . "\n";
			}
		}
		return $text . $this->json( $response['data'] );
	}

	/**
	 * What a request left behind, since the last call: new entries with their values and meta, new log rows, captured mails.
	 *
	 * Also checks the shape of every new row (see assert_row_shape()).
	 *
	 * @return string "(nothing stored, nothing mailed)" when there is nothing.
	 */
	private function delta() {
		global $wpdb;

		$text = '';

		$entries_table = SwiftForms\Database::table( 'entries' );
		$entries       = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, schema_hash, source, status, starred, user_id, HEX(ip) AS ip, user_agent, created_gmt, updated_gmt FROM {$entries_table} WHERE id > %d ORDER BY id", $this->cursor['entries'] ), ARRAY_A );
		foreach ( is_array( $entries ) ? $entries : [] as $entry ) {
			$this->cursor['entries'] = max( $this->cursor['entries'], (int) $entry['id'] );
			$this->assert_row_shape( 'entries', $entry );
			$entry['ip'] = '' === $entry['ip'] ? null : $entry['ip'];
			$text       .= "entry:\n" . $this->json( $entry );

			$values = $wpdb->get_results( $wpdb->prepare( 'SELECT entry_id, field, idx, value FROM ' . SwiftForms\Database::table( 'entry_values' ) . ' WHERE entry_id = %d ORDER BY field, idx', (int) $entry['id'] ), ARRAY_A );
			foreach ( is_array( $values ) ? $values : [] as $value ) {
				$this->assert_row_shape( 'entry_values', $value );
				$text .= '  value ' . $value['field'] . '[' . $value['idx'] . '] = ' . $this->text( wp_json_encode( $value['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
			}
			if ( ! $values ) {
				$text .= "  (no value rows)\n";
			}
		}

		$meta = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, entry_id, meta_key, meta_value, user_id, created_gmt FROM ' . SwiftForms\Database::table( 'entry_meta' ) . ' WHERE meta_id > %d ORDER BY meta_id', $this->cursor['meta'] ), ARRAY_A );
		foreach ( is_array( $meta ) ? $meta : [] as $row ) {
			$this->cursor['meta'] = max( $this->cursor['meta'], (int) $row['meta_id'] );
			$this->assert_row_shape( 'entry_meta', $row );
			unset( $row['meta_id'] );
			$text .= "meta:\n" . $this->json( $row );
		}

		$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, ref, level, message, context FROM ' . SwiftForms\Database::table( 'log' ) . ' WHERE id > %d ORDER BY id', $this->cursor['log'] ), ARRAY_A );
		foreach ( is_array( $logs ) ? $logs : [] as $row ) {
			$this->cursor['log'] = max( $this->cursor['log'], (int) $row['id'] );
			$context             = json_decode( (string) $row['context'], true );
			$text               .= 'log ' . $row['type'] . '/' . $row['level'] . ' ' . $this->text( $row['ref'] ) . ': ' . $this->text( $row['message'] ) . ' ' . $this->text( wp_json_encode( $this->clean( null === $context ? $row['context'] : $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n";
		}

		$mails = SF_User_Config_Loader::mails();
		$total = count( $mails );
		for ( $i = $this->mail_cursor; $i < $total; $i++ ) {
			$text .= $this->format_mail( $mails[ $i ], $i - $this->mail_cursor + 1 );
		}
		$this->mail_cursor = $total;

		return '' === $text ? "(nothing stored, nothing logged, nothing mailed)\n" : $text;
	}

	/**
	 * A captured mail as text. The mail is either the arguments of wp_mail() (pre_wp_mail capture) or the final PHPMailer state (phpmailer_init capture).
	 *
	 * @param array<string, mixed> $mail  Captured mail.
	 * @param int                  $index Position among the mails of this request.
	 * @return string
	 */
	private function format_mail( array $mail, $index ) {
		$lines = [ '-- mail ' . $index . ' (' . ( isset( $mail['via'] ) ? $mail['via'] : 'wp_mail arguments' ) . ') --' ];
		if ( isset( $mail['via'] ) ) {
			$addresses = static function ( $list ) {
				$out = [];
				foreach ( (array) $list as $pair ) {
					$out[] = '' !== (string) ( $pair[1] ?? '' ) ? $pair[1] . ' <' . $pair[0] . '>' : $pair[0];
				}
				return implode( ', ', $out );
			};
			$lines[]   = 'from: ' . $mail['from_name'] . ' <' . $mail['from'] . '>';
			$lines[]   = 'sender: ' . $mail['sender'];
			$lines[]   = 'mailer: ' . $mail['mailer'];
			$lines[]   = 'to: ' . $addresses( $mail['to'] );
			$lines[]   = 'cc: ' . $addresses( $mail['cc'] );
			$lines[]   = 'bcc: ' . $addresses( $mail['bcc'] );
			$lines[]   = 'reply-to: ' . $addresses( $mail['reply_to'] );
			$lines[]   = 'subject: ' . $mail['subject'];
			$lines[]   = 'content-type: ' . $mail['content_type'] . '; charset=' . $mail['charset'];
			foreach ( (array) $mail['headers'] as $header ) {
				$lines[] = 'header: ' . $header[0] . ': ' . $header[1];
			}
			$lines[] = '-- body --';
			$lines[] = false !== stripos( (string) $mail['content_type'], 'html' ) ? SF_Normaliser::html( $mail['body'] ) : $mail['body'];
			$lines[] = '-- alt body --';
			$lines[] = '' === $mail['alt_body'] ? '(none)' : $mail['alt_body'];
		} else {
			$to      = is_array( $mail['to'] ?? '' ) ? implode( ', ', $mail['to'] ) : (string) ( $mail['to'] ?? '' );
			$headers = (array) ( $mail['headers'] ?? [] );
			$lines[] = 'to: ' . $to;
			$lines[] = 'subject: ' . ( $mail['subject'] ?? '' );
			foreach ( $headers as $header ) {
				$lines[] = 'header: ' . $header;
			}
			$lines[] = 'attachments: ' . ( $mail['attachments'] ? implode( ', ', (array) $mail['attachments'] ) : '(none)' );
			$body    = (string) ( $mail['message'] ?? '' );
			$lines[] = '-- body --';
			$lines[] = false !== stripos( implode( "\n", $headers ), 'text/html' ) ? SF_Normaliser::html( $body ) : $body;
		}
		return $this->text( SF_Normaliser::text( implode( "\n", $lines ) ) ) . "\n";
	}

	/**
	 * Assert that a freshly written row has the shape of 1.0.0 rows (C-4, spec principle 1): the same columns and
	 * the same kind of value in each column as the rows the config inserted raw, and always the documented formats.
	 *
	 * @param string               $table Table name without prefix.
	 * @param array<string, mixed> $row   Row as read from the database.
	 */
	private function assert_row_shape( $table, array $row ) {
		++$this->checks;
		$formats = [
			'entries'      => [
				'id'          => 'int',
				'form_id'     => 'uuid',
				'schema_hash' => 'sha256',
				'source'      => 'ref',
				'status'      => 'string',
				'starred'     => 'bit',
				'user_id'     => 'int',
				'ip'          => 'ip',
				'user_agent'  => 'string',
				'created_gmt' => 'datetime',
				'updated_gmt' => 'datetime',
			],
			'entry_values' => [
				'entry_id' => 'int',
				'field'    => 'string',
				'idx'      => 'int',
				'value'    => 'string',
			],
			'entry_meta'   => [
				'entry_id'    => 'int',
				'meta_key'    => 'string',
				'meta_value'  => 'string',
				'user_id'     => 'int',
				'created_gmt' => 'datetime',
			],
		];
		foreach ( $formats[ $table ] as $column => $format ) {
			if ( ! array_key_exists( $column, $row ) ) {
				$this->failures[] = 'Row shape: ' . $table . ' lacks the column ' . $column . '.';
				continue;
			}
			$kind = $this->kind( $row[ $column ] );
			if ( 'ip' === $format ) {
				$ok = 'null' === $kind || 1 === preg_match( '/^(?:[0-9A-F]{8}|[0-9A-F]{32})$/D', (string) $row[ $column ] );
			} elseif ( 'bit' === $format ) {
				$ok = in_array( (string) $row[ $column ], [ '0', '1' ], true );
			} elseif ( 'string' === $format ) {
				$ok = null !== $row[ $column ] && is_string( $row[ $column ] );
				if ( 'user_agent' === $column ) {
					$ok = null === $row[ $column ] || is_string( $row[ $column ] );
				}
			} else {
				$ok = $kind === $format;
			}
			if ( ! $ok ) {
				$this->failures[] = 'Row shape: ' . $table . '.' . $column . ' should be ' . $format . ', got ' . $kind . ' (' . wp_json_encode( $row[ $column ] ) . ').';
			}
		}

		// The rows the config itself inserted are the 1.0.0 reference: same kind of value in every column.
		$reference = $this->reference_row( $table );
		if ( null !== $reference ) {
			foreach ( $reference as $column => $value ) {
				if ( in_array( $column, [ 'ip', 'user_agent', 'value', 'meta_value', 'field', 'meta_key', 'status' ], true ) || ! array_key_exists( $column, $row ) ) {
					continue;
				}
				if ( $this->kind( $value ) !== $this->kind( $row[ $column ] ) ) {
					$this->failures[] = 'Row shape: ' . $table . '.' . $column . ' of a new row is ' . $this->kind( $row[ $column ] ) . ' but the config\'s own row is ' . $this->kind( $value ) . '.';
				}
			}
			if ( array_diff( array_keys( $reference ), array_keys( $row ) ) ) {
				$this->failures[] = 'Row shape: a new ' . $table . ' row lacks columns the config\'s own rows have.';
			}
		}
	}

	/**
	 * The first row a config inserted into a table, as the database returns it, or null.
	 *
	 * @param string $table Table name without prefix.
	 * @return array<string, mixed>|null
	 */
	private function reference_row( $table ) {
		global $wpdb;

		$rows = $this->config['tables'][ 'swiftforms_' . $table ] ?? [];
		if ( ! $rows ) {
			return null;
		}
		$first = $rows[0];
		$full  = SwiftForms\Database::table( $table );
		if ( 'entries' === $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, schema_hash, source, status, starred, user_id, HEX(ip) AS ip, user_agent, created_gmt, updated_gmt FROM {$full} WHERE id = %d", (int) $first['id'] ), ARRAY_A );
			if ( is_array( $row ) ) {
				$row['ip'] = '' === $row['ip'] ? null : $row['ip'];
			}
			return is_array( $row ) ? $row : null;
		}
		if ( 'entry_values' === $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT entry_id, field, idx, value FROM {$full} WHERE entry_id = %d AND field = %s AND idx = %d", (int) $first['entry_id'], (string) $first['field'], (int) $first['idx'] ), ARRAY_A );
			return is_array( $row ) ? $row : null;
		}
		return null;
	}

	/**
	 * The kind of a stored value: null, int, datetime, uuid, sha256, ref (post:12 style) or string.
	 *
	 * @param mixed $value Value as the database returns it.
	 * @return string
	 */
	private function kind( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		$value = (string) $value;
		if ( 1 === preg_match( '/^[0-9]+$/D', $value ) ) {
			return 'int';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return 'datetime';
		}
		if ( SwiftForms\Uuid::is_valid( $value ) ) {
			return 'uuid';
		}
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/D', $value ) ) {
			return 'sha256';
		}
		if ( 1 === preg_match( '/^[a-z_]+:\S+$/D', $value ) ) {
			return 'ref';
		}
		return 'string';
	}

	/**
	 * Record a failed expectation.
	 *
	 * @param bool   $condition What must hold.
	 * @param string $message   The failure message.
	 */
	private function expect( $condition, $message ) {
		++$this->checks;
		if ( ! $condition ) {
			$this->failures[] = $this->text( $message );
		}
	}

	/**
	 * S1: render every form location as guest and as admin.
	 *
	 * A location is a page or post (the_content), a synced pattern (a core/block reference), a template part or a
	 * template of the active theme (rendered the way the theme renders it), or a widget area. For each one the
	 * snapshot holds the normalised HTML, the SwiftForms assets the page enqueues and the client state the view
	 * module reads. A location without a form must enqueue no SwiftForms asset. Every token and signed render
	 * time in the HTML is verified before the normaliser replaces it.
	 */
	private function scenario_s1() {
		$admin = $this->admin_id();

		foreach ( [
			'guest' => 0,
			'admin' => $admin,
		] as $who => $user_id ) {
			wp_set_current_user( $user_id );
			$text = '';
			foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
				$location = $this->render_location( (int) $id );
				if ( null === $location ) {
					continue;
				}
				if ( $location['has_form'] ) {
					$this->assert_controls_valid( $key, $who, $location['html'] );
					$this->assert_anchors( $key, $who, (string) get_post( $id )->post_content, $location['html'] );
				} else {
					$this->assert_no_assets( $key, $who, $location['assets'] );
				}
				$text .= '== ' . $key . ' (' . get_post_type( $id ) . ") ==\n" . $this->text( SF_Normaliser::html( $location['html'] ) ) . "\n"
					. '-- assets: ' . ( $location['assets'] ? implode( ', ', $location['assets'] ) : '(none)' ) . "\n"
					. ( '' !== $location['data'] ? '-- client state --' . "\n" . $this->text( $location['data'] ) . "\n" : '' ) . "\n";
			}
			foreach ( (array) ( $this->config['renders'] ?? [] ) as $spec ) {
				$location = $this->render_extra( $spec );
				if ( null === $location ) {
					continue;
				}
				$label = $spec['name'];
				if ( $location['has_form'] ) {
					$this->assert_controls_valid( $label, $who, $location['html'] );
				} else {
					$this->assert_no_assets( $label, $who, $location['assets'] );
				}
				$text .= '== ' . $label . " (rendered: {$spec['type']}) ==\n" . $this->text( SF_Normaliser::html( $location['html'] ) ) . "\n"
					. '-- assets: ' . ( $location['assets'] ? implode( ', ', $location['assets'] ) : '(none)' ) . "\n"
					. ( '' !== $location['data'] ? '-- client state --' . "\n" . $this->text( $location['data'] ) . "\n" : '' ) . "\n";
			}
			$this->output( 'S1.' . $who . '.txt', '' === $text ? "(none)\n" : $text );
		}
		wp_set_current_user( 0 );
	}

	/**
	 * Render one stored post as a visitor would meet it.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $nojs_key A no-JS result key to show (S4), or an empty string.
	 * @return array{html:string, has_form:bool, assets:string[], data:string}|null Null for a post that is not a location.
	 */
	private function render_location( $post_id, $nojs_key = '' ) {
		global $_wp_current_template_id;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		Form_Renderer::reset();
		Form_State::reset();
		Source_Stack::reset();
		wp_scripts()->queue = [];
		wp_styles()->queue  = [];
		$this->reset_interactivity();

		$url     = get_permalink( $post );
		$context = 0;
		if ( 'wp_block' === $post->post_type || 0 === strpos( $post->post_type, 'wp_template' ) ) {
			$url = home_url( '/' );
		}
		if ( 'wp_template' === $post->post_type ) {
			// A template that shows a post (single, page) is rendered for the first post of the config that it applies to.
			$context = $this->template_context( $post->post_name );
			if ( $context > 0 ) {
				$url = get_permalink( $context );
			}
		}
		if ( '' !== $nojs_key ) {
			$url = add_query_arg( Nojs_Handler::RESULT_PARAM, $nojs_key, $url );
		}
		$this->test->sf_go_to( $url );
		if ( '' !== $nojs_key ) {
			Nojs_Handler::maybe_handle();
		}

		switch ( $post->post_type ) {
			case 'page':
			case 'post':
				// A draft or trashed page is a 404 for a guest, so the main query holds no post: show it the way the editor's preview does.
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				setup_postdata( $post );
				$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				wp_reset_postdata();
				break;

			case 'wp_block':
				$html = do_blocks( '<!-- wp:block {"ref":' . (int) $post->ID . '} /-->' );
				break;

			case 'wp_template_part':
				$html = do_blocks(
					'<!-- wp:template-part ' . wp_json_encode(
						[
							'slug'  => $post->post_name,
							'theme' => get_stylesheet(),
						]
					) . ' /-->'
				);
				break;

			case 'wp_template':
				if ( $context > 0 && have_posts() ) {
					the_post();
				}
				$_wp_current_template_id = get_stylesheet() . '//' . $post->post_name; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- What core sets while it renders a template.
				$html                    = do_blocks( $post->post_content );
				$_wp_current_template_id = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- See above.
				break;

			default:
				return null;
		}

		return $this->finish_render( $html );
	}

	/**
	 * What a render left behind: the HTML, whether it holds a form, the SwiftForms assets the page enqueues and the client state.
	 *
	 * @param string $html Rendered HTML.
	 * @return array{html:string, has_form:bool, assets:string[], data:string}
	 */
	private function finish_render( $html ) {
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$assets = [];
		foreach ( wp_styles()->queue as $handle ) {
			if ( 0 === stripos( $handle, 'swiftforms' ) ) {
				$assets[] = 'style ' . $handle;
			}
		}
		foreach ( wp_scripts()->queue as $handle ) {
			if ( 0 === stripos( $handle, 'swiftforms' ) ) {
				$assets[] = 'script ' . $handle;
			}
		}
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$modules = ob_get_clean();
		if ( preg_match_all( '/id=["\']([^"\']*swiftforms[^"\']*)-js-module["\']/i', (string) $modules, $found ) ) {
			foreach ( $found[1] as $id ) {
				$assets[] = 'module ' . $id;
			}
		}

		// A site that turned the plugin's styles off (general.load_styles false): the style stays in the queue, but must not be printed.
		if ( false === Settings::get_value( 'general.load_styles' ) && in_array( 'style swiftforms-form', $assets, true ) ) {
			ob_start();
			wp_print_styles();
			$printed = (string) ob_get_clean();
			$this->expect( false === strpos( $printed, 'swiftforms-form-css' ), 'S1: general.load_styles is false but the SwiftForms stylesheet is printed.' );
			$assets[] = 'style printed: ' . ( false === strpos( $printed, 'swiftforms-form-css' ) ? 'no (load_styles is off)' : 'YES' );
		}

		return [
			'html'     => $html,
			'has_form' => false !== strpos( $html, 'name="_sf_form"' ),
			'assets'   => $assets,
			'data'     => $this->interactivity_data(),
		];
	}

	/**
	 * The post a template is rendered for: single shows the first post, page the first page of the config.
	 *
	 * @param string $slug Template slug.
	 * @return int Post ID, or 0 for a template that shows no post.
	 */
	private function template_context( $slug ) {
		$type = [
			'single' => 'post',
			'page'   => 'page',
		][ $slug ] ?? '';
		if ( '' === $type ) {
			return 0;
		}
		foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
			if ( $type === get_post_type( $id ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Render a location that is not a stored post: a template or part of the theme (a file or the database), a widget area or the blog index.
	 *
	 * The config lists them under "renders" as { name, type: template | template_part | widget_area | blog_index, id | sidebar }.
	 *
	 * @param array<string, string> $spec     One entry of the config's "renders".
	 * @param string                $nojs_key A no-JS result key to show (S4), or an empty string.
	 * @return array{html:string, has_form:bool, assets:string[], data:string}|null
	 */
	private function render_extra( array $spec, $nojs_key = '' ) {
		global $_wp_current_template_id;

		Form_Renderer::reset();
		Form_State::reset();
		Source_Stack::reset();
		wp_scripts()->queue = [];
		wp_styles()->queue  = [];
		$this->reset_interactivity();
		$home = home_url( '/' );
		if ( '' !== $nojs_key ) {
			$home = add_query_arg( Nojs_Handler::RESULT_PARAM, $nojs_key, $home );
		}
		$this->test->sf_go_to( $home );
		if ( '' !== $nojs_key ) {
			Nojs_Handler::maybe_handle();
		}

		switch ( $spec['type'] ) {
			case 'template':
				$template = get_block_template( get_stylesheet() . '//' . $spec['id'], 'wp_template' );
				if ( ! $template instanceof WP_Block_Template ) {
					$this->failures[] = 'S1: the template ' . $spec['id'] . ' of the theme does not exist.';
					return null;
				}
				$context = $this->template_context( (string) $template->slug );
				if ( $context > 0 ) {
					$this->test->sf_go_to( get_permalink( $context ) );
					if ( have_posts() ) {
						the_post();
					}
				}
				$_wp_current_template_id = $template->id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- What core sets while it renders a template.
				$html                    = do_blocks( $template->content );
				$_wp_current_template_id = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- See above.
				break;

			case 'template_part':
				$html = do_blocks(
					'<!-- wp:template-part ' . wp_json_encode(
						[
							'slug'  => $spec['id'],
							'theme' => get_stylesheet(),
						]
					) . ' /-->'
				);
				break;

			case 'widget_area':
				ob_start();
				dynamic_sidebar( $spec['sidebar'] );
				$html = (string) ob_get_clean();
				break;

			case 'blog_index':
				// The loop of the fixture theme's index.php, on the main query of the posts page.
				$this->test->sf_go_to( home_url( '/' ) );
				ob_start();
				while ( have_posts() ) {
					the_post();
					the_content();
				}
				$html = (string) ob_get_clean();
				wp_reset_postdata();
				break;

			default:
				$this->failures[] = 'S1: unknown render type "' . $spec['type'] . '".';
				return null;
		}
		return $this->finish_render( $html );
	}

	/**
	 * Forget what core keeps for the rest of a request: the client state of the Interactivity API and the queue
	 * and the printed list of script modules. The next render is a new request.
	 */
	private function reset_interactivity() {
		// Core 6.8 keeps the "enqueued" flag on the registered module; later cores keep a queue, handled below.
		wp_dequeue_script_module( SwiftForms\Assets::VIEW_MODULE );
		$objects = [ wp_script_modules() ];
		if ( function_exists( 'wp_interactivity' ) ) {
			$objects[] = wp_interactivity();
		}
		foreach ( $objects as $object ) {
			foreach ( [ 'state_data', 'config_data', 'namespace_stack', 'context_stack', 'queue', 'done' ] as $property ) {
				if ( property_exists( $object, $property ) ) {
					$reflection = new ReflectionProperty( $object, $property );
					if ( PHP_VERSION_ID < 80100 ) {
						$reflection->setAccessible( true );
					}
					$reflection->setValue( $object, [] );
				}
			}
		}
	}

	/**
	 * The client state core would hand the view module for the page (core 6.7 and later print it from this filter), or an empty string.
	 *
	 * @return string
	 */
	private function interactivity_data() {
		$data = apply_filters( 'script_module_data_@wordpress/interactivity', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook.
		return is_array( $data ) && $data ? trim( SF_Normaliser::json( $data ) ) : '';
	}

	/**
	 * Every form on a rendered location carries a valid render token and a valid signed render time.
	 *
	 * @param string $key  Post key.
	 * @param string $who  "guest" or "admin".
	 * @param string $html Rendered HTML.
	 */
	private function assert_controls_valid( $key, $who, $html ) {
		$pick    = static function ( $name ) use ( $html ) {
			preg_match_all( '/<input type="hidden" name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m );
			return $m[1];
		};
		$forms   = $pick( '_sf_form' );
		$sources = $pick( '_sf_source' );
		$tokens  = $pick( '_sf_token' );
		$times   = $pick( '_sf_t' );

		$this->expect( count( $forms ) === count( $sources ) && count( $forms ) === count( $tokens ) && count( $forms ) === count( $times ), 'S1 (' . $who . '): ' . $key . ' has forms with missing hidden controls.' );
		foreach ( $forms as $i => $form_id ) {
			$source = html_entity_decode( $sources[ $i ] ?? '', ENT_QUOTES );
			$schema = Schema_Resolver::resolve( $form_id, $source );
			$this->expect( null !== $schema, 'S1 (' . $who . '): ' . $key . ' renders a form (' . $form_id . ', ' . $source . ') that does not resolve.' );
			if ( null === $schema ) {
				continue;
			}
			$token = Render_Token::verify( $tokens[ $i ] ?? '', $form_id, $source, [ $schema->hash ] );
			$this->expect( 'valid' === $token['state'], 'S1 (' . $who . '): ' . $key . ' form ' . $form_id . ' has a render token that is ' . $token['state'] . '.' );
			$time = Time_Trap::parse( $times[ $i ] ?? '', $form_id, $source );
			$this->expect( 'signed' === $time['format'], 'S1 (' . $who . '): ' . $key . ' form ' . $form_id . ' has a render time that is ' . $time['format'] . '.' );
		}
	}

	/**
	 * Where core prints the anchor of a dynamic block, the stored anchor of every form block is an id in the render.
	 *
	 * @param string $key     Post key.
	 * @param string $who     "guest" or "admin".
	 * @param string $content Stored post content.
	 * @param string $html    Rendered HTML.
	 */
	private function assert_anchors( $key, $who, $content, $html ) {
		if ( ! function_exists( 'wp_apply_anchor_support' ) ) {
			return; // Core before the release that prints the anchor of a dynamic block; the normaliser removes it from the HTML.
		}
		if ( preg_match_all( '/<!-- wp:swiftforms\/form \{[^}]*"anchor":"([^"]+)"/', $content, $m ) ) {
			foreach ( $m[1] as $anchor ) {
				$this->expect( false !== strpos( $html, ' id="' . $anchor . '"' ), 'S1 (' . $who . '): the anchor "' . $anchor . '" of a form in ' . $key . ' is not printed as an id.' );
			}
		}
	}

	/**
	 * Fail when a SwiftForms script, style or script module is enqueued where there is no form (a site with a form in an active widget area is the exception).
	 *
	 * @param string   $key    Location.
	 * @param string   $who    guest or admin.
	 * @param string[] $assets Assets the render enqueued.
	 */
	private function assert_no_assets( $key, $who, array $assets ) {
		// A page of a site with a form in an active widget area shows that form (the theme prints the sidebar on every page), so it needs the assets.
		foreach ( $this->forms as $form ) {
			if ( 0 === strpos( $form['source'], 'widget:' ) && 'live' === $form['status'] ) {
				$this->expect( (bool) $assets, 'S1 (' . $who . '): location "' . $key . '" shows an active widget form but enqueues no SwiftForms assets.' );
				return;
			}
		}
		$this->expect( ! $assets, 'S1 (' . $who . '): post "' . $key . '" has no SwiftForms form but enqueues SwiftForms assets: ' . implode( ', ', $assets ) );
	}

	/**
	 * S2: valid submissions over REST, one request per payload a config lists for each live form.
	 *
	 * Built the way the view module builds them (a token issued now for the form's current schema, js.10000 as the
	 * measured time, an empty honeypot). Guests first; then the first form once more as an administrator, who is
	 * the only caller that gets an entryId. Snapshot: the response, the rows the request wrote (shape asserted), the
	 * mails it sent and the log rows it added.
	 */
	private function scenario_s2() {
		$out   = '';
		$forms = $this->live_forms();
		$admin = $this->admin_id();

		foreach ( $forms as $form ) {
			foreach ( $this->payload_names( $form, 'valid' ) as $name ) {
				$sf       = SF_VALUES( $name );
				$request  = $this->controls( $form ) + [ 'sf' => $sf ];
				$response = $this->submit( $form, $sf );
				$delta    = $this->delta();

				$this->expect( 200 === $response['status'] && 'success' === ( $response['data']['status'] ?? '' ), 'S2: ' . $form['key'] . ' / ' . $name . ' was not accepted (' . $response['status'] . ').' );
				$this->expect( ! isset( $response['data']['entryId'] ), 'S2: ' . $form['key'] . ' / ' . $name . ' gave a guest an entryId.' );
				$out .= $this->block( 'S2 ' . $form['key'] . ' / ' . $name . ' (guest)', "request:\n" . $this->json( $request ) . "response:\n" . $this->describe( $response ) . "left behind:\n" . $delta );
			}
		}

		if ( $forms ) {
			$form = $forms[0];
			$name = $this->payload_names( $form, 'valid' )[0] ?? '';
			if ( '' !== $name ) {
				$response = $this->submit( $form, SF_VALUES( $name ), [], $admin );
				$delta    = $this->delta();
				$stored   = false !== strpos( $delta, 'entry:' );
				$this->expect( 200 === $response['status'], 'S2: ' . $form['key'] . ' / ' . $name . ' was not accepted as an administrator.' );
				$this->expect( $stored === isset( $response['data']['entryId'] ), 'S2: an administrator gets an entryId exactly when an entry was stored (' . $form['key'] . ').' );
				$out .= $this->block( 'S2 ' . $form['key'] . ' / ' . $name . ' (administrator)', "response:\n" . $this->describe( $response ) . "left behind:\n" . $delta );
			}
		}

		$this->output( 'S2.txt', '' === $out ? "(no live form)\n" : $out );
	}

	/**
	 * S3: invalid submissions: each required field missing, then the invalid payloads a config lists.
	 *
	 * Snapshot: the 422 payloads. Nothing may be stored or mailed.
	 */
	private function scenario_s3() {
		$out = '';
		foreach ( $this->live_forms() as $form ) {
			$schema = Schema_Resolver::resolve( $form['form_id'], $form['source'] );
			$valid  = $this->payload_names( $form, 'valid' );
			if ( null === $schema || ! $valid ) {
				continue;
			}
			$base = SF_VALUES( $valid[0] );

			$text = '';
			foreach ( $schema->fields as $field ) {
				if ( empty( $field['required'] ) || 'hidden' === $field['type'] ) {
					continue;
				}
				$sf = $base;
				unset( $sf[ $field['name'] ] );
				$response = $this->submit( $form, $sf );
				$delta    = $this->delta();
				$this->expect( 422 === $response['status'] && isset( $response['data']['errors'] ) && isset( ( (array) $response['data']['errors'] )[ $field['name'] ] ), 'S3: ' . $form['key'] . ' accepts a submission without the required field ' . $field['name'] . ' (' . $response['status'] . ').' );
				$this->expect( false === strpos( $delta, 'entry:' ) && false === strpos( $delta, '-- mail' ), 'S3: ' . $form['key'] . ' stored or mailed a submission without ' . $field['name'] . '.' );
				$text .= '-- without ' . $field['name'] . " --\n" . $this->describe( $response );
			}

			foreach ( $this->payload_names( $form, 'invalid' ) as $name ) {
				$response = $this->submit( $form, SF_VALUES( $name ) );
				$delta    = $this->delta();
				$this->expect( 422 === $response['status'], 'S3: ' . $form['key'] . ' / ' . $name . ' should be invalid (' . $response['status'] . ').' );
				$this->expect( false === strpos( $delta, 'entry:' ) && false === strpos( $delta, '-- mail' ), 'S3: ' . $form['key'] . ' / ' . $name . ' stored or mailed an invalid submission.' );
				$text .= '-- payload ' . $name . " --\n" . $this->describe( $response );
			}
			$out .= $this->block( 'S3 ' . $form['key'], $text );
		}
		$this->output( 'S3.txt', '' === $out ? "(no live form)\n" : $out );
	}

	/**
	 * S4: forms posted without JavaScript: valid, invalid and an expired token.
	 *
	 * Each is posted to Nojs_Handler the way a browser posts the page (the signed render time of the form, the
	 * token of the form as rendered) and the 303 it answers with is captured instead of exiting. A result key is
	 * checked (32 hex characters, a stored result) before the normaliser replaces it, and the page it points at is
	 * rendered with it. A redirect confirmation goes straight to its target.
	 */
	private function scenario_s4() {
		$out = '';
		$this->capture_exits();

		foreach ( $this->live_forms() as $form ) {
			$valid = $this->payload_names( $form, 'valid' );
			// A form in a widget is posted to the home page, where the sidebar shows; its result is read from the widget area.
			$widget_area = null;
			if ( 0 === strpos( $form['source'], 'widget:' ) ) {
				foreach ( (array) ( $this->config['renders'] ?? [] ) as $spec ) {
					if ( 'widget_area' === $spec['type'] ) {
						$widget_area = $spec;
						break;
					}
				}
			}
			if ( ( $form['render_id'] < 1 && null === $widget_area ) || ! $valid ) {
				$out .= $this->block( 'S4 ' . $form['key'], "(no page or stored template shows this form, so there is nothing to post to)\n" );
				continue;
			}
			$cases = [ 'valid' => [ SF_VALUES( $valid[0] ), 0 ] ];
			$bad   = $this->payload_names( $form, 'invalid' );
			if ( $bad ) {
				$cases['invalid'] = [ SF_VALUES( $bad[0] ), 0 ];
			}
			$cases['expired token'] = [ SF_VALUES( $valid[0] ), 200 * HOUR_IN_SECONDS ];

			$text = '';
			foreach ( $cases as $label => $case ) {
				$this->reset_limits( false );
				$exit  = $this->post_without_js( $form, $case[0], $case[1] );
				$text .= '-- ' . $label . " --\n" . $this->describe_exit( $exit );
				$delta = $this->delta();
				$text .= "left behind:\n" . $delta;

				$key = $this->result_key( $exit );
				if ( '' !== $key ) {
					$this->expect( 1 === preg_match( '/^[0-9a-f]{32}$/D', $key ) && false !== get_transient( Nojs_Handler::TRANSIENT_PREFIX . $key ), 'S4: ' . $form['key'] . ' / ' . $label . ' redirects to a result key that is not a stored 128-bit key.' );
					Nojs_Handler::$uncacheable = false;
					Nojs_Handler::$headers     = [];
					$location                  = null !== $widget_area ? $this->render_extra( $widget_area, $key ) : $this->render_location( $form['render_id'], $key );
					$this->expect( null !== $location, 'S4: the page of ' . $form['key'] . ' could not be rendered.' );
					if ( null !== $location ) {
						$text .= 'result page (' . ( Nojs_Handler::$uncacheable ? 'uncacheable' : 'CACHEABLE' ) . ', headers: ' . implode( ' | ', Nojs_Handler::$headers ) . "):\n" . $this->text( SF_Normaliser::html( $location['html'] ) ) . "\n";
					}
					$this->expect( false === get_transient( Nojs_Handler::TRANSIENT_PREFIX . $key ), 'S4: the result key of ' . $form['key'] . ' / ' . $label . ' survived its single read.' );
				}
				$text .= "\n";
			}
			$out .= $this->block( 'S4 ' . $form['key'], $text );
		}

		$this->release_exits();
		$this->output( 'S4.txt', '' === $out ? "(no live form)\n" : $out );
	}

	/**
	 * Post a form to its page without JavaScript.
	 *
	 * @param array<string, mixed> $form        Form.
	 * @param array<string, mixed> $sf          Field values.
	 * @param int                  $token_age   Seconds before now the render token was issued (0 for a fresh one).
	 * @return SF_Test_Redirect|SF_Test_Die The exit the handler took.
	 */
	private function post_without_js( array $form, array $sf, $token_age ) {
		$now = SF_User_Config_Loader::FROZEN_TIME;

		Clock::freeze( $now - $token_age );
		$token = $this->controls( $form )['_sf_token'];
		Clock::freeze( $now - 10 );
		$signed = Time_Trap::issue( $form['form_id'], $form['source'] );
		Clock::freeze( $now );

		$controls = $this->controls(
			$form,
			[
				'_sf_token' => $token,
				'_sf_t'     => $signed,
			]
		);
		// A form in a template is posted to the home page, which is a page view like any other for the handler.
		$permalink = $form['page_id'] > 0 ? get_permalink( $form['page_id'] ) : home_url( '/' );
		$this->test->sf_go_to( $permalink );
		$path                      = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$path                      = '' === $path ? '/' : $path;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = $path;
		$_POST                     = wp_slash( array_merge( $controls, [ 'sf' => $sf ] ) );

		$exit = null;
		try {
			Form_Renderer::reset();
			Nojs_Handler::maybe_handle();
		} catch ( SF_Test_Redirect $e ) {
			$exit = $e;
		} catch ( SF_Test_Die $e ) {
			$exit = $e;
		}
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		if ( null === $exit ) {
			$this->failures[] = 'S4: the no-JS handler neither redirected nor stopped for ' . $form['key'] . '.';
			$exit             = new SF_Test_Die( 'no exit' );
		}
		return $exit;
	}

	/**
	 * An exit of the no-JS handler as text.
	 *
	 * @param SF_Test_Redirect|SF_Test_Die $exit Exit.
	 * @return string
	 */
	private function describe_exit( $exit ) {
		if ( $exit instanceof SF_Test_Redirect ) {
			return 'redirect ' . $exit->status . ' Location: ' . $this->text( $exit->location ) . "\n";
		}
		return 'die ' . $exit->status . ': ' . $this->text( $exit->getMessage() ) . "\n";
	}

	/**
	 * The sf_result key of a redirect, or an empty string.
	 *
	 * @param SF_Test_Redirect|SF_Test_Die $exit Exit.
	 * @return string
	 */
	private function result_key( $exit ) {
		if ( ! $exit instanceof SF_Test_Redirect ) {
			return '';
		}
		parse_str( (string) wp_parse_url( $exit->location, PHP_URL_QUERY ), $query );
		return isset( $query[ Nojs_Handler::RESULT_PARAM ] ) && is_string( $query[ Nojs_Handler::RESULT_PARAM ] ) ? $query[ Nojs_Handler::RESULT_PARAM ] : '';
	}

	/**
	 * Closures that turn wp_redirect() and wp_die() into exceptions, so a handler that exits can be read.
	 *
	 * @var array<int, array{0:string, 1:callable, 2:int}>
	 */
	private $exit_filters = [];

	/**
	 * Make wp_redirect() throw SF_Test_Redirect and wp_die() throw SF_Test_Die until release_exits().
	 */
	private function capture_exits() {
		$redirect = static function ( $location, $status ) {
			$e           = new SF_Test_Redirect( 'redirect' );
			$e->location = $location;
			$e->status   = $status;
			throw $e;
		};
		$die      = static function () {
			return static function ( $message, $title, $args ) {
				$e         = new SF_Test_Die( is_string( $message ) ? $message : 'die' );
				$e->status = isset( $args['response'] ) ? (int) $args['response'] : 500;
				throw $e;
			};
		};
		add_filter( 'wp_redirect', $redirect, 10, 2 );
		add_filter( 'wp_die_handler', $die );
		$this->exit_filters = [
			[ 'wp_redirect', $redirect, 10 ],
			[ 'wp_die_handler', $die, 10 ],
		];
	}

	/**
	 * Undo capture_exits().
	 */
	private function release_exits() {
		foreach ( $this->exit_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->exit_filters        = [];
		Nojs_Handler::$uncacheable = false;
		Nojs_Handler::$headers     = [];
	}

	/**
	 * S5: spam and abuse.
	 *
	 * On the first live form: a filled honeypot, a time that is too fast, a token that does not verify, a REST
	 * post without the browser-measured time, the blocked words of the config, and the per-form rate limit; then
	 * the coarse limiter (the 61st request gets 429) and locations that are not live (answered "closed"). A spam
	 * submission must be answered byte for byte like a clean one, and must send no mail; the entry is stored with
	 * status spam or not at all, per spam.spam_action.
	 */
	private function scenario_s5() {
		$forms = $this->live_forms();
		if ( ! $forms ) {
			$this->output( 'S5.txt', "(no live form)\n" );
			return;
		}
		$form  = $forms[0];
		$names = $this->payload_names( $form, 'valid' );
		if ( ! $names ) {
			$this->output( 'S5.txt', "(the first live form has no valid payload)\n" );
			return;
		}
		$sf  = SF_VALUES( $names[0] );
		$out = '';

		// The reference: a clean submission of the same values.
		$control = $this->submit( $form, $sf );
		$out    .= $this->block( 'S5 control: a clean submission of the same values', $this->describe( $control ) . "left behind:\n" . $this->delta() );

		$cases = [
			'honeypot filled'          => [ [ '_sf_hp' => 'http://spam.example/' ], $sf ],
			'too fast (js.500)'        => [ [ '_sf_t' => 'js.500' ], $sf ],
			'forged token'             => [ [ '_sf_token' => 'v1.' . base_convert( (string) intdiv( SF_User_Config_Loader::FROZEN_TIME, HOUR_IN_SECONDS ), 10, 36 ) . '.' . str_repeat( '0', 32 ) ], $sf ],
			'no token'                 => [ [ '_sf_token' => null ], $sf ],
			'no browser-measured time' => [ [ '_sf_t' => null ], $sf ],
		];
		if ( true === Settings::get_value( 'spam.js_token' ) ) {
			$cases['signed time over REST (no js token)'] = [ [ '_sf_t' => Time_Trap::issue( $form['form_id'], $form['source'] ) ], $sf ];
		}
		// A site that changed the minimum time (default 3 seconds) is tested at its own limit: one second under it is spam, one second over it is a clean submission.
		$min_seconds = (int) Settings::get_value( 'spam.min_seconds', 3 );
		if ( 3 !== $min_seconds && $min_seconds > 1 ) {
			$cases[ 'too fast for this site (js.' . ( $min_seconds * 1000 - 1000 ) . ', min_seconds ' . $min_seconds . ')' ] = [ [ '_sf_t' => 'js.' . ( $min_seconds * 1000 - 1000 ) ], $sf ];
		}
		$words = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) Settings::get_value( 'spam.blocked_words', '' ) ) ) ) );
		foreach ( $this->payload_names( $form, 'spam' ) as $name ) {
			$cases[ 'blocked word: ' . $name ] = [ [], SF_VALUES( $name ) ];
		}
		if ( $words && ! $this->payload_names( $form, 'spam' ) ) {
			$this->failures[] = 'S5: the config blocks words (' . implode( ', ', $words ) . ') but lists no submissions.spam payload for ' . $form['key'] . '.';
		}

		foreach ( $cases as $label => $case ) {
			$response = $this->submit( $form, $case[1], $case[0] );
			$delta    = $this->delta();
			// A case with the control's own values must be answered byte for byte like the control; a case with other values (a blocked word) only has to be a success of the same kind.
			$same_values = $case[1] === $sf;
			$identical   = $response['status'] === $control['status'] && ( $same_values ? $response['data'] === $control['data'] : ( $response['data']['status'] ?? '' ) === ( $control['data']['status'] ?? '' ) && ( $response['data']['confirmation']['type'] ?? '' ) === ( $control['data']['confirmation']['type'] ?? '' ) );
			$this->expect( $identical, 'S5: the spam case "' . $label . '" is not answered like a clean submission.' );
			$this->expect( false === strpos( $delta, '-- mail' ), 'S5: the spam case "' . $label . '" sent mail.' );
			$verdict = $same_values ? "answered exactly like the clean submission\n" : "answered like a clean submission (other values than the control, so the body is compared by kind)\n";
			$out    .= $this->block( 'S5 ' . $form['key'] . ' / ' . $label, ( $identical ? $verdict : "ANSWERED DIFFERENTLY FROM THE CLEAN SUBMISSION\n" ) . $this->describe( $response ) . "left behind:\n" . $delta );
		}

		// One second over the site's own minimum is a clean submission: stored as new, mail sent.
		if ( 3 !== $min_seconds && $min_seconds > 1 ) {
			$response = $this->submit( $form, $sf, [ '_sf_t' => 'js.' . ( $min_seconds * 1000 + 1000 ) ] );
			$delta    = $this->delta();
			$this->expect( 200 === $response['status'] && false !== strpos( $delta, '"status": "new"' ) && false !== strpos( $delta, '-- mail' ), 'S5: a submission one second over min_seconds (' . $min_seconds . ') was not handled as a clean one.' );
			$out .= $this->block( 'S5 ' . $form['key'] . ' / slow enough for this site (js.' . ( $min_seconds * 1000 + 1000 ) . ', min_seconds ' . $min_seconds . ')', $this->describe( $response ) . "left behind:\n" . $delta );
		}

		// A site that turned the JS token off (spam.js_token false) accepts a headless REST client that sends the signed render time: a clean submission.
		if ( false === Settings::get_value( 'spam.js_token' ) ) {
			Clock::freeze( SF_User_Config_Loader::FROZEN_TIME - 10 );
			$signed = Time_Trap::issue( $form['form_id'], $form['source'] );
			Clock::freeze( SF_User_Config_Loader::FROZEN_TIME );
			$response = $this->submit( $form, $sf, [ '_sf_t' => $signed ] );
			$delta    = $this->delta();
			$this->expect( 200 === $response['status'] && false !== strpos( $delta, '"status": "new"' ), 'S5: with spam.js_token off, a REST client with the signed time was not handled as a clean submission.' );
			$out .= $this->block( 'S5 ' . $form['key'] . ' / headless REST client with the signed time (js_token off)', $this->describe( $response ) . "left behind:\n" . $delta );
		}

		// The per-form rate limit: the counter is kept between these requests, the others are not.
		$limit = (int) Settings::get_value( 'spam.rate_limit_per_minute', 5 );
		$text  = '';
		if ( $limit > 0 ) {
			$this->reset_limits( false );
			$statuses = [];
			for ( $i = 1; $i <= $limit + 1; $i++ ) {
				$this->delete_counters( [ 'swiftforms_c_', 'swiftforms_d_' ] );
				$request    = $this->controls( $form, [ '_sf_hp' => 'probe' ] ) + [ 'sf' => array_merge( $sf, [ 'message' => 'Rate limit probe ' . $i ] ) ];
				$response   = $this->rest( 'POST', '/swiftforms/v1/submit', $request );
				$statuses[] = $response['status'];
				$last       = $response;
			}
			$delta = $this->delta();
			$this->expect( array_slice( $statuses, 0, $limit ) === array_fill( 0, $limit, 200 ) && 429 === $statuses[ $limit ], 'S5: the per-form limit of ' . $limit . ' did not answer 429 on request ' . ( $limit + 1 ) . ' (' . implode( ',', $statuses ) . ').' );
			$text .= 'limit ' . $limit . ' per minute; statuses of requests 1 to ' . ( $limit + 1 ) . ': ' . implode( ', ', $statuses ) . "\nlast response:\n" . $this->describe( $last ) . "left behind:\n" . $delta;
		} else {
			$text .= "the per-form limiter is off (rate_limit_per_minute is 0)\n";
		}
		$out .= $this->block( 'S5 ' . $form['key'] . ' / per-form rate limit', $text );

		// The coarse limiter: 60 requests a minute per client, the 61st is refused before anything else happens.
		$this->reset_limits( false );
		$statuses = [];
		for ( $i = 1; $i <= 61; $i++ ) {
			$response   = $this->rest( 'POST', '/swiftforms/v1/submit', [ '_sf_form' => wp_generate_uuid4() ] );
			$statuses[] = $response['status'];
			$last       = $response;
		}
		$this->expect( array_fill( 0, 60, 403 ) === array_slice( $statuses, 0, 60 ) && 429 === $statuses[60], 'S5: the coarse limiter did not refuse exactly the 61st request (' . implode( ',', array_unique( $statuses ) ) . ').' );
		$out .= $this->block( 'S5 coarse limiter (61 requests in one minute)', 'statuses of requests 1 to 60: ' . implode( ', ', array_unique( array_slice( $statuses, 0, 60 ) ) ) . "\nrequest 61:\n" . $this->describe( $last ) );
		$this->reset_limits( false );

		// Locations that are not live.
		$text   = '';
		$strays = [
			'unknown formId, real source'     => [ wp_generate_uuid4(), $form['source'] ],
			'real formId, source that is not' => [ $form['form_id'], 'post:7000000' ],
			'real formId, malformed source'   => [ $form['form_id'], 'somewhere else' ],
			'malformed formId'                => [ 'not-a-uuid', $form['source'] ],
		];
		foreach ( $forms as $other ) {
			if ( $other['source'] !== $form['source'] ) {
				$strays['real formId, the source of another form'] = [ $form['form_id'], $other['source'] ];
				break;
			}
		}
		foreach ( $strays as $label => $pair ) {
			$this->reset_limits( false );
			$request  = $this->controls(
				$form,
				[
					'_sf_form'   => $pair[0],
					'_sf_source' => $pair[1],
				]
			) + [ 'sf' => $sf ];
			$response = $this->rest( 'POST', '/swiftforms/v1/submit', $request );
			$this->expect( 403 === $response['status'] && 'closed' === ( $response['data']['status'] ?? '' ), 'S5: "' . $label . '" is not answered closed (' . $response['status'] . ').' );
			$text .= '-- ' . $label . " --\n" . $this->describe( $response );
		}
		$delta = $this->delta();
		$this->expect( false === strpos( $delta, 'entry:' ), 'S5: a location that is not live stored an entry.' );
		$out .= $this->block( 'S5 locations that are not live', $text . "left behind:\n" . $delta );

		$this->output( 'S5.txt', $out );
	}

	/**
	 * Delete some request counters only.
	 *
	 * @param string[] $prefixes Transient name prefixes (without "_transient_").
	 */
	private function delete_counters( array $prefixes ) {
		global $wpdb;
		foreach ( $prefixes as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $prefix ) . '%' ) );
			foreach ( is_array( $names ) ? $names : [] as $name ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			}
		}
	}

	/**
	 * S6: the entries REST routes, as an administrator.
	 *
	 * The list, the list per form and per status, one entry, and the state changes (read, starred, spam, trash).
	 * A guest is refused. IDs are labelled in the order the run meets them.
	 */
	private function scenario_s6() {
		$admin = $this->admin_id();
		$out   = '';

		$list = $this->rest( 'GET', '/swiftforms/v1/entries', [], $admin );
		$out .= $this->block( 'S6 GET /entries (default: new and read, newest first)', $this->describe_list( $list ) );

		$forms = $this->rest( 'GET', '/swiftforms/v1/forms', [], $admin );
		$out  .= $this->block( 'S6 GET /forms', $this->describe( $forms ) );

		foreach ( $this->forms as $form ) {
			$response = $this->rest(
				'GET',
				'/swiftforms/v1/entries',
				[
					'form'   => $form['form_id'],
					'status' => 'new',
				],
				$admin
			);
			$out     .= $this->block( 'S6 GET /entries?form=' . $form['key'] . '&status=new', $this->describe_list( $response ) );
		}
		foreach ( [ 'new', 'read', 'spam', 'trash' ] as $status ) {
			$response = $this->rest( 'GET', '/swiftforms/v1/entries', [ 'status' => $status ], $admin );
			$out     .= $this->block( 'S6 GET /entries?status=' . $status, $this->describe_list( $response ) );
		}

		$first = null;
		foreach ( (array) ( $list['data'] ?? [] ) as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$first = (int) $item['id'];
				break;
			}
		}
		if ( null === $first ) {
			$out .= $this->block( 'S6 single entry', "(the list is empty, so there is no entry to read or change)\n" );
		} else {
			$label = $this->entry_label( $first );
			$one   = $this->rest( 'GET', '/swiftforms/v1/entries/' . $first, [], $admin );
			$out  .= $this->block( 'S6 GET /entries/' . $label, $this->describe( $one ) );
			$steps = [
				'status read'   => [ 'status' => 'read' ],
				'starred true'  => [ 'starred' => true ],
				'status spam'   => [ 'status' => 'spam' ],
				'status trash'  => [ 'status' => 'trash' ],
				'status new'    => [ 'status' => 'new' ],
				'starred false' => [ 'starred' => false ],
			];
			foreach ( $steps as $step => $body ) {
				$response = $this->rest( 'PATCH', '/swiftforms/v1/entries/' . $first, $body, $admin );
				// The first answer is shown whole; the rest only for what changes.
				$shown = 'status read' === $step ? $this->describe( $response ) : 'status: ' . $response['status'] . ', entry status: ' . ( $response['data']['status'] ?? '?' ) . ', starred: ' . wp_json_encode( $response['data']['starred'] ?? null ) . "\n";
				$out  .= $this->block( 'S6 PATCH /entries/' . $label . ' ' . wp_json_encode( $body ), $shown );
			}
			$response = $this->rest( 'PATCH', '/swiftforms/v1/entries/' . $first, [], $admin );
			$out     .= $this->block( 'S6 PATCH /entries/' . $label . ' with nothing to change', $this->describe( $response ) );
			$response = $this->rest( 'GET', '/swiftforms/v1/entries/999999999', [], $admin );
			$out     .= $this->block( 'S6 GET /entries/999999999 (no such entry)', $this->describe( $response ) );
		}

		$guest = $this->rest( 'GET', '/swiftforms/v1/entries' );
		$this->expect( in_array( $guest['status'], [ 401, 403 ], true ), 'S6: a guest can list entries (' . $guest['status'] . ').' );
		$out  .= $this->block( 'S6 GET /entries as a guest', $this->describe( $guest ) );
		$guest = $this->rest( 'PATCH', '/swiftforms/v1/entries/' . ( $first ?? 1 ), [ 'status' => 'trash' ] );
		$this->expect( in_array( $guest['status'], [ 401, 403 ], true ), 'S6: a guest can change an entry (' . $guest['status'] . ').' );
		$out .= $this->block( 'S6 PATCH /entries/{id} as a guest', $this->describe( $guest ) );

		$this->output( 'S6.txt', $out );
	}

	/**
	 * A list response with the pagination headers.
	 *
	 * @param array{status:int, data:mixed, headers:array<string, string>} $response Response.
	 * @return string
	 */
	private function describe_list( array $response ) {
		$text = '';
		foreach ( [ 'X-WP-Total', 'X-WP-TotalPages' ] as $name ) {
			if ( isset( $response['headers'][ $name ] ) ) {
				$text .= strtolower( $name ) . ': ' . $response['headers'][ $name ] . "\n";
			}
		}
		return $text . $this->describe( $response );
	}

	/**
	 * S7: there is no export in 1.0.0; this becomes the CSV and JSON bytes in R02.
	 */
	private function scenario_s7() {
		$found = [];
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( 0 === strpos( $route, '/swiftforms' ) && false !== stripos( $route, 'export' ) ) {
				$found[] = $route;
			}
		}
		$response = $this->rest( 'GET', '/swiftforms/v1/entries/export', [], $this->admin_id() );
		$this->expect( ! $found, 'S7: a SwiftForms route with "export" in its name exists: ' . implode( ', ', $found ) );
		$this->expect( 404 === $response['status'], 'S7: /swiftforms/v1/entries/export answers ' . $response['status'] . ', not 404.' );
		$this->output(
			'S7.txt',
			"Export does not exist in 1.0.0: no REST route with \"export\" in its name is registered.\n"
			. "GET /swiftforms/v1/entries/export as an administrator:\n" . $this->describe( $response )
			. "From R02 this file holds the CSV and JSON bytes of an export.\n"
		);
	}

	/**
	 * Give the settings page the defaults a request of this site would see.
	 *
	 * The page takes its field defaults when it registers (init), which happened before the config set its locale or
	 * its install marker. A real request registers after both, so this brings the page to that state.
	 */
	private function refresh_settings_page_defaults() {
		Settings::flush_cache();

		$walk = static function ( array $nodes ) use ( &$walk ) {
			foreach ( $nodes as $key => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( isset( $node['name'] ) && is_string( $node['name'] ) && array_key_exists( 'default', $node ) ) {
					$path = Settings::path_of( Settings::PREFIX . $node['name'] );
					if ( null !== $path ) {
						$node['default'] = Settings::default_of( $path );
					}
				}
				$nodes[ $key ] = $walk( $node );
			}
			return $nodes;
		};

		$handler = SwiftForms\Vendor\PedalCMS\CassetteCMF\Core\Manager::init()->get_new_settings_handler();
		foreach ( $handler->get_fields( SwiftForms\Settings_Page::PAGE_ID ) as $field ) {
			if ( 'metabox' === $field->get_type() ) {
				$field->set_config( 'fields', $walk( (array) $field->get_config( 'fields', [] ) ) );
			}
		}
	}

	/**
	 * S8: the settings route (cassette-cmf's). GET, then POST the same payload: no write at all.
	 *
	 * An absent option stays absent and a present one keeps its exact bytes; no add_option, update_option or
	 * delete_option for any swiftforms_{section}_* option may run, and cassette-cmf writes no option of its own.
	 */
	private function scenario_s8() {
		global $wpdb;

		$route = '/swiftforms-cmf/v1/settings/swiftforms';
		$this->refresh_settings_page_defaults();
		$admin      = $this->admin_id();
		$before     = Settings::stored_options();
		$cmf_rows   = static function () use ( $wpdb ) {
			return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cassette\\_cmf\\_%' ORDER BY option_name" );
		};
		$cmf_before = $cmf_rows();
		$writes     = [];
		$update     = static function ( $value ) use ( &$writes ) {
			$writes[] = 'update_option';
			return $value;
		};
		$add        = static function ( $name ) use ( &$writes ) {
			if ( null !== Settings::path_of( $name ) ) {
				$writes[] = 'add_option ' . $name;
			}
		};
		$delete     = static function ( $name ) use ( &$writes ) {
			if ( null !== Settings::path_of( $name ) ) {
				$writes[] = 'delete_option ' . $name;
			}
		};
		foreach ( Settings::paths() as $path ) {
			add_filter( 'pre_update_option_' . Settings::option_name( $path ), $update );
		}
		add_action( 'add_option', $add );
		add_action( 'delete_option', $delete );

		$get  = $this->rest( 'GET', $route, [], $admin );
		$out  = $this->block( 'S8 GET ' . $route . ' (' . ( $before ? count( $before ) . ' settings option(s) stored' : 'no settings option stored' ) . ')', $this->describe( $get ) );
		$post = $this->rest( 'POST', $route, [ 'values' => (array) ( $get['data']['values'] ?? [] ) ], $admin );
		$out .= $this->block( 'S8 POST with the same values', $this->describe( $post ) );

		$partial = [
			'values' => [
				'general_store_entries' => Settings::get_value( 'general.store_entries' ),
				'spam_honeypot'         => Settings::get_value( 'spam.honeypot' ),
			],
		];
		$post2   = $this->rest( 'POST', $route, $partial, $admin );
		$out    .= $this->block( 'S8 POST with two values it already has', $this->describe( $post2 ) );

		$guest = $this->rest( 'POST', $route, [ 'values' => (array) ( $get['data']['values'] ?? [] ) ] );
		$this->expect( in_array( $guest['status'], [ 401, 403 ], true ), 'S8: a guest can post settings (' . $guest['status'] . ').' );

		foreach ( Settings::paths() as $path ) {
			remove_filter( 'pre_update_option_' . Settings::option_name( $path ), $update );
		}
		remove_action( 'add_option', $add );
		remove_action( 'delete_option', $delete );

		$after = Settings::stored_options();
		$this->expect( 200 === $get['status'] && 200 === $post['status'] && 200 === $post2['status'], 'S8: the settings routes did not answer 200 (' . $get['status'] . ', ' . $post['status'] . ', ' . $post2['status'] . ').' );
		$this->expect( [] === $writes, 'S8: a settings option was written by a no-op save: ' . implode( ', ', $writes ) . '.' );
		$unchanged = serialize( $before ) === serialize( $after ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Byte comparison of stored values, never stored.
		$this->expect( $unchanged, 'S8: the stored settings options changed (an absent one stayed absent, a present one keeps its bytes).' );
		$this->expect( $cmf_before === $cmf_rows(), 'S8: cassette-cmf wrote an option of its own.' );
		$out .= $this->block(
			'S8 writes',
			'writes to swiftforms_{section}_* options: ' . count( $writes ) . "\nsettings options before: " . count( $before ) . ', after: ' . count( $after ) . "\nstored options unchanged (names and values): " . ( $unchanged ? 'yes' : 'NO' ) . "\ncassette-cmf options written: " . count( array_diff( $cmf_rows(), $cmf_before ) ) . "\n"
		);
		$this->output( 'S8.txt', $out );
	}

	/**
	 * S9: the privacy exporter and eraser, for the email the config names.
	 *
	 * Runs the callbacks WordPress would run (the registered personal-data exporter and eraser of SwiftForms)
	 * page by page, for the known email and for an unknown one, and lists what is left behind.
	 */
	private function scenario_s9() {
		global $wpdb;

		$email = (string) $this->config['privacy_email'];
		if ( '' === $email ) {
			$this->failures[] = 'S9: the config names no privacy_email.';
			$this->output( 'S9.txt', "(no privacy_email)\n" );
			return;
		}

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->expect( isset( $exporters['swiftforms-entries'], $erasers['swiftforms-entries'] ), 'S9: the SwiftForms exporter or eraser is not registered.' );
		if ( ! isset( $exporters['swiftforms-entries'], $erasers['swiftforms-entries'] ) ) {
			$this->output( 'S9.txt', "(not registered)\n" );
			return;
		}

		$out = $this->block(
			'S9 registered',
			'exporter: ' . $exporters['swiftforms-entries']['exporter_friendly_name'] . "\neraser: " . $erasers['swiftforms-entries']['eraser_friendly_name'] . "\n"
		);

		$run = static function ( $callback, $address ) {
			$pages = [];
			$page  = 1;
			do {
				$result  = call_user_func( $callback, $address, $page );
				$pages[] = $result;
				++$page;
			} while ( empty( $result['done'] ) && $page < 20 );
			return $pages;
		};

		$exported = $run( $exporters['swiftforms-entries']['callback'], $email );
		$out     .= $this->block( 'S9 export for ' . $email, $this->json( $exported ) );
		$this->expect( ! empty( $exported[0]['data'] ), 'S9: the exporter found nothing for ' . $email . '.' );

		$unknown = $run( $exporters['swiftforms-entries']['callback'], 'nobody@example.com' );
		$out    .= $this->block( 'S9 export for an address with no entries', $this->json( $unknown ) );

		$count     = static function ( $address ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT entry_id) FROM ' . SwiftForms\Database::table( 'entry_values' ) . ' WHERE value = %s', $address ) );
		};
		$mail_rows = static function ( $address ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'log' ) . " WHERE type = 'mail' AND (context LIKE %s OR message LIKE %s)", '%' . $wpdb->esc_like( $address ) . '%', '%' . $wpdb->esc_like( $address ) . '%' ) );
		};
		$before    = [
			'entries with the address' => $count( $email ),
			'entries'                  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entries' ) ),
			'value rows'               => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entry_values' ) ),
			'mail log rows naming it'  => $mail_rows( $email ),
		];

		$erased = $run( $erasers['swiftforms-entries']['callback'], $email );
		$out   .= $this->block( 'S9 erase ' . $email, $this->json( $erased ) );

		$after = [
			'entries with the address' => $count( $email ),
			'entries'                  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entries' ) ),
			'value rows'               => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entry_values' ) ),
			'orphan value rows'        => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entry_values' ) . ' v LEFT JOIN ' . SwiftForms\Database::table( 'entries' ) . ' e ON e.id = v.entry_id WHERE e.id IS NULL' ),
			'orphan meta rows'         => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entry_meta' ) . ' m LEFT JOIN ' . SwiftForms\Database::table( 'entries' ) . ' e ON e.id = m.entry_id WHERE e.id IS NULL' ),
			'mail log rows naming it'  => $mail_rows( $email ),
		];
		$this->expect( 0 === $after['entries with the address'] && 0 === $after['orphan value rows'] && 0 === $after['orphan meta rows'] && 0 === $after['mail log rows naming it'], 'S9: erasing ' . $email . ' left rows behind: ' . wp_json_encode( $after ) );
		$out .= $this->block( 'S9 rows before and after the erasure', "before:\n" . $this->json( $before ) . "after:\n" . $this->json( $after ) );

		$again = $run( $exporters['swiftforms-entries']['callback'], $email );
		$out  .= $this->block( 'S9 export again after the erasure', $this->json( $again ) );
		$none  = $run( $erasers['swiftforms-entries']['callback'], 'nobody@example.com' );
		$out  .= $this->block( 'S9 erase an address with no entries', $this->json( $none ) );

		$this->output( 'S9.txt', $out );
	}

	/**
	 * S11: the migration runner, twice.
	 *
	 * First on the site as the config stores it (a 1.0.0 site: already at the current database version, so both
	 * runs do nothing). Then on the same site with the version row removed, which is what a site looks like
	 * just before an update migrates it: the first run backs up, migrates and records the version, the second
	 * does nothing. A site with neither a settings option nor a version row gets no backup. The install marker is
	 * never touched.
	 */
	private function scenario_s11() {
		global $wpdb;

		$state   = function () use ( $wpdb ) {
			$ring = SwiftForms\Backups::all();
			$last = $ring ? end( $ring ) : null;
			if ( is_array( $last ) && SWIFTFORMS_VERSION === $last['version'] ) {
				$last['version'] = '{VERSION}'; // The backup records the plugin version that made it; that changes every release.
			}
			return [
				'db_version row'       => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION_DB_VERSION ) ),
				'install marker (raw)' => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SwiftForms\Install_Marker::OPTION ) ),
				'settings row present' => Settings::has_stored(), // Any swiftforms_{section}_* option row.
				'backups in the ring'  => count( $ring ),
				'newest backup'        => $last,
				'migration log rows'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'log' ) . " WHERE type = 'migration'" ),
				'migration lock row'   => null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION_LOCK ) ),
			];
		};
		$changed = 0;
		$count   = static function () use ( &$changed ) {
			++$changed;
		};
		add_action( 'swiftforms_db_version_changed', $count );

		$out    = '';
		$marker = $state()['install marker (raw)'];

		$start = $state();
		$first = Migrations::maybe_run();
		$mid   = $state();
		$next  = Migrations::maybe_run();
		$end   = $state();
		$this->expect( false === $first && false === $next && wp_json_encode( $mid ) === wp_json_encode( $start ) && wp_json_encode( $end ) === wp_json_encode( $start ), 'S11: running the migrations on a site at the current version changed something.' );
		$out .= $this->block( 'S11 site as stored', "before:\n" . $this->json( $start ) . 'first run returned ' . wp_json_encode( $first ) . ', second run returned ' . wp_json_encode( $next ) . "\nafter both:\n" . $this->json( $end ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION_DB_VERSION ) );
		wp_cache_delete( Migrations::OPTION_DB_VERSION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$changed     = 0;
		$start       = $state();
		$first       = Migrations::maybe_run();
		$mid         = $state();
		$mid_changed = $changed;
		$next        = Migrations::maybe_run();
		$end         = $state();

		$has_data = $start['settings row present'] || null !== $start['db_version row'];
		$this->expect( true === $first && false === $next, 'S11: with the version row removed, the first run should migrate and the second do nothing (' . wp_json_encode( [ $first, $next ] ) . ').' );
		$this->expect( wp_json_encode( $end ) === wp_json_encode( $mid ), 'S11: the second run changed the stored state.' ); // JSON, not ===: a backup of a corrupt option may hold an object.
		$this->expect( $marker === $end['install marker (raw)'], 'S11: a migration changed the install marker.' );
		$this->expect( $start['backups in the ring'] + ( $has_data ? 1 : 0 ) === $mid['backups in the ring'], 'S11: the backup ring should have gained ' . ( $has_data ? 'one entry' : 'no entry' ) . ' (' . $start['backups in the ring'] . ' to ' . $mid['backups in the ring'] . ').' );
		$this->expect( 1 === $mid_changed, 'S11: swiftforms_db_version_changed should fire once (' . $mid_changed . ').' );
		$out .= $this->block( 'S11 site as before an update (version row removed)', "before:\n" . $this->json( $start ) . 'first run returned ' . wp_json_encode( $first ) . ' (swiftforms_db_version_changed fired ' . $mid_changed . " time(s))\nafter the first run:\n" . $this->json( $mid ) . 'second run returned ' . wp_json_encode( $next ) . "\nafter the second run:\n" . $this->json( $end ) );

		remove_action( 'swiftforms_db_version_changed', $count );
		$this->output( 'S11.txt', $out );
	}

	/**
	 * Check "retention": the daily job deletes exactly the entries strictly older than retention_days.
	 *
	 * The expected set is worked out here from the rows the config inserted, independently of the plugin.
	 */
	private function check_retention() {
		global $wpdb;

		$days  = (int) Settings::get_value( 'general.retention_days', 0 );
		$table = SwiftForms\Database::table( 'entries' );
		$ids   = static function () use ( $wpdb, $table ) {
			return $wpdb->get_results( "SELECT id, created_gmt FROM {$table} ORDER BY created_gmt, id", ARRAY_A );
		};

		$cutoff = gmdate( 'Y-m-d H:i:s', SF_User_Config_Loader::FROZEN_TIME - $days * DAY_IN_SECONDS );
		$before = $ids();
		$expect = [];
		foreach ( $before as $row ) {
			if ( $row['created_gmt'] < $cutoff ) {
				$expect[] = (int) $row['id'];
			}
		}

		$scheduled = wp_next_scheduled( SwiftForms\Cron::DAILY );
		do_action( SwiftForms\Cron::DAILY );
		$after = $ids();

		$kept    = array_map( 'intval', wp_list_pluck( $after, 'id' ) );
		$deleted = array_values( array_diff( array_map( 'intval', wp_list_pluck( $before, 'id' ) ), $kept ) );
		sort( $expect );
		sort( $deleted );
		$this->expect( $expect === $deleted, 'Retention: the daily job deleted ' . wp_json_encode( $deleted ) . ' but only ' . wp_json_encode( $expect ) . ' are older than ' . $days . ' days.' );
		$this->expect( false !== $scheduled, 'Retention: the daily job (swiftforms_daily) is not scheduled.' );

		$describe    = function ( array $rows ) {
			return $rows ? $rows[0]['created_gmt'] . ' to ' . $rows[ count( $rows ) - 1 ]['created_gmt'] : '(none)';
		};
		$oldest_kept = '';
		foreach ( $after as $row ) {
			$oldest_kept = $row['created_gmt'];
			break;
		}
		$this->output(
			'check-retention.txt',
			'retention_days: ' . $days . "\nfrozen now: " . gmdate( 'Y-m-d H:i:s', SF_User_Config_Loader::FROZEN_TIME ) . "\ncutoff (entries strictly older are deleted): " . $cutoff
			. "\ndaily job scheduled: " . ( false !== $scheduled ? 'yes' : 'NO' )
			. "\nentries before: " . count( $before ) . ' (' . $describe( $before ) . ')'
			. "\nentries after: " . count( $after ) . ' (' . $describe( $after ) . ')'
			. "\ndeleted: " . count( $deleted ) . ' entries, ids ' . implode( ', ', $deleted ) . "\noldest entry kept: " . $oldest_kept . "\n"
		);
	}

	/**
	 * Check "source_states": the same valid submission to the form of every kind of source (config key "source_states": form key => accepted | closed | password).
	 *
	 * A form on a published page accepts; a draft, private, scheduled or trashed page answers "closed" (and so does a form the index
	 * does not call live); a password-protected page answers "closed" until the visitor has the post-password cookie. Nothing may be stored by a refusal.
	 */
	private function check_source_states() {
		$expect = (array) ( $this->config['source_states'] ?? [] );
		$out    = '';
		$this->delta(); // What the scenarios before left behind (a migration log row) is not this check's.
		$this->expect( count( $expect ) === count( $this->forms ), 'Source states: the config describes ' . count( $expect ) . ' forms but the index holds ' . count( $this->forms ) . '.' );

		$text = '';
		foreach ( $this->forms as $form ) {
			$text .= $form['key'] . ' (' . $form['source'] . '): index status ' . $form['status'] . "\n";
		}
		$out .= $this->block( 'Forms in the index', $text );

		foreach ( $this->forms as $form ) {
			$wanted = $expect[ $form['key'] ] ?? null;
			if ( null === $wanted ) {
				$this->failures[] = 'Source states: no expectation for ' . $form['key'] . '.';
				continue;
			}
			$valid = $this->payload_names( $form, 'valid' );
			$sf    = SF_VALUES( $valid[0] );
			$text  = '';

			$response = $this->submit( $form, $sf );
			$delta    = $this->delta();
			$accepted = 200 === $response['status'];
			$closed   = 403 === $response['status'] && 'closed' === ( $response['data']['status'] ?? '' );
			$this->expect( 'accepted' === $wanted ? $accepted : $closed, 'Source states: ' . $form['key'] . ' should be ' . ( 'accepted' === $wanted ? 'accepted' : 'closed' ) . ' (' . $response['status'] . ' ' . wp_json_encode( $response['data']['status'] ?? '' ) . ').' );
			$this->expect( $accepted === ( false !== strpos( $delta, 'entry:' ) ), 'Source states: ' . $form['key'] . ' stored an entry exactly when it was not accepted.' );
			$text .= 'expected: ' . $wanted . ( 'password' === $wanted ? ' (closed without the cookie)' : '' ) . "\n" . $this->describe( $response ) . "left behind:\n" . $delta;

			if ( 'password' === $wanted ) {
				// The visitor has entered the password: the cookie WordPress sets holds a phpass hash of it.
				$post   = get_post( (int) preg_replace( '/^post:/', '', $form['source'] ) );
				$cookie = 'wp-postpass_' . COOKIEHASH;
				require_once ABSPATH . WPINC . '/class-phpass.php';
				$hasher             = new PasswordHash( 8, true );
				$_COOKIE[ $cookie ] = $hasher->HashPassword( $post->post_password );
				$response           = $this->submit( $form, $sf );
				$delta              = $this->delta();
				unset( $_COOKIE[ $cookie ] );
				$this->expect( 200 === $response['status'] && false !== strpos( $delta, 'entry:' ), 'Source states: ' . $form['key'] . ' did not accept a visitor who entered the password (' . $response['status'] . ').' );
				$text .= "with the post-password cookie:\n" . $this->describe( $response ) . "left behind:\n" . $delta;
			}
			$out .= $this->block( 'Source states: ' . $form['key'], $text );
		}
		$this->output( 'check-source-states.txt', $out );
	}

	/**
	 * Check "roles": what each user of the config can do through the REST routes and Access::can(), and that SwiftForms wrote no role.
	 *
	 * For every user: the six SwiftForms capabilities, the entries routes (list, read, change, delete), the forms list, the settings
	 * routes (GET and a no-op POST), and whether a submission they make gets an entryId (only a user who can view entries does).
	 * A guest is refused everywhere. The wp_user_roles option, and the capabilities stored in every user's meta,
	 * must be the same bytes after the whole run as before it: roles and capabilities are never modified in the database (C-8.4).
	 */
	private function check_roles() {
		global $wpdb;

		$roles_before = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '{$wpdb->prefix}user_roles'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed name.
		$caps_before  = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' ORDER BY user_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed name.
		$this->delta();

		$out  = $this->block( 'Capability map (after the swiftforms_capability_map filter)', $this->json( SwiftForms\Access::map() ) );
		$out .= $this->block( 'Roles in the database before the check', $this->text( implode( ', ', array_keys( (array) maybe_unserialize( $roles_before ) ) ) ) . "\n" );

		$form           = $this->live_forms()[0];
		$valid          = $this->payload_names( $form, 'valid' );
		$sf             = SF_VALUES( $valid[0] );
		$users          = SF_User_Config_Loader::user_ids();
		$users['guest'] = 0;
		$matrix         = '';
		foreach ( $users as $key => $user_id ) {
			$text = '';
			foreach ( SwiftForms\Access::CAPS as $cap ) {
				$can   = $user_id > 0 && user_can( $user_id, $cap );
				$text .= str_pad( $cap, 34 ) . ( $can ? 'yes' : 'no' ) . "\n";
				$this->expect( $can === ( $user_id > 0 && SwiftForms\Access::can( $cap, $user_id ) ), 'Roles: Access::can() and user_can() disagree for ' . $key . ' / ' . $cap . '.' );
			}
			$responses                                  = [
				'GET /entries'             => $this->rest( 'GET', '/swiftforms/v1/entries', [], $user_id ),
				'GET /forms'               => $this->rest( 'GET', '/swiftforms/v1/forms', [], $user_id ),
				'GET /entries/3'           => $this->rest( 'GET', '/swiftforms/v1/entries/3', [], $user_id ),
				'PATCH /entries/3 starred' => $this->rest( 'PATCH', '/swiftforms/v1/entries/3', [ 'starred' => true ], $user_id ),
				'PATCH /entries/3 unstar'  => $this->rest( 'PATCH', '/swiftforms/v1/entries/3', [ 'starred' => false ], $user_id ),
				'DELETE /entries/999999'   => $this->rest( 'DELETE', '/swiftforms/v1/entries/999999', [], $user_id ),
				'GET /settings'            => $this->rest( 'GET', '/swiftforms-cmf/v1/settings/swiftforms', [], $user_id ), // The route is cassette-cmf's.
			];
			$responses['POST /settings (same payload)'] = $this->rest( 'POST', '/swiftforms-cmf/v1/settings/swiftforms', [ 'values' => (array) ( $responses['GET /settings']['data']['values'] ?? [] ) ], $user_id );
			$text                                      .= "\n";
			foreach ( $responses as $label => $response ) {
				$text .= str_pad( $label, 34 ) . $response['status'] . ( isset( $response['data']['code'] ) ? ' ' . $response['data']['code'] : '' ) . "\n";
			}

			// The routes must agree with the capabilities.
			$view = $user_id > 0 && SwiftForms\Access::can( 'swiftforms_view_entries', $user_id );
			$this->expect( $view === ( 200 === $responses['GET /entries']['status'] ) && $view === ( 200 === $responses['GET /forms']['status'] ), 'Roles: the entries routes do not follow swiftforms_view_entries for ' . $key . '.' );
			$this->expect( ( $user_id > 0 && SwiftForms\Access::can( 'swiftforms_edit_entries', $user_id ) ) === ( 200 === $responses['PATCH /entries/3 starred']['status'] ), 'Roles: PATCH /entries does not follow swiftforms_edit_entries for ' . $key . '.' );
			$this->expect( ( $user_id > 0 && SwiftForms\Access::can( 'swiftforms_delete_entries', $user_id ) ) === ( 404 === $responses['DELETE /entries/999999']['status'] ), 'Roles: DELETE /entries does not follow swiftforms_delete_entries for ' . $key . '.' );
			$this->expect( ( $user_id > 0 && SwiftForms\Access::can( 'swiftforms_manage_settings', $user_id ) ) === ( 200 === $responses['GET /settings']['status'] ), 'Roles: GET /settings does not follow swiftforms_manage_settings for ' . $key . '.' );
			if ( 0 === $user_id ) {
				foreach ( $responses as $label => $response ) {
					$this->expect( 401 === $response['status'], 'Roles: a guest got ' . $response['status'] . ' for ' . $label . '.' );
				}
			}

			$response = $this->submit( $form, $sf, [], $user_id );
			$delta    = $this->delta();
			$this->expect( 200 === $response['status'] && $view === isset( $response['data']['entryId'] ), 'Roles: ' . $key . ' ' . ( $view ? 'should' : 'must not' ) . ' get an entryId from a submission.' );
			$text   .= "\nsubmission: " . $response['status'] . ', entryId ' . ( isset( $response['data']['entryId'] ) ? 'returned' : 'not returned' ) . ', entry stored by user ' . $this->text( 1 === preg_match( '/"user_id": ("[^"]*"|\d+)/', $delta, $m ) ? $m[1] : '?' ) . "\n";
			$matrix .= $this->block( 'User ' . $key . ' (' . ( $user_id > 0 ? implode( ',', get_userdata( $user_id )->roles ) : 'no role' ) . ')', $text );
		}
		$out .= $matrix;

		$roles_after = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '{$wpdb->prefix}user_roles'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed name.
		$caps_after  = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' ORDER BY user_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed name.
		$this->expect( $roles_before === $roles_after, 'Roles: the wp_user_roles option changed during the run.' );
		$this->expect( $caps_before === $caps_after, 'Roles: a user\'s stored capabilities changed during the run.' );
		$this->expect( false === strpos( $roles_after, 'swiftforms_' ), 'Roles: a SwiftForms capability was written to wp_user_roles.' );
		$out .= $this->block(
			'Stored roles and capabilities',
			'wp_user_roles unchanged: ' . ( $roles_before === $roles_after ? 'yes' : 'NO' ) . "\nuser capabilities unchanged: " . ( $caps_before === $caps_after ? 'yes' : 'NO' ) . "\nwp_user_roles mentions swiftforms_: " . ( false === strpos( $roles_after, 'swiftforms_' ) ? 'no' : 'YES' ) . "\n"
		);
		$this->output( 'check-roles.txt', $out );
	}

	/**
	 * Check "multisite": a network install (config key "subsites", WP_TESTS_MULTISITE=1).
	 *
	 * Sites that existed before the network activation: "alpha" and "beta" had the plugin on (own tables, own settings, own
	 * entries, written raw), "legacy" never had it (no tables). Then the plugin is network-activated, "fresh" is created
	 * (wp_initialize_site installs it), "legacy" gets a first request (lazy install), a submission goes to alpha, and
	 * "fresh" is deleted (its tables are dropped). At each step the main site's own data must not move. Site IDs depend on the
	 * run, so sites are named by their label in the snapshot.
	 */
	private function check_multisite() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->failures[] = 'Multisite: this config needs WP_TESTS_MULTISITE=1.';
			return;
		}
		$data    = (array) $this->config['subsites'];
		$factory = $this->test->sf_factory();
		$active  = get_site_option( 'active_sitewide_plugins' );
		$ids     = [];
		$names   = [];
		$out     = '';
		$this->delta();

		$main_state = function () use ( $wpdb ) {
			return [
				'entries'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SwiftForms\Database::table( 'entries' ) ),
				'settings' => serialize( Settings::stored_options() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Byte comparison of stored values, never stored.
				'tables'   => count( SwiftForms\Database::missing() ),
			];
		};
		$label_of   = function ( $text ) use ( &$ids ) {
			foreach ( $ids as $label => $id ) {
				$text = str_replace( $GLOBALS['wpdb']->get_blog_prefix( $id ), '{prefix:' . $label . '}', $text );
			}
			return str_replace( $GLOBALS['wpdb']->base_prefix, '{prefix}', $text );
		};
		$describe   = function ( $label ) use ( &$ids, $wpdb, $label_of ) {
			switch_to_blog( $ids[ $label ] );
			Settings::flush_cache();
			$missing = SwiftForms\Database::missing();
			$raw     = static function ( $name ) use ( $wpdb ) {
				return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			};
			$text    = 'tables: ' . ( $missing ? count( $missing ) . ' of ' . count( SwiftForms\Database::TABLES ) . ' missing' : 'all ' . count( SwiftForms\Database::TABLES ) . ' present' ) . "\n";
			$text   .= 'table prefix: ' . $label_of( $wpdb->prefix ) . "\n";
			$text   .= 'swiftforms_db_version: ' . ( null === $raw( 'swiftforms_db_version' ) ? '(absent)' : $raw( 'swiftforms_db_version' ) ) . "\n";
			$marker  = $raw( 'swiftforms_installed' );
			// Only a marker the plugin wrote carries the running version; the seeded sites keep their frozen "1.0.0" as stored, so the
			// snapshot does not change when SWIFTFORMS_VERSION happens to be 1.0.0.
			if ( null !== $marker && ! in_array( $label, self::MULTISITE_SEEDED_SITES, true ) ) {
				$marker = str_replace( SWIFTFORMS_VERSION, '{VERSION}', (string) $marker );
			}
			$text  .= 'swiftforms_installed: ' . ( null === $marker ? '(absent)' : $marker ) . "\n";
			$stored = Settings::stored_options();
			$text  .= 'settings options: ' . ( $stored ? count( $stored ) : '(none)' ) . "\n";
			foreach ( $stored as $name => $value ) {
				$text .= '  ' . $name . ' = ' . maybe_serialize( $value ) . "\n";
			}
			$text .= 'effective notification_to: ' . wp_json_encode( Settings::get_value( 'email.notification_to' ) ) . ', retention_days: ' . wp_json_encode( Settings::get_value( 'general.retention_days' ) ) . ', store_ip: ' . wp_json_encode( Settings::get_value( 'general.store_ip' ) ) . "\n";
			if ( ! $missing ) {
				$counts = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . SwiftForms\Database::table( 'entries' ) . ' GROUP BY status ORDER BY status', ARRAY_A );
				$text  .= 'entries: ' . ( $counts ? implode(
					', ',
					array_map(
						static function ( $row ) {
							return $row['status'] . ' ' . $row['total'];
						},
						$counts
					)
				) : '(none)' ) . "\n";
				$forms  = $wpdb->get_results( 'SELECT title, status FROM ' . SwiftForms\Database::table( 'forms' ) . ' ORDER BY title', ARRAY_A );
				$text  .= 'forms in the index: ' . ( $forms ? implode(
					', ',
					array_map(
						static function ( $row ) {
							return $row['title'] . ' (' . $row['status'] . ')';
						},
						$forms
					)
				) : '(none)' ) . "\n";
			}
			restore_current_blog();
			Settings::flush_cache();
			return $text;
		};

		$out   .= $this->block( 'Main site before anything else', 'entries: ' . $main_state()['entries'] . ', tables missing: ' . $main_state()['tables'] . "\n" );
		$before = $main_state();

		// 1. Sites that exist before the plugin is network-activated.
		update_site_option( 'active_sitewide_plugins', [] );
		foreach ( [ 'alpha', 'beta', 'legacy' ] as $label ) {
			$ids[ $label ] = $factory->blog->create( [ 'path' => '/' . $label . '/' ] );
		}
		foreach ( [ 'alpha', 'beta' ] as $label ) {
			$this->multisite_write_site( $ids[ $label ], $data[ $label ], $label );
		}
		$out .= $this->block( 'Before network activation: alpha (plugin was on, own data)', $describe( 'alpha' ) );
		$out .= $this->block( 'Before network activation: beta (plugin was on, own data and settings)', $describe( 'beta' ) );
		$out .= $this->block( 'Before network activation: legacy (never had the plugin)', $describe( 'legacy' ) );

		// 2. Network activation changes no site; a site created afterwards is installed on wp_initialize_site.
		update_site_option( 'active_sitewide_plugins', $active ? $active : [ plugin_basename( SWIFTFORMS_FILE ) => SF_User_Config_Loader::FROZEN_TIME ] );
		$out         .= $this->block( 'After network activation: legacy is still untouched', $describe( 'legacy' ) );
		$ids['fresh'] = $factory->blog->create( [ 'path' => '/fresh/' ] );
		$out         .= $this->block( 'Created after activation: fresh (installed by wp_initialize_site)', $describe( 'fresh' ) );
		$this->multisite_write_site( $ids['fresh'], $data['fresh'], 'fresh' );
		$out .= $this->block( 'fresh after its page and an entry were added', $describe( 'fresh' ) );

		// 3. The first request on legacy installs it, and writes no settings option.
		switch_to_blog( $ids['legacy'] );
		SwiftForms\Activator::maybe_install();
		restore_current_blog();
		$out .= $this->block( 'legacy after its first request (lazy install)', $describe( 'legacy' ) );

		// 4. A submission to alpha lands in alpha's tables only.
		switch_to_blog( $ids['alpha'] );
		wp_cache_flush();
		SwiftForms\Form_Index::flush_cache();
		$row = $wpdb->get_row( 'SELECT form_id, source FROM ' . SwiftForms\Database::table( 'forms' ) . ' LIMIT 1', ARRAY_A );
		$this->expect( is_array( $row ), 'Multisite: alpha has no form in its index.' );
		$text = '';
		if ( is_array( $row ) ) {
			$form = [
				'key'     => 'alpha#1',
				'form_id' => $row['form_id'],
				'source'  => $row['source'],
			];
			$sf   = $data['alpha']['submission'];
			$this->reset_limits( false );
			$response = $this->rest( 'POST', '/swiftforms/v1/submit', array_merge( $this->controls( $form ), [ 'sf' => $sf ] ) );
			$this->expect( 200 === $response['status'] && 'success' === ( $response['data']['status'] ?? '' ), 'Multisite: the submission to alpha was not accepted (' . $response['status'] . ').' );
			$text .= $this->describe( $response );
		}
		restore_current_blog();
		$out  .= $this->block( 'A submission to alpha', $text . $describe( 'alpha' ) );
		$out  .= $this->block( 'beta (untouched by the submission)', $describe( 'beta' ) );
		$after = $main_state();
		$this->expect( $before === $after, 'Multisite: the main site\'s own entries, settings or tables changed while sites were set up.' );
		$out .= $this->block( 'Main site after all of it', 'entries: ' . $after['entries'] . ', tables missing: ' . $after['tables'] . ', settings options unchanged: ' . ( $before['settings'] === $after['settings'] ? 'yes' : 'NO' ) . "\n" );

		// 5. Deleting a site drops its tables; the others keep theirs.
		$fresh_prefix = $wpdb->get_blog_prefix( $ids['fresh'] );
		$tables_of    = static function ( $prefix ) use ( $wpdb ) {
			// DESCRIBE (not SHOW TABLES) because the test suite's tables are TEMPORARY, which MySQL 8 does not list (same as Database::missing()).
			$count    = 0;
			$suppress = $wpdb->suppress_errors();
			foreach ( \SwiftForms\Database::TABLES as $name ) {
				if ( $wpdb->get_results( 'DESCRIBE `' . esc_sql( $prefix . \SwiftForms\Database::PREFIX . $name ) . '`' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name built from a prefix and a fixed list.
					++$count;
				}
			}
			$wpdb->suppress_errors( $suppress );
			return $count;
		};
		$had          = $tables_of( $fresh_prefix );
		wp_delete_site( $ids['fresh'] );
		$now = $tables_of( $fresh_prefix );
		$this->expect( 6 === $had && 0 === $now, 'Multisite: deleting fresh should drop its 6 tables (had ' . $had . ', now ' . $now . ').' );
		$legacy_prefix = $wpdb->get_blog_prefix( $ids['legacy'] );
		wp_delete_site( $ids['legacy'] );
		$out .= $this->block( 'Deleting sites', 'fresh: ' . $had . ' SwiftForms tables before, ' . $now . ' after' . "\nlegacy (installed lazily): " . $tables_of( $legacy_prefix ) . " tables after\nalpha: " . $tables_of( $wpdb->get_blog_prefix( $ids['alpha'] ) ) . ' tables, beta: ' . $tables_of( $wpdb->get_blog_prefix( $ids['beta'] ) ) . " tables (untouched)\n" );
		$out .= $this->block( 'Main site at the very end', 'entries: ' . $main_state()['entries'] . ', tables missing: ' . $main_state()['tables'] . ', settings options unchanged: ' . ( $before['settings'] === $main_state()['settings'] ? 'yes' : 'NO' ) . "\n" );

		update_site_option( 'active_sitewide_plugins', $active );
		$this->output( 'check-multisite.txt', $out );
	}

	/**
	 * Write a subsite the way a site that already runs SwiftForms looks: tables, options, a page with a form, entries.
	 *
	 * The tables are created with Database::install() (as the harness does for the main site); everything else is a raw row.
	 *
	 * @param int                  $blog_id Site ID.
	 * @param array<string, mixed> $data    The config's description of the site.
	 * @param string               $label   Site label.
	 */
	private function multisite_write_site( $blog_id, array $data, $label ) {
		global $wpdb;

		switch_to_blog( $blog_id );
		if ( in_array( $label, self::MULTISITE_SEEDED_SITES, true ) ) {
			SwiftForms\Database::install();
			update_option( 'swiftforms_db_version', 1 );
			update_option(
				'swiftforms_installed',
				[
					'version' => '1.0.0',
					'time'    => SF_User_Config_Loader::FROZEN_TIME,
				]
			);
		}
		foreach ( (array) ( $data['settings'] ?? [] ) as $name => $value ) {
			update_option( $name, $value );
		}
		$wpdb->insert(
			$wpdb->posts,
			[
				'post_author'       => 1,
				'post_date'         => '2026-10-01 09:00:00',
				'post_date_gmt'     => '2026-10-01 09:00:00',
				'post_content'      => $data['page'],
				'post_title'        => 'Contact',
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'closed',
				'post_name'         => 'contact',
				'post_modified'     => '2026-10-01 09:00:00',
				'post_modified_gmt' => '2026-10-01 09:00:00',
				'post_type'         => 'page',
			]
		);
		$post_id = (int) $wpdb->insert_id;
		clean_post_cache( $post_id );
		SwiftForms\Form_Index::rebuild();
		$form_id = (string) $wpdb->get_var( 'SELECT form_id FROM ' . SwiftForms\Database::table( 'forms' ) . ' LIMIT 1' );
		$hash    = (string) $wpdb->get_var( 'SELECT schema_hash FROM ' . SwiftForms\Database::table( 'schemas' ) . ' LIMIT 1' );
		foreach ( (array) ( $data['entries'] ?? [] ) as $n => $entry ) {
			$wpdb->insert(
				SwiftForms\Database::table( 'entries' ),
				[
					'form_id'     => $form_id,
					'schema_hash' => $hash,
					'source'      => 'post:' . $post_id,
					'status'      => $entry['status'],
					'starred'     => 0,
					'user_id'     => 0,
					'created_gmt' => $entry['created'],
					'updated_gmt' => $entry['created'],
				]
			);
			$entry_id = (int) $wpdb->insert_id;
			foreach ( $entry['values'] as $field => $value ) {
				$wpdb->insert(
					SwiftForms\Database::table( 'entry_values' ),
					[
						'entry_id' => $entry_id,
						'field'    => $field,
						'idx'      => 0,
						'value'    => $value,
					]
				);
			}
		}
		restore_current_blog();
	}

	/**
	 * Check "global_styles": the site owner's styles for SwiftForms blocks (the user layer of theme.json) reach the merged global styles.
	 *
	 * The raw merged data for the swiftforms/* blocks is the snapshot (the same on every WordPress version). The generated stylesheet is not
	 * compared: its text differs between WordPress versions, and the throwaway core does not print block styles from a wp_global_styles post for any block.
	 */
	private function check_global_styles() {
		wp_clean_theme_json_cache();
		WP_Theme_JSON_Resolver::clean_cached_data();
		$raw    = WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
		$blocks = [];
		foreach ( (array) ( $raw['styles']['blocks'] ?? [] ) as $name => $styles ) {
			if ( 0 === strpos( (string) $name, 'swiftforms/' ) ) {
				$blocks[ $name ] = $styles;
			}
		}
		ksort( $blocks );
		$this->expect( isset( $blocks['swiftforms/form']['color']['background'] ) && isset( $blocks['swiftforms/submit']['color']['background'] ), 'Global styles: the owner\'s styles for the SwiftForms blocks are not in the merged global styles.' );
		$this->output( 'check-global-styles.txt', $this->block( 'Styles for swiftforms/* blocks in the merged global styles (raw)', '' === wp_json_encode( $blocks ) || ! $blocks ? "(none)\n" : $this->json( $blocks ) ) );
	}

	/**
	 * Check "hooks": the mu-plugin of the config uses every documented v1 hook (C-8.3), and the hooks that change behaviour do.
	 *
	 * The snippet records each call (hook => calls, argument types). Written to the snapshot: whether each hook ran, the argument types, and
	 * for the three actions the number of calls. Then two effects that need a request of their own: swiftforms_client_ip (the stored address is the
	 * one the hook returns) and swiftforms_throttle_limit (the coarse limiter lets exactly the hook's number of requests through).
	 */
	private function check_hooks() {
		global $wpdb;

		$documented = [
			'swiftforms_entry_saved',
			'swiftforms_mail_sent',
			'swiftforms_submission_complete',
			'swiftforms_validate_field',
			'swiftforms_spam_check',
			'swiftforms_before_send_mail',
			'swiftforms_merge_tags',
			'swiftforms_render_form',
			'swiftforms_capability_map',
			'swiftforms_client_ip',
			'swiftforms_throttle_limit',
		];
		$log        = isset( $GLOBALS['sf_user_config_hook_log'] ) ? (array) $GLOBALS['sf_user_config_hook_log'] : [];
		$text       = '';
		foreach ( $documented as $hook ) {
			$ran = ! empty( $log[ $hook ]['calls'] );
			$this->expect( $ran, 'Hooks: ' . $hook . ' never ran during the scenarios.' );
			$types = $ran ? array_keys( $log[ $hook ]['types'] ) : [];
			sort( $types );
			$counted = in_array( $hook, [ 'swiftforms_entry_saved', 'swiftforms_mail_sent', 'swiftforms_submission_complete' ], true );
			$text   .= str_pad( $hook, 34 ) . ( $ran ? 'ran' : 'DID NOT RUN' ) . ( $ran && $counted ? ', ' . $log[ $hook ]['calls'] . ' calls' : '' ) . "\n";
			foreach ( $types as $signature ) {
				$text .= '    arguments: ' . $signature . "\n";
			}
		}
		$out = $this->block( 'Documented v1 hooks used by the config\'s mu-plugin', $text );

		// swiftforms_client_ip: the address the hook returns is the one that is stored.
		$forms = $this->live_forms();
		$form  = $forms[0];
		$sf    = SF_VALUES( $this->payload_names( $form, 'valid' )[0] );
		$this->delta();
		$_SERVER['HTTP_X_SF_CLIENT'] = '198.51.100.9';
		$response                    = $this->submit( $form, $sf );
		unset( $_SERVER['HTTP_X_SF_CLIENT'] );
		$ip = $wpdb->get_var( 'SELECT HEX(ip) FROM ' . SwiftForms\Database::table( 'entries' ) . ' ORDER BY id DESC LIMIT 1' );
		$this->expect( 200 === $response['status'] && 'C6336409' === $ip, 'Hooks: swiftforms_client_ip did not decide the stored address (' . wp_json_encode( $ip ) . ').' );
		$out .= $this->block( 'swiftforms_client_ip returns 198.51.100.9', 'status: ' . $response['status'] . "\nstored address (hex): " . $ip . "\n" );
		$this->delta();

		// swiftforms_throttle_limit: the hook says 3 requests a minute.
		$GLOBALS['sf_user_config_throttle'] = 3; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Read by the config's mu-plugin snippet.
		$this->reset_limits( false );
		$statuses = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$response   = $this->rest( 'POST', '/swiftforms/v1/submit', [ '_sf_form' => wp_generate_uuid4() ] );
			$statuses[] = $response['status'];
		}
		unset( $GLOBALS['sf_user_config_throttle'] );
		$this->reset_limits( false );
		$this->expect( [ 403, 403, 403, 429, 429 ] === $statuses, 'Hooks: swiftforms_throttle_limit (3) did not limit the coarse limiter as expected (' . implode( ',', $statuses ) . ').' );
		$out .= $this->block( 'swiftforms_throttle_limit returns 3', 'statuses of 5 requests: ' . implode( ', ', $statuses ) . "\n" );

		$this->output( 'check-hooks.txt', $out );
	}

	/**
	 * Check "heavy_history": the entries list of a big site.
	 *
	 * For every form, with the status filter on, the middle and the last page: the SwiftForms queries the request runs are
	 * counted (at most four) and the count and page queries are EXPLAINed (no ANALYZE TABLE) to see that MySQL picks
	 * the form_status_date index. Entries that point at older schema snapshots are read one by one: each is shown with the labels
	 * of the snapshot it was stored under, even for fields the current form no longer has. Timings are not asserted.
	 */
	private function check_heavy_history() {
		global $wpdb;

		$admin  = $this->admin_id();
		$table  = SwiftForms\Database::table( 'entries' );
		$out    = '';
		$counts = $wpdb->get_results( "SELECT form_id, status, COUNT(*) AS total, SUM(starred) AS starred FROM {$table} GROUP BY form_id, status ORDER BY form_id, status", ARRAY_A );
		$text   = '';
		foreach ( is_array( $counts ) ? $counts : [] as $row ) {
			$text .= $this->text( $row['form_id'] ) . ' ' . $row['status'] . ': ' . $row['total'] . ' entries, ' . (int) $row['starred'] . " starred\n";
		}
		$out .= $this->block( 'Entries stored per form and status (after the scenarios)', $text );

		$queries = [];
		$capture = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		$prefix  = $wpdb->prefix . 'swiftforms_';

		foreach ( $this->forms as $form ) {
			foreach ( [ 'new', 'read', 'spam', 'trash' ] as $status ) {
				$text  = '';
				$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %s AND status = %s", $form['form_id'], $status ) );
				$last  = max( 1, (int) ceil( $total / 20 ) );
				foreach ( array_unique( [ 1, (int) ceil( $last / 2 ), $last ] ) as $page ) {
					$queries = [];
					add_filter( 'query', $capture );
					$response = $this->rest(
						'GET',
						'/swiftforms/v1/entries',
						[
							'form'   => $form['form_id'],
							'status' => $status,
							'page'   => $page,
						],
						$admin
					);
					remove_filter( 'query', $capture );

					$own = array_values(
						array_filter(
							$queries,
							static function ( $sql ) use ( $prefix ) {
								return false !== strpos( $sql, $prefix );
							}
						)
					);
					$this->expect( 200 === $response['status'], 'Heavy history: the list of ' . $form['key'] . ' ' . $status . ' page ' . $page . ' answered ' . $response['status'] . '.' );
					$this->expect( count( $own ) <= 4, 'Heavy history: the list of ' . $form['key'] . ' ' . $status . ' page ' . $page . ' ran ' . count( $own ) . ' SwiftForms queries (at most 4).' );

					$keys = [];
					foreach ( $own as $sql ) {
						if ( 0 === strpos( ltrim( $sql ), 'SELECT COUNT(*)' ) || 0 === strpos( ltrim( $sql ), 'SELECT id, form_id, schema_hash' ) ) {
							$plan = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own query, captured above.
							$key  = is_array( $plan ) ? (string) ( $plan['key'] ?? '' ) : '';
							$this->expect( 'form_status_date' === $key, 'Heavy history: the ' . ( 0 === strpos( ltrim( $sql ), 'SELECT COUNT' ) ? 'count' : 'page' ) . ' query of ' . $form['key'] . ' ' . $status . ' uses the index "' . $key . '", not form_status_date.' );
							$keys[] = ( 0 === strpos( ltrim( $sql ), 'SELECT COUNT' ) ? 'count' : 'page' ) . ' query uses ' . $key;
						}
					}
					$ids   = wp_list_pluck( (array) $response['data'], 'id' );
					$text .= $form['key'] . ' / ' . $status . ' / page ' . $page . ' of ' . $last . ': x-wp-total ' . ( $response['headers']['X-WP-Total'] ?? '?' ) . ', ' . count( $ids ) . ' items, ' . count( $own ) . ' SwiftForms queries; ' . implode( '; ', $keys ) . "\n";
				}
				$out .= $this->block( 'List queries: ' . $form['key'] . ' / ' . $status, $text );
			}
		}

		// One entry per schema snapshot, oldest first: the labels come from the snapshot the entry was stored under.
		$text    = '';
		$hashes  = $wpdb->get_col( "SELECT DISTINCT schema_hash FROM {$table} WHERE form_id = '{$this->forms[0]['form_id']}' ORDER BY schema_hash" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A UUID read from the index.
		$samples = [];
		foreach ( (array) $hashes as $hash ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$table} WHERE schema_hash = %s", $hash ) );
			if ( $id > 0 ) {
				$samples[ $id ] = $hash;
			}
		}
		ksort( $samples );
		foreach ( $samples as $id => $hash ) {
			$one   = $this->rest( 'GET', '/swiftforms/v1/entries/' . $id, [], $admin );
			$text .= '-- entry ' . $id . " --\n" . $this->describe( $one );
		}
		$out .= $this->block( 'One entry per schema snapshot of the first form', $text );

		$this->output( 'check-heavy-history.txt', $out );
	}

	/**
	 * Check "patterns": the patterns SwiftForms registers (swiftforms/*) are in the inserter, but a pattern that is registered and placed nowhere is not a
	 * live location (C-3.11): a submission to one is answered "closed" and stores nothing.
	 *
	 * The form blocks of the patterns have no formId, so their effective IDs are the ones the contract derives for a missing ID.
	 */
	private function check_patterns() {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$out      = '';
		$names    = [];
		foreach ( $registry->get_all_registered() as $pattern ) {
			if ( 0 === strpos( (string) $pattern['name'], 'swiftforms/' ) ) {
				$names[] = (string) $pattern['name'];
			}
		}
		sort( $names );
		$this->expect( (bool) $names, 'Patterns: SwiftForms registers no pattern.' );
		$this->delta();

		foreach ( $names as $name ) {
			$ref   = 'pattern:' . $name;
			$forms = Block_Walker::forms( parse_blocks( (string) $registry->get_registered( $name )['content'] ) );
			$text  = '';
			foreach ( $forms as $form ) {
				$form_id = SwiftForms\Uuid::v5( 'missing|' . $ref . '|' . $form['ordinal'] );
				$live    = SwiftForms\Form_Index::is_live( $form_id, $ref );
				$this->reset_limits( false );
				$response = $this->rest(
					'POST',
					'/swiftforms/v1/submit',
					[
						'_sf_form'   => $form_id,
						'_sf_source' => $ref,
						'_sf_token'  => 'v1.' . base_convert( (string) intdiv( SF_User_Config_Loader::FROZEN_TIME, HOUR_IN_SECONDS ), 10, 36 ) . '.' . str_repeat( '0', 32 ),
						'_sf_hp'     => '',
						'_sf_t'      => 'js.10000',
						'_sf_v'      => SWIFTFORMS_VERSION,
						'_sf_i'      => '1',
						'sf'         => [ 'name' => 'Probe' ],
					]
				);
				$delta    = $this->delta();
				$this->expect( ! $live && 403 === $response['status'] && 'closed' === ( $response['data']['status'] ?? '' ) && false === strpos( $delta, 'entry:' ), 'Patterns: a submission to the registered, unplaced pattern ' . $name . ' is not answered "closed".' );
				$text .= 'form ' . $form['ordinal'] . ': in the index as live: ' . ( $live ? 'YES' : 'no' ) . "\n" . $this->describe( $response );
			}
			$out .= $this->block( 'Registered pattern ' . $name . ( $forms ? '' : ' (no form block)' ), $text );
		}
		$this->output( 'check-patterns.txt', $out );
	}

	/**
	 * Check "resave": every stored post, pattern, template and part with a form is saved again without an edit, the way the editor
	 * saves a document nobody changed (wp_update_post with the content it already has, as an administrator).
	 *
	 * The content must come back byte for byte. The one documented exception (config key "resave_writes": true) is the server writing the
	 * effective formId of a form block that has none or whose ID is a copy's (C-2.7); then nothing but the formId values may change.
	 */
	private function check_resave() {
		$may_write = ! empty( $this->config['resave_writes'] );
		$out       = '';
		wp_set_current_user( $this->admin_id() );
		foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || false === strpos( $post->post_content, '<!-- wp:swiftforms/form' ) ) {
				continue;
			}
			$before = $post->post_content;
			wp_update_post(
				wp_slash(
					[
						'ID'           => $id,
						'post_content' => $before,
					]
				),
				true
			);
			clean_post_cache( $id );
			$after = (string) get_post( $id )->post_content;

			$ids     = static function ( $content ) {
				preg_match_all( '/"formId":"([^"]*)"/', $content, $m );
				return $m[1];
			};
			$strip   = static function ( $content ) {
				return str_replace( [ ',}', '{}' ], [ '}', '' ], preg_replace( '/"formId":"[^"]*",?/', '', $content ) );
			};
			$changed = $before !== $after;
			if ( $changed ) {
				$this->expect( $may_write && $strip( $before ) === $strip( $after ), 'Resave: saving "' . $key . '" without an edit changed more than the formId values the server may write.' );
			}
			$out .= $key . ' (' . $post->post_type . '): ' . ( $changed ? 'formId written by the server: ' . $this->text( ( [] === $ids( $before ) ? '(none stored)' : implode( ', ', $ids( $before ) ) ) ) . ' -> ' . $this->text( implode( ', ', $ids( $after ) ) ) : 'unchanged' ) . "\n";
		}
		wp_set_current_user( 0 );
		$this->output( 'check-resave.txt', '' === $out ? "(no stored content with a form)\n" : $out );
	}

	/**
	 * Check "entry_details": the detail view of every entry the config inserted (at most 20), as an administrator.
	 *
	 * For data that is not as the plugin would have written it: an entry whose schema snapshot is missing, one whose form is gone.
	 * Whatever the answer is, it is an answer (no fatal) and it is the same on every release.
	 */
	private function check_entry_details() {
		$admin = $this->admin_id();
		$out   = '';
		foreach ( array_slice( $this->fixture_entry_ids, 0, 20 ) as $id ) {
			$response = $this->rest( 'GET', '/swiftforms/v1/entries/' . $id, [], $admin );
			$this->expect( in_array( $response['status'], [ 200, 404 ], true ), 'Entry details: entry ' . $id . ' answered ' . $response['status'] . '.' );
			$out .= $this->block( 'GET /entries/' . $id, $this->describe( $response ) );
		}
		$this->output( 'check-entry-details.txt', '' === $out ? "(the config inserted no entries)\n" : $out );
	}

	/**
	 * Check "spam_off": a form with spamProtection "off" skips the honeypot, the time trap, the JS token and the blocked words,
	 * but a forged token is still spam (Spam_Guard). Each case is stored as the status the contract gives it.
	 */
	private function check_spam_off() {
		$out = '';
		foreach ( $this->live_forms() as $form ) {
			$schema = Schema_Resolver::resolve( $form['form_id'], $form['source'] );
			if ( null === $schema || 'off' !== ( $schema->attributes['spamProtection'] ?? 'default' ) ) {
				continue;
			}
			$valid = $this->payload_names( $form, 'valid' );
			$sf    = SF_VALUES( $valid[0] );
			$cases = [
				'honeypot filled'       => [ [ '_sf_hp' => 'http://spam.example/' ], $sf, 'new' ],
				'too fast (js.100)'     => [ [ '_sf_t' => 'js.100' ], $sf, 'new' ],
				'no JS token over REST' => [ [ '_sf_t' => Time_Trap::issue( $form['form_id'], $form['source'] ) ], $sf, 'new' ],
				'forged token'          => [ [ '_sf_token' => 'v1.' . base_convert( (string) intdiv( SF_User_Config_Loader::FROZEN_TIME, HOUR_IN_SECONDS ), 10, 36 ) . '.' . str_repeat( '0', 32 ) ], $sf, 'spam' ],
			];
			foreach ( $this->payload_names( $form, 'spam' ) as $name ) {
				$cases[ 'payload with a blocked word: ' . $name ] = [ [], SF_VALUES( $name ), 'new' ];
			}
			$text = '';
			foreach ( $cases as $label => $case ) {
				$response = $this->submit( $form, $case[1], $case[0] );
				$delta    = $this->delta();
				$stored   = 1 === preg_match( '/"status": "([a-z]+)"/', $delta, $m ) ? $m[1] : '(nothing stored)';
				$this->expect( 200 === $response['status'] && $case[2] === $stored, 'Spam off: "' . $label . '" on ' . $form['key'] . ' was stored as ' . $stored . ', expected ' . $case[2] . '.' );
				$text .= '-- ' . $label . " --\nexpected entry status: " . $case[2] . "\n" . $this->describe( $response ) . "left behind:\n" . $delta;
			}
			$out .= $this->block( 'Spam protection off: ' . $form['key'], $text );
		}
		$this->output( 'check-spam-off.txt', '' === $out ? "(no form has spamProtection off)\n" : $out );
	}

	/**
	 * S10: schema and registry of this site (tables and blocks).
	 */
	private function scenario_s10() {
		global $wpdb;

		$text   = "== tables ==\n";
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swiftforms_' ) . '%' ) );
		sort( $tables );
		foreach ( $tables as $table ) {
			$text .= '{prefix}' . substr( $table, strlen( $wpdb->prefix ) ) . "\n";
		}
		$text .= $tables ? '' : "(none)\n";

		$text .= "\n== blocks ==\n" . self::blocks_text();
		++$this->checks;
		$this->output( 'S10.txt', $text );
	}

	/**
	 * The swiftforms/* blocks SwiftForms hands to the builder: name, attribute schema and the relations
	 * and supports that are part of the contract (ancestor, parent, context, supports).
	 *
	 * Read from SwiftForms' own block configs, compiled by the builder's Block_Definition without
	 * registering anything, so a snapshot does not depend on the builder having started. Whether the
	 * blocks are registered is asserted separately. One line per block: the name, a space, compact JSON.
	 *
	 * @return string
	 */
	public static function blocks_text() {
		$configs    = SwiftForms\Plugin::instance()->block_configs();
		$blocks     = isset( $configs['blocks'] ) && is_array( $configs['blocks'] ) ? $configs['blocks'] : [];
		$definition = '\\SwiftForms\\Vendor\\PedalCMS\\CassetteCMFBlocks\\Core\\Block_Definition';
		$lines      = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['id'] ) || 0 !== strpos( (string) $block['id'], 'swiftforms/' ) ) {
				continue;
			}
			$compiled = new $definition( $block );
			$args     = $compiled->get_args();
			$shape    = [ 'attributes' => $compiled->attribute_schema() ];
			foreach ( [ 'ancestor', 'parent', 'providesContext', 'usesContext', 'supports' ] as $key ) {
				if ( isset( $args[ $key ] ) ) {
					$shape[ $key ] = $args[ $key ];
				}
			}
			$lines[ $block['id'] ] = $block['id'] . ' ' . json_encode( $shape, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable bytes are required; wp_json_encode may differ between versions.
		}
		ksort( $lines );
		return $lines ? implode( '', $lines ) : "(none)\n";
	}

	/**
	 * Hooks text for the _global snapshot: every swiftforms_* hook with callbacks
	 * and every SwiftForms\ callback on any hook, as "hook priority callback".
	 *
	 * @return string
	 */
	public static function hooks_text() {
		global $wp_filter;
		$lines = [];
		foreach ( $wp_filter as $hook => $filter ) {
			foreach ( $filter->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( 0 === strpos( $hook, 'swiftforms_' ) || SF_User_Config_Loader::is_swiftforms_callback( $entry['function'] ) ) {
						// The activation hooks are named after the plugin file's path, which differs per machine.
						$name    = preg_replace( '/^(activate|deactivate)_.+swiftforms\.php$/', '$1_{plugin}', $hook );
						$lines[] = $name . ' ' . ( PHP_INT_MAX === $priority ? 'PHP_INT_MAX' : $priority ) . ' ' . self::describe_callback( $entry['function'] );
					}
				}
			}
		}
		sort( $lines, SORT_STRING );
		return $lines ? implode( "\n", $lines ) . "\n" : "(none)\n";
	}

	/**
	 * REST routes in the swiftforms/v1 namespace, with methods and whether a logged-out caller passes.
	 *
	 * @return string
	 */
	public static function rest_routes_text() {
		wp_set_current_user( 0 );
		$lines = [];
		foreach ( rest_get_server()->get_routes( 'swiftforms/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) $handler['methods'] ) );
				sort( $methods );
				$lines[] = $route . ' ' . implode( '|', $methods ) . ' ' . ( self::is_public( $handler ) ? 'public' : 'private' );
			}
		}
		sort( $lines, SORT_STRING );
		return $lines ? implode( "\n", $lines ) . "\n" : "(none)\n";
	}

	/**
	 * Whether a route handler lets a logged-out caller through.
	 *
	 * @param array $handler Route handler (from WP_REST_Server::get_routes()).
	 * @return bool
	 */
	public static function is_public( array $handler ) {
		if ( ! isset( $handler['permission_callback'] ) ) {
			return true;
		}
		if ( '__return_true' === $handler['permission_callback'] ) {
			return true;
		}
		wp_set_current_user( 0 );
		return true === call_user_func( $handler['permission_callback'], new WP_REST_Request( 'GET', '/' ) );
	}

	/**
	 * CREATE TABLE statements of every swiftforms_* table, with prefix, AUTO_INCREMENT, integer widths and numeric default quoting normalised.
	 *
	 * @return string
	 */
	public static function db_schema_text() {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swiftforms_' ) . '%' ) );
		sort( $tables );
		$text = '';
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			$sql = str_replace( '`' . $wpdb->prefix, '`{prefix}', $row[1] );
			$sql = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $sql );
			// MySQL 8.0.17+ drops integer display widths and quotes numeric defaults; MariaDB keeps the width and does not quote. Snapshots must not depend on the server.
			$sql = preg_replace( '/\b(tinyint|smallint|mediumint|bigint|int)\(\d+\)/', '$1', $sql );
			$sql = preg_replace( "/ DEFAULT (-?\d+)(?=,?\n)/", " DEFAULT '\$1'", $sql );
			// MySQL 8 repeats the table collation/charset on every text column when it differs from the server default; MariaDB omits it. Drop only the clauses that equal the table's own.
			if ( preg_match( '/ COLLATE=(\w+)/', $sql, $m ) ) {
				$sql = preg_replace( '/ COLLATE ' . preg_quote( $m[1], '/' ) . '\b(?!=)/', '', $sql );
			}
			if ( preg_match( '/DEFAULT CHARSET=(\w+)/', $sql, $m ) ) {
				$sql = preg_replace( '/ CHARACTER SET ' . preg_quote( $m[1], '/' ) . '\b(?!=)/', '', $sql );
			}
			$text .= $sql . ";\n\n";
		}
		return SF_Normaliser::text( '' === $text ? "(none)\n" : $text );
	}

	/**
	 * Name a callback without anything that changes between runs (object ids, line numbers).
	 *
	 * @param mixed $callback A WordPress callback.
	 * @return string
	 */
	public static function describe_callback( $callback ) {
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$scope      = $reflection->getClosureScopeClass();
			return 'Closure(' . ( $scope ? $scope->getName() : $reflection->getNamespaceName() ) . ')';
		}
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return (string) $callback;
	}

	/**
	 * Record a snapshot text.
	 *
	 * @param string $name File name inside the config's snapshot directory.
	 * @param string $text Text.
	 */
	private function output( $name, $text ) {
		// A variant's snapshots sit next to the base ones: S2.txt becomes S2.<variant>.txt.
		if ( '' !== $this->variant && '_skipped.txt' !== $name ) {
			$name = preg_replace( '/\.txt$/D', '.' . $this->variant . '.txt', $name );
		}
		$this->outputs[ $this->slug . '/' . $name ] = SF_Normaliser::text( $text );
	}
}
